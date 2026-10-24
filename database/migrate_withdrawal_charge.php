<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
$found = Database::fetchOne("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'withdrawals' AND COLUMN_NAME = 'withdrawal_charge'");
if (!$found) Database::query('ALTER TABLE withdrawals ADD COLUMN withdrawal_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00');
Database::query("INSERT IGNORE INTO settings (setting_key, setting_value, setting_type, description) VALUES ('withdrawal_charge', '0', 'number', 'Fixed withdrawal charge (TZS)')");
echo "Withdrawal charge migration complete. Existing withdrawals have a zero charge.\n";
