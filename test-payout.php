<?php
/**
 * EarnSphere - Direct Payout Test Script
 * 
 * STANDALONE - No database required.
 * Reads API keys from .env file.
 * 
 * DELETE THIS FILE AFTER TESTING!
 */

// Load .env
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        list($key, $value) = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}

$apiKey       = getenv('SNIPPE_API_KEY');
$apiUrl       = getenv('SNIPPE_API_URL') ?: 'https://api.snippe.sh';
$apiVersion   = getenv('SNIPPE_API_VERSION') ?: '2026-01-25';
$payoutWebhook = getenv('PAYOUT_WEBHOOK_URL') ?: '';

$result = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone  = preg_replace('/[\s\-()]/', '', trim($_POST['phone'] ?? ''));
    $amount = (float)($_POST['amount'] ?? 0);
    $name   = trim($_POST['name'] ?? 'Test User');
    $narration = trim($_POST['narration'] ?? 'EarnSphere - Test Payout');

    // Normalize phone
    if (strpos($phone, '+') === 0) $phone = substr($phone, 1);
    if (strpos($phone, '0') === 0 && strlen($phone) === 10) $phone = '255' . substr($phone, 1);
    if (strlen($phone) === 9 && strpos($phone, '0') !== 0) $phone = '255' . $phone;

    if (empty($apiKey)) {
        $error = 'SNIPPE_API_KEY not found in .env file.';
    } elseif (strlen($phone) < 10 || $amount <= 0) {
        $error = 'Please enter a valid phone number and amount.';
    } else {
        $idempotencyKey = 'test-' . bin2hex(random_bytes(8));

        $payload = [
            'amount'          => (int)$amount,
            'channel'         => 'mobile',
            'recipient_phone' => $phone,
            'recipient_name'  => $name,
            'narration'       => $narration,
            'webhook_url'     => $payoutWebhook,
            'metadata'        => ['source' => 'test-script'],
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => rtrim($apiUrl, '/') . '/v1/payouts/send',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'X-Api-Version: ' . $apiVersion,
                'Idempotency-Key: ' . $idempotencyKey,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $error = 'cURL Error: ' . $curlErr;
        } else {
            $decoded = json_decode($response, true);
            $result = [
                'http_code'   => $httpCode,
                'response'    => $decoded,
                'request'     => $payload,
                'idempotency' => $idempotencyKey,
            ];
            if ($httpCode >= 400) {
                $error = $decoded['message'] ?? $decoded['error'] ?? "HTTP $httpCode error";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EarnSphere - Test Payout</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; display: flex; justify-content: center; align-items: flex-start; padding: 40px 16px; }
        .container { width: 100%; max-width: 520px; }
        .warning { background: #7c2d12; border: 1px solid #dc2626; border-radius: 10px; padding: 14px 18px; margin-bottom: 24px; font-size: 13px; color: #fed7aa; text-align: center; }
        .warning strong { color: #fca5a5; }
        h1 { font-size: 22px; font-weight: 700; margin-bottom: 6px; color: #f8fafc; }
        .subtitle { font-size: 13px; color: #64748b; margin-bottom: 28px; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 28px; margin-bottom: 20px; }
        label { display: block; font-size: 13px; font-weight: 600; color: #94a3b8; margin-bottom: 6px; }
        input, textarea { width: 100%; padding: 12px 14px; background: #0f172a; border: 1px solid #334155; border-radius: 8px; color: #f1f5f9; font-size: 15px; outline: none; transition: border-color 0.2s; }
        input:focus, textarea:focus { border-color: #3b82f6; }
        textarea { resize: vertical; min-height: 60px; font-size: 13px; }
        .field { margin-bottom: 18px; }
        .row { display: flex; gap: 14px; }
        .row .field { flex: 1; }
        .btn { width: 100%; padding: 14px; background: #2563eb; color: #fff; border: none; border-radius: 8px; font-size: 16px; font-weight: 600; cursor: pointer; transition: background 0.2s; }
        .btn:hover { background: #1d4ed8; }
        .btn:disabled { background: #475569; cursor: not-allowed; }
        .btn-danger { background: #dc2626; }
        .btn-danger:hover { background: #b91c1c; }
        .result-card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 24px; margin-bottom: 20px; }
        .result-card.success { border-color: #22c55e; }
        .result-card.error { border-color: #ef4444; }
        .result-title { font-size: 15px; font-weight: 700; margin-bottom: 14px; }
        .result-title.ok { color: #4ade80; }
        .result-title.fail { color: #f87171; }
        pre { background: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 14px; font-size: 12px; line-height: 1.6; overflow-x: auto; color: #94a3b8; white-space: pre-wrap; word-break: break-all; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-green { background: #166534; color: #4ade80; }
        .badge-red { background: #7f1d1d; color: #fca5a5; }
        .badge-yellow { background: #713f12; color: #fde047; }
        .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #1e293b; font-size: 13px; }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #64748b; }
        .info-value { color: #e2e8f0; font-weight: 500; }
    </style>
</head>
<body>
<div class="container">
    <div class="warning">
        <strong>WARNING:</strong> DELETE this file after testing! <br>
        <code>rm /var/www/html/earnsphere/test-payout.php</code>
    </div>

    <h1>Direct Payout Test</h1>
    <p class="subtitle">Sends a real payout via Snippe API. No database required.</p>

    <div class="card">
        <form method="POST">
            <div class="field">
                <label>Recipient Phone</label>
                <input type="text" name="phone" placeholder="0712345678" required
                       value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
            </div>
            <div class="row">
                <div class="field">
                    <label>Amount (TZS)</label>
                    <input type="number" name="amount" placeholder="5000" min="100" required
                           value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>Recipient Name</label>
                    <input type="text" name="name" placeholder="John Doe"
                           value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
                </div>
            </div>
            <div class="field">
                <label>Narration</label>
                <textarea name="narration" placeholder="Payment description"><?= htmlspecialchars($_POST['narration'] ?? 'EarnSphere - Test Payout') ?></textarea>
            </div>
            <button type="submit" class="btn">Send Payout</button>
        </form>
    </div>

    <?php if ($error && !$result): ?>
    <div class="result-card error">
        <div class="result-title fail">Error</div>
        <p style="color: #fca5a5; font-size: 14px;"><?= htmlspecialchars($error) ?></p>
    </div>
    <?php endif; ?>

    <?php if ($result): ?>
    <div class="result-card <?= ($result['http_code'] >= 200 && $result['http_code'] < 300) ? 'success' : 'error' ?>">
        <div class="result-title <?= ($result['http_code'] >= 200 && $result['http_code'] < 300) ? 'ok' : 'fail' ?>">
            <?= ($result['http_code'] >= 200 && $result['http_code'] < 300) ? 'Payout Sent' : 'Request Failed' ?>
            <span class="badge <?= ($result['http_code'] >= 200 && $result['http_code'] < 300) ? 'badge-green' : 'badge-red' ?>">
                HTTP <?= $result['http_code'] ?>
            </span>
        </div>

        <div class="info-row">
            <span class="info-label">Phone</span>
            <span class="info-value"><?= htmlspecialchars($result['request']['recipient_phone']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Amount</span>
            <span class="info-value">TZS <?= number_format($result['request']['amount']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Recipient</span>
            <span class="info-value"><?= htmlspecialchars($result['request']['recipient_name']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Idempotency Key</span>
            <span class="info-value" style="font-size:11px"><?= htmlspecialchars($result['idempotency']) ?></span>
        </div>

        <details style="margin-top: 16px;">
            <summary style="cursor: pointer; color: #64748b; font-size: 13px; margin-bottom: 10px;">Full API Response</summary>
            <pre><?= htmlspecialchars(json_encode($result['response'], JSON_PRETTY_PRINT)) ?></pre>
        </details>
    </div>
    <?php endif; ?>

    <p style="text-align: center; font-size: 12px; color: #475569; margin-top: 20px;">
        API: <?= htmlspecialchars($apiUrl) ?> | Version: <?= htmlspecialchars($apiVersion) ?>
    </p>
</div>
</body>
</html>
