<?php
require_once __DIR__ . '/../config/abliner.php';

class AblinerClient {
    protected array $config;

    public function __construct(?array $config = null) {
        $this->config = $config ?? ablinerConfig();
    }

    public function ready(): bool {
        return $this->config['api_key'] !== '' && $this->config['webhook_secret'] !== ''
            && str_starts_with($this->config['callback_url'], 'https://');
    }

    public static function normalizePhone(string $phone): string {
        $phone = preg_replace('/[\s()+-]/', '', trim($phone));
        if (preg_match('/^0[67]\d{8}$/D', $phone)) $phone = '255' . substr($phone, 1);
        if (preg_match('/^[67]\d{8}$/D', $phone)) $phone = '255' . $phone;
        if (!preg_match('/^255[67]\d{8}$/D', $phone)) {
            throw new InvalidArgumentException('Enter a valid Tanzania mobile money number.');
        }
        return $phone;
    }

    public function callbackUrl(): string { return $this->config['callback_url']; }
    public function balance(): array { return $this->request('GET', '/balance'); }
    public function lookup(string $phone): array {
        return $this->request('GET', '/lookup?' . http_build_query(['phone' => self::normalizePhone($phone)]));
    }
    public function preview(float $amount): array {
        return $this->request('GET', '/withdrawals/preview?' . http_build_query(['method' => 'mobile', 'amount' => $amount]));
    }
    public function deposit(array $payload, string $key): array { return $this->request('POST', '/deposits', $payload, $key); }
    public function withdraw(array $payload, string $key): array { return $this->request('POST', '/withdrawals', $payload, $key); }
    public function transactions(array $filter): array { return $this->request('GET', '/transactions?' . http_build_query($filter)); }

    public function verifyWebhookSignature(array $headers, string $rawBody): bool {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $timestamp = $headers['x-webhook-timestamp'] ?? '';
        $signature = $headers['x-webhook-signature'] ?? '';
        if (!$this->config['webhook_secret'] || !is_string($timestamp) || !ctype_digit($timestamp)
            || abs(time() - (int) $timestamp) > 300 || !is_string($signature)
            || !preg_match('/^[a-f0-9]{64}$/D', $signature)) return false;
        return hash_equals(hash_hmac('sha256', $timestamp . '.' . $rawBody, $this->config['webhook_secret']), $signature);
    }

    protected function request(string $method, string $path, array $payload = [], ?string $key = null): array {
        if (!$this->config['api_key']) return ['success' => false, 'code' => 'not_configured', 'error' => 'Abliner API key is not configured.', 'uncertain' => false];
        $body = $method === 'POST' ? json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : '';
        $headers = ['Authorization: Bearer ' . $this->config['api_key'], 'Content-Type: application/json', 'Accept: application/json'];
        if ($key !== null) $headers[] = 'Idempotency-Key: ' . $key;
        if ($method === 'POST') {
            if (!$this->ready()) return ['success' => false, 'code' => 'not_configured', 'error' => 'Configure Abliner secrets and an HTTPS site URL first.', 'uncertain' => false];
            $timestamp = (string) time();
            $headers[] = 'x-abliner-timestamp: ' . $timestamp;
            $headers[] = 'x-abliner-signature: ' . hash_hmac('sha256', $timestamp . '.' . $body, $this->config['webhook_secret']);
        }
        $response = $this->sendHttp($method, $this->config['base_url'] . '/api/v1' . $path, $headers, $body);
        $json = json_decode($response['body'], true);
        if ($response['error'] !== '' || !is_array($json)) {
            return ['success' => false, 'code' => 'transport_error', 'error' => 'Abliner could not be reached. Check this transaction before trying again.', 'uncertain' => true];
        }
        if ($response['http_code'] >= 200 && $response['http_code'] < 300 && ($json['status'] ?? '') === 'success' && isset($json['data']) && is_array($json['data'])) {
            return ['success' => true, 'data' => $json['data'], 'http_code' => $response['http_code']];
        }
        return ['success' => false, 'code' => $json['code'] ?? 'provider_error',
            'error' => (string) ($json['message'] ?? 'Abliner request failed.'),
            'retryable' => (bool) ($json['retryable'] ?? false), 'transaction_id' => $json['transaction_id'] ?? null,
            'uncertain' => $response['http_code'] >= 500 || in_array($json['code'] ?? '', ['request_in_progress', 'idempotency_conflict']) || !empty($json['transaction_id']),
            'http_code' => $response['http_code']];
    }

    protected function sendHttp(string $method, string $url, array $headers, string $body): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_FOLLOWLOCATION => false]);
        if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        $result = ['body' => $raw === false ? '' : $raw, 'http_code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'error' => curl_error($ch)];
        curl_close($ch);
        return $result;
    }
}
