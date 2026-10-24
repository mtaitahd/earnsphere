<?php
require_once __DIR__ . '/../classes/AblinerPayment.php';
header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
$raw = file_get_contents('php://input');
$client = new AblinerClient();
if (!$client->verifyWebhookSignature(getallheaders(), $raw)) { http_response_code(401); exit('{"error":"Invalid signature"}'); }
try {
    $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($body)) throw new InvalidArgumentException('Invalid body');
    $service = new AblinerPayment($client);
    $result = $service->processWebhook($body);
    echo json_encode(['success' => true] + $result);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    // Cron sends queued email/SMS after the payment transaction has committed.
} catch (JsonException | InvalidArgumentException $e) {
    http_response_code(400); echo '{"error":"Invalid event"}';
} catch (Throwable $e) {
    error_log('Abliner webhook processing failed: ' . $e->getMessage());
    http_response_code(503); echo '{"error":"Reconciliation required"}';
}
