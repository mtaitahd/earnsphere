<?php
require_once __DIR__ . '/../includes/payment_api.php';
paymentApiUser();
$amount = filter_var($_GET['amount'] ?? '', FILTER_VALIDATE_INT);
if (!$amount || $amount < (float) app_setting('min_withdrawal', MIN_WITHDRAWAL) || $amount > (float) app_setting('max_withdrawal', MAX_WITHDRAWAL)) paymentApiReply(['success' => false, 'error' => 'Invalid amount.'], 422);
$result = (new AblinerClient())->preview((float) $amount);
paymentApiReply($result, $result['success'] ? 200 : 503);
