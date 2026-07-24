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
        
        if (!$verify['success']) { $failed++; continue; }
        
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
                    }
                }
            }
        }
    } catch (Exception $e) {
        $failed++;
        error_log("EarnSphere: Cron verify error: " . $e->getMessage());
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
