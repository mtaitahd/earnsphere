<?php
require_once __DIR__ . '/../classes/AblinerPayment.php';
require_once __DIR__ . '/../classes/StaffAccess.php';
require_once __DIR__.'/../classes/SnippePayout.php';
function withdrawalProvider(string $gateway): AblinerPayment {
    if (!in_array($gateway,['abliner','snippe'],true)) throw new RuntimeException('Unknown withdrawal provider.');
    return $gateway==='snippe'?new SnippePayout():new AblinerPayment();
}
Auth::initSession();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function paymentApiUser(bool $admin = false, ?string $permission = null): int {
    $id = (int) ($_SESSION['user_id'] ?? $_SESSION['pending_user_id'] ?? 0);
    if (!$id) paymentApiReply(['success' => false, 'error' => 'Please log in first.'], 401);
    if ($admin) {
        // Admin-only remains the default; endpoints explicitly grant module access to agents.
        if ($permission !== null) StaffAccess::requirePermission($permission, true);
        elseif (!StaffAccess::isAdministrator()) StaffAccess::deny(true);
    } elseif (($_SESSION['user_role'] ?? '') === 'agent' && !StaffAccess::isStaff()) {
        StaffAccess::deny(true);
    }
    return $id;
}
function paymentApiPost(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') paymentApiReply(['success' => false, 'error' => 'POST required.'], 405);
    if (!Auth::validateCSRF()) paymentApiReply(['success' => false, 'error' => 'Invalid security token.'], 403);
}
function paymentApiReply(array $body, int $status = 200, ?AblinerPayment $service = null): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    if ($service) {
        try { $service->flushNotifications(); }
        catch (Throwable $e) { error_log('Payment notification dispatch deferred: ' . $e->getMessage()); }
    }
    exit;
}
