<?php
/**
 * EarnSphere - Meseji SMS Configuration
 *
 * Central Meseji SMS API configuration. Reads from database settings
 * (editable in the admin dashboard) with fallback to .env constants.
 *
 * Usage: require_once this file wherever Meseji config is needed.
 * This populates the getMesejiConfig() function.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require_once __DIR__ . '/config.php';

/**
 * Build the Meseji SMS configuration array.
 * Priority: Database settings > config.php constants > defaults
 */
function getMesejiConfig(): array {
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $dbSettings = [];
    try {
        require_once __DIR__ . '/database.php';
        $rows = Database::fetchAll(
            "SELECT setting_key, setting_value FROM settings
             WHERE setting_key LIKE 'meseji%' OR setting_key LIKE 'sms_%'"
        );
        foreach ($rows as $row) {
            $dbSettings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Exception $e) {
        // DB not available, use constants only
    }

    $enabled = filter_var($dbSettings['meseji_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

    $config = [
        'api_key'    => $dbSettings['meseji_api_key'] ?? MESEJI_API_KEY,
        'api_url'    => $dbSettings['meseji_api_url'] ?? MESEJI_API_URL,
        'sender_id'  => $dbSettings['meseji_sender_id'] ?? MESEJI_SENDER_ID,
        'enabled'    => $enabled,
    ];

    return $config;
}
