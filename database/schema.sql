-- ============================================================
-- EarnSphere Database Schema
-- Mfumo wa kisasa wa referrals
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+03:00";

CREATE DATABASE IF NOT EXISTS `atimscot_earnsphere` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `atimscot_earnsphere`;

-- ============================================================
-- TABLE: users
-- Stores all registered users (admin + regular)
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `full_name` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(20) NOT NULL,
    `email` VARCHAR(150) DEFAULT NULL,
    `password` VARCHAR(255) NOT NULL,
    `referral_code` VARCHAR(20) NOT NULL,
    `referred_by` INT UNSIGNED DEFAULT NULL,
    `referred_by_level` TINYINT UNSIGNED DEFAULT NULL COMMENT '1=direct,2=level2,3=level3',
    `avatar` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('pending','active','suspended') NOT NULL DEFAULT 'pending',
    `role` ENUM('user','admin') NOT NULL DEFAULT 'user',
    `is_online` TINYINT(1) NOT NULL DEFAULT 0,
    `last_login` DATETIME DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_phone` (`phone`),
    UNIQUE KEY `uk_email` (`email`),
    UNIQUE KEY `uk_referral_code` (`referral_code`),
    KEY `idx_referred_by` (`referred_by`),
    KEY `idx_status` (`status`),
    KEY `idx_role` (`role`),
    CONSTRAINT `fk_users_referred_by` FOREIGN KEY (`referred_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: user_sessions
-- Track active sessions for security
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_sessions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `session_token` VARCHAR(128) NOT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` TEXT DEFAULT NULL,
    `expires_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_token` (`session_token`),
    KEY `idx_expires` (`expires_at`),
    CONSTRAINT `fk_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: payments
-- Stores registration payment records via Snippe
-- ============================================================
CREATE TABLE IF NOT EXISTS `payments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `transaction_ref` VARCHAR(100) DEFAULT NULL COMMENT 'Snippe payment reference',
    `order_id` VARCHAR(100) NOT NULL COMMENT 'Our internal order ID',
    `amount` DECIMAL(12,2) NOT NULL DEFAULT 10000.00,
    `currency` VARCHAR(5) NOT NULL DEFAULT 'TZS',
    `phone` VARCHAR(20) DEFAULT NULL,
    `payment_method` VARCHAR(50) DEFAULT 'mobile_money',
    `status` ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
    `webhook_received` TINYINT(1) NOT NULL DEFAULT 0,
    `metadata` JSON DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `completed_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_order_id` (`order_id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_transaction_ref` (`transaction_ref`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_payments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: wallets
-- Each user has one wallet for commission balance
-- ============================================================
CREATE TABLE IF NOT EXISTS `wallets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `withdrawable_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Only commission earnings - registration fee not included',
    `total_earned` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total_withdrawn` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `pending_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_wallet_user` (`user_id`),
    CONSTRAINT `fk_wallets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: wallet_transactions
-- All wallet movements: credits, debits, adjustments
-- ============================================================
CREATE TABLE IF NOT EXISTS `wallet_transactions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `wallet_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `type` ENUM('commission','referral_bonus','withdrawal','admin_adjustment','registration_bonus') NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `balance_before` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `balance_after` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `description` VARCHAR(255) DEFAULT NULL,
    `reference_id` INT UNSIGNED DEFAULT NULL COMMENT 'Related commission/withdrawal ID',
    `reference_type` VARCHAR(50) DEFAULT NULL COMMENT 'commission, withdrawal, etc',
    `status` ENUM('completed','pending','cancelled') NOT NULL DEFAULT 'completed',
    `created_by` INT UNSIGNED DEFAULT NULL COMMENT 'Admin who made adjustment',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_wallet_id` (`wallet_id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_type` (`type`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_wt_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_wt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: referrals
-- Tracks the referral tree/chain between users
-- ============================================================
CREATE TABLE IF NOT EXISTS `referrals` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `referrer_id` INT UNSIGNED NOT NULL COMMENT 'Who referred',
    `referred_id` INT UNSIGNED NOT NULL COMMENT 'Who was referred',
    `level` TINYINT UNSIGNED NOT NULL COMMENT '1=direct, 2=grand, 3=great-grand',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_referral_pair` (`referrer_id`, `referred_id`),
    KEY `idx_referred_id` (`referred_id`),
    KEY `idx_level` (`level`),
    CONSTRAINT `fk_ref_referrer` FOREIGN KEY (`referrer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ref_referred` FOREIGN KEY (`referred_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: commissions
-- Records every commission earned from referral chain
-- ============================================================
CREATE TABLE IF NOT EXISTS `commissions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `earner_id` INT UNSIGNED NOT NULL COMMENT 'Who earns the commission',
    `source_user_id` INT UNSIGNED NOT NULL COMMENT 'Who triggered the commission (new registrant)',
    `level` TINYINT UNSIGNED NOT NULL COMMENT '1, 2, or 3',
    `amount` DECIMAL(12,2) NOT NULL,
    `status` ENUM('pending','approved','paid','cancelled') NOT NULL DEFAULT 'pending',
    `wallet_transaction_id` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_earner_id` (`earner_id`),
    KEY `idx_source_user_id` (`source_user_id`),
    KEY `idx_level` (`level`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_comm_earner` FOREIGN KEY (`earner_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_comm_source` FOREIGN KEY (`source_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: withdrawals
-- User withdrawal requests
-- ============================================================
CREATE TABLE IF NOT EXISTS `withdrawals` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `phone` VARCHAR(20) NOT NULL COMMENT 'Mobile money number for payout',
    `payment_method` VARCHAR(50) NOT NULL DEFAULT 'mobile_money',
    `status` ENUM('pending','approved','processing','completed','rejected') NOT NULL DEFAULT 'pending',
    `admin_note` TEXT DEFAULT NULL,
    `processed_by` INT UNSIGNED DEFAULT NULL,
    `processed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_wd_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: settings
-- System configuration (key-value)
-- ============================================================
CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT DEFAULT NULL,
    `setting_type` ENUM('text','number','boolean','json') NOT NULL DEFAULT 'text',
    `description` VARCHAR(255) DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: activity_logs
-- Audit trail for important actions
-- ============================================================
CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` TEXT DEFAULT NULL,
    `metadata` JSON DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_action` (`action`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: user_otps
-- OTP codes for password reset and verification
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_otps` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `type` VARCHAR(20) NOT NULL DEFAULT 'reset',
    `otp` VARCHAR(10) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_otp` (`otp`),
    KEY `idx_expires` (`expires_at`),
    CONSTRAINT `fk_otps_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- INSERT DEFAULT SETTINGS
-- ============================================================
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_type`, `description`) VALUES
('site_name', 'EarnSphere', 'text', 'System name'),
('site_tagline', 'Eneo la fursa na kipato', 'text', 'Site tagline'),
('registration_fee', '10000', 'number', 'Registration fee in TZS'),
('company_earning', '6000', 'number', 'Company earning per registration'),
('commission_total', '4000', 'number', 'Total commission per registration'),
('commission_l1', '2000', 'number', 'Level 1 commission'),
('commission_l2', '1200', 'number', 'Level 2 commission'),
('commission_l3', '800', 'number', 'Level 3 commission'),
('currency', 'TZS', 'text', 'System currency'),
('min_withdrawal', '5000', 'number', 'Minimum withdrawal amount'),
('max_withdrawal', '500000', 'number', 'Maximum withdrawal amount'),
('maintenance_mode', '0', 'boolean', 'System maintenance mode'),
('snippe_api_key', '', 'text', 'Snippe Payment API Key'),
('snippe_webhook_secret', '', 'text', 'Snippe Webhook Secret'),
('snippe_api_url', 'https://api.snippe.sh', 'text', 'Snippe API URL'),
('snippe_api_version', '2026-01-25', 'text', 'Snippe API Version'),
('admin_email', 'admin@earnsphere.com', 'text', 'Admin contact email'),
('support_phone', '+255700000000', 'text', 'Support phone number'),
('terms_url', '#', 'text', 'Terms and conditions URL'),
('privacy_url', '#', 'text', 'Privacy policy URL');

-- ============================================================
-- INSERT DEFAULT ADMIN USER
-- Password: Admin@123 (bcrypt hashed)
-- ============================================================
INSERT INTO `users` (`full_name`, `phone`, `email`, `password`, `referral_code`, `status`, `role`)
VALUES (
    'System Administrator',
    '0700000000',
    'admin@earnsphere.com',
    '$2y$12$Vjw7JzQeoUKJQVSm0SIqHeG2Uw0MAl.ifENia/It.5qjS9RqGy8mK',
    'ADMIN001',
    'active',
    'admin'
);

-- Create admin wallet
INSERT INTO `wallets` (`user_id`, `balance`, `total_earned`, `total_withdrawn`)
SELECT `id`, 0, 0, 0 FROM `users` WHERE `role` = 'admin' LIMIT 1;

COMMIT;
