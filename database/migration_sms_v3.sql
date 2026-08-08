-- ============================================================
-- EarnSphere - SMS System Update (v3)
-- Rewrites the default (system) SMS templates so every message
-- starts with "Dear {name}, ...".
-- Run this ONLY if you already imported migration_sms.sql
-- (custom templates created by the admin are left untouched).
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+03:00";

UPDATE `sms_templates` SET `message` = 'Dear {name}, Karibu kwenye {site}! Kamilisha malipo ya TZS {fee} ili uanze kupata commissions kupitia code yako: {code}', `updated_at` = CURRENT_TIMESTAMP WHERE `template_key` = 'welcome' AND `is_system` = 1;

UPDATE `sms_templates` SET `message` = 'Dear {name}, malipo yako ya TZS {amount} yamekamilika na akaunti yako imewashwa kikamilifu. Sasa unaweza kuingia na kuanza kupata commissions. Code yako ya referral: {code}', `updated_at` = CURRENT_TIMESTAMP WHERE `template_key` = 'payment_success' AND `is_system` = 1;

UPDATE `sms_templates` SET `message` = 'Dear {name}, bado haujamaliza kulipa TZS {fee} ya usajili. Maliza leo ili uanze kupata commissions!', `updated_at` = CURRENT_TIMESTAMP WHERE `template_key` = 'payment_reminder' AND `is_system` = 1;

UPDATE `sms_templates` SET `message` = 'Dear {name}, utoaji wako wa TZS {amount} umekamilika kwenye namba {phone}.', `updated_at` = CURRENT_TIMESTAMP WHERE `template_key` = 'withdrawal_success' AND `is_system` = 1;

UPDATE `sms_templates` SET `message` = 'Dear {name}, samahani utoaji wako wa TZS {amount} umeshindikana. Tafadhali wasiliana na support.', `updated_at` = CURRENT_TIMESTAMP WHERE `template_key` = 'withdrawal_failed' AND `is_system` = 1;

COMMIT;
