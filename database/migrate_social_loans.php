<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
foreach (explode(';', file_get_contents(__DIR__ . '/social_loans.sql')) as $statement) if (trim($statement)) Database::query($statement);
$column = Database::fetchOne("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wallet_transactions' AND COLUMN_NAME='type'");
if ($column && str_starts_with($column['COLUMN_TYPE'], 'enum(')) {
    $type = $column['COLUMN_TYPE'];
    foreach (['social_reward','promotion_payment','promotion_refund'] as $value) if (!str_contains($type, "'" . $value . "'")) $type = substr($type, 0, -1) . ",'" . $value . "')";
    if ($type !== $column['COLUMN_TYPE']) Database::query("ALTER TABLE wallet_transactions MODIFY type $type NOT NULL");
}
require_once __DIR__ . '/migrate_notifications.php';
echo "Social campaigns and loan applications ready. No balances changed and no loans disbursed.\n";
