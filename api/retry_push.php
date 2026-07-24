<?php
/**
 * EarnSphere - Retry USSD Push API
 * Re-triggers the Snippe USSD push for a pending payment
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/classes/SnippePayment.php';

header('Content-Type: application/json');

$order_id = trim($_GET['order_id'] ?? '');

if (empty($order_id)) {
    echo json_encode(['success' => false, 'message' => 'Order ID required']);
    exit;
}

$payment = Database::fetchOne(
    "SELECT * FROM payments WHERE order_id = ? AND status = 'pending'",
    [$order_id]
);

if (!$payment) {
    echo json_encode(['success' => false, 'message' => 'No pending payment found']);
    exit;
}

if (empty($payment['snippe_reference'])) {
    echo json_encode(['success' => false, 'message' => 'Payment reference not found']);
    exit;
}

try {
    $snippe = new SnippePayment();
    $phone = $payment['phone'] ?? '';
    
    $result = $snippe->retryPush($payment['snippe_reference'], $phone);
    
    if ($result['success']) {
        Auth::logActivity($payment['user_id'], 'push_retry', "USSD push retried for order {$order_id}");
        echo json_encode(['success' => true, 'message' => 'USSD push resent successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Failed to resend push']);
    }
} catch (Exception $e) {
    error_log("Retry push error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'System error. Try again.']);
}
