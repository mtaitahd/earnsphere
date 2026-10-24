<?php
require_once __DIR__ . '/../includes/payment_api.php';
$userId = paymentApiUser();
paymentApiPost();
try {
    $service = new AblinerPayment();
    $result = $service->initiatePayment($userId, (string) ($_POST['phone'] ?? ''));
    paymentApiReply($result, $result['success'] ? 200 : 422, $service);
} catch (InvalidArgumentException $e) { paymentApiReply(['success' => false, 'error' => $e->getMessage()], 422); }
catch (Throwable $e) { error_log('Abliner payment initiation failed: ' . $e->getMessage()); paymentApiReply(['success' => false, 'error' => 'Payment could not be started. Check existing payment status before trying again.'], 503); }
