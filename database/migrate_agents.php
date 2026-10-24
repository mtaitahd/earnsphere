<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
$role = Database::fetchOne("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'");
if ($role && str_starts_with($role['COLUMN_TYPE'], 'enum(') && !str_contains($role['COLUMN_TYPE'], "'agent'")) {
    $type = substr($role['COLUMN_TYPE'], 0, -1) . ",'agent')";
    Database::query("ALTER TABLE users MODIFY role $type NOT NULL DEFAULT 'user'");
}
$version = Database::fetchOne("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'staff_version'");
if (!$version) Database::query('ALTER TABLE users ADD COLUMN staff_version INT UNSIGNED NOT NULL DEFAULT 0');
Database::query("CREATE TABLE IF NOT EXISTS agent_permissions (
    user_id INT UNSIGNED NOT NULL,
    permission VARCHAR(60) NOT NULL,
    PRIMARY KEY (user_id, permission),
    CONSTRAINT fk_agent_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "Agent permissions migration complete. Existing administrators keep full access.\n";
