<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

class PaymentNotifications {
    private static function enqueue(string $eventKey, int $userId, string $kind, string $title, string $message, array $payload, bool $sms = true, ?string $createdAt = null): void {
        if (Database::fetchOne('SELECT id FROM user_notifications WHERE event_key = ?', [$eventKey])) return;
        try {
            Database::insert('user_notifications', ['event_key' => $eventKey, 'user_id' => $userId, 'kind' => $kind,
                'title' => $title, 'message' => $message, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'sms_status' => $sms ? 'pending' : 'skipped', 'created_at' => $createdAt ?? gmdate('Y-m-d H:i:s')]);
        } catch (PDOException $e) {
            // A concurrent dashboard backfill or duplicate event must not reverse financial settlement.
            if (!Database::fetchOne('SELECT id FROM user_notifications WHERE event_key = ? AND user_id = ?', [$eventKey, $userId])) throw $e;
        }
    }

    public static function payment(array $payment, bool $sms = true): void {
        $message = 'Malipo yako ya ' . formatCurrency((float) $payment['amount']) . ' yamefanikiwa. Akaunti yako imewashwa.';
        self::enqueue('payment:' . $payment['id'], (int) $payment['user_id'], 'payment_success', 'Malipo yamefanikiwa', $message,
            ['amount' => (float) $payment['amount'], 'payment_id' => (int) $payment['id']], $sms, !$sms && !empty($payment['completed_at']) ? (new DateTimeImmutable($payment['completed_at'], new DateTimeZone('Africa/Dar_es_Salaam')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null);
    }

    public static function withdrawal(array $wd, float $received, bool $sms = true): void {
        $gross = (float) $wd['amount'];
        $systemCharge = (float) ($wd['withdrawal_charge'] ?? 0);
        $requested = $gross - $systemCharge;
        $providerCharge = max(0, $requested - $received);
        $message = 'Utoaji umefanikiwa. Wallet: ' . formatCurrency($gross)
            . '; ada ya mfumo: ' . formatCurrency($systemCharge) . '; ada ya mtoa huduma: ' . formatCurrency($providerCharge)
            . '; umepokea ' . formatCurrency($received) . ' kwenye ' . $wd['phone'] . '.';
        self::enqueue('withdrawal:' . $wd['id'], (int) $wd['user_id'], 'withdrawal_success', 'Utoaji umefanikiwa', $message,
            ['withdrawal_id' => (int) $wd['id'], 'gross_amount' => $gross, 'system_charge' => $systemCharge,
                'provider_charge' => $providerCharge, 'received_amount' => $received, 'recipient' => $wd['phone']], $sms, !$sms && !empty($wd['processed_at']) ? (new DateTimeImmutable($wd['processed_at'], new DateTimeZone('Africa/Dar_es_Salaam')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null);
    }

    public static function backfillForUser(int $userId): void {
        // History is visible immediately; never blast SMS for historical events.
        foreach (Database::fetchAll("SELECT * FROM payments WHERE user_id = ? AND purpose = 'registration' AND status = 'completed' ORDER BY id DESC LIMIT 10", [$userId]) as $payment) self::payment($payment, false);
        $rows = Database::fetchAll("SELECT w.*, p.amount AS requested_amount, p.metadata AS payout_metadata FROM withdrawals w JOIN payouts p ON p.withdrawal_id = w.id WHERE w.user_id = ? AND p.user_id = ? AND w.status = 'completed' AND p.status = 'completed' AND p.gateway = 'abliner' ORDER BY w.id DESC LIMIT 10", [$userId, $userId]);
        foreach ($rows as $row) {
            $data = json_decode($row['payout_metadata'] ?? '{}', true) ?: [];
            $received = $data['settlement_review']['provider_reported_tzs'] ?? $data['amount'] ?? (isset($data['amount_cents']) ? (float) $data['amount_cents'] / 100 : $row['requested_amount']);
            self::withdrawal($row, (float) $received, false);
        }
    }

    public static function listForUser(int $userId): array {
        $items = Database::fetchAll('SELECT id, kind, title, message, created_at, read_at FROM user_notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 20', [$userId]);
        $unread = Database::count('user_notifications', 'user_id = ? AND read_at IS NULL', [$userId]);
        return ['items' => $items, 'unread' => $unread, 'latest_id' => (int) (Database::fetchOne('SELECT COALESCE(MAX(id),0) AS latest_id FROM user_notifications WHERE user_id = ?', [$userId])['latest_id'] ?? 0)];
    }

    public static function markAllRead(int $userId, int $throughId): void {
        if ($throughId <= 0) return;
        Database::query('UPDATE user_notifications SET read_at = ? WHERE user_id = ? AND id <= ? AND read_at IS NULL', [gmdate('Y-m-d H:i:s'), $userId, $throughId]);
    }

    public static function dispatch(int $limit = 1, ?callable $sender = null): void {
        for ($i = 0; $i < max(0, min(5, $limit)); $i++) {
            Database::beginTransaction();
            try {
                $row = Database::fetchOne("SELECT * FROM user_notifications WHERE sms_status = 'pending' ORDER BY id LIMIT 1 FOR UPDATE");
                if (!$row) { Database::commit(); return; }
                Database::update('user_notifications', ['sms_status' => 'sending', 'sms_attempted_at' => gmdate('Y-m-d H:i:s')], 'id = ? AND sms_status = ?', [$row['id'], 'pending']);
                Database::commit();
            } catch (Throwable $e) { if (Database::inTransaction()) Database::rollback(); throw $e; }
            try {
                $user = Database::fetchOne('SELECT phone FROM users WHERE id = ?', [$row['user_id']]);
                if (!$user) throw new RuntimeException('Notification user not found.');
                if ($sender) $result = $sender($row, $user['phone']);
                else {
                    require_once __DIR__ . '/MesejiSms.php';
                    $sms = new MesejiSms();
                    if ($row['kind'] === 'payment_success' && !filter_var(app_setting('sms_payment_enabled', '1'), FILTER_VALIDATE_BOOLEAN)) $result = ['success' => false, 'error' => 'Payment success SMS is disabled in settings.'];
                    else $result = $sms->send($row['message'], $user['phone'], null, $row['kind'] === 'payment_success' ? 'payment' : 'custom', $row['kind'], null, (int) $row['user_id']);
                    // Sender override is intentionally null: use the database-configured sender ID.
                }
                Database::update('user_notifications', ['sms_status' => !empty($result['success']) ? 'sent' : 'failed',
                    'sms_sent_at' => !empty($result['success']) ? gmdate('Y-m-d H:i:s') : null,
                    'sms_error' => !empty($result['success']) ? null : substr((string) ($result['error'] ?? 'SMS was not accepted.'), 0, 500)], 'id = ?', [$row['id']]);
            } catch (Throwable $e) {
                Database::update('user_notifications', ['sms_status' => 'unknown', 'sms_error' => substr($e->getMessage(), 0, 500)], 'id = ?', [$row['id']]);
                error_log('Payment notification SMS #' . $row['id'] . ' needs review: ' . $e->getMessage());
            }
            // No blind retry after an ambiguous send: gateway acceptance may precede a timeout.
        }
    }
}
