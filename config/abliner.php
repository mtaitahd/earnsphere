<?php
require_once __DIR__ . '/database.php';

function ablinerConfig(): array {
    return [
        'api_key' => trim((string) app_setting('abliner_api_key', getenv('ABLINER_API_KEY') ?: '')),
        'webhook_secret' => trim((string) app_setting('abliner_webhook_secret', getenv('ABLINER_WEBHOOK_SECRET') ?: '')),
        'base_url' => 'https://abliner.net',
        'callback_url' => rtrim(SITE_URL, '/') . '/webhooks/abliner.php',
    ];
}
