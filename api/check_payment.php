<?php
/**
 * EarnSphere - Check Payment Status API
 * AJAX endpoint for polling payment confirmation
 * Includes Snippe API verification fallback for pending payments
 * Uses status priority system to prevent race conditions
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/snippe.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/classes/SnippePayment.php';

header('Content-Type: application/json');

$order_id = trim($_GET['order_id'] ?? '');

if (empty($order_id)) {
    echo json_encode(['status' => 'error', 'message' => 'Order ID required']);
    exit;
}

$payment = Database::fetchOne(
    "SELECT * FROM payments WHERE order_id = ?",
    [$order_id]
);

if (!$payment) {
    echo json_encode(['status' => 'not_found']);
    exit;
}

// If already completed or failed, just return status (no re-verification)
if (in_array($payment['status'], ['completed', 'failed', 'voided', 'expired'])) {
    echo json_encode([
        'status'   => $payment['status'],
        'order_id' => $order_id,
    ]);
    exit;
}

// If still pending and has snippe_reference, try verifying via Snippe API
if ($payment['status'] === 'pending' && !empty($payment['snippe_reference'])) {
    try {
        $snippe = new SnippePayment();
        $verify = $snippe->verifyPayment($payment['snippe_reference']);
        
        if ($verify['success']) {
            $newStatus = $verify['status'];
            if ($newStatus === 'successful') $newStatus = 'completed';
            
            // Status priority: completed(2) > failed/voided/expired(1) > pending(0)
            // Prevents race condition where API returns "pending" after webhook already set "completed"
            $statusPriority = ['pending' => 0, 'failed' => 1, 'voided' => 1, 'expired' => 1, 'completed' => 2];
            $currentPriority = $statusPriority[$payment['status']] ?? 0;
            $newPriority = $statusPriority[$newStatus] ?? 0;
            
            if ($newPriority > $currentPriority && $newStatus !== $payment['status']) {
                $updateData = [
                    'status'       => $newStatus,
                    'metadata'     => json_encode($verify['data']),
                    'completed_at' => $newStatus === 'completed' ? date('Y-m-d H:i:s') : null,
                ];
                
                Database::update('payments', $updateData, 'id = ?', [$payment['id']]);
                
                // If confirmed via polling (webhook may have missed), activate account
                if ($newStatus === 'completed') {
                    require_once dirname(__DIR__) . '/classes/Wallet.php';
                    require_once dirname(__DIR__) . '/classes/CommissionEngine.php';
                    
                    $user = Database::fetchOne("SELECT status FROM users WHERE id = ?", [$payment['user_id']]);
                    if ($user && $user['status'] !== 'active') {
                        Database::beginTransaction();
                        try {
                            Database::update('users', ['status' => 'active'], 'id = ?', [$payment['user_id']]);
                            CommissionEngine::processRegistrationCommissions($payment['user_id']);
                            Auth::logActivity($payment['user_id'], 'account_activated', 'Account activated via payment polling');
                            Database::commit();
                        } catch (Exception $e) {
                            Database::rollback();
                            error_log("EarnSphere: Polling activation error: " . $e->getMessage());
                        }
                    }
                }
                
                $payment['status'] = $newStatus;
            }
        }
    } catch (Exception $e) {
        error_log("Payment verify fallback error: " . $e->getMessage());
    }
}

echo json_encode([
    'status'   => $payment['status'],
    'order_id' => $order_id,
]);
