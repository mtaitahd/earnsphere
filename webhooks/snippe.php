<?php
/**
 * EarnSphere - Snippe Webhook Handler
 * 
 * Receives payment confirmations from Snippe API for both:
 * - Collection (registration payments)
 * - Disbursement (commission payouts)
 * 
 * Security: HMAC-SHA256 signature verification via SnippePayment::processWebhook()
 * Uses response-first pattern: respond 200 immediately, process async.
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/snippe.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/classes/SnippePayment.php';
require_once dirname(__DIR__) . '/classes/CommissionEngine.php';
require_once dirname(__DIR__) . '/classes/Wallet.php';

// Ensure logs directory exists
$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Read raw body — CRITICAL: must be passed to signature verification
$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody, true);

if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body']);
    exit;
}

// Get headers (normalize for different server configs)
$headers = getallheaders();

// Quick HMAC check before responding
$snippe = new SnippePayment();
$signatureValid = $snippe->verifyWebhookSignature($headers, $rawBody);

// Respond to Snippe immediately (response-first pattern)
// Prevents Snippe from retrying due to timeout
http_response_code(200);
header('Content-Type: text/plain');
echo 'OK';

// Flush response to client immediately
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} elseif (function_exists('ob_flush')) {
    ob_flush();
    flush();
}

// Process async AFTER response is sent
if (!$signatureValid) {
    error_log("EarnSphere: Webhook HMAC verification failed - ignoring");
    exit;
}

$result = $snippe->processWebhook($headers, $body, $rawBody);

// Log result
$logEntry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'success'   => $result['success'],
    'type'      => $result['type'] ?? 'unknown',
    'status'    => $result['status'] ?? 'unknown',
    'duplicate' => $result['duplicate'] ?? false,
    'error'     => $result['error'] ?? null,
];
file_put_contents($logDir . '/webhook.log', json_encode($logEntry) . "\n", FILE_APPEND | LOCK_EX);
