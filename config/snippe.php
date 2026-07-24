<?php
/**
 * EarnSphere - Snippe Configuration
 * 
 * Central Snippe API configuration. Reads from database settings
 * with fallback to constants defined in config.php.
 * 
 * Usage: require_once this file wherever Snippe config is needed.
 * This populates $SNIPPE_CONFIG array.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require_once __DIR__ . '/config.php';

// ============================================================
// Build Snippe configuration array
// Priority: Database settings > config.php constants > defaults
// ============================================================
function getSnippeConfig(): array {
    static $config = null;
    
    if ($config !== null) {
        return $config;
    }
    
    // Try loading from database
    $dbSettings = [];
    try {
        require_once __DIR__ . '/database.php';
        $rows = Database::fetchAll(
            "SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'snippe%' OR setting_key = 'payout_channel' OR setting_key = 'payout_webhook'"
        );
        foreach ($rows as $row) {
            $dbSettings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (Exception $e) {
        // DB not available, use constants only
    }
    
    $config = [
        // API credentials
        'api_key'          => $dbSettings['snippe_api_key'] ?? SNIPPE_API_KEY,
        'webhook_secret'   => $dbSettings['snippe_webhook_secret'] ?? SNIPPE_WEBHOOK_SECRET,
        'api_url'          => $dbSettings['snippe_api_url'] ?? SNIPPE_API_URL,
        'api_version'      => $dbSettings['snippe_api_version'] ?? SNIPPE_API_VERSION,
        
        // Collection settings
        'currency'         => CURRENCY,
        'registration_fee' => $dbSettings['registration_fee'] ?? REGISTRATION_FEE,
        
        // Payout settings
        'payout_channel'   => $dbSettings['payout_channel'] ?? 'mobile',
        'payout_webhook'   => $dbSettings['payout_webhook'] ?? PAYOUT_WEBHOOK_URL,
        
        // Supported mobile money providers
        'providers'        => [
            'Airtel'    => 'Airtel Money',
            'Tigo'      => 'Mixx by Yas',
            'Halopesa'  => 'HaloPesa',
        ],
    ];
    
    return $config;
}

/**
 * Generate a unique idempotency key for Snippe requests
 * Snippe API requires max 30 characters.
 * Format: {prefix}{timestamp6}{random6} = 3+6+6 = 15 chars max
 */
function generateIdempotencyKey(string $prefix = 'es'): string {
    $random = bin2hex(random_bytes(3));
    $time = dechex(time());
    return substr($prefix . $time . $random, 0, 30);
}
