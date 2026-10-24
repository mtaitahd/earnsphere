<?php
require_once __DIR__ . '/AblinerClient.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Wallet.php';
require_once __DIR__ . '/CommissionEngine.php';

class AblinerPayment {
    private AblinerClient $client;

    public function __construct(?AblinerClient $client = null) { $this->client = $client ?? new AblinerClient(); }
    public function validatePhone(string $phone): array {
        try { return ['valid' => true, 'phone' => AblinerClient::normalizePhone($phone)]; }
        catch (InvalidArgumentException $e) { return ['valid' => false, 'error' => $e->getMessage()]; }
    }

    public function initiatePayment(int $userId, string $phone, float $amount = 0): array {
        return $this->startDeposit($userId, $phone, (float) app_setting('registration_fee', REGISTRATION_FEE), 'registration');
    }

    public function initiateFunding(int $adminId, string $phone, float $amount): array {
        return $this->startDeposit($adminId, $phone, $amount, 'admin_funding');
    }

    private function startDeposit(int $userId, string $phone, float $amount, string $purpose): array {
        if (!$this->client->ready()) return ['success' => false, 'error' => 'Abliner payments are not configured yet. Contact support.'];
        $phone = AblinerClient::normalizePhone($phone);
        if (!is_finite($amount) || $amount < 500 || $amount > 3000000 || floor($amount) !== $amount) return ['success' => false, 'error' => 'Enter a whole TZS amount from 500 to 3,000,000.'];
        Database::beginTransaction();
        try {
            $user = Database::fetchOne('SELECT * FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if (!$user || ($purpose === 'registration' && $user['status'] !== 'pending')
                || ($purpose === 'admin_funding' && ($user['role'] !== 'admin' || $user['status'] !== 'active'))) throw new RuntimeException('Account cannot initiate this deposit.');
            $existing = Database::fetchOne("SELECT * FROM payments WHERE user_id = ? AND purpose = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [$userId, $purpose]);
            if ($existing) {
                Database::commit();
                if (($existing['gateway'] ?? 'snippe') !== 'abliner') return ['success' => false, 'error' => 'An earlier payment needs reconciliation by support before starting an Abliner payment.'];
                return $this->paymentResult($existing, 'A payment is already awaiting confirmation. Check its status before starting another.');
            }
            $order = 'ES-' . bin2hex(random_bytes(12));
            $id = Database::insert('payments', ['user_id' => $userId, 'order_id' => $order, 'amount' => $amount,
                'currency' => 'TZS', 'phone' => $phone, 'payment_method' => 'mobile_money', 'status' => 'pending',
                'gateway' => 'abliner', 'idempotency_key' => $order, 'purpose' => $purpose]);
            Database::commit();
        } catch (Throwable $e) { if (Database::inTransaction()) Database::rollback(); throw $e; }
        $result = $this->client->deposit(['amount' => (int) $amount, 'method' => 'mobile', 'currency' => 'TZS',
            'phone' => $phone, 'reference' => $order, 'callback_url' => $this->client->callbackUrl()], $order);
        $this->handleSubmission('payments', $id, $result);
        $payment = Database::fetchOne('SELECT * FROM payments WHERE id = ?', [$id]);
        if (!$result['success'] && empty($result['uncertain'])) return ['success' => false, 'error' => $result['error'], 'code' => $result['code']];
        return $this->paymentResult($payment, $result['success'] ? 'Payment request accepted. Approve the prompt if it appears; payment is confirmed only after verification.' : 'Payment status is uncertain. We will check the existing request; do not start another payment.');
    }

    private function paymentResult(array $p, string $message): array {
        return ['success' => true, 'payment_id' => $p['id'], 'order_id' => $p['order_id'],
            'reference' => $p['gateway_reference'] ?? null, 'status' => $p['status'], 'message' => $message];
    }

    public function requestWithdrawal(int $userId, float $amount, string $phone, string $expectedName = ''): array {
        if (!$this->client->ready()) throw new RuntimeException('Abliner withdrawals are not configured yet.');
        if (!is_finite($amount) || floor($amount) !== $amount || $amount < (float) app_setting('min_withdrawal', MIN_WITHDRAWAL)
            || $amount > (float) app_setting('max_withdrawal', MAX_WITHDRAWAL)) throw new InvalidArgumentException('Enter a whole TZS amount within the withdrawal limits.');
        $phone = AblinerClient::normalizePhone($phone);
        // Independently verify the recipient; never trust a browser-supplied name or network.
        $lookup = $this->client->lookup($phone);
        if (!$lookup['success'] || empty($lookup['data']['name']) || empty($lookup['data']['network'])
            || AblinerClient::normalizePhone((string) ($lookup['data']['phone'] ?? '')) !== $phone) {
            throw new RuntimeException($lookup['error'] ?? 'Recipient name could not be verified.');
        }
        if ($expectedName !== '' && $lookup['data']['name'] !== $expectedName) throw new RuntimeException('Recipient name changed. Verify and confirm the number again.');
        Database::beginTransaction();
        try {
            $user = Database::fetchOne('SELECT status FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if (!$user || $user['status'] !== 'active') throw new RuntimeException('An active account is required.');
            $wallet = Database::fetchOne('SELECT * FROM wallets WHERE user_id = ? FOR UPDATE', [$userId]);
            // withdrawable_balance already excludes held withdrawals: do not subtract pending twice.
            if (!$wallet || $amount > (float) $wallet['withdrawable_balance']) throw new RuntimeException('Insufficient available commission earnings.');
            $withdrawal = Database::insert('withdrawals', ['user_id' => $userId, 'amount' => $amount, 'phone' => $phone,
                'payment_method' => 'mobile_money', 'status' => 'pending', 'gateway' => 'abliner',
                'recipient_name' => $lookup['data']['name'], 'recipient_network' => $lookup['data']['network']]);
            Database::update('wallets', ['withdrawable_balance' => (float) $wallet['withdrawable_balance'] - $amount,
                'pending_amount' => (float) $wallet['pending_amount'] + $amount], 'id = ?', [$wallet['id']]);
            Database::insert('wallet_transactions', ['wallet_id' => $wallet['id'], 'user_id' => $userId, 'type' => 'withdrawal',
                'amount' => -$amount, 'balance_before' => $wallet['balance'], 'balance_after' => $wallet['balance'],
                'description' => 'Abliner mobile money withdrawal', 'status' => 'pending',
                'reference_id' => $withdrawal, 'reference_type' => 'withdrawal']);
            Database::commit();
        } catch (Throwable $e) { if (Database::inTransaction()) Database::rollback(); throw $e; }
        $result = $this->sendPayout($withdrawal, $userId, $amount, $phone, $lookup['data']['name']);
        return ['success' => true, 'withdrawal_id' => $withdrawal, 'payout_status' => $result['status'] ?? 'pending',
            'amount' => $amount, 'phone' => $phone, 'recipient_name' => $lookup['data']['name'], 'network' => $lookup['data']['network'],
            'fees' => $result['fees'] ?? 0, 'reference' => $result['reference'] ?? null,
            'message' => $result['error'] ?? ($result['status'] === 'completed' ? 'Money has been sent to your mobile money account.' : 'Withdrawal is awaiting confirmation. Do not submit it again.')];
    }

    public function sendPayout(int $withdrawalId, int $userId, float $amount = 0, string $phone = '', string $name = ''): array {
        if (!$this->client->ready()) throw new RuntimeException('Abliner withdrawals are not configured yet.');
        Database::beginTransaction();
        try {
            $wd = Database::fetchOne('SELECT * FROM withdrawals WHERE id = ? AND user_id = ? FOR UPDATE', [$withdrawalId, $userId]);
            if (!$wd || ($wd['gateway'] ?? '') !== 'abliner') throw new RuntimeException('Legacy withdrawals must be reconciled with their original provider.');
            $existing = Database::fetchOne('SELECT * FROM payouts WHERE withdrawal_id = ? ORDER BY id DESC LIMIT 1', [$withdrawalId]);
            if ($existing) {
                Database::commit();
                return ['success' => true, 'payout_id' => $existing['id'], 'status' => $existing['status'], 'reference' => $existing['reference'],
                    'fees' => $existing['fees'], 'message' => 'Existing payout retained. Verify it; no second transfer was sent.'];
            }
            if (!in_array($wd['status'], ['pending', 'approved'], true) || empty($wd['recipient_name'])) throw new RuntimeException('Withdrawal cannot be dispatched.');
            $key = 'ES-WD-' . $withdrawalId . '-' . bin2hex(random_bytes(8));
            $id = Database::insert('payouts', ['user_id' => $userId, 'withdrawal_id' => $withdrawalId,
                'amount' => $wd['amount'], 'total' => $wd['amount'], 'channel' => 'mobile', 'provider' => $wd['recipient_network'],
                'recipient_phone' => $wd['phone'], 'recipient_name' => $wd['recipient_name'], 'status' => 'pending',
                'gateway' => 'abliner', 'idempotency_key' => $key]);
            Database::update('withdrawals', ['status' => 'processing'], 'id = ?', [$withdrawalId]);
            Database::commit();
        } catch (Throwable $e) { if (Database::inTransaction()) Database::rollback(); throw $e; }
        $result = $this->client->withdraw(['amount' => (int) $wd['amount'], 'method' => 'mobile', 'currency' => 'TZS',
            'recipient' => $wd['phone'], 'reference' => $key, 'callback_url' => $this->client->callbackUrl()], $key);
        $this->handleSubmission('payouts', $id, $result);
        $payout = Database::fetchOne('SELECT * FROM payouts WHERE id = ?', [$id]);
        return ['success' => $result['success'], 'payout_id' => $id, 'status' => $payout['status'], 'reference' => $payout['reference'],
            'fees' => $payout['fees'], 'error' => $result['error'] ?? null];
    }

    private function handleSubmission(string $table, int $id, array $result): void {
        if ($result['success']) {
            // An accepted request is never sufficient to credit the wallet. Validate all fields.
            $this->applyTransaction($table, $id, $result['data']);
        } elseif (!empty($result['uncertain'])) {
            // A timeout, 5xx or transaction_id may mean a transfer exists. Keep the hold.
            Database::update($table, ['metadata' => json_encode(['code' => $result['code'], 'message' => $result['error'],
                'transaction_id' => $result['transaction_id'] ?? null])], 'id = ? AND status = ?', [$id, 'pending']);
        } else {
            Database::beginTransaction();
            try {
                $row = Database::fetchOne("SELECT * FROM $table WHERE id = ? FOR UPDATE", [$id]);
                if ($row['status'] === 'pending') {
                    if ($table === 'payouts') $this->settleWithdrawal($row, 'failed');
                    Database::update($table, ['status' => 'failed', 'metadata' => json_encode(['code' => $result['code'], 'message' => $result['error']])], 'id = ?', [$id]);
                }
                Database::commit();
            } catch (Throwable $e) { if (Database::inTransaction()) Database::rollback(); throw $e; }
        }
    }

    public function reconcile(string $table, int $id): array {
        if (!in_array($table, ['payments', 'payouts'], true)) throw new InvalidArgumentException('Invalid transaction kind.');
        $row = Database::fetchOne("SELECT * FROM $table WHERE id = ?", [$id]);
        if (!$row || ($row['gateway'] ?? '') !== 'abliner') return ['success' => false, 'error' => 'Reconcile this legacy transaction with its original provider.'];
        if ($row['status'] !== 'pending') return ['success' => true, 'status' => $row['status']];
        $filter = !empty($row['gateway_id']) ? ['id' => $row['gateway_id']] : ['customer_reference' => $table === 'payments' ? $row['order_id'] : $row['idempotency_key']];
        $result = $this->client->transactions($filter);
        if (!$result['success']) return $result;
        $found = false;
        foreach ($result['data'] as $transaction) {
            if (!is_array($transaction) || !$this->matches($table, $row, $transaction)) continue;
            $this->applyTransaction($table, $id, $transaction);
            $found = true;
        }
        $current = Database::fetchOne("SELECT * FROM $table WHERE id = ?", [$id]);
        return ['success' => true, 'status' => $current['status'], 'found' => $found,
            'message' => $found ? 'Status checked with Abliner.' : 'No matching transaction found yet. Funds remain held; contact support before another attempt.'];
    }

    private function matches(string $table, array $row, array $data): bool {
        $expectedType = $table === 'payments' ? 'deposit' : 'withdrawal';
        if (($data['type'] ?? '') !== $expectedType || !is_string($data['id'] ?? null) || $data['id'] === '') return false;
        $customerRef = $table === 'payments' ? $row['order_id'] : $row['idempotency_key'];
        $providerRef = $table === 'payments' ? ($row['gateway_reference'] ?? '') : ($row['reference'] ?? '');
        if (!empty($data['customer_reference']) && !hash_equals($customerRef, (string) $data['customer_reference'])) return false;
        if ($table === 'payouts' && !empty($data['recipient']) && AblinerClient::normalizePhone((string) $data['recipient']) !== $row['recipient_phone']) return false;
        if (!empty($row['gateway_id'])) return hash_equals($row['gateway_id'], $data['id']);
        return (!empty($data['customer_reference']) && hash_equals($customerRef, (string) $data['customer_reference']))
            || ($providerRef !== '' && !empty($data['reference']) && hash_equals($providerRef, (string) $data['reference']));
    }

    public function processWebhook(array $body): array {
        $event = $body['event'] ?? '';
        $data = $body['data'] ?? null;
        $eventId = $body['id'] ?? '';
        if (!in_array($event, ['transaction.completed', 'transaction.failed', 'withdrawal.completed', 'withdrawal.failed'], true)) return ['ignored' => true];
        if (!is_array($data) || !is_string($eventId) || $eventId === '' || strlen($eventId) > 150) throw new InvalidArgumentException('Invalid webhook envelope.');
        if (($data['status'] ?? '') !== (str_ends_with($event, '.completed') ? 'completed' : 'failed')) throw new InvalidArgumentException('Webhook status mismatch.');
        $table = ($data['type'] ?? '') === 'deposit' ? 'payments' : (($data['type'] ?? '') === 'withdrawal' ? 'payouts' : '');
        if ($table === '' || (str_starts_with($event, 'withdrawal.') && $table !== 'payouts')) throw new InvalidArgumentException('Invalid webhook transaction type.');
        Database::beginTransaction();
        try {
            if (Database::fetchOne('SELECT event_id FROM abliner_webhook_events WHERE event_id = ?', [$eventId])) {
                Database::commit(); return ['duplicate' => true];
            }
            // Bind only to exact provider IDs or our unique per-transaction reference.
            $refColumn = $table === 'payments' ? 'order_id' : 'idempotency_key';
            $row = Database::fetchOne("SELECT * FROM $table WHERE gateway = 'abliner' AND (gateway_id = ? OR $refColumn = ?) FOR UPDATE",
                [(string) ($data['id'] ?? ''), (string) ($data['customer_reference'] ?? '')]);
            if (!$row || !$this->matches($table, $row, $data)) throw new RuntimeException('Matching Abliner transaction not found.');
            $this->applyTransaction($table, (int) $row['id'], $data, true);
            Database::insert('abliner_webhook_events', ['event_id' => $eventId]);
            Database::commit();
            return ['success' => true];
        } catch (Throwable $e) { if (Database::inTransaction()) Database::rollback(); throw $e; }
    }

    public function applyTransaction(string $table, int $id, array $data, bool $nested = false): void {
        if (!in_array($table, ['payments', 'payouts'], true)) throw new InvalidArgumentException('Invalid transaction kind.');
        if (!$nested) Database::beginTransaction();
        try {
            $row = Database::fetchOne("SELECT * FROM $table WHERE id = ? FOR UPDATE", [$id]);
            if (!$row || $row['gateway'] !== 'abliner' || !$this->matches($table, $row, $data)) throw new RuntimeException('Transaction identity mismatch.');
            $amount = isset($data['amount']) ? (float) $data['amount'] : (isset($data['amount_cents']) ? (float) $data['amount_cents'] / 100 : -1);
            if (($data['currency'] ?? '') !== 'TZS' || !is_finite($amount) || abs($amount - (float) $row['amount']) > 0.001) throw new RuntimeException('Transaction amount or currency mismatch.');
            $status = $data['status'] ?? '';
            if (!in_array($status, ['pending', 'processing', 'completed', 'failed'], true)) throw new RuntimeException('Unknown Abliner status; manual reconciliation required.');
            $status = $status === 'processing' ? 'pending' : $status;
            if ($row['status'] !== 'pending') {
                if ($row['status'] === 'failed' && $status === 'completed') throw new RuntimeException('Conflicting final states; manual reconciliation required.');
                if (!$nested) Database::commit();
                return; // Duplicate terminal events and late pending/failed events cannot change balances.
            }
            $changes = ['gateway_id' => $data['id'], 'status' => $status, 'metadata' => json_encode($data)];
            if ($table === 'payments') {
                $changes['gateway_reference'] = $data['reference'] ?? $row['gateway_reference'];
                $changes['transaction_ref'] = $data['reference'] ?? $row['transaction_ref'];
                $changes['completed_at'] = $status === 'completed' ? date('Y-m-d H:i:s') : null;
                if ($status === 'completed' && ($row['purpose'] ?? 'registration') === 'registration') {
                    $user = Database::fetchOne('SELECT status FROM users WHERE id = ? FOR UPDATE', [$row['user_id']]);
                    if (!$user) throw new RuntimeException('Payment user not found.');
                    if ($user['status'] === 'pending') {
                        Database::update('users', ['status' => 'active'], 'id = ?', [$row['user_id']]);
                        CommissionEngine::processRegistrationCommissions((int) $row['user_id']);
                        Database::insert('abliner_notifications', ['payment_id' => $row['id'], 'user_id' => $row['user_id'], 'amount' => $row['amount']]);
                    } elseif ($user['status'] !== 'active') throw new RuntimeException('Account cannot be activated automatically.');
                }
            } else {
                $changes['reference'] = $data['reference'] ?? $row['reference'];
                $changes['external_reference'] = $data['provider_reference'] ?? $row['external_reference'];
                if (isset($data['fee_tzs']) && is_numeric($data['fee_tzs']) && (float) $data['fee_tzs'] >= 0) $changes['fees'] = (float) $data['fee_tzs'];
                if (isset($data['total_debited_tzs']) && is_numeric($data['total_debited_tzs']) && (float) $data['total_debited_tzs'] >= (float) $row['amount']) $changes['total'] = (float) $data['total_debited_tzs'];
                if (in_array($status, ['completed', 'failed'], true)) $this->settleWithdrawal($row, $status);
            }
            Database::update($table, $changes, 'id = ?', [$id]);
            if (!$nested) Database::commit();
        } catch (Throwable $e) {
            if (!$nested && Database::inTransaction()) Database::rollback();
            throw $e;
        }
    }

    private function settleWithdrawal(array $payout, string $status): void {
        $wd = Database::fetchOne('SELECT * FROM withdrawals WHERE id = ? FOR UPDATE', [$payout['withdrawal_id']]);
        if (!$wd || $wd['gateway'] !== 'abliner' || !in_array($wd['status'], ['pending', 'approved', 'processing'], true)) throw new RuntimeException('Withdrawal hold is no longer available.');
        $wallet = Database::fetchOne('SELECT * FROM wallets WHERE user_id = ? FOR UPDATE', [$payout['user_id']]);
        $amount = (float) $payout['amount'];
        if (!$wallet || (float) $wallet['pending_amount'] < $amount) throw new RuntimeException('Wallet hold mismatch.');
        $balanceAfter = (float) $wallet['balance'] - ($status === 'completed' ? $amount : 0);
        $values = ['pending_amount' => (float) $wallet['pending_amount'] - $amount];
        if ($status === 'completed') {
            if ($balanceAfter < 0) throw new RuntimeException('Insufficient wallet balance for confirmed payout.');
            $values['balance'] = $balanceAfter;
            $values['total_withdrawn'] = (float) $wallet['total_withdrawn'] + $amount;
        } else $values['withdrawable_balance'] = (float) $wallet['withdrawable_balance'] + $amount;
        Database::update('wallets', $values, 'id = ?', [$wallet['id']]);
        Database::update('withdrawals', ['status' => $status, 'processed_at' => date('Y-m-d H:i:s')], 'id = ?', [$wd['id']]);
        Database::update('wallet_transactions', ['status' => $status, 'balance_after' => $balanceAfter],
            'reference_id = ? AND reference_type = ? AND user_id = ? AND status = ?', [$wd['id'], 'withdrawal', $wd['user_id'], 'pending']);
    }

    public function flushNotifications(): void {
        // Only cron sends email/SMS: webhook acknowledgements must not wait on external services.
        if (PHP_SAPI !== 'cli') return;
        for ($i = 0; $i < 20; $i++) {
            Database::beginTransaction();
            try {
                $row = Database::fetchOne('SELECT * FROM abliner_notifications WHERE sent_at IS NULL AND (claimed_at IS NULL OR claimed_at < ?) ORDER BY payment_id LIMIT 1 FOR UPDATE', [date('Y-m-d H:i:s', time() - 600)]);
                if (!$row) { Database::commit(); return; }
                Database::update('abliner_notifications', ['claimed_at' => date('Y-m-d H:i:s')], 'payment_id = ?', [$row['payment_id']]);
                Database::commit();
            } catch (Throwable $e) { if (Database::inTransaction()) Database::rollback(); throw $e; }
            try {
                notifyAccountActivated((int) $row['user_id'], (float) $row['amount']);
                Database::update('abliner_notifications', ['sent_at' => date('Y-m-d H:i:s')], 'payment_id = ?', [$row['payment_id']]);
            } catch (Throwable $e) { error_log('Abliner activation notification failed'); }
        }
    }
}
