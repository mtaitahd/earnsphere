<?php
// Run with PHP CLI; no database, provider calls, or real credentials required.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../classes/SnippePayment.php';
require_once __DIR__ . '/../classes/Wallet.php';

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Keep deliberately rejected test events out of the production error database.
$logging = new ReflectionProperty(ErrorLogger::class, 'isLogging');
$logging->setValue(null, true);
$reflection = new ReflectionClass(SnippePayment::class);
$gateway = $reflection->newInstanceWithoutConstructor();
$config = $reflection->getProperty('config');
$config->setValue($gateway, ['webhook_secret' => 'test-only-secret']);
$body = '{"type":"payment.completed","data":{"reference":"test"}}';
$timestamp = (string) time();
$headers = [
    'X-Webhook-Timestamp' => $timestamp,
    'X-Webhook-Signature' => hash_hmac('sha256', $timestamp . '.' . $body, 'test-only-secret'),
];
check($gateway->verifyWebhookSignature($headers, $body), 'Valid signature rejected');
check(!$gateway->verifyWebhookSignature($headers, $body . ' '), 'Tampered body accepted');
check(!$gateway->verifyWebhookSignature([], $body), 'Missing signature accepted');
$oldTime = (string) (time() - 601);
check(!$gateway->verifyWebhookSignature([
    'x-webhook-timestamp' => $oldTime,
    'x-webhook-signature' => hash_hmac('sha256', $oldTime . '.' . $body, 'test-only-secret'),
], $body), 'Stale signature accepted');
$config->setValue($gateway, ['webhook_secret' => '']);
check(!$gateway->verifyWebhookSignature($headers, $body), 'Missing secret accepted');
check(Wallet::autoExpirePending(123) === 0, 'Elapsed time must not release payout holds');

// Each retired endpoint must return 404 before loading services, even on POST.
foreach (['test-payout.php', 'temp_setup_otp.php', 'diagnostic.php', 'mail_test.php', 'install.php', 'api/payment-debug.php'] as $endpoint) {
    $file = dirname(__DIR__) . '/' . $endpoint;
    foreach (['GET', 'POST'] as $method) {
        $script = '$_SERVER["REQUEST_METHOD"]=' . var_export($method, true) . ';'
            . 'register_shutdown_function(function(){echo http_response_code();});'
            . 'include ' . var_export($file, true) . ';';
        $output = [];
        $runner = tempnam(sys_get_temp_dir(), 'earnsphere-test-');
        try {
            file_put_contents($runner, '<?php ' . $script);
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner), $output, $exitCode);
        } finally {
            unlink($runner);
        }
        check($exitCode === 0 && implode('', $output) === '404', "$endpoint failed to deny $method");
    }
}
echo "Security regression checks passed.\n";
