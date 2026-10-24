<?php
require_once __DIR__ . '/../includes/payment_api.php';
paymentApiUser(true, 'withdrawals');
$id = (int) ($_GET['payout_id'] ?? 0);
$ref = (string) ($_GET['reference'] ?? '');
$payout = $id > 0 ? Database::fetchOne('SELECT * FROM payouts WHERE id = ?', [$id]) : Database::fetchOne('SELECT * FROM payouts WHERE reference = ?', [$ref]);
if (!$payout) paymentApiReply(['success' => false, 'error' => 'Payout not found.'], 404);
try {
    $result = (new AblinerPayment())->reconcile('payouts', (int) $payout['id']);
    paymentApiReply($result + ['source' => 'abliner_api']);
} catch (Throwable $e) { error_log('Abliner payout reconciliation failed: ' . $e->getMessage()); paymentApiReply(['success' => false, 'error' => 'Verification unavailable. The payout hold is retained.'], 503); }
