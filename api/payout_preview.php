<?php
require_once __DIR__ . '/../includes/payment_api.php';
paymentApiUser();
if ((StaffAccess::current()['role'] ?? '') === 'agent') StaffAccess::requirePermission('withdrawals', true);
$amount = filter_var($_GET['amount'] ?? '', FILTER_VALIDATE_INT);
if (!$amount || $amount < (float) app_setting('min_withdrawal', MIN_WITHDRAWAL) || $amount > (float) app_setting('max_withdrawal', MAX_WITHDRAWAL)) paymentApiReply(['success' => false, 'error' => 'Invalid amount.'], 422);
try { $quote = WithdrawalCharge::quote((float) $amount); }
catch (InvalidArgumentException $e) { paymentApiReply(['success' => false, 'error' => $e->getMessage()], 422); }
$result = (new AblinerClient())->preview((float) $quote['net_amount']);
$result['withdrawal_quote'] = $quote;
paymentApiReply($result, $result['success'] ? 200 : 503);
