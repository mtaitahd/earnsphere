<?php
/**
 * EarnSphere - Installation Script
 * Run this once to set up the database + Snippe migration
 * Compatible with MariaDB 10.4+ (no PREPARE/EXECUTE)
 */

define('APP_ROOT', __DIR__);
require_once APP_ROOT . '/config/config.php';

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>EarnSphere Installer</title>";
echo "<link href='https://fonts.googleapis.com/css2?family=Nunito:wght@400;700;800&display=swap' rel='stylesheet'>";
echo "<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css' rel='stylesheet'>";
echo "<link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css'>";
echo "<style>body{font-family:'Nunito',sans-serif;background:#f5f3f7;display:flex;align-items:center;justify-content:center;min-height:100vh;}</style>";
echo "</head><body><div class='container'><div class='row justify-content-center'><div class='col-md-8'>";

echo "<div class='card shadow'><div class='card-body p-4'>";
echo "<h3 class='text-center mb-1'><i class='fas fa-gem' style='color:#72578B;'></i> EarnSphere Installer</h3>";
echo "<p class='text-center text-muted'>Mfumo wa kisasa wa referrals</p><hr>";

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    ]);
    
    echo "<div class='alert alert-success'><i class='fas fa-check-circle me-1'></i> MySQL " . $pdo->query('SELECT VERSION()')->fetchColumn() . " connected.</div>";
    
    // ---- Step 1: Base schema ----
    echo "<h6 class='mt-3'><i class='fas fa-database me-1'></i> Hatua 1: Schema</h6>";
    
    $schema = file_get_contents(APP_ROOT . '/database/schema.sql');
    $statements = array_filter(array_map('trim', explode(';', $schema)));
    $ok = 0;
    $fail = 0;
    
    foreach ($statements as $stmt) {
        if (empty($stmt) || strpos($stmt, '--') === 0) continue;
        try {
            $pdo->exec($stmt);
            $ok++;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') === false) {
                $fail++;
                echo "<div class='text-warning small'>⚠ " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }
    echo "<div class='alert alert-info'><i class='fas fa-info-circle me-1'></i> Schema: {$ok} ok, {$fail} errors</div>";
    
    // ---- Step 2: Snippe migration (PHP-based, no PREPARE/EXECUTE) ----
    echo "<h6 class='mt-3'><i class='fas fa-plug me-1'></i> Hatua 2: Snippe Migration</h6>";
    
    $pdo->exec("USE " . DB_NAME);
    
    $migOk = 0;
    $migFail = 0;
    
    // Helper: check if column exists
    function colExists(PDO $pdo, string $table, string $col): bool {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $stmt->execute([$table, $col]);
        return (int) $stmt->fetchColumn() > 0;
    }
    
    // Helper: check if index exists
    function idxExists(PDO $pdo, string $table, string $idx): bool {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
        $stmt->execute([$table, $idx]);
        return (int) $stmt->fetchColumn() > 0;
    }
    
    // payments columns
    $migrationCols = [
        ['payments', 'snippe_reference', "ALTER TABLE `payments` ADD COLUMN `snippe_reference` VARCHAR(100) DEFAULT NULL AFTER `order_id`"],
        ['payments', 'payment_type', "ALTER TABLE `payments` ADD COLUMN `payment_type` ENUM('registration','other') NOT NULL DEFAULT 'registration' AFTER `currency`"],
        ['withdrawals', 'snippe_reference', "ALTER TABLE `withdrawals` ADD COLUMN `snippe_reference` VARCHAR(100) DEFAULT NULL AFTER `status`"],
        ['withdrawals', 'fees', "ALTER TABLE `withdrawals` ADD COLUMN `fees` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `amount`"],
        ['withdrawals', 'provider', "ALTER TABLE `withdrawals` ADD COLUMN `provider` VARCHAR(50) DEFAULT NULL AFTER `payment_method`"],
        ['withdrawals', 'external_reference', "ALTER TABLE `withdrawals` ADD COLUMN `external_reference` VARCHAR(100) DEFAULT NULL AFTER `snippe_reference`"],
    ];
    
    foreach ($migrationCols as [$table, $col, $sql]) {
        if (colExists($pdo, $table, $col)) {
            echo "<div class='text-muted small'>✓ {$table}.{$col} (exists)</div>";
            continue;
        }
        try {
            $pdo->exec($sql);
            echo "<div class='text-success small'><i class='fas fa-plus-circle me-1'></i> {$table}.{$col} added</div>";
            $migOk++;
        } catch (PDOException $e) {
            echo "<div class='text-danger small'>✗ {$table}.{$col}: " . $e->getMessage() . "</div>";
            $migFail++;
        }
    }
    
    // Indexes
    $migrationIdx = [
        ['payments', 'idx_snippe_reference', "ALTER TABLE `payments` ADD INDEX `idx_snippe_reference` (`snippe_reference`)"],
        ['withdrawals', 'idx_wd_snippe_reference', "ALTER TABLE `withdrawals` ADD INDEX `idx_wd_snippe_reference` (`snippe_reference`)"],
    ];
    
    foreach ($migrationIdx as [$table, $idx, $sql]) {
        if (idxExists($pdo, $table, $idx)) {
            echo "<div class='text-muted small'>✓ {$idx} (exists)</div>";
            continue;
        }
        try {
            $pdo->exec($sql);
            echo "<div class='text-success small'><i class='fas fa-plus-circle me-1'></i> {$idx} added</div>";
            $migOk++;
        } catch (PDOException $e) {
            echo "<div class='text-danger small'>✗ {$idx}: " . $e->getMessage() . "</div>";
            $migFail++;
        }
    }
    
    // Payouts table
    $payoutsExists = $pdo->query("SHOW TABLES LIKE 'payouts'")->fetch();
    if ($payoutsExists) {
        echo "<div class='text-muted small'>✓ payouts table (exists)</div>";
    } else {
        try {
            $pdo->exec("CREATE TABLE `payouts` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `withdrawal_id` INT UNSIGNED NOT NULL,
                `reference` VARCHAR(100) DEFAULT NULL,
                `external_reference` VARCHAR(100) DEFAULT NULL,
                `amount` DECIMAL(12,2) NOT NULL,
                `fees` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                `total` DECIMAL(12,2) NOT NULL,
                `channel` VARCHAR(20) NOT NULL DEFAULT 'mobile',
                `provider` VARCHAR(50) DEFAULT NULL,
                `recipient_phone` VARCHAR(20) NOT NULL,
                `recipient_name` VARCHAR(150) NOT NULL,
                `narration` VARCHAR(255) DEFAULT NULL,
                `status` ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
                `error_code` VARCHAR(50) DEFAULT NULL,
                `error_message` TEXT DEFAULT NULL,
                `webhook_received` TINYINT(1) NOT NULL DEFAULT 0,
                `metadata` JSON DEFAULT NULL,
                `idempotency_key` VARCHAR(100) DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_payout_reference` (`reference`),
                KEY `idx_payout_user` (`user_id`),
                KEY `idx_payout_withdrawal` (`withdrawal_id`),
                KEY `idx_payout_status` (`status`),
                KEY `idx_payout_idempotency` (`idempotency_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            echo "<div class='text-success small'><i class='fas fa-plus-circle me-1'></i> payouts table created</div>";
            $migOk++;
        } catch (PDOException $e) {
            echo "<div class='text-danger small'>✗ payouts: " . $e->getMessage() . "</div>";
            $migFail++;
        }
    }
    
    // Error logs table
    $errorLogsExists = $pdo->query("SHOW TABLES LIKE 'error_logs'")->fetch();
    if ($errorLogsExists) {
        echo "<div class='text-muted small'>✓ error_logs table (exists)</div>";
    } else {
        try {
            $pdo->exec("CREATE TABLE `error_logs` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED DEFAULT NULL,
                `category` VARCHAR(50) NOT NULL DEFAULT 'system',
                `severity` VARCHAR(20) NOT NULL DEFAULT 'error',
                `source` VARCHAR(150) DEFAULT NULL,
                `message` TEXT NOT NULL,
                `context` JSON DEFAULT NULL,
                `request_method` VARCHAR(10) DEFAULT NULL,
                `request_uri` VARCHAR(255) DEFAULT NULL,
                `ip_address` VARCHAR(45) DEFAULT NULL,
                `user_agent` TEXT DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_error_user` (`user_id`),
                KEY `idx_error_category` (`category`),
                KEY `idx_error_severity` (`severity`),
                KEY `idx_error_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            echo "<div class='text-success small'><i class='fas fa-plus-circle me-1'></i> error_logs table created</div>";
            $migOk++;
        } catch (PDOException $e) {
            echo "<div class='text-danger small'>✗ error_logs: " . $e->getMessage() . "</div>";
            $migFail++;
        }
    }
    
    // Payout settings
    $payoutSettings = [
        ['payout_channel', 'mobile', 'Default payout channel'],
        ['payout_webhook_url', SITE_URL . '/webhooks/snippe.php', 'Payout webhook URL'],
    ];
    foreach ($payoutSettings as [$key, $val, $desc]) {
        $exists = $pdo->query("SELECT setting_key FROM settings WHERE setting_key = '{$key}'")->fetch();
        if (!$exists) {
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, setting_type, description) VALUES (?, ?, 'text', ?)");
            $stmt->execute([$key, $val, $desc]);
            echo "<div class='text-success small'><i class='fas fa-plus-circle me-1'></i> {$key} setting added</div>";
        }
    }
    
    echo "<div class='alert alert-info'><i class='fas fa-info-circle me-1'></i> Migration: {$migOk} added, {$migFail} errors</div>";
    
    // ---- Step 3: Verify ----
    echo "<h6 class='mt-3'><i class='fas fa-check-double me-1'></i> Hatua 3: Ukaguzi</h6>";
    
    $checks = [
        ['payments', 'snippe_reference'],
        ['payments', 'payment_type'],
        ['payments', 'webhook_received'],
        ['withdrawals', 'snippe_reference'],
        ['withdrawals', 'fees'],
        ['withdrawals', 'provider'],
        ['withdrawals', 'external_reference'],
    ];
    
    $allGood = true;
    foreach ($checks as [$table, $col]) {
        if (colExists($pdo, $table, $col)) {
            echo "<div class='text-success small'><i class='fas fa-check me-1'></i> {$table}.{$col}</div>";
        } else {
            echo "<div class='text-danger small'><i class='fas fa-times me-1'></i> {$table}.{$col} MISSING</div>";
            $allGood = false;
        }
    }
    
    if ($pdo->query("SHOW TABLES LIKE 'payouts'")->fetch()) {
        echo "<div class='text-success small'><i class='fas fa-check me-1'></i> payouts table</div>";
    } else {
        echo "<div class='text-danger small'><i class='fas fa-times me-1'></i> payouts table MISSING</div>";
        $allGood = false;
    }
    
    if ($pdo->query("SHOW TABLES LIKE 'error_logs'")->fetch()) {
        echo "<div class='text-success small'><i class='fas fa-check me-1'></i> error_logs table</div>";
    } else {
        echo "<div class='text-danger small'><i class='fas fa-times me-1'></i> error_logs table MISSING</div>";
        $allGood = false;
    }
    
    if ($allGood) {
        echo "<div class='alert alert-success mt-2'><i class='fas fa-check-circle me-1'></i> Viungo vyote viko sawa!</div>";
    } else {
        echo "<div class='alert alert-danger mt-2'><i class='fas fa-exclamation-circle me-1'></i> Baadhi ya viungo vimekosekana. Angalia hapo juu.</div>";
    }
    
    // ---- Step 4: Daily Missions Migration ----
    echo "<h6 class='mt-3'><i class='fas fa-trophy me-1'></i> Hatua 4: Daily Missions</h6>";
    $missionFile = APP_ROOT . '/database/migration_daily_missions.sql';
    if (file_exists($missionFile)) {
        $missionSql = file_get_contents($missionFile);
        $missionStmts = array_filter(array_map('trim', explode(';', $missionSql)));
        $misOk = 0; $misFail = 0;
        foreach ($missionStmts as $stmt) {
            $stmtLines = array_filter(explode("\n", $stmt), fn($line) => trim($line) === '' || strpos(trim($line), '--') !== 0);
            $cleanStmt = trim(implode("\n", $stmtLines));
            if (empty($cleanStmt)) continue;
            try {
                $pdo->exec($cleanStmt);
                $misOk++;
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'already exists') === false) {
                    $misFail++;
                    echo "<div class='text-warning small'>⚠ " . htmlspecialchars($e->getMessage()) . "</div>";
                }
            }
        }
        echo "<div class='alert alert-info'><i class='fas fa-info-circle me-1'></i> Missions: {$misOk} ok, {$misFail} errors</div>";
    }

    // ---- Step 5: AI Assistant Migration ----
    echo "<h6 class='mt-3'><i class='fas fa-wand-magic-sparkles me-1'></i> Hatua 5: AI Assistant</h6>";
    $aiFile = APP_ROOT . '/database/migration_ai_assistant.sql';
    if (file_exists($aiFile)) {
        $aiSql = file_get_contents($aiFile);
        $aiStmts = array_filter(array_map('trim', explode(';', $aiSql)));
        $aiOk = 0; $aiFail = 0;
        foreach ($aiStmts as $stmt) {
            $stmtLines = array_filter(explode("\n", $stmt), fn($line) => trim($line) === '' || strpos(trim($line), '--') !== 0);
            $cleanStmt = trim(implode("\n", $stmtLines));
            if (empty($cleanStmt)) continue;
            try {
                $pdo->exec($cleanStmt);
                $aiOk++;
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'already exists') === false && strpos($e->getMessage(), 'Duplicate') === false) {
                    $aiFail++;
                    echo "<div class='text-warning small'>⚠ " . htmlspecialchars($e->getMessage()) . "</div>";
                }
            }
        }
        echo "<div class='alert alert-info'><i class='fas fa-info-circle me-1'></i> AI Assistant: {$aiOk} ok, {$aiFail} errors</div>";
    }

    // ---- Step 6: Admin password ----
    echo "<h6 class='mt-3'><i class='fas fa-user-shield me-1'></i> Hatua 6: Admin</h6>";
    $hash = password_hash('Admin@123', PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE role = 'admin' AND email = 'admin@earnsphere.com'");
    $stmt->execute([$hash]);
    echo "<div class='alert alert-success'><i class='fas fa-user-shield me-1'></i> Admin password: Admin@123</div>";

    // ---- Step 7: Announcements Migration ----
    echo "<h6 class='mt-3'><i class='fas fa-bullhorn me-1'></i> Hatua 7: Announcements</h6>";
    $annOk = 0; $annFail = 0;
    $annTables = [
        "CREATE TABLE IF NOT EXISTS `announcements` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `title` VARCHAR(200) NOT NULL,
            `content` TEXT NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_by` INT UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_active` (`is_active`),
            KEY `idx_created_by` (`created_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `user_announcement_views` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `announcement_id` INT UNSIGNED NOT NULL,
            `viewed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_user_announcement` (`user_id`, `announcement_id`),
            KEY `idx_user_id` (`user_id`),
            CONSTRAINT `fk_uav_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_uav_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($annTables as $annSql) {
        try {
            $pdo->exec($annSql);
            $annOk++;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') === false && strpos($e->getMessage(), 'Duplicate') === false) {
                $annFail++;
                echo "<div class='text-warning small'>⚠ " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }
    echo "<div class='alert alert-info'><i class='fas fa-info-circle me-1'></i> Announcements: {$annOk} ok, {$annFail} errors</div>";
    
    echo "<hr>";
    echo "<div class='text-center'>";
    echo "<h5 class='text-success'><i class='fas fa-check-circle me-1'></i> Usakinishaji Umekamilika!</h5>";
    echo "<div class='d-grid gap-2 d-md-flex justify-content-center mt-3'>";
    echo "<a href='" . SITE_URL . "/register.php' class='btn btn-primary' style='background:#72578B;border:none;'><i class='fas fa-user-plus me-1'></i> Jiandikishe</a>";
    echo "<a href='" . SITE_URL . "/admin/login.php' class='btn btn-outline-primary'><i class='fas fa-sign-in-alt me-1'></i> Admin Login</a>";
    echo "</div>";
    echo "<div class='mt-3 p-3 bg-light rounded'>";
    echo "<small class='text-muted'>";
    echo "<strong>Admin:</strong> admin@earnsphere.com / Admin@123<br>";
    echo "<strong>Webhook:</strong> " . SITE_URL . "/webhooks/snippe.php<br>";
    echo "</small></div></div>";
    
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'><i class='fas fa-exclamation-circle me-1'></i> " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "</div></div></div></div></body></html>";
