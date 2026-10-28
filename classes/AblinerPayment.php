<?php
require_once __DIR__ . '/AblinerClient.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Wallet.php';
require_once __DIR__ . '/CommissionEngine.php';
require_once __DIR__ . '/CommissionPlan.php';
require_once __DIR__ . '/WithdrawalCharge.php';
require_once __DIR__ . '/StaffAccess.php';
require_once __DIR__ . '/PaymentNotifications.php';
require_once __DIR__.'/RegistrationPayment.php';

class AblinerPayment {
    private AblinerClient $client;

    public function __construct(?AblinerClient $client = null) { $this->client = $client ?? new AblinerClient(); }
    protected function gateway(): string { return 'abliner'; }
    public function validatePhone(string $phone): array {
        try { return ['valid' => true, 'phone' => AblinerClient::normalizePhone($phone)]; }
        catch (InvalidArgumentException $e) { return ['valid' => false, 'error' => $e->getMessage()]; }
    }

    public function initiatePayment(int $userId, string $phone, float $amount = 0, bool $confirmNumberChange = false, string $previousOrder = ''): array {
        return $this->startDeposit($userId, $phone, (float) app_setting('registration_fee', REGISTRATION_FEE), 'registration', $confirmNumberChange, $previousOrder);
    }

    public function initiateFunding(int $adminId, string $phone, float $amount, bool $confirmNumberChange = false, string $previousOrder = ''): array {
        return $this->startDeposit($adminId, $phone, $amount, 'admin_funding', $confirmNumberChange, $previousOrder);
    }

    private function startDeposit(int $userId, string $phone, float $amount, string $purpose, bool $confirmNumberChange = false, string $previousOrder = ''): array {
        if (!$this->client->ready()) return ['success' => false, 'error' => 'Abliner payments are not configured yet. Contact support.'];
        $plan = $purpose === 'registration' ? CommissionPlan::values() : null;
        if ($plan) $amount = (float) $plan['registration_fee'];
        $phone = AblinerClient::normalizePhone($phone);
        if (!is_finite($amount) || $amount < 500 || $amount > 3000000 || floor($amount) !== $amount) return ['success' => false, 'error' => 'Enter a whole TZS amount from 500 to 3,000,000.'];
        // Check an older request before deciding whether another push is safe.
        // Never expire an ambiguous payment locally or repeat a push after a timeout.
        $previous = Database::fetchOne("SELECT * FROM payments WHERE user_id = ? AND purpose = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [$userId, $purpose]);
        if ($previous && ($previous['gateway'] ?? '') === 'abliner') {
            $this->reconcile('payments', (int) $previous['id']);
            $checked = Database::fetchOne('SELECT * FROM payments WHERE id = ?', [$previous['id']]);
            if ($checked['status'] === 'completed') return $this->paymentResult($checked, 'Payment confirmed.');
        }
        Database::beginTransaction();
        try {
            $user = Database::fetchOne('SELECT * FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if (!$user || ($purpose === 'registration' && ($user['status'] !== 'pending' || $user['role'] !== 'user'))
                || ($purpose === 'admin_funding' && !StaffAccess::canForUser($userId, 'wallet_manage'))) throw new RuntimeException('Account cannot initiate this deposit.');
            $existing = Database::fetchOne("SELECT * FROM payments WHERE user_id = ? AND purpose = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [$userId, $purpose]);
            if ($existing) {
                if (($existing['gateway'] ?? 'snippe') !== 'abliner') {
                    Database::commit();
                    return ['success' => false, 'error' => 'An earlier payment needs reconciliation by support before starting an Abliner payment.'];
                }
                $switch = in_array($purpose, ['registration', 'admin_funding'], true) && $existing['phone'] !== $phone
                    && $confirmNumberChange && hash_equals($existing['order_id'], $previousOrder);
                if (!$switch) {
                    Database::commit();
                    if ($existing['phone'] !== $phone) return ['success' => false, 'error' => $purpose === 'admin_funding'
                        ? 'Your earlier company deposit is still pending. Tick the confirmation in the deposit form to use a different number.'
                        : 'To use another number, confirm below that you have not paid and will not approve the old prompt. Then submit again.'];
                    if ($purpose === 'admin_funding' && abs((float) $existing['amount'] - $amount) > 0.001) return ['success' => false, 'error' => 'This number already has a pending deposit of TZS ' . number_format((float) $existing['amount']) . '. Check that request before changing the amount. No new prompt was sent.'];
                    return $this->paymentResult($existing, 'Your earlier request is still pending. No new prompt was sent.');
                }
                // A deliberate switch keeps the previous request available for late callbacks.
                // Reusing a number with an unresolved request must never send another push.
                $samePhone = Database::fetchOne("SELECT * FROM payments WHERE user_id = ? AND purpose = ? AND status = 'pending' AND phone = ? ORDER BY id DESC LIMIT 1", [$userId, $purpose, $phone]);
                if ($samePhone) {
                    Database::commit();
                    return $this->paymentResult($samePhone, 'This number already has a pending request. No new prompt was sent.');
                }
            }
            $order = 'ES-' . bin2hex(random_bytes(12));
            $id = Database::insert('payments', ['user_id' => $userId, 'order_id' => $order, 'amount' => $amount,
                'currency' => 'TZS', 'phone' => $phone, 'payment_method' => 'mobile_money', 'status' => 'pending',
                'gateway' => 'abliner', 'idempotency_key' => $order, 'purpose' => $purpose,
                'metadata' => json_encode($plan ? ['commission_plan' => $plan] : [])]);
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

    public function requestWithdrawal(int $userId, float $amount, string $phone, string $expectedName = '', ?int $expectedCharge = null, ?string $requestKey = null): array {
        if (!$this->client->ready()) throw new RuntimeException('The selected withdrawal provider is not configured yet.');
        if (!is_finite($amount) || floor($amount) !== $amount || $amount < (float) app_setting('min_withdrawal', MIN_WITHDRAWAL)
            || $amount > (float) app_setting('max_withdrawal', MAX_WITHDRAWAL)) throw new InvalidArgumentException('Enter a whole TZS amount within the withdrawal limits.');
        if ($requestKey !== null && !preg_match('/^[a-f0-9]{64}$/D', $requestKey)) throw new InvalidArgumentException('Invalid withdrawal request token.');
        $quote = WithdrawalCharge::quote($amount);
        if ($expectedCharge !== null && $expectedCharge !== $quote['withdrawal_charge']) throw new RuntimeException('The withdrawal charge has changed. Reload the page and confirm the new amount.');
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
            $user = Database::fetchOne('SELECT status, role FROM users WHERE id = ? FOR UPDATE', [$userId]);
            if (!$user || $user['status'] !== 'active' || $user['role'] !== 'user') throw new RuntimeException('An active user account is required.');
            $wallet = Database::fetchOne('SELECT * FROM wallets WHERE user_id = ? FOR UPDATE', [$userId]);
            if ($requestKey !== null) {
                $prior = Database::fetchOne('SELECT * FROM withdrawals WHERE user_id = ? AND request_key = ?', [$userId, $requestKey]);
                if ($prior) {
                    if ((float) $prior['amount'] !== $amount || $prior['phone'] !== $phone || $prior['gateway'] !== $this->gateway()) throw new RuntimeException('This withdrawal token was already used with different details. Reload the form.');
                    Database::commit();
                    return ['success' => true, 'withdrawal_id' => $prior['id'], 'payout_status' => $prior['status'] === 'processing' ? 'pending' : $prior['status'],
                        'amount' => (float) $prior['amount'], 'withdrawal_charge' => (float) $prior['withdrawal_charge'],
                        'net_amount' => (float) $prior['amount'] - (float) $prior['withdrawal_charge'], 'phone' => $prior['phone'],
                        'message' => 'Existing withdrawal retained. No second transfer was sent.'];
                }
            }
            if ($requestKey !== null && Database::fetchOne("SELECT id FROM withdrawals WHERE user_id = ? AND gateway IN ('abliner','snippe') AND status IN ('pending','approved','processing') LIMIT 1", [$userId])) {
                throw new RuntimeException('An earlier withdrawal is still being verified. Check its status before submitting another withdrawal.');
            }
            // withdrawable_balance already excludes held withdrawals: do not subtract pending twice.
            if (!$wallet || $amount > (float) $wallet['withdrawable_balance']) throw new RuntimeException('Insufficient available commission earnings.');
            $withdrawal = Database::insert('withdrawals', ['user_id' => $userId, 'amount' => $amount, 'phone' => $phone,
                'payment_method' => 'mobile_money', 'status' => 'pending', 'gateway' => $this->gateway(),
                'request_key' => $requestKey, 'created_at' => date('Y-m-d H:i:s'), 'withdrawal_charge' => $quote['withdrawal_charge'], 'recipient_name' => $lookup['data']['name'], 'recipient_network' => $lookup['data']['network']]);
            Database::update('wallets', ['withdrawable_balance' => (float) $wallet['withdrawable_balance'] - $amount,
                'pending_amount' => (float) $wallet['pending_amount'] + $amount], 'id = ?', [$wallet['id']]);
            Database::insert('wallet_transactions', ['wallet_id' => $wallet['id'], 'user_id' => $userId, 'type' => 'withdrawal',
                'amount' => -$amount, 'balance_before' => $wallet['balance'], 'balance_after' => $wallet['balance'],
                'description' => 'Mobile money withdrawal; charge TZS ' . $quote['withdrawal_charge'] . '; recipient receives TZS ' . $quote['net_amount'], 'status' => 'pending',
                'reference_id' => $withdrawal, 'reference_type' => 'withdrawal']);
            Database::commit();
        } catch (Throwable $e) { if (Database::inTransaction()) Database::rollback(); throw $e; }
        $result = $this->sendPayout($withdrawal, $userId, $amount, $phone, $lookup['data']['name']);
        return ['success' => true, 'withdrawal_id' => $withdrawal, 'payout_status' => $result['status'] ?? 'pending',
            'amount' => $amount, 'withdrawal_charge' => $quote['withdrawal_charge'], 'net_amount' => $quote['net_amount'], 'phone' => $phone, 'recipient_name' => $lookup['data']['name'], 'network' => $lookup['data']['network'],
            'fees' => $result['fees'] ?? 0, 'reference' => $result['reference'] ?? null,
            'message' => $result['error'] ?? ($result['status'] === 'completed' ? 'Money has been sent to your mobile money account.' : 'Withdrawal is awaiting confirmation. Do not submit it again.')];
    }

    public function sendPayout(int $withdrawalId, int $userId, float $amount = 0, string $phone = '', string $name = ''): array {
        if (!$this->client->ready()) throw new RuntimeException('The selected withdrawal provider is not configured yet.');
        Database::beginTransaction();
        try {
            $wd = Database::fetchOne('SELECT * FROM withdrawals WHERE id = ? AND user_id = ? FOR UPDATE', [$withdrawalId, $userId]);
            if (!$wd || ($wd['gateway'] ?? '') !== $this->gateway()) throw new RuntimeException('Legacy withdrawals must be reconciled with their original provider.');
            $existing = Database::fetchOne('SELECT * FROM payouts WHERE withdrawal_id = ? ORDER BY id DESC LIMIT 1', [$withdrawalId]);
            if ($existing) {
                Database::commit();
                return ['success' => true, 'payout_id' => $existing['id'], 'status' => $existing['status'], 'reference' => $existing['reference'],
                    'fees' => $existing['fees'], 'message' => 'Existing payout retained. Verify it; no second transfer was sent.'];
            }
            if (!in_array($wd['status'], ['pending', 'approved'], true) || empty($wd['recipient_name'])) throw new RuntimeException('Withdrawal cannot be dispatched.');
            $netAmount = (float) $wd['amount'] - (float) ($wd['withdrawal_charge'] ?? 0);
            if ($netAmount <= 0) throw new RuntimeException('Invalid withdrawal charge.');
            $key = ($this->gateway()==='snippe'?'SN-WD-':'ES-WD-') . $withdrawalId . '-' . bin2hex(random_bytes($this->gateway()==='snippe'?6:8));
            $id = Database::insert('payouts', ['user_id' => $userId, 'withdrawal_id' => $withdrawalId,
                'amount' => $netAmount, 'total' => $netAmount, 'channel' => 'mobile', 'provider' => $wd['recipient_network'],
                'recipient_phone' => $wd['phone'], 'recipient_name' => $wd['recipient_name'], 'status' => 'pending',
                'gateway' => $this->gateway(), 'idempotency_key' => $key, 'created_at' => date('Y-m-d H:i:s')]);
            Database::update('withdrawals', ['status' => 'processing'], 'id = ?', [$withdrawalId]);
            Database::commit();
        } catch (Throwable $e) { if (Database::inTransaction()) Database::rollback(); throw $e; }
        $payload = ['amount' => (int) $netAmount, 'method' => 'mobile', 'currency' => 'TZS',
            'recipient' => $wd['phone'], 'reference' => $key, 'callback_url' => $this->client->callbackUrl()];
        if ($this->gateway()==='snippe') $payload['recipient_name']=$wd['recipient_name'];
        $result = $this->client->withdraw($payload, $key);
        try {
            $this->handleSubmission('payouts', $id, $result);
        } catch (RuntimeException $e) {
            // Money may already have moved. Retain the hold and the evidence for reconciliation.
            $row = Database::fetchOne('SELECT * FROM payouts WHERE id = ?', [$id]);
            Database::update('payouts', ['metadata' => $this->metadata($row, ['submission_response' => $result['data'] ?? [], 'verification_error' => $e->getMessage()])], 'id = ? AND status = ?', [$id, 'pending']);
            error_log('Abliner payout #' . $id . ' requires verification: ' . $e->getMessage());
            $result = ['success' => false, 'error' => 'The transfer was submitted but its response could not be verified. Your funds are reserved. Check withdrawal status; do not submit another payout.'];
        }
        $payout = Database::fetchOne('SELECT * FROM payouts WHERE id = ?', [$id]);
        if ($payout['status'] === 'pending') {
            try { $this->reconcile('payouts', $id); }
            catch (Throwable $e) { error_log('Abliner immediate payout verification #' . $id . ': ' . $e->getMessage()); }
            $payout = Database::fetchOne('SELECT * FROM payouts WHERE id = ?', [$id]);
        }
        if ($payout['status'] === 'completed') $result = ['success' => true];
        if ($this->gateway()==='snippe' && $payout['status']==='failed') {
            $failure=json_decode($payout['metadata']??'{}',true)?:[];
            $result['error']=SnippeErrors::message((string)($failure['failure_code']??$failure['code']??'snippe_error'));
        }
        return ['success' => $result['success'], 'payout_id' => $id, 'status' => $payout['status'], 'reference' => $payout['reference'],
            'fees' => $payout['fees'], 'error' => $result['error'] ?? null];
    }

    private function metadata(array $row, array $provider): string {
        $stored = json_decode($row['metadata'] ?? '{}', true) ?: [];
        unset($provider['commission_plan']);
        if (isset($stored['commission_plan'])) $provider['commission_plan'] = $stored['commission_plan'];
        foreach (['superseded_by_payment', 'local_failure_reason'] as $key) {
            unset($provider[$key]);
            if (isset($stored[$key])) $provider[$key] = $stored[$key];
        }
        return json_encode($provider, JSON_THROW_ON_ERROR);
    }

    /** Bind only an authenticated response to the exact outbound POST (or its saved evidence).
     * Never use this method for a webhook or arbitrary transaction search result.
     */
    private function bindSubmissionIdentity(string $table, int $id, array $data): void {
        if (!in_array($table, ['payments','payouts'], true)) throw new InvalidArgumentException('Invalid transaction kind.');
        $expectedType = $table === 'payments' ? 'deposit' : 'withdrawal';
        if (($data['type'] ?? '') !== $expectedType || !is_string($data['id'] ?? null)
            || $data['id'] === '' || strlen($data['id']) > 100) throw new RuntimeException('Invalid provider submission identity.');
        Database::beginTransaction();
        try {
            $row = Database::fetchOne("SELECT * FROM $table WHERE id = ? FOR UPDATE", [$id]);
            if (!$row || $row['gateway'] !== $this->gateway()) throw new RuntimeException('Submission record not found.');
            $ourReference = $table === 'payments' ? $row['order_id'] : $row['idempotency_key'];
            if (!empty($data['customer_reference']) && (!is_string($data['customer_reference']) || !hash_equals($ourReference, $data['customer_reference']))) throw new RuntimeException('Submission customer reference mismatch.');
            if ($table === 'payouts' && !empty($data['recipient']) && AblinerClient::normalizePhone((string) $data['recipient']) !== $row['recipient_phone']) throw new RuntimeException('Submission recipient mismatch.');
            if (!empty($row['gateway_id']) && !hash_equals($row['gateway_id'], $data['id'])) throw new RuntimeException('Submission provider ID changed.');
            $other = Database::fetchOne("SELECT id FROM $table WHERE gateway = ? AND gateway_id = ? AND id <> ? LIMIT 1", [$this->gateway(), $data['id'], $id]);
            if ($other) throw new RuntimeException('Submission provider ID belongs to another transaction.');
            Database::update($table, ['gateway_id' => $data['id']], 'id = ?', [$id]);
            Database::commit();
        } catch (Throwable $e) { if (Database::inTransaction()) Database::rollback(); throw $e; }
    }

    private function handleSubmission(string $table, int $id, array $result): void {
        if ($result['success']) {
            // Direct HTTP response is tied to this POST; provider references may be generated.
            $this->bindSubmissionIdentity($table, $id, $result['data']);
            // Identity binding is not payment confirmation: validate amount, currency and state.
            $this->applyTransaction($table, $id, $result['data']);
        } elseif (!empty($result['uncertain'])) {
            // A timeout, 5xx or transaction_id may mean a transfer exists. Keep the hold.
            Database::update($table, ['metadata' => $this->metadata(Database::fetchOne("SELECT * FROM $table WHERE id = ?", [$id]), ['code' => $result['code'], 'http_status' => $result['http_status'] ?? null, 'message' => $result['error'],
                'transaction_id' => $result['transaction_id'] ?? null])], 'id = ? AND status = ?', [$id, 'pending']);
        } else {
            Database::beginTransaction();
            try {
                $row = Database::fetchOne("SELECT * FROM $table WHERE id = ? FOR UPDATE", [$id]);
                if ($row['status'] === 'pending') {
                    if ($table === 'payouts') $this->settleWithdrawal($row, 'failed');
                    Database::update($table, ['status' => 'failed', 'metadata' => $this->metadata($row, ['code' => $result['code'], 'http_status' => $result['http_status'] ?? null, 'message' => $result['error']])], 'id = ?', [$id]);
                }
                Database::commit();
            } catch (Throwable $e) { if (Database::inTransaction()) Database::rollback(); throw $e; }
        }
    }

    public function reconcile(string $table, int $id): array {
        if (!in_array($table, ['payments', 'payouts'], true)) throw new InvalidArgumentException('Invalid transaction kind.');
        $row = Database::fetchOne("SELECT * FROM $table WHERE id = ?", [$id]);
        if (!$row || ($row['gateway'] ?? '') !== $this->gateway()) return ['success' => false, 'error' => 'Reconcile this legacy transaction with its original provider.'];
        if ($row['status'] !== 'pending') return ['success' => true, 'status' => $row['status']];
        if ($table === 'payouts' && empty($row['gateway_id'])) {
            $evidence = json_decode($row['metadata'] ?? '{}', true) ?: [];
            if (!empty($evidence['submission_response']) && is_array($evidence['submission_response'])) {
                try { $this->bindSubmissionIdentity($table, $id, $evidence['submission_response']); }
                catch (RuntimeException $e) { error_log('Saved payout identity could not be bound #' . $id . ': ' . $e->getMessage()); }
                $row = Database::fetchOne("SELECT * FROM $table WHERE id = ?", [$id]);
            }
        }
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
        $message = $found ? ($current['status'] === 'pending' ? 'The provider still reports this request as pending. No new prompt was sent. If no prompt arrived, contact support with the order ID before paying again.' : 'Status checked with the provider.') : 'The provider has not returned a matching transaction yet. No new prompt was sent. Contact support with the order ID before another attempt.';
        if ($table === 'payouts') $message = $found ? ($current['status'] === 'pending' ? 'The provider still reports the transfer as pending. Your funds remain reserved; do not submit another payout.' : 'Transfer status verified with the provider.') : 'No matching transfer was returned by the provider. Funds remain reserved; contact support before another withdrawal.';
        return ['success' => true, 'status' => $current['status'], 'found' => $found, 'message' => $message, 'settlement_review' => (json_decode($current['metadata'] ?? '{}', true) ?: [])['settlement_review'] ?? null];
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
            || ($table === 'payouts' && !empty($data['reference']) && hash_equals($customerRef, (string) $data['reference']))
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
            $lookup = [ (string) ($data['id'] ?? ''), (string) ($data['customer_reference'] ?? '') ];
            $extra = '';
            if ($table === 'payouts') { $extra = ' OR idempotency_key = ?'; $lookup[] = (string) ($data['reference'] ?? ''); }
            $row = Database::fetchOne("SELECT * FROM $table WHERE gateway = 'abliner' AND (gateway_id = ? OR $refColumn = ?$extra) FOR UPDATE", $lookup);
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
            if (!$row || $row['gateway'] !== $this->gateway() || !$this->matches($table, $row, $data)) throw new RuntimeException('Transaction identity mismatch.');
            $rawAmount = $data['amount'] ?? (isset($data['amount_cents']) && is_numeric($data['amount_cents']) ? (float) $data['amount_cents'] / 100 : null);
            $amount = is_numeric($rawAmount) ? (float) $rawAmount : -1;
            $amountValid = is_finite($amount) && $amount > 0;
            if (isset($data['amount_cents']) && (!is_numeric($data['amount_cents']) || abs((float) $data['amount_cents'] / 100 - $amount) > 0.001)) $amountValid = false;
            $amountDiffers = abs($amount - (float) $row['amount']) > 0.001;
            $completedPayout = $table === 'payouts' && ($data['status'] ?? '') === 'completed';
            $payoutAmountMayDiffer = $table === 'payouts' && in_array($data['status'] ?? '', ['pending','processing','completed'], true);
            if (($data['currency'] ?? '') !== 'TZS' || !$amountValid || ($amountDiffers && !$payoutAmountMayDiffer)) {
                $comparison = ['expected_amount_tzs' => (float) $row['amount'], 'expected_currency' => 'TZS',
                    'provider_amount' => $data['amount'] ?? null, 'provider_amount_cents' => $data['amount_cents'] ?? null,
                    'provider_currency' => $data['currency'] ?? null, 'provider_status' => $data['status'] ?? null,
                    'provider_fee_tzs' => $data['fee_tzs'] ?? null, 'provider_total_debited_tzs' => $data['total_debited_tzs'] ?? null];
                throw new RuntimeException('Transaction amount or currency mismatch: ' . json_encode($comparison, JSON_INVALID_UTF8_SUBSTITUTE));
            }
            $status = $data['status'] ?? '';
            if (!in_array($status, ['pending', 'processing', 'completed', 'failed'], true)) throw new RuntimeException('Unknown Abliner status; manual reconciliation required.');
            $status = $status === 'processing' ? 'pending' : $status;
            if ($row['status'] !== 'pending') {
                $storedState = json_decode($row['metadata'] ?? '{}', true) ?: [];
                $lateReplacedPayment = $table === 'payments' && $row['status'] === 'failed' && $status === 'completed'
                    && !empty($storedState['superseded_by_payment']);
                if ($row['status'] === 'failed' && $status === 'completed' && !$lateReplacedPayment) throw new RuntimeException('Conflicting final states; manual reconciliation required.');
                if (!$lateReplacedPayment) {
                    if (!$nested) Database::commit();
                    return; // Ignore duplicate terminal events; retain late money on replaced requests.
                }
            }
            if ($completedPayout) {
                // A verified completed transfer must never remain available for retry merely
                // because the provider reports a different amount. Do not invent a fee.
                $data['settlement_review'] = ['requested_tzs' => (float) $row['amount'], 'provider_reported_tzs' => $amount,
                    'difference_tzs' => (float) $row['amount'] - $amount, 'requires_review' => $amountDiffers];
            }
            $changes = ['gateway_id' => $data['id'], 'status' => $status, 'metadata' => $this->metadata($row, $data)];
            if ($table === 'payments') {
                $changes['gateway_reference'] = $data['reference'] ?? $row['gateway_reference'];
                $changes['transaction_ref'] = $data['reference'] ?? $row['transaction_ref'];
                $changes['completed_at'] = $status === 'completed' ? date('Y-m-d H:i:s') : null;
                if ($status === 'completed' && ($row['purpose'] ?? 'registration') === 'registration') {
                    RegistrationPayment::activate($row);
                }
            } else {
                $changes['reference'] = $data['reference'] ?? $row['reference'];
                $changes['external_reference'] = $data['provider_reference'] ?? $row['external_reference'];
                if (isset($data['fee_tzs']) && is_numeric($data['fee_tzs']) && (float) $data['fee_tzs'] >= 0) $changes['fees'] = (float) $data['fee_tzs'];
                if (isset($data['total_debited_tzs']) && is_numeric($data['total_debited_tzs']) && (float) $data['total_debited_tzs'] >= (float) $row['amount']) $changes['total'] = (float) $data['total_debited_tzs'];
                if (in_array($status, ['completed', 'failed'], true)) $this->settleWithdrawal($row, $status, $amount);
            }
            Database::update($table, $changes, 'id = ?', [$id]);
            if (!$nested) Database::commit();
        } catch (Throwable $e) {
            if (!$nested && Database::inTransaction()) Database::rollback();
            throw $e;
        }
    }

    private function settleWithdrawal(array $payout, string $status, ?float $received = null): void {
        $wd = Database::fetchOne('SELECT * FROM withdrawals WHERE id = ? FOR UPDATE', [$payout['withdrawal_id']]);
        if (!$wd || $wd['gateway'] !== $this->gateway() || !in_array($wd['status'], ['pending', 'approved', 'processing'], true)) throw new RuntimeException('Withdrawal hold is no longer available.');
        $wallet = Database::fetchOne('SELECT * FROM wallets WHERE user_id = ? FOR UPDATE', [$payout['user_id']]);
        $amount = (float) $wd['amount']; // The held wallet debit includes the user's fixed charge.
        $net = $amount - (float) ($wd['withdrawal_charge'] ?? 0);
        if (abs($net - (float) $payout['amount']) > 0.001) throw new RuntimeException('Payout and withdrawal charge do not match.');
        if (!$wallet || (float) $wallet['pending_amount'] < $amount) throw new RuntimeException('Wallet hold mismatch.');
        $balanceAfter = (float) $wallet['balance'] - ($status === 'completed' ? $amount : 0);
        $values = ['pending_amount' => (float) $wallet['pending_amount'] - $amount];
        if ($status === 'completed') {
            if ($balanceAfter < 0) {
                $adjustments=Database::fetchOne("SELECT COALESCE(SUM(amount),0) AS total FROM wallet_transactions WHERE user_id = ? AND type = 'commission' AND reference_type = 'commission_reconciliation' AND status = 'completed'",[$payout['user_id']]);
                if ($balanceAfter-(float)$adjustments['total'] < 0) throw new RuntimeException('Insufficient wallet balance for confirmed payout.');
            }
            $values['balance'] = $balanceAfter;
            $values['total_withdrawn'] = (float) $wallet['total_withdrawn'] + $amount;
        } else $values['withdrawable_balance'] = (float) $wallet['withdrawable_balance'] + $amount;
        Database::update('wallets', $values, 'id = ?', [$wallet['id']]);
        if ($status === 'completed') PaymentNotifications::withdrawal($wd, $received ?? (float) $payout['amount']);
        Database::update('withdrawals', ['status' => $status, 'processed_at' => date('Y-m-d H:i:s')], 'id = ?', [$wd['id']]);
        Database::update('wallet_transactions', ['status' => $status, 'balance_after' => $balanceAfter],
            'reference_id = ? AND reference_type = ? AND user_id = ? AND status = ?', [$wd['id'], 'withdrawal', $wd['user_id'], 'pending']);
    }

    public function flushNotifications(int $limit = 1): void {
        // Financial settlement is already committed. SMS must not wait for email delivery.
        ignore_user_abort(true);
        PaymentNotifications::dispatch($limit);
        for ($i = 0; $i < max(0, min(20, $limit)); $i++) {
            Database::beginTransaction();
            try {
                $row = Database::fetchOne('SELECT * FROM abliner_notifications WHERE sent_at IS NULL AND (claimed_at IS NULL OR claimed_at < ?) ORDER BY payment_id LIMIT 1 FOR UPDATE', [date('Y-m-d H:i:s', time() - 600)]);
                if (!$row) { Database::commit(); break; }
                Database::update('abliner_notifications', ['claimed_at' => date('Y-m-d H:i:s')], 'payment_id = ?', [$row['payment_id']]);
                Database::commit();
            } catch (Throwable $e) { if (Database::inTransaction()) Database::rollback(); throw $e; }
            try {
                notifyAccountActivated((int) $row['user_id'], (float) $row['amount'], false);
                Database::update('abliner_notifications', ['sent_at' => date('Y-m-d H:i:s')], 'payment_id = ?', [$row['payment_id']]);
            } catch (Throwable $e) { error_log('Abliner activation notification failed'); }
        }
    }
}
