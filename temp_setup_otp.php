<?php
define('APP_ROOT', 'C:/xampp/htdocs/earnsphere');
require_once APP_ROOT . '/config/config.php';

$pdo = new PDO('mysql:host=localhost;dbname=' . DB_NAME, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Create user_otps table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `user_otps` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL,
        `type` ENUM('verify','login','reset') NOT NULL DEFAULT 'reset',
        `otp` VARCHAR(10) NOT NULL,
        `expires_at` DATETIME NOT NULL,
        `used` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user_id` (`user_id`),
        KEY `idx_type` (`type`),
        KEY `idx_expires` (`expires_at`),
        CONSTRAINT `fk_otp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "user_otps table: OK\n";
} catch (PDOException $e) {
    echo "user_otps: " . $e->getMessage() . "\n";
}

// Add SMTP settings
$smtpSettings = [
    ['smtp_host', 'brandy.hostns.io', 'SMTP host'],
    ['smtp_port', '465', 'SMTP port'],
    ['smtp_encryption', 'ssl', 'SMTP encryption'],
    ['smtp_user', 'info@mtaitatech.online', 'SMTP username'],
    ['smtp_pass', 'M20032003$', 'SMTP password'],
    ['from_email', 'info@mtaitatech.online', 'From email address'],
    ['from_name', 'EarnSphere', 'From name'],
];

foreach ($smtpSettings as [$key, $val, $desc]) {
    $exists = $pdo->query("SELECT setting_key FROM settings WHERE setting_key = '{$key}'")->fetch();
    if (!$exists) {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES (?, ?, 'text', ?)");
        $stmt->execute([$key, $val, $desc]);
        echo "Added: {$key}\n";
    } else {
        echo "Exists: {$key}\n";
    }
}
