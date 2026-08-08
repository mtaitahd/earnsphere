-- ============================================================
-- EarnSphere - SMS System Update (v2)
-- Improves the Payment Success template message to explicitly
-- state the account has been ACTIVATED (kikamilifu).
-- Run this ONLY if you already imported database/migration_sms.sql
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+03:00";

UPDATE `sms_templates`
SET `message` = 'Hongera {name}! Malipo yako ya TZS {amount} yamekamilika na akaunti yako imewashwa kikamilifu. Sasa unaweza kuingia na kuanza kupata commissions. Code yako ya referral: {code}',
    `variables` = 'name,amount,code,site',
    `updated_at` = CURRENT_TIMESTAMP
WHERE `template_key` = 'payment_success';

COMMIT;
