<?php
require_once __DIR__ . '/../includes/payment_api.php';
$userId = paymentApiUser();
if ((StaffAccess::current()['role'] ?? '') === 'agent') StaffAccess::requirePermission('wallet', true);
$order = (string) ($_GET['order_id'] ?? '');
$payment = Database::fetchOne('SELECT * FROM payments WHERE order_id = ? AND user_id = ?', [$order, $userId]);
if (!$payment) paymentApiReply(['status' => 'not_found'], 404);
try {
    $service = new AblinerPayment();
    $isSnippe=($payment['gateway']??'')==='snippe';
    if($isSnippe)require_once dirname(__DIR__).'/classes/SnippePayment.php';
    $last = $_SESSION['payment_poll'][$payment['id']] ?? 0;
    if ($payment['status'] === 'pending' && time() - $last >= 5) {
        $_SESSION['payment_poll'][$payment['id']] = time();
        $result = $isSnippe ? (new SnippePayment())->reconcile((int)$payment['id']) : $service->reconcile('payments', (int) $payment['id']);
    }
    $current = Database::fetchOne('SELECT status FROM payments WHERE id = ?', [$payment['id']]);
    paymentApiReply(['status' => $current['status'], 'order_id' => $order, 'message' => $result['error'] ?? $result['message'] ?? null], 200, $service);
} catch (Throwable $e) { error_log('Payment reconciliation failed: ' . $e->getMessage()); paymentApiReply(['status' => $payment['status'], 'message' => 'Payment verification is temporarily unavailable. Do not pay again.']); }
