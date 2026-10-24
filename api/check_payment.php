<?php
require_once __DIR__ . '/../includes/payment_api.php';
$userId = paymentApiUser();
$order = (string) ($_GET['order_id'] ?? '');
$payment = Database::fetchOne('SELECT * FROM payments WHERE order_id = ? AND user_id = ?', [$order, $userId]);
if (!$payment) paymentApiReply(['status' => 'not_found'], 404);
try {
    $service = new AblinerPayment();
    $last = $_SESSION['payment_poll'][$payment['id']] ?? 0;
    if ($payment['status'] === 'pending' && time() - $last >= 5) {
        $_SESSION['payment_poll'][$payment['id']] = time();
        $result = $service->reconcile('payments', (int) $payment['id']);
    }
    $current = Database::fetchOne('SELECT status FROM payments WHERE id = ?', [$payment['id']]);
    paymentApiReply(['status' => $current['status'], 'order_id' => $order, 'message' => $result['error'] ?? $result['message'] ?? null], 200, $service);
} catch (Throwable $e) { error_log('Abliner payment reconciliation failed: ' . $e->getMessage()); paymentApiReply(['status' => $payment['status'], 'message' => 'Payment verification is temporarily unavailable. Do not pay again.']); }
