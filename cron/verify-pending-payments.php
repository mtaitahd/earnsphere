<?php
/**
 * EarnSphere - Cron Job: Verify Pending Payments & Payouts
 * 
 * Safety net for missed webhooks.
 * Verifies stuck pending payments AND processing payouts via Snippe API.
 * 
 * Usage:
 *   CLI:  php cron/verify-pending-payments.php
 *   Web:  cron/verify-pending-payments.php?secret=es-cron-2026
 * 
 * Schedule: every 5 minutes
 */

$isCLI = php_sapi_name() === 'cli';

if (!$isCLI) {
    header('Content-Type: application/json');
}

$secret = $_GET['secret'] ?? '';
if (!$isCLI && $secret !== 'es-cron-2026') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/snippe.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/classes/SnippePayment.php';
require_once dirname(__DIR__) . '/classes/Wallet.php';
require_once dirname(__DIR__) . '/classes/CommissionEngine.php';
require_once dirname(__DIR__) . '/classes/ErrorLogger.php';

$snippe = new SnippePayment();

// PART 1: Verify stuck pending COLLECTION PAYMENTS

$pendingPayments = Database::fetchAll(
    "SELECT id, order_id, snippe_reference, user_id, created_at
     FROM payments
     WHERE status = 'pending'
     AND snippe_reference IS NOT NULL
     AND created_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)
     ORDER BY created_at ASC
     LIMIT 50"
);

$verified = 0;
$failed = 0;
$activated = 0;

foreach ($pendingPayments as $payment) {
    if (empty($payment['snippe_reference'])) continue;

    try {
        $verify = $snippe->verifyPayment($payment['snippe_reference']);
        
        if (!$verify['success']) {
            $failed++;
            ErrorLogger::log('payment', 'Cron payment verification failed', [
                'payment_id' => $payment['id'],
                'order_id'   => $payment['order_id'],
                'reference'  => $payment['snippe_reference'],
                'error'      => $verify['error'] ?? 'Unknown verification error',
            ], (int) $payment['user_id'], 'error', 'cron/verify-pending-payments.php');
            continue;
        }
        
        $newStatus = $verify['status'];
        $statusPriority = ['pending' => 0, 'failed' => 1, 'voided' => 1, 'expired' => 1, 'completed' => 2];
        $newPriority = $statusPriority[$newStatus] ?? 0;
        
        if ($newPriority > 0 && $newStatus !== 'pending') {
            Database::update('payments', [
                'status'       => $newStatus,
                'metadata'     => json_encode($verify['data']),
                'completed_at' => $newStatus === 'completed' ? date('Y-m-d H:i:s') : null,
            ], 'id = ?', [$payment['id']]);
            
            $verified++;
            
            if ($newStatus === 'completed') {
                $user = Database::fetchOne("SELECT status FROM users WHERE id = ?", [$payment['user_id']]);
                if ($user && $user['status'] !== 'active') {
                    Database::beginTransaction();
                    try {
                        Database::update('users', ['status' => 'active'], 'id = ?', [$payment['user_id']]);
                        CommissionEngine::processRegistrationCommissions($payment['user_id']);
                        Auth::logActivity($payment['user_id'], 'account_activated', 'Account activated via cron');
                        Database::commit();
                        $activated++;
                    } catch (Exception $e) {
                        Database::rollback();
                        error_log("EarnSphere: Cron activation error: " . $e->getMessage());
                        ErrorLogger::logException($e, 'payment', (int) $payment['user_id'], 'cron/verify-pending-payments.php');
                    }
                }
            } elseif ($newStatus === 'failed') {
                ErrorLogger::log('payment', 'Payment failed during cron verification', [
                    'payment_id' => $payment['id'],
                    'order_id'   => $payment['order_id'],
                    'reference'  => $payment['snippe_reference'],
                    'response'   => $verify['data'],
                ], (int) $payment['user_id'], 'error', 'cron/verify-pending-payments.php');
            }
        }
    } catch (Exception $e) {
        $failed++;
        error_log("EarnSphere: Cron verify error: " . $e->getMessage());
        ErrorLogger::logException($e, 'payment', (int) $payment['user_id'], 'cron/verify-pending-payments.php');
    }
}

if ($isCLI && !empty($pendingPayments)) {
    echo "Payments - Verified: {$verified}, Activated: {$activated}, Failed: {$failed}, Checked: " . count($pendingPayments) . "\n";
}

// PART 2: Verify stuck PAYOUTS (withdrawals)

$stuckPayouts = Database::fetchAll(
    "SELECT p.id, p.withdrawal_id, p.user_id, p.amount, p.reference, p.status, p.created_at
     FROM payouts p
     WHERE p.status IN ('pending', 'processing')
     AND p.created_at < DATE_SUB(NOW(), INTERVAL 2 MINUTE)
     ORDER BY created_at ASC
     LIMIT 20"
);

$pwVerified = 0;
$pwFailed = 0;

foreach ($stuckPayouts as $payout) {
    if (empty($payout['reference'])) {
        if ($isCLI) echo "  Payout #{$payout['id']}: skipped (no reference)\n";
        continue;
    }

    try {
        $verify = $snippe->verifyPayout($payout['reference']);

        if (!$verify['success']) {
            $pwFailed++;
            ErrorLogger::log('withdrawal', 'Cron payout verification failed', [
                'payout_id'     => $payout['id'],
                'withdrawal_id' => $payout['withdrawal_id'],
                'reference'     => $payout['reference'],
                'error'         => $verify['error'] ?? 'Unknown verification error',
            ], (int) $payout['user_id'], 'error', 'cron/verify-pending-payments.php');
            if ($isCLI) echo "  Payout #{$payout['id']}: API error\n";
            continue;
        }

        $newStatus = $verify['status'];

        if ($newStatus === 'completed' && $payout['status'] !== 'completed') {
            Database::update('payouts', ['status' => 'completed'], 'id = ?', [$payout['id']]);
            Database::update('withdrawals', [
                'status' => 'completed',
                'processed_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$payout['withdrawal_id']]);
            $pwVerified++;
            if ($isCLI) echo "  Payout #{$payout['id']}: completed!\n";
        } elseif ($newStatus === 'failed' && $payout['status'] !== 'failed') {
            $snippe->handlePayoutFailure($payout['id'], $payout['withdrawal_id'], $payout['user_id'], $payout['amount'], 'Payout failed (verified by cron)');
            $pwFailed++;
            if ($isCLI) echo "  Payout #{$payout['id']}: failed - wallet restored\n";
        } else {
            if ($isCLI) echo "  Payout #{$payout['id']}: unchanged ({$newStatus})\n";
        }
    } catch (Exception $e) {
        $pwFailed++;
        error_log("EarnSphere: Cron payout verify error #{$payout['id']}: " . $e->getMessage());
        ErrorLogger::logException($e, 'withdrawal', (int) $payout['user_id'], 'cron/verify-pending-payments.php');
        if ($isCLI) echo "  Payout #{$payout['id']}: error\n";
    }
}

if ($isCLI) {
    if (!empty($stuckPayouts)) {
        echo "Payouts - Completed: {$pwVerified}, Failed: {$pwFailed}, Checked: " . count($stuckPayouts) . "\n";
    }
    if (empty($pendingPayments) && empty($stuckPayouts)) {
        echo "Nothing to verify.\n";
    }
}

// PART 3: Expire pending withdrawals older than 1 hour

$expiredWithdrawals = Database::fetchAll(
    "SELECT w.id, w.user_id, w.amount, w.created_at
     FROM withdrawals w
     WHERE w.status = 'pending'
     AND w.created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
     ORDER BY w.created_at ASC
     LIMIT 50"
);

$expired = 0;

foreach ($expiredWithdrawals as $wd) {
    try {
        Database::beginTransaction();
        
        $payout = Database::fetchOne(
            "SELECT id FROM payouts WHERE withdrawal_id = ? AND status IN ('pending', 'processing')",
            [$wd['id']]
        );
        
        if ($payout) {
            Database::update('payouts', [
                'status'        => 'failed',
                'error_message' => 'Expired: pending for over 1 hour',
            ], 'id = ?', [$payout['id']]);
        }
        
        Database::update('withdrawals', [
            'status'     => 'failed',
            'admin_note' => 'Auto-expired: pending for over 1 hour',
        ], 'id = ?', [$wd['id']]);
        
        $wallet = Database::fetchOne("SELECT * FROM wallets WHERE user_id = ?", [$wd['user_id']]);
        if ($wallet) {
            $withdrawableBefore = (float) ($wallet['withdrawable_balance'] ?? 0);
            Database::update('wallets', [
                'withdrawable_balance' => $withdrawableBefore + $wd['amount'],
                'pending_amount'       => max(0, (float)$wallet['pending_amount'] - $wd['amount']),
            ], 'id = ?', [$wallet['id']]);
        }
        
        Database::update('wallet_transactions', [
            'status' => 'failed',
        ], 'user_id = ? AND reference_id = ? AND reference_type = ? AND status = ?', [
            $wd['user_id'], $wd['id'], 'withdrawal', 'pending'
        ]);
        
        Database::commit();
        $expired++;

        ErrorLogger::log('withdrawal', 'Withdrawal auto-expired by cron', [
            'withdrawal_id' => $wd['id'],
            'amount'        => $wd['amount'],
            'created_at'    => $wd['created_at'],
        ], (int) $wd['user_id'], 'warning', 'cron/verify-pending-payments.php');
        
        if ($isCLI) echo "  Withdrawal #{$wd['id']}: expired (TZS " . number_format($wd['amount']) . ") - wallet restored\n";
        
    } catch (Exception $e) {
        Database::rollback();
        error_log("EarnSphere: Cron expire withdrawal error #{$wd['id']}: " . $e->getMessage());
        ErrorLogger::logException($e, 'withdrawal', (int) $wd['user_id'], 'cron/verify-pending-payments.php');
        if ($isCLI) echo "  Withdrawal #{$wd['id']}: error\n";
    }
}

if ($isCLI && $expired > 0) {
    echo "Withdrawals - Expired: {$expired}\n";
}
