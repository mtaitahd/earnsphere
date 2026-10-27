<?php
require_once dirname(__DIR__) . '/classes/ReferralLookup.php';
Auth::initSession();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
StaffAccess::requirePermission('referral-lookup', true);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET'); http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Use GET.']); exit;
}
try {
    $code = is_string($_GET['code'] ?? null) ? $_GET['code'] : '';
    $level = is_string($_GET['level'] ?? null) && in_array($_GET['level'], ['1', '2'], true) ? (int) $_GET['level'] : 0;
    $page = is_scalar($_GET['page'] ?? 1) ? max(1, (int) ($_GET['page'] ?? 1)) : 1;
    $members = ReferralLookup::members($code, $level, $page);
    if (!$members) { http_response_code(404); echo json_encode(['success' => false, 'error' => 'Referral code not found.']); exit; }
    echo json_encode(['success' => true, 'data' => $members], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (InvalidArgumentException $e) {
    http_response_code(400); echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(503); echo json_encode(['success' => false, 'error' => 'Could not load members. Please try again.']);
}
