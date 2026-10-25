<?php
require_once __DIR__ . '/../includes/payment_api.php';
$userId = paymentApiUser();
$user = Database::fetchOne('SELECT status, role FROM users WHERE id = ?', [$userId]);
if (!$user || $user['role'] !== 'user' || $user['status'] !== 'active') paymentApiReply(['success' => false, 'error' => 'An active user account is required.'], 403);
$payout = Database::fetchOne('SELECT p.* FROM payouts p JOIN withdrawals w ON w.id = p.withdrawal_id WHERE w.id = ? AND w.user_id = ? AND p.user_id = ? ORDER BY p.id DESC LIMIT 1', [(int) ($_GET['withdrawal_id'] ?? 0), $userId, $userId]);
if (!$payout) paymentApiReply(['success' => false, 'error' => 'Payout not found.'], 404);
try {
    $last = $_SESSION['withdrawal_poll'][$payout['id']] ?? 0;
    if ($payout['status'] === 'pending' && time() - $last < 5) paymentApiReply(['success' => true, 'status' => 'pending', 'message' => 'Please wait a few seconds before checking again.']);
    $_SESSION['withdrawal_poll'][$payout['id']] = time();
    $service = new AblinerPayment();
    $result = $service->reconcile('payouts', (int) $payout['id']);
    paymentApiReply(['success' => $result['success'], 'status' => $result['status'] ?? $payout['status'], 'message' => $result['success'] ? ($result['message'] ?? 'Status checked.') : 'Verification unavailable. Your funds remain reserved; do not submit another payout.'], 200, $service);
} catch (Throwable $e) {
    error_log('User payout verification #' . $payout['id'] . ': ' . $e->getMessage());
    paymentApiReply(['success' => false, 'error' => 'Transfer verification is unavailable. Funds remain reserved; contact support before another withdrawal.'], 503);
}
