<?php
/**
 * EarnSphere - Configuration File
 * Central configuration for database, payment, and system settings
 */

// Prevent direct access
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// ============================================================
// Load .env file if exists
// ============================================================
$envFile = APP_ROOT . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}

// ============================================================
// Environment Detection
// ============================================================
define('ENVIRONMENT', getenv('APP_ENV') ?: 'development'); // development | production

// ============================================================
// Database Configuration
// ============================================================
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'earnsphere');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// ============================================================
// Site Configuration
// ============================================================
define('SITE_NAME', getenv('APP_NAME') ?: 'EarnSphere');
define('SITE_TAGLINE', 'Your gateway to opportunity and income');
define('SITE_URL', getenv('APP_URL') ?: 'http://localhost/earnsphere');
define('ADMIN_URL', SITE_URL . '/admin');

// ============================================================
// Payment Configuration (Snippe API)
// ============================================================
define('SNIPPE_API_KEY', getenv('SNIPPE_API_KEY') ?: '');
define('SNIPPE_WEBHOOK_SECRET', getenv('SNIPPE_WEBHOOK_SECRET') ?: '');
define('SNIPPE_API_URL', getenv('SNIPPE_API_URL') ?: 'https://api.snippe.sh');
define('SNIPPE_API_VERSION', getenv('SNIPPE_API_VERSION') ?: '2026-01-25');
define('CURRENCY', 'TZS');
define('REGISTRATION_FEE', (int)(getenv('REGISTRATION_FEE') ?: 11500));

// ============================================================
// Commission Structure
// ============================================================
define('COMPANY_EARNING', (int)(getenv('COMPANY_EARNING') ?: 6000));
define('COMMISSION_TOTAL', (int)(getenv('COMMISSION_TOTAL') ?: 4000));
define('COMMISSION_L1', (int)(getenv('COMMISSION_L1') ?: 2000));
define('COMMISSION_L2', (int)(getenv('COMMISSION_L2') ?: 1200));
define('COMMISSION_L3', (int)(getenv('COMMISSION_L3') ?: 800));

// ============================================================
// Withdrawal Settings
// ============================================================
define('MIN_WITHDRAWAL', (int)(getenv('MIN_WITHDRAWAL') ?: 5000));
define('MAX_WITHDRAWAL', (int)(getenv('MAX_WITHDRAWAL') ?: 500000));

// ============================================================
// Payout Configuration (Snippe Disbursement)
// ============================================================
define('PAYOUT_CHANNEL', 'mobile');
define('PAYOUT_WEBHOOK_URL', getenv('PAYOUT_WEBHOOK_URL') ?: 'http://localhost/earnsphere/webhooks/snippe.php');

// ============================================================
// Session Configuration
// ============================================================
define('SESSION_LIFETIME', 86400 * 7); // 7 days
define('CSRF_TOKEN_NAME', 'csrf_token');

// ============================================================
// Timezone
// ============================================================
date_default_timezone_set('Africa/Dar_es_Salaam');

// ============================================================
// Error Reporting
// ============================================================
if (ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('log_errors', 1);
} else {
    // In production, log errors but show a friendly message
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', APP_ROOT . '/logs/error.log');
    ini_set('log_errors_max_len', 1024);
    
    // Disable dangerous PHP functions in production
    $disabled = 'exec,passthru,shell_exec,system,proc_open,popen,parse_ini_file,show_source,pcntl_exec';
    ini_set('disable_functions', $disabled);
}

// ============================================================
// Upload Configuration
// ============================================================
define('UPLOAD_DIR', APP_ROOT . '/uploads');
define('AVATAR_DIR', UPLOAD_DIR . '/avatars');
define('QR_DIR', UPLOAD_DIR . '/qrcodes');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

// ============================================================
// Email / SMTP Configuration
// ============================================================
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'brandy.hostns.io');
define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 465));
define('SMTP_ENCRYPTION', getenv('SMTP_ENCRYPTION') ?: 'ssl');
define('SMTP_USER', getenv('SMTP_USER') ?: 'info@mtaitatech.online');
define('SMTP_PASS', getenv('SMTP_PASS') ?: 'M20032003$');
define('FROM_EMAIL', getenv('FROM_EMAIL') ?: 'info@mtaitatech.online');
define('FROM_NAME', getenv('FROM_NAME') ?: 'EarnSphere');

// ============================================================
// SMS Configuration (Meseji API)
// ============================================================
define('MESEJI_API_KEY', getenv('MESEJI_API_KEY') ?: '');
define('MESEJI_SENDER_ID', getenv('MESEJI_SENDER_ID') ?: 'MESEJI');
define('MESEJI_API_URL', getenv('MESEJI_API_URL') ?: 'https://meseji.co.tz/api/v1');

// ============================================================
// OTP Configuration
// ============================================================
define('OTP_LENGTH', 6);
define('OTP_EXPIRY_MINUTES', 15);
