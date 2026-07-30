-- EarnSphere - AI Share Assistant Migration
-- Run this migration to add the AI Share Assistant feature

-- AI Content History: Stores user-generated AI content
CREATE TABLE IF NOT EXISTS `ai_content_history` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `content_type` VARCHAR(50) NOT NULL,
    `tone` VARCHAR(50) NOT NULL DEFAULT 'professional',
    `language` VARCHAR(50) NOT NULL DEFAULT 'english',
    `prompt_input` TEXT,
    `generated_content` TEXT NOT NULL,
    `word_count` INT UNSIGNED DEFAULT 0,
    `character_count` INT UNSIGNED DEFAULT 0,
    `ip_address` VARCHAR(45),
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_content` (`user_id`, `content_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Share Tracking: Tracks shares, clicks, and conversions
CREATE TABLE IF NOT EXISTS `share_tracking` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `content_type` VARCHAR(50) NOT NULL,
    `share_platform` VARCHAR(50) NOT NULL,
    `share_url` TEXT,
    `click_count` INT UNSIGNED DEFAULT 0,
    `registration_count` INT UNSIGNED DEFAULT 0,
    `paid_referral_count` INT UNSIGNED DEFAULT 0,
    `commission_earned` DECIMAL(12,2) DEFAULT 0.00,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_user_platform` (`user_id`, `share_platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add AI generation rate limiting config to settings
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `setting_type`, `description`)
VALUES ('ai_max_generations_per_hour', '10', 'number', 'Maximum AI content generations per user per hour');

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `setting_type`, `description`)
VALUES ('ai_api_provider', 'openai', 'text', 'AI API provider (openai, gemini, or custom)');

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `setting_type`, `description`)
VALUES ('ai_api_key_encrypted', '', 'text', 'AI API key (stored encrypted)');

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `setting_type`, `description`)
VALUES ('ai_api_model', 'gpt-4o-mini', 'text', 'AI model to use for content generation');

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `setting_type`, `description`)
VALUES ('ai_api_endpoint', 'https://api.openai.com/v1/chat/completions', 'text', 'AI API endpoint URL');
