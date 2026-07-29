<?php
/**
 * EarnSphere - Retry Payment API
 * If payment is still pending and older than 4 hours (expired), signal to create new one.
 * Otherwise just check current status.
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/classes/SnippePayment.php';
require_once dirname(__DIR__) . '/classes/ErrorLogger.php';

header('Content-Type: application/json');

$order_id = trim($_GET['order_id'] ?? '');

if (empty($order_id)) {
    ErrorLogger::log('api', 'Retry payment failed: order ID missing', [], null, 'warning', 'api/retry_push.php');
    echo json_encode(['success' => false, 'message' => 'Order ID required']);
    exit;
}

$payment = Database::fetchOne(
    "SELECT * FROM payments WHERE order_id = ?",
    [$order_id]
);

if (!$payment) {
    ErrorLogger::log('payment', 'Retry payment failed: payment not found', [
        'order_id' => $order_id,
    ], null, 'warning', 'api/retry_push.php');
    echo json_encode(['success' => false, 'message' => 'Payment not found']);
    exit;
}

if ($payment['status'] === 'completed') {
    echo json_encode(['success' => true, 'status' => 'completed', 'message' => 'Payment already completed']);
    exit;
}

// Payment expired (> 4 hours) — user should create a new one
$created = strtotime($payment['created_at'] ?? 'now');
if (time() - $created > 14400) {
    Database::update('payments', ['status' => 'expired'], 'id = ?', [$payment['id']]);
    ErrorLogger::log('payment', 'Payment expired before confirmation', [
        'payment_id' => $payment['id'],
        'order_id'   => $order_id,
        'created_at' => $payment['created_at'] ?? null,
    ], (int) $payment['user_id'], 'warning', 'api/retry_push.php');
    echo json_encode(['success' => false, 'status' => 'expired', 'message' => 'Payment expired. Please create a new payment.']);
    exit;
}

// Still within window — try to verify via Snippe API
if (!empty($payment['snippe_reference'])) {
    try {
        $snippe = new SnippePayment();
        $verify = $snippe->verifyPayment($payment['snippe_reference']);
        
        if ($verify['success'] && $verify['status'] === 'completed') {
            echo json_encode(['success' => true, 'status' => 'completed', 'message' => 'Payment confirmed!']);
            exit;
        }
        if (!$verify['success']) {
            ErrorLogger::log('payment', 'Retry payment verification failed', [
                'payment_id' => $payment['id'],
                'order_id'   => $order_id,
                'reference'  => $payment['snippe_reference'],
                'error'      => $verify['error'] ?? 'Unknown verification error',
            ], (int) $payment['user_id'], 'error', 'api/retry_push.php');
        }
    } catch (Exception $e) {
        error_log("Retry verify error: " . $e->getMessage());
        ErrorLogger::logException($e, 'payment', (int) $payment['user_id'], 'api/retry_push.php');
    }
}

echo json_encode(['success' => false, 'status' => $payment['status'], 'message' => 'Payment not yet confirmed. Please check your phone and approve the USSD prompt.']);
