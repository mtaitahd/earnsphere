<?php
require_once __DIR__.'/database.php';
function snippeConfig(): array {
    $secret=static function(string $key): string {
        $stored=trim((string)app_setting($key,''));
        return $stored!==''?$stored:trim((string)(getenv(strtoupper($key))?:''));
    };
    return ['api_key'=>$secret('snippe_api_key'),
        'webhook_secret'=>$secret('snippe_webhook_secret'),
        'enabled'=>(string)app_setting('snippe_enabled','0')==='1',
        'base_url'=>'https://api.snippe.sh','callback_url'=>rtrim(SITE_URL,'/').'/webhooks/snippe.php'];
}
