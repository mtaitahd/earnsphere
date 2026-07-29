<?php
/**
 * EarnSphere - Check Payout Status API
 * AJAX endpoint for verifying payout status via Snippe API
 * Called from admin/withdrawals.php via fetch()
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/snippe.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/classes/SnippePayment.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

header('Content-Type: application/json');

Auth::initSession();

// Require admin
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    jsonResponse(['success' => false, 'error' => 'Permission required'], 403);
}

$payoutId = (int)($_GET['payout_id'] ?? 0);
$reference = trim($_GET['reference'] ?? '');

if ($payoutId <= 0 && empty($reference)) {
    jsonResponse(['success' => false, 'error' => 'Payout ID or reference required']);
}

// Fetch payout record
if ($payoutId > 0) {
    $payout = Database::fetchOne("SELECT * FROM payouts WHERE id = ?", [$payoutId]);
} else {
    $payout = Database::fetchOne("SELECT * FROM payouts WHERE reference = ?", [$reference]);
}

if (!$payout) {
    jsonResponse(['success' => false, 'error' => 'Payout record not found']);
}

// If no Snippe reference, we can't verify
if (empty($payout['reference'])) {
    jsonResponse([
        'success' => true,
        'status'  => $payout['status'],
        'source'  => 'database',
        'message' => 'No Snippe reference yet',
    ]);
}

// Verify via Snippe API
$snippe = new SnippePayment();
$result = $snippe->verifyPayout($payout['reference']);

if ($result['success']) {
    $snippeStatus = $result['status'];
    
    // Map Snippe status to our internal status
    $mappedStatus = match($snippeStatus) {
        'completed' => 'completed',
        'failed'    => 'failed',
        default     => 'pending',
    };
    
    // Only process status changes (not duplicates)
    if ($mappedStatus !== $payout['status']) {
        Database::update('payouts', [
            'status'   => $mappedStatus,
            'metadata' => json_encode($result['data']),
        ], 'id = ?', [$payout['id']]);
        
        require_once dirname(__DIR__) . '/classes/Wallet.php';
        require_once dirname(__DIR__) . '/classes/CommissionEngine.php';
        $snippeHandler = new SnippePayment();
        
        if ($mappedStatus === 'completed') {
            $snippeHandler->finalizePayoutSuccess(
                $payout['id'], $payout['withdrawal_id'], $payout['user_id'], $payout['amount']
            );
        } elseif ($mappedStatus === 'failed') {
            $snippeHandler->handlePayoutFailure(
                $payout['id'], $payout['withdrawal_id'], $payout['user_id'], $payout['amount'],
                $result['data']['error_message'] ?? 'Payout failed (verified by admin)'
            );
        }
    }
    
    jsonResponse([
        'success'         => true,
        'status'          => $mappedStatus,
        'snippe_status'   => $snippeStatus,
        'source'          => 'snippe_api',
        'fees'            => is_array($result['data']['fees'] ?? null) ? ($result['data']['fees']['value'] ?? 0) : ($result['data']['fees'] ?? 0),
        'external_ref'    => $result['data']['external_reference'] ?? null,
    ]);
} else {
    // API verification failed, return current DB status
    jsonResponse([
        'success'  => true,
        'status'   => $payout['status'],
        'source'   => 'database',
        'message'  => $result['error'] ?? 'Cannot verify with Snippe',
    ]);
}
