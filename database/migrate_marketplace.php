<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/migrate_social_loans.php';
foreach (explode(';', file_get_contents(__DIR__ . '/marketplace.sql')) as $statement) if (trim($statement)) Database::query($statement);
$column=Database::fetchOne("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wallet_transactions' AND COLUMN_NAME='type'");
if ($column && str_starts_with($column['COLUMN_TYPE'],'enum(')) {
    $type=$column['COLUMN_TYPE'];
    foreach (['marketplace_fee','marketplace_refund'] as $value) if (!str_contains($type,"'".$value."'")) $type=substr($type,0,-1).",'".$value."')";
    if ($type!==$column['COLUMN_TYPE']) Database::query("ALTER TABLE wallet_transactions MODIFY type $type NOT NULL");
}
echo "Marketplace ready. No fees charged and no balances changed.\n";
