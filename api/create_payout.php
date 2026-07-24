<?php
/**
 * EarnSphere - Create Payout API
 * AJAX endpoint for admin to send payout via Snippe Disbursement
 * Called from admin/withdrawals.php via fetch()
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/snippe.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/classes/SnippePayment.php';
require_once dirname(__DIR__) . '/classes/Wallet.php';

header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

Auth::initSession();

// Require admin
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    jsonResponse(['success' => false, 'error' => 'Permission required'], 403);
}

// CSRF check
if (!Auth::validateCSRF($_POST[CSRF_TOKEN_NAME] ?? '')) {
    jsonResponse(['success' => false, 'error' => 'Usalama: CSRF token si sahihi'], 403);
}

$withdrawalId = (int)($_POST['withdrawal_id'] ?? 0);
$adminId      = (int) $_SESSION['user_id'];

if ($withdrawalId <= 0) {
    jsonResponse(['success' => false, 'error' => 'ID ya ombi si sahihi']);
}

// Fetch withdrawal with user data
$withdrawal = Database::fetchOne(
    "SELECT w.*, u.full_name, u.phone as user_phone, u.email
     FROM withdrawals w
     JOIN users u ON w.user_id = u.id
     WHERE w.id = ?",
    [$withdrawalId]
);

if (!$withdrawal) {
    jsonResponse(['success' => false, 'error' => 'Withdrawal request not found']);
}

if (!in_array($withdrawal['status'], ['approved', 'failed'])) {
    jsonResponse(['success' => false, 'error' => 'This request cannot be processed (status: ' . $withdrawal['status'] . ')']);
}

// Use withdrawal phone (user may have entered a different one at request time)
$phone = $withdrawal['phone'] ?: $withdrawal['user_phone'];
$name  = $withdrawal['full_name'];
$amount = (float) $withdrawal['amount'];

// Send payout via Snippe
$snippe = new SnippePayment();
$result = $snippe->sendPayout($withdrawalId, $withdrawal['user_id'], $amount, $phone, $name);

if ($result['success']) {
    jsonResponse([
        'success'   => true,
        'payout_id' => $result['payout_id'],
        'reference' => $result['reference'] ?? null,
        'fees'      => $result['fees'] ?? 0,
        'total'     => $result['total'] ?? $amount,
        'provider'  => $result['provider'] ?? 'mobile',
        'status'    => $result['status'] ?? 'pending',
        'message'   => 'Payout imetumwa kwa Snippe.',
    ]);
} else {
    jsonResponse([
        'success' => false,
        'error'   => $result['error'] ?? 'Payout imeshindwa. Jaribu tena.',
    ]);
}
