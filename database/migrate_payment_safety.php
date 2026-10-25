<?php
// Additive and repeatable. Run before serving the updated withdrawal form.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
$column = Database::fetchOne("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='withdrawals' AND COLUMN_NAME='request_key'");
if (!$column) Database::query('ALTER TABLE withdrawals ADD COLUMN request_key VARCHAR(64) DEFAULT NULL');
$index = Database::fetchOne("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='withdrawals' AND INDEX_NAME='uk_withdrawal_request'");
if (!$index) Database::query('ALTER TABLE withdrawals ADD UNIQUE INDEX uk_withdrawal_request (request_key)');
$status = Database::fetchOne("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wallet_transactions' AND COLUMN_NAME='status'");
if ($status && str_starts_with($status['COLUMN_TYPE'], 'enum(')) {
    $type = $status['COLUMN_TYPE'];
    foreach (['failed','rejected'] as $value) if (!str_contains($type, "'" . $value . "'")) $type = substr($type, 0, -1) . ",'" . $value . "')";
    if ($type !== $status['COLUMN_TYPE']) Database::query("ALTER TABLE wallet_transactions MODIFY status $type NOT NULL DEFAULT 'completed'");
}
echo "Payment safety migration complete. No balances or provider transactions were changed.\n";
