<?php
/**
 * EarnSphere - Create Payment API
 * AJAX endpoint for initiating Snippe payment collection
 * Called from payment.php via fetch()
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/snippe.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/classes/SnippePayment.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

Auth::initSession();

// Require authentication
if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'error' => 'Please login first'], 401);
}

// CSRF check
if (!Auth::validateCSRF($_POST[CSRF_TOKEN_NAME] ?? '')) {
    jsonResponse(['success' => false, 'error' => 'Security: Invalid CSRF token'], 403);
}

$userId = (int) $_SESSION['user_id'];
$phone  = trim($_POST['phone'] ?? '');

// Validate input
if (empty($phone)) {
    jsonResponse(['success' => false, 'error' => 'Enter payment phone number']);
}

// Validate phone format
$snippeValidator = new SnippePayment();
$phoneCheck = $snippeValidator->validatePhone($phone);
if (!$phoneCheck['valid']) {
    jsonResponse(['success' => false, 'error' => $phoneCheck['error']]);
}
$phone = $phoneCheck['phone'];

// Check user exists and is pending
$user = Database::fetchOne("SELECT id, status FROM users WHERE id = ?", [$userId]);
if (!$user) {
    jsonResponse(['success' => false, 'error' => 'User not found']);
}
if ($user['status'] === 'active') {
    jsonResponse(['success' => false, 'error' => 'Your account is already activated']);
}

// Check no duplicate pending payment in last 5 minutes
$recentPayment = Database::fetchOne(
    "SELECT id FROM payments WHERE user_id = ? AND status = 'pending' AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)",
    [$userId]
);
if ($recentPayment) {
    jsonResponse(['success' => false, 'error' => 'Payment already initiated. Please wait a moment.']);
}

// Initiate payment
$snippe = new SnippePayment();
$result = $snippe->initiatePayment($userId, $phone);

if ($result['success']) {
    jsonResponse([
        'success'    => true,
        'payment_id' => $result['payment_id'],
        'order_id'   => $result['order_id'],
        'reference'  => $result['reference'] ?? null,
        'message'    => 'Payment initiated. Complete on your phone.',
    ]);
} else {
    jsonResponse([
        'success' => false,
        'error'   => $result['error'] ?? 'Payment could not be initiated. Try again.',
    ]);
}
