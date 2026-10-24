<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../classes/AblinerClient.php';
require_once __DIR__ . '/../classes/Wallet.php';
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$gateway = new AblinerClient(['api_key' => 'test', 'webhook_secret' => 'test-only-secret', 'base_url' => 'https://abliner.net', 'callback_url' => 'https://example.test/webhooks/abliner.php']);
$body = '{"event":"transaction.completed","data":{"reference":"test"}}';
$timestamp = (string) time();
$headers = ['X-Webhook-Timestamp' => $timestamp, 'X-Webhook-Signature' => hash_hmac('sha256', $timestamp . '.' . $body, 'test-only-secret')];
check($gateway->verifyWebhookSignature($headers, $body), 'Valid signature rejected');
check(!$gateway->verifyWebhookSignature($headers, $body . ' '), 'Tampered body accepted');
check(!$gateway->verifyWebhookSignature([], $body), 'Missing signature accepted');
$oldTime = (string) (time() - 601);
check(!$gateway->verifyWebhookSignature(['x-webhook-timestamp' => $oldTime, 'x-webhook-signature' => hash_hmac('sha256', $oldTime . '.' . $body, 'test-only-secret')], $body), 'Stale signature accepted');
$noSecret = new AblinerClient(['api_key' => 'test', 'webhook_secret' => '', 'base_url' => 'https://abliner.net', 'callback_url' => 'https://example.test/webhooks/abliner.php']);
check(!$noSecret->verifyWebhookSignature($headers, $body), 'Missing secret accepted');
check(Wallet::autoExpirePending(123) === 0, 'Elapsed time must not release holds');
echo "Abliner signature and hold security checks passed.\n";
