<?php
// Run once through the hosting terminal: php database/migrate_abliner.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
$columns = [
    'payments' => ['purpose' => "VARCHAR(30) NOT NULL DEFAULT 'registration'", 'gateway' => "VARCHAR(20) NOT NULL DEFAULT 'snippe'", 'gateway_id' => 'VARCHAR(100) DEFAULT NULL',
        'gateway_reference' => 'VARCHAR(100) DEFAULT NULL', 'idempotency_key' => 'VARCHAR(100) DEFAULT NULL'],
    'payouts' => ['gateway' => "VARCHAR(20) NOT NULL DEFAULT 'snippe'", 'gateway_id' => 'VARCHAR(100) DEFAULT NULL'],
    'withdrawals' => ['gateway' => "VARCHAR(20) NOT NULL DEFAULT 'snippe'", 'recipient_name' => 'VARCHAR(150) DEFAULT NULL',
        'recipient_network' => 'VARCHAR(50) DEFAULT NULL'],
];
foreach ($columns as $table => $fields) {
    foreach ($fields as $column => $definition) {
        $found = Database::fetchOne('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
        if (!$found) Database::query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}
$status = Database::fetchOne("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'withdrawals' AND COLUMN_NAME = 'status'");
if ($status && str_starts_with($status['COLUMN_TYPE'], 'enum(') && !str_contains($status['COLUMN_TYPE'], "'failed'")) {
    $type = substr($status['COLUMN_TYPE'], 0, -1) . ",'failed')";
    Database::query("ALTER TABLE withdrawals MODIFY status $type NOT NULL DEFAULT 'pending'");
}
foreach (['payments', 'payouts'] as $table) {
    $found = Database::fetchOne("SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'uk_gateway_id'", [$table]);
    if (!$found) Database::query("ALTER TABLE `$table` ADD UNIQUE INDEX uk_gateway_id (gateway, gateway_id)");
}
Database::query("CREATE TABLE IF NOT EXISTS abliner_webhook_events (
    event_id VARCHAR(150) PRIMARY KEY,
    processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
Database::query("CREATE TABLE IF NOT EXISTS abliner_notifications (
    payment_id INT UNSIGNED PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    claimed_at DATETIME DEFAULT NULL,
    sent_at DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
foreach (['abliner_api_key' => 'Abliner API key', 'abliner_webhook_secret' => 'Abliner webhook signing secret'] as $key => $label) {
    Database::query("INSERT IGNORE INTO settings (setting_key, setting_value, setting_type, description) VALUES (?, '', 'text', ?)", [$key, $label]);
}
require __DIR__ . '/migrate_withdrawal_charge.php';
require __DIR__ . '/migrate_payment_safety.php';
echo "Abliner migration complete. Existing transactions retain their original gateway.\n";
