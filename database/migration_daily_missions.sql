-- EarnSphere - Daily Missions System Migration
-- Run this migration to add the Daily Missions feature

-- Missions table: Defines available missions
CREATE TABLE IF NOT EXISTS `missions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(150) NOT NULL,
    `description` TEXT,
    `type` VARCHAR(50) NOT NULL DEFAULT 'daily_referral',
    `requirement_type` VARCHAR(50) NOT NULL DEFAULT 'paid_referrals',
    `requirement_count` INT UNSIGNED NOT NULL DEFAULT 2,
    `reward_amount` DECIMAL(12,2) NOT NULL DEFAULT 500.00,
    `currency` VARCHAR(5) DEFAULT 'TZS',
    `is_active` TINYINT(1) DEFAULT 1,
    `sort_order` INT UNSIGNED DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User Daily Missions: Tracks each user's daily mission progress
CREATE TABLE IF NOT EXISTS `user_daily_missions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `mission_id` INT UNSIGNED NOT NULL,
    `mission_date` DATE NOT NULL,
    `requirement_type` VARCHAR(50) NOT NULL DEFAULT 'paid_referrals',
    `requirement_count` INT UNSIGNED NOT NULL DEFAULT 2,
    `completed_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `reward_amount` DECIMAL(12,2) NOT NULL DEFAULT 500.00,
    `status` ENUM('in_progress','completed','expired') DEFAULT 'in_progress',
    `completed_at` DATETIME NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`mission_id`) REFERENCES `missions`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_user_mission_date` (`user_id`, `mission_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mission Rewards: Records awarded bonuses
CREATE TABLE IF NOT EXISTS `mission_rewards` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `user_daily_mission_id` INT UNSIGNED NOT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `currency` VARCHAR(5) DEFAULT 'TZS',
    `wallet_transaction_id` INT UNSIGNED NULL,
    `status` ENUM('completed','cancelled') DEFAULT 'completed',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_daily_mission_id`) REFERENCES `user_daily_missions`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`wallet_transaction_id`) REFERENCES `wallet_transactions`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default daily mission
INSERT INTO `missions` (`title`, `description`, `type`, `requirement_type`, `requirement_count`, `reward_amount`, `sort_order`, `is_active`)
VALUES ('Daily Referral Mission', 'Invite 2 new paid members today and earn a bonus!', 'daily_referral', 'paid_referrals', 2, 500.00, 1, 1)
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`);

-- Add daily_mission_bonus to wallet_transactions type enum
ALTER TABLE `wallet_transactions` 
MODIFY COLUMN `type` ENUM('commission','referral_bonus','withdrawal','admin_adjustment','registration_bonus','daily_mission_bonus') NOT NULL;
