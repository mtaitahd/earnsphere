<?php
require_once __DIR__ . '/../includes/payment_api.php';
require_once __DIR__ . '/../classes/PaymentNotifications.php';
$userId = paymentApiUser();
$user = Database::fetchOne('SELECT role, status FROM users WHERE id = ?', [$userId]);
if (!$user || $user['role'] !== 'user' || !in_array($user['status'], ['active','pending'], true)) paymentApiReply(['success' => false, 'error' => 'User account required.'], 403);
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        paymentApiPost();
        if (!in_array($_POST['action'] ?? '', ['mark_all_read','clear_all'], true)) paymentApiReply(['success' => false, 'error' => 'Unknown notification action.'], 422);
        $throughId = filter_var($_POST['through_id'] ?? '', FILTER_VALIDATE_INT);
        if ($throughId === false || $throughId < 0) paymentApiReply(['success' => false, 'error' => 'Invalid notification ID.'], 422);
        if ($_POST['action'] === 'clear_all') PaymentNotifications::clearAll($userId, $throughId);
        else PaymentNotifications::markAllRead($userId, $throughId);
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') paymentApiReply(['success' => false, 'error' => 'Method not allowed.'], 405);
    if (empty($_SESSION['notifications_backfilled'][$userId])) {
        PaymentNotifications::backfillForUser($userId);
        $_SESSION['notifications_backfilled'][$userId] = true;
    }
    $body = ['success' => true] + PaymentNotifications::listForUser($userId);
    // Financial state and the JSON response must not depend on SMS availability.
    http_response_code(200);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    try { PaymentNotifications::dispatch(1, null, $userId); } catch (Throwable $e) { error_log('Notification dispatch: ' . $e->getMessage()); }
} catch (Throwable $e) {
    error_log('User notifications failed: ' . $e->getMessage());
    paymentApiReply(['success' => false, 'error' => 'Notifications are temporarily unavailable.'], 503);
}
