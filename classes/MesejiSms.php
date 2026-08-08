<?php
/**
 * EarnSphere - Meseji SMS Class
 *
 * Wraps the Meseji SMS gateway (https://meseji.co.tz/api/v1).
 * Handles sending SMS to one or many recipients, template rendering,
 * logging, and the automatic welcome / payment-success notifications.
 *
 * API Reference: Meseji API Reference (x-api-key header, zs_ prefixed keys)
 */

require_once __DIR__ . '/../config/meseji.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ErrorLogger.php';

class MesejiSms {

    private string $apiKey;
    private string $apiUrl;
    private string $senderId;
    private bool $enabled;

    public function __construct() {
        $config = getMesejiConfig();
        $this->apiKey   = (string) ($config['api_key'] ?? '');
        $this->apiUrl   = rtrim((string) ($config['api_url'] ?? 'https://meseji.co.tz/api/v1'), '/');
        $this->senderId = (string) ($config['sender_id'] ?? 'MESEJI');
        $this->enabled  = (bool) ($config['enabled'] ?? false);
    }

    /**
     * Whether the SMS service is configured and enabled.
     */
    public function isEnabled(): bool {
        return $this->enabled && !empty($this->apiKey);
    }

    public function isConfigured(): bool {
        return !empty($this->apiKey);
    }

    public function getSenderId(): string {
        return $this->senderId;
    }

    // ================================================================
    // SENDING
    // ================================================================

    /**
     * Send one SMS to one or multiple recipients.
     *
     * @param string          $message     Message body (already rendered)
     * @param string|array    $contacts    Phone number(s) — accepts 07xx, +255, 2557 formats
     * @param string|null     $senderId    Override sender ID (default: configured)
     * @param string          $type        sms_logs type: welcome, payment, reminder, broadcast, custom
     * @param string|null     $templateKey Related template key
     * @param int|null        $sentBy      Admin user id who triggered the send
     * @param int|null        $userId      Linked user id (single recipient)
     * @return array ['success' => bool, 'batch_id' => ?string, 'recipients' => int, 'error' => ?string]
     */
    public function send(string $message, $contacts, ?string $senderId = null, string $type = 'custom', ?string $templateKey = null, ?int $sentBy = null, ?int $userId = null): array {
        if (!$this->isEnabled()) {
            $this->log($message, [], $type, $templateKey, null, 0, 'failed', 'SMS service is disabled or API key is missing', $sentBy, $userId);
            return ['success' => false, 'error' => 'SMS service is disabled. Enable it in Admin > Settings > SMS.', 'batch_id' => null, 'recipients' => 0];
        }

        $phones = $this->collectValidPhones($contacts);
        if (empty($phones)) {
            $this->log($message, [], $type, $templateKey, null, 0, 'failed', 'No valid phone numbers provided', $sentBy, $userId);
            return ['success' => false, 'error' => 'No valid phone numbers provided', 'batch_id' => null, 'recipients' => 0];
        }

        $message = trim($message);
        if ($message === '') {
            $this->log($message, $phones, $type, $templateKey, null, 0, 'failed', 'Message body is empty', $sentBy, $userId);
            return ['success' => false, 'error' => 'Message body is empty', 'batch_id' => null, 'recipients' => count($phones)];
        }

        $payload = [
            'sender_id' => $senderId ?: $this->senderId,
            'message'   => $message,
            'contacts'  => implode(', ', $phones),
        ];

        $response = $this->request('POST', '/sms/send', $payload);

        if ($response['success']) {
            $data     = $response['data'];
            $batchId  = $data['batch_id'] ?? null;
            $cost     = $data['estimated_cost'] ?? 0;
            $status   = $data['status'] ?? 'queued';

            $this->log($message, $phones, $type, $templateKey, $batchId, (float) $cost, $status, null, $sentBy, $userId);

            return [
                'success'    => true,
                'batch_id'   => $batchId,
                'recipients' => count($phones),
                'status'     => $status,
                'cost'       => (float) $cost,
            ];
        }

        $error = $response['error'] ?? 'Unknown SMS error';
        $this->log($message, $phones, $type, $templateKey, null, 0, 'failed', $error, $sentBy, $userId);
        ErrorLogger::log('sms', 'Meseji SMS send failed', [
            'endpoint' => '/sms/send',
            'error'    => $error,
            'error_code' => $response['error_code'] ?? null,
            'recipients' => count($phones),
            'type'     => $type,
            'template' => $templateKey,
        ], $userId, 'error', 'MesejiSms::send');

        return ['success' => false, 'error' => $error, 'batch_id' => null, 'recipients' => count($phones)];
    }

    /**
     * Send the same (already rendered) message to many recipients in chunks.
     * Meseji accepts comma-separated contacts, so we batch to keep calls low.
     */
    public function sendBulk(array $contacts, string $message, string $type = 'broadcast', ?string $templateKey = null, ?int $sentBy = null): array {
        $chunks  = array_chunk(array_values(array_unique($contacts)), 100);
        $results = [];
        $sent    = 0;
        $failed  = 0;

        foreach ($chunks as $chunk) {
            $res = $this->send($message, $chunk, null, $type, $templateKey, $sentBy);
            $results[] = $res;
            if ($res['success']) {
                $sent += $res['recipients'];
            } else {
                $failed += count($chunk);
            }
        }

        return [
            'success'   => $sent > 0,
            'sent'      => $sent,
            'failed'    => $failed,
            'results'   => $results,
        ];
    }

    /**
     * Send a personalized message to a single user. Placeholders in $message
     * are replaced with the user's data.
     */
    public function sendToUser(int $userId, string $message, string $type = 'custom', ?string $templateKey = null, ?int $sentBy = null): array {
        $user = Database::fetchOne("SELECT id, full_name, phone, referral_code FROM users WHERE id = ?", [$userId]);
        if (!$user) {
            return ['success' => false, 'error' => 'User not found', 'batch_id' => null, 'recipients' => 0];
        }

        $vars = $this->defaultVars();
        $vars = array_merge($vars, [
            'name'  => $user['full_name'],
            'phone' => $user['phone'],
            'code'  => $user['referral_code'],
        ]);

        $rendered = $this->parseTemplate($message, $vars);
        return $this->send($rendered, $user['phone'], null, $type, $templateKey, $sentBy, (int) $user['id']);
    }

    /**
     * Send a personalized message to multiple users, one API call per user.
     */
    public function sendToUsers(array $userIds, string $message, string $type = 'broadcast', ?string $templateKey = null, ?int $sentBy = null): array {
        $sent   = 0;
        $failed = 0;
        $results = [];

        foreach (array_values(array_unique($userIds)) as $id) {
            $res = $this->sendToUser((int) $id, $message, $type, $templateKey, $sentBy);
            $results[] = $res;
            if ($res['success']) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return ['success' => $sent > 0, 'sent' => $sent, 'failed' => $failed, 'results' => $results];
    }

    // ================================================================
    // AUTOMATIC NOTIFICATIONS
    // ================================================================

    /**
     * Send the welcome SMS to a newly registered user (pending payment).
     */
    public static function notifyWelcome(int $userId): void {
        try {
            if (!class_exists('MesejiSms', false)) {
                require_once __DIR__ . '/MesejiSms.php';
            }
            $sms = new self();
            if (!$sms->isEnabled()) return;

            $welcomeEnabled = (bool) app_setting('sms_welcome_enabled', '1');
            if (!$welcomeEnabled) return;

            $user = Database::fetchOne("SELECT id, full_name, phone, referral_code FROM users WHERE id = ?", [$userId]);
            if (!$user) return;

            $template = $sms->renderTemplate('welcome');
            if (!$template) return;

            $vars = $sms->defaultVars();
            $vars = array_merge($vars, [
                'name' => $user['full_name'],
                'code' => $user['referral_code'],
            ]);

            $sms->send(
                $sms->parseTemplate($template, $vars),
                $user['phone'],
                null,
                'welcome',
                'welcome',
                null,
                (int) $user['id']
            );
        } catch (Throwable $e) {
            error_log("Welcome SMS error: " . $e->getMessage());
            if (class_exists('ErrorLogger')) {
                ErrorLogger::log('sms', 'Welcome SMS error', [
                    'user_id' => $userId,
                    'error'   => $e->getMessage(),
                ], $userId, 'warning', 'MesejiSms::notifyWelcome');
            }
        }
    }

    /**
     * Send the payment-success SMS after a registration payment completes.
     */
    public static function notifyPaymentSuccess(int $userId, float $amount = 0): void {
        try {
            if (!class_exists('MesejiSms', false)) {
                require_once __DIR__ . '/MesejiSms.php';
            }
            $sms = new self();
            if (!$sms->isEnabled()) return;

            $paymentEnabled = (bool) app_setting('sms_payment_enabled', '1');
            if (!$paymentEnabled) return;

            $user = Database::fetchOne("SELECT id, full_name, phone, referral_code FROM users WHERE id = ?", [$userId]);
            if (!$user) return;

            $template = $sms->renderTemplate('payment_success');
            if (!$template) return;

            $vars = $sms->defaultVars();
            $vars = array_merge($vars, [
                'name'   => $user['full_name'],
                'code'   => $user['referral_code'],
                'amount' => number_format($amount > 0 ? $amount : (float) app_setting('registration_fee', REGISTRATION_FEE)),
            ]);

            $sms->send(
                $sms->parseTemplate($template, $vars),
                $user['phone'],
                null,
                'payment',
                'payment_success',
                null,
                (int) $user['id']
            );
        } catch (Throwable $e) {
            error_log("Payment SMS error: " . $e->getMessage());
            if (class_exists('ErrorLogger')) {
                ErrorLogger::log('sms', 'Payment SMS error', [
                    'user_id' => $userId,
                    'amount'  => $amount,
                    'error'   => $e->getMessage(),
                ], $userId, 'warning', 'MesejiSms::notifyPaymentSuccess');
            }
        }
    }

    // ================================================================
    // TEMPLATES
    // ================================================================

    /**
     * Load an active template message by key.
     */
    public function renderTemplate(string $templateKey): ?string {
        $tpl = Database::fetchOne(
            "SELECT message FROM sms_templates WHERE template_key = ? AND is_active = 1 LIMIT 1",
            [$templateKey]
        );
        return $tpl ? $tpl['message'] : null;
    }

    /**
     * List all templates (for the admin picker).
     */
    public static function listTemplates(bool $activeOnly = true): array {
        try {
            $tableExists = Database::fetchOne("SHOW TABLES LIKE 'sms_templates'");
            if (!$tableExists) {
                return [];
            }
            $where = $activeOnly ? 'WHERE is_active = 1' : '';
            return Database::fetchAll("SELECT * FROM sms_templates {$where} ORDER BY is_system DESC, name ASC");
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Replace {placeholder} tokens in a message with values.
     */
    public function parseTemplate(string $template, array $vars = []): string {
        $vars = array_merge($this->defaultVars(), $vars);

        $search  = [];
        $replace = [];
        foreach ($vars as $key => $value) {
            $search[]  = '{' . $key . '}';
            $replace[] = (string) $value;
        }

        return str_replace($search, $replace, $template);
    }

    /**
     * Default variables available to every template.
     */
    private function defaultVars(): array {
        return [
            'site' => SITE_NAME,
            'fee'  => number_format((float) app_setting('registration_fee', REGISTRATION_FEE)),
        ];
    }

    // ================================================================
    // MESEJI ACCOUNT ENDPOINTS (for the admin dashboard)
    // ================================================================

    public function getSenderIds(): array {
        return $this->request('GET', '/sms/sender-ids');
    }

    public function getAccountStats(): array {
        return $this->request('GET', '/sms/user-stats');
    }

    public function getHistory(): array {
        return $this->request('GET', '/sms/history');
    }

    public function getBatchStats(string $batchId): array {
        return $this->request('GET', '/sms/stats/' . urlencode($batchId));
    }

    // ================================================================
    // INTERNALS
    // ================================================================

    /**
     * Convert any contact input into a validated list of 255XXXXXXXXX phones.
     */
    private function collectValidPhones($contacts): array {
        if (is_string($contacts)) {
            $contacts = preg_split('/[\s,;]+/', trim($contacts)) ?: [];
        }
        if (!is_array($contacts)) {
            $contacts = [$contacts];
        }

        $phones = [];
        foreach ($contacts as $contact) {
            $normalized = $this->normalizePhone((string) $contact);
            if (preg_match('/^255[67]\d{8}$/', $normalized)) {
                $phones[] = $normalized;
            }
        }

        return array_values(array_unique($phones));
    }

    /**
     * Normalize a Tanzanian phone number to 255XXXXXXXXX format.
     * 07xx/06xx → 2557xx/2556xx, +255... → 255...
     */
    public function normalizePhone(string $phone): string {
        $phone = preg_replace('/[\s\-()]/', '', trim($phone));

        if (str_starts_with($phone, '+')) {
            $phone = substr($phone, 1);
        }

        if (str_starts_with($phone, '0') && strlen($phone) === 10) {
            return '255' . substr($phone, 1);
        }

        return $phone;
    }

    /**
     * Write a row into sms_logs.
     */
    private function log(string $message, array $phones, string $type, ?string $templateKey, ?string $batchId, float $cost, string $status, ?string $error, ?int $sentBy, ?int $userId): void {
        try {
            $tableExists = Database::fetchOne("SHOW TABLES LIKE 'sms_logs'");
            if (!$tableExists) {
                error_log("MesejiSms: sms_logs table missing — run database/migration_sms.sql");
                return;
            }

            Database::insert('sms_logs', [
                'sent_by'         => $sentBy,
                'user_id'         => $userId,
                'phone'           => count($phones) === 1 ? $phones[0] : null,
                'phones'          => json_encode($phones, JSON_UNESCAPED_UNICODE),
                'message'         => mb_substr($message, 0, 1000),
                'type'            => substr($type ?: 'custom', 0, 30),
                'template_key'    => $templateKey ? substr($templateKey, 0, 50) : null,
                'batch_id'        => $batchId ? substr($batchId, 0, 100) : null,
                'total_recipients'=> count($phones),
                'estimated_cost'  => $cost,
                'status'          => $status,
                'error'           => $error ? mb_substr($error, 0, 500) : null,
            ]);
        } catch (Throwable $e) {
            error_log("MesejiSms: failed to log SMS: " . $e->getMessage());
        }
    }

    /**
     * Perform an HTTP request against the Meseji API.
     */
    private function request(string $method, string $endpoint, array $data = []): array {
        if (!$this->isConfigured()) {
            return ['success' => false, 'error' => 'Meseji API key is not configured'];
        }

        $url = $this->apiUrl . $endpoint;

        $headers = [
            'x-api-key: ' . $this->apiKey,
            'Content-Type: application/json',
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("Meseji API cURL error [{$method} {$endpoint}]: {$curlErr}");
            ErrorLogger::log('api', 'Meseji API cURL error', [
                'method'   => $method,
                'endpoint' => $endpoint,
                'error'    => $curlErr,
            ], null, 'error', 'MesejiSms::request');
            return ['success' => false, 'error' => "SMS service unavailable: {$curlErr}"];
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'data' => $decoded];
        }

        $errorMsg = $decoded['message'] ?? $decoded['error'] ?? ($decoded['errors'][0] ?? null) ?? "Meseji API returned HTTP {$httpCode}";
        $errorCode = $decoded['error_code'] ?? null;

        error_log("Meseji API error [{$method} {$endpoint}] HTTP {$httpCode}: {$response}");
        ErrorLogger::log('api', 'Meseji API returned an error', [
            'method'     => $method,
            'endpoint'   => $endpoint,
            'http_code'  => $httpCode,
            'error'      => $errorMsg,
            'error_code' => $errorCode,
            'response'   => $decoded ?? $response,
            'payload'    => $data,
        ], null, 'error', 'MesejiSms::request');

        return [
            'success'    => false,
            'error'      => $errorMsg,
            'error_code' => $errorCode,
            'http_code'  => $httpCode,
        ];
    }
}
