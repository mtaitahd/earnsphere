-- ============================================================
-- EarnSphere - Support Tickets Migration
-- Adds:
--   1. support_tickets table (help requests from landing page + dashboard)
-- Compatible with MariaDB 10.4+ (no ADD COLUMN IF NOT EXISTS)
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+03:00";

-- ============================================================
-- TABLE: support_tickets
-- Users/visitors submit help requests; admin replies inline.
-- user_read controls the user-side notification bell:
--   0 = admin replied but user has not cleared the notification
--   1 = notification cleared by the user
-- ============================================================
CREATE TABLE IF NOT EXISTS `support_tickets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED DEFAULT NULL COMMENT 'Linked account (nullable for landing-page visitors)',
    `name` VARCHAR(150) NOT NULL,
    `phone` VARCHAR(20) DEFAULT NULL,
    `email` VARCHAR(150) DEFAULT NULL,
    `subject` VARCHAR(200) NOT NULL DEFAULT 'Help Request',
    `message` TEXT NOT NULL,
    `status` ENUM('open','answered','closed') NOT NULL DEFAULT 'open',
    `admin_reply` TEXT DEFAULT NULL,
    `replied_by` INT UNSIGNED DEFAULT NULL,
    `replied_at` DATETIME DEFAULT NULL,
    `user_read` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = unread admin reply (notification shown)',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_st_user` (`user_id`),
    KEY `idx_st_status` (`status`),
    KEY `idx_st_phone` (`phone`),
    CONSTRAINT `fk_st_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_st_replier` FOREIGN KEY (`replied_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
