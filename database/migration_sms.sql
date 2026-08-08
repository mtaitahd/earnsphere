-- ============================================================
-- EarnSphere - SMS System Migration (Meseji API)
-- Adds:
--   1. sms_templates table (reusable message templates)
--   2. sms_logs table (send history / audit)
--   3. Default templates + SMS settings
-- Compatible with MariaDB 10.4+ (no ADD COLUMN IF NOT EXISTS)
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+03:00";

-- ============================================================
-- TABLE: sms_templates
-- Reusable SMS templates. {variable} placeholders are replaced
-- per recipient: {name}, {phone}, {email}, {code}, {fee},
-- {amount}, {message}, {site}
-- ============================================================
CREATE TABLE IF NOT EXISTS `sms_templates` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `template_key` VARCHAR(50) NOT NULL,
    `message` TEXT NOT NULL,
    `variables` VARCHAR(255) DEFAULT NULL COMMENT 'Comma-separated placeholder hints',
    `is_system` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = built-in template (protected)',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_st_key` (`template_key`),
    KEY `idx_st_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: sms_logs
-- Audit trail for every SMS sent (system or admin initiated)
-- ============================================================
CREATE TABLE IF NOT EXISTS `sms_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `sent_by` INT UNSIGNED DEFAULT NULL COMMENT 'Admin who triggered the send',
    `user_id` INT UNSIGNED DEFAULT NULL COMMENT 'Single recipient user (nullable for broadcasts)',
    `phone` VARCHAR(20) DEFAULT NULL COMMENT 'Single recipient phone',
    `phones` TEXT DEFAULT NULL COMMENT 'JSON array of recipient phones (bulk sends)',
    `message` TEXT NOT NULL,
    `type` VARCHAR(30) NOT NULL DEFAULT 'custom' COMMENT 'welcome,payment,reminder,broadcast,custom',
    `template_key` VARCHAR(50) DEFAULT NULL,
    `batch_id` VARCHAR(100) DEFAULT NULL COMMENT 'Meseji batch id',
    `total_recipients` INT UNSIGNED NOT NULL DEFAULT 1,
    `estimated_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `status` VARCHAR(20) NOT NULL DEFAULT 'queued' COMMENT 'queued,sent,failed',
    `error` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sl_type` (`type`),
    KEY `idx_sl_status` (`status`),
    KEY `idx_sl_user` (`user_id`),
    KEY `idx_sl_batch` (`batch_id`),
    KEY `idx_sl_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DEFAULT TEMPLATES
-- ============================================================
INSERT INTO `sms_templates` (`name`, `template_key`, `message`, `variables`, `is_system`, `is_active`) VALUES
('Welcome Message', 'welcome', 'Karibu {name}! Umejisajili kwenye {site}. Kamilisha malipo ya TZS {fee} ili uanze kupata commissions kupitia code yako: {code}', 'name,phone,code,fee,site', 1, 1),
('Payment Success', 'payment_success', 'Hongera {name}! Malipo yako ya TZS {amount} yamekamilika. Akaunti yako sasa ni ACTIVE. Code yako ya referral: {code}', 'name,amount,code,site', 1, 1),
('Payment Reminder', 'payment_reminder', 'Kumbukumbu {name}: bado haujamaliza kulipa TZS {fee} ya usajili. Maliza leo ili uanze kupata commissions!', 'name,fee,site', 1, 1),
('Withdrawal Success', 'withdrawal_success', 'Hongera {name}! Utoaji wako wa TZS {amount} umekamilika kwenye namba {phone}.', 'name,amount,phone', 1, 1),
('Withdrawal Failed', 'withdrawal_failed', 'Samahani {name}, utoaji wako wa TZS {amount} umeshindikana. Tafadhali wasiliana na support.', 'name,amount,phone', 1, 1);

-- ============================================================
-- DEFAULT SMS SETTINGS
-- ============================================================
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `setting_type`, `description`) VALUES
('meseji_api_key', '', 'text', 'Meseji SMS API Key (starts with zs_)'),
('meseji_api_url', 'https://meseji.co.tz/api/v1', 'text', 'Meseji SMS API base URL'),
('meseji_sender_id', 'MESEJI', 'text', 'Approved SMS sender ID (1-11 characters)'),
('meseji_enabled', '0', 'boolean', 'Enable SMS sending (Meseji)'),
('sms_welcome_enabled', '1', 'boolean', 'Send welcome SMS on registration'),
('sms_payment_enabled', '1', 'boolean', 'Send SMS on payment success');

COMMIT;
