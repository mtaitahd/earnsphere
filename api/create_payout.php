<?php
require_once __DIR__ . '/../includes/payment_api.php';
paymentApiUser(true);
paymentApiPost();
$wd = Database::fetchOne('SELECT * FROM withdrawals WHERE id = ?', [(int) ($_POST['withdrawal_id'] ?? 0)]);
if (!$wd) paymentApiReply(['success' => false, 'error' => 'Withdrawal not found.'], 404);
try {
    $result = (new AblinerPayment())->sendPayout((int) $wd['id'], (int) $wd['user_id']);
    paymentApiReply($result);
} catch (Throwable $e) { error_log('Abliner payout dispatch failed: ' . $e->getMessage()); paymentApiReply(['success' => false, 'error' => 'Payout could not be dispatched. Verify its status or contact support.'], 422); }
