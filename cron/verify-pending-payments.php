<?php
// Run from cron every minute: php /path/to/earnsphere/cron/verify-pending-payments.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../classes/AblinerPayment.php';
$service = new AblinerPayment();
$failed = 0;
$verbose = in_array('--verbose', $argv ?? [], true);
foreach (['payments', 'payouts'] as $table) {
    $rows = Database::fetchAll("SELECT id FROM $table WHERE gateway = 'abliner' AND status = 'pending' ORDER BY updated_at ASC, id ASC LIMIT 100");
    foreach ($rows as $row) {
        try {
            $result = $service->reconcile($table, (int) $row['id']);
            if (!$result['success']) $failed++;
            if ($verbose) {
                $detail = ['kind' => $table, 'local_id' => (int) $row['id'],
                    'verified' => (bool) $result['success'], 'status' => $result['status'] ?? 'unverified',
                    'matched' => $result['found'] ?? null, 'code' => $result['code'] ?? null,
                    'message' => $result['error'] ?? $result['message'] ?? null];
                echo json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
            }
            Database::update($table, ['updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$row['id']]);
        } catch (Throwable $e) {
            $failed++;
            error_log("Abliner reconciliation failed: $table #{$row['id']}: " . $e->getMessage());
            if ($verbose) echo json_encode(['kind' => $table, 'local_id' => (int) $row['id'], 'verified' => false,
                'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
        }
    }
}
$service->flushNotifications();
echo "Abliner reconciliation finished; failures: $failed\n";
exit($failed ? 1 : 0);
