<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
Database::query("CREATE TABLE IF NOT EXISTS user_notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_key VARCHAR(100) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    kind VARCHAR(30) NOT NULL,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    payload JSON DEFAULT NULL,
    read_at DATETIME DEFAULT NULL,
    cleared_at DATETIME DEFAULT NULL,
    sms_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    sms_attempted_at DATETIME DEFAULT NULL,
    sms_sent_at DATETIME DEFAULT NULL,
    sms_error VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_notification_event (event_key),
    KEY idx_notification_user (user_id, id),
    KEY idx_notification_sms (sms_status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$clearedColumn = Database::fetchOne("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_notifications' AND COLUMN_NAME = 'cleared_at'");
if (!$clearedColumn) Database::query('ALTER TABLE user_notifications ADD COLUMN cleared_at DATETIME DEFAULT NULL');

echo "Notification migration complete. No SMS sent and no balances changed.\n";
