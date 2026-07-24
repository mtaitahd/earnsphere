<?php
/**
 * EarnSphere - Payment Debug Endpoint
 * Shows payment status and recent webhook logs
 * DELETE AFTER DEBUGGING
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/snippe.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/SnippePayment.php';

header('Content-Type: application/json');

$order_id = trim($_GET['order_id'] ?? '');

if (empty($order_id)) {
    echo json_encode(['error' => 'Provide ?order_id=ES-XXX']);
    exit;
}

$payment = Database::fetchOne("SELECT * FROM payments WHERE order_id = ?", [$order_id]);

if (!$payment) {
    echo json_encode(['error' => 'Payment not found', 'order_id' => $order_id]);
    exit;
}

// Try verifying via Snippe API now
$snippeVerify = null;
if (!empty($payment['snippe_reference'])) {
    try {
        $snippe = new SnippePayment();
        $snippeVerify = $snippe->verifyPayment($payment['snippe_reference']);
    } catch (Exception $e) {
        $snippeVerify = ['success' => false, 'error' => $e->getMessage()];
    }
}

// Read last 20 webhook log lines
$webhookLog = [];
$logFile = dirname(__DIR__) . '/logs/webhook.log';
if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $webhookLog = array_slice($lines, -20);
}

echo json_encode([
    'payment' => [
        'id'                => $payment['id'],
        'order_id'          => $payment['order_id'],
        'user_id'           => $payment['user_id'],
        'amount'            => $payment['amount'],
        'status'            => $payment['status'],
        'snippe_reference'  => $payment['snippe_reference'],
        'webhook_received'  => $payment['webhook_received'],
        'created_at'        => $payment['created_at'],
        'completed_at'      => $payment['completed_at'],
    ],
    'snippe_verify' => $snippeVerify,
    'webhook_log'   => $webhookLog,
], JSON_PRETTY_PRINT);
