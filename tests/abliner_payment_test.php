<?php
// Offline integration tests: an in-memory database and a simulated HTTP transport.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../classes/AblinerPayment.php';
(new ReflectionProperty(ErrorLogger::class, 'isLogging'))->setValue(null, true);

function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rejects(callable $action, string $message): void {
    try { $action(); } catch (Throwable $e) { return; }
    throw new RuntimeException($message);
}
// SQLite exercises atomicity; production row locks use MySQL's FOR UPDATE.
class TestPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}
$db = new TestPDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
(new ReflectionProperty(Database::class, 'instance'))->setValue(null, $db);
$schema = [
    'settings' => 'id INTEGER PRIMARY KEY, setting_key TEXT, setting_value TEXT',
    'users' => 'id INTEGER PRIMARY KEY, status TEXT, role TEXT DEFAULT "user", referred_by INTEGER',
    'wallets' => 'id INTEGER PRIMARY KEY, user_id INTEGER UNIQUE, balance REAL DEFAULT 0, withdrawable_balance REAL DEFAULT 0, pending_amount REAL DEFAULT 0, total_earned REAL DEFAULT 0, total_withdrawn REAL DEFAULT 0',
    'payments' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, order_id TEXT UNIQUE, purpose TEXT DEFAULT "registration", amount REAL, currency TEXT, phone TEXT, payment_method TEXT, status TEXT, gateway TEXT DEFAULT "snippe", gateway_id TEXT, gateway_reference TEXT, transaction_ref TEXT, idempotency_key TEXT, metadata TEXT, completed_at TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP',
    'withdrawals' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, amount REAL, phone TEXT, payment_method TEXT, status TEXT, gateway TEXT, recipient_name TEXT, recipient_network TEXT, processed_at TEXT',
    'payouts' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, withdrawal_id INTEGER, amount REAL, total REAL, fees REAL DEFAULT 0, channel TEXT, provider TEXT, recipient_phone TEXT, recipient_name TEXT, status TEXT, gateway TEXT, gateway_id TEXT, reference TEXT, external_reference TEXT, idempotency_key TEXT, metadata TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP',
    'wallet_transactions' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, wallet_id INTEGER, user_id INTEGER, type TEXT, amount REAL, balance_before REAL, balance_after REAL, description TEXT, status TEXT, reference_id INTEGER, reference_type TEXT',
    'commissions' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, earner_id INTEGER, source_user_id INTEGER, level INTEGER, amount REAL, status TEXT, wallet_transaction_id INTEGER',
    'activity_logs' => 'id INTEGER PRIMARY KEY, user_id INTEGER, action TEXT, description TEXT, ip_address TEXT, user_agent TEXT',
    'abliner_webhook_events' => 'event_id TEXT PRIMARY KEY',
    'abliner_notifications' => 'payment_id INTEGER PRIMARY KEY, user_id INTEGER, amount REAL, claimed_at TEXT, sent_at TEXT',
];
foreach ($schema as $table => $columns) $db->exec("CREATE TABLE $table ($columns)");
$db->exec("INSERT INTO users(id,status,role,referred_by) VALUES (1,'pending','user',2),(2,'active','user',NULL),(3,'active','admin',NULL),(4,'pending','user',NULL),(5,'pending','user',NULL),(6,'pending','user',NULL)");
$db->exec("INSERT INTO settings(setting_key, setting_value) VALUES ('registration_fee','12000'),('commission_l1','2500'),('commission_l2','1500'),('commission_l3','9000')");
$db->exec('INSERT INTO wallets(id,user_id,balance,withdrawable_balance) VALUES (2,2,50000,50000)');

class MockAbliner extends AblinerClient {
    public array $calls = [];
    public string $mode = 'pending';
    public array $transactions = [];
    public bool $lookupMissing = false;
    public function __construct() { parent::__construct(['api_key' => 'test-key', 'webhook_secret' => 'test-secret', 'base_url' => 'https://abliner.net', 'callback_url' => 'https://example.test/webhooks/abliner.php']); }
    protected function sendHttp(string $method, string $url, array $headers, string $body): array {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        if ($method === 'POST') {
            $map = [];
            foreach ($headers as $header) { [$key,$value] = explode(': ', $header, 2); $map[strtolower($key)] = $value; }
            expect(isset($map['idempotency-key']), 'Missing idempotency key');
            expect(hash_equals(hash_hmac('sha256', $map['x-abliner-timestamp'] . '.' . $body, 'test-secret'), $map['x-abliner-signature']), 'Outbound signature does not match exact request bytes');
        }
        if (str_contains($url, '/lookup?')) {
            if ($this->lookupMissing) return ['http_code' => 404, 'error' => '', 'body' => json_encode(['status' => 'error', 'code' => 'name_not_found', 'message' => 'Number not found.'])];
            return $this->ok(['phone' => '255712345678', 'name' => 'TEST RECIPIENT', 'network' => 'vodacom']);
        }
        if (str_contains($url, '/preview?')) return $this->ok(['amount_tzs' => 5000, 'fee_tzs' => 100, 'total_debited_tzs' => 5100]);
        if (str_contains($url, '/transactions?')) return $this->ok($this->transactions);
        if (str_ends_with($url, '/balance')) return $this->ok(['balance' => 1250, 'currency' => 'TZS', 'balances' => ['TZS' => 1250, 'USD' => 0]]);
        if ($this->mode === 'timeout') return ['http_code' => 0, 'error' => 'timeout', 'body' => ''];
        if ($this->mode === 'html') return ['http_code' => 502, 'error' => '', 'body' => '<html>Upstream error</html>'];
        if ($this->mode === 'reject') return ['http_code' => 402, 'error' => '', 'body' => json_encode(['status' => 'error', 'code' => 'insufficient_funds', 'message' => 'Insufficient balance.', 'retryable' => false])];
        $payload = json_decode($body, true);
        $type = str_ends_with($url, '/deposits') ? 'deposit' : 'withdrawal';
        return $this->ok(['id' => 'tx-' . $payload['reference'], 'type' => $type, 'status' => $this->mode,
            'amount' => $payload['amount'], 'currency' => $payload['currency'], 'customer_reference' => $payload['reference'],
            'reference' => 'ABL-' . $payload['reference']], 201);
    }
    private function ok(array $data, int $code = 200): array { return ['http_code' => $code, 'error' => '', 'body' => json_encode(['status' => 'success', 'data' => $data])]; }
    public function postCount(): int { return count(array_filter($this->calls, fn($c) => $c['method'] === 'POST')); }
}
function eventFor(array $data, string $eventId): array {
    return ['id' => $eventId, 'event' => ($data['type'] === 'deposit' ? 'transaction.' : 'withdrawal.') . $data['status'], 'data' => $data];
}
function transactionFor(string $table, int $id, string $status): array {
    $row = Database::fetchOne("SELECT * FROM $table WHERE id = ?", [$id]);
    $ref = $table === 'payments' ? $row['order_id'] : $row['idempotency_key'];
    return ['id' => $row['gateway_id'] ?: 'tx-' . $ref, 'type' => $table === 'payments' ? 'deposit' : 'withdrawal',
        'amount' => (float) $row['amount'], 'currency' => 'TZS', 'status' => $status, 'reference' => 'ABL-' . $ref, 'customer_reference' => $ref];
}
$client = new MockAbliner();
$service = new AblinerPayment($client);
expect(AblinerClient::normalizePhone('+255 712 345 678') === '255712345678', 'Normalization failed');
expect(AblinerClient::normalizePhone('0712345678') === '255712345678', 'Local normalization failed');
rejects(fn() => AblinerClient::normalizePhone('255123'), 'Invalid number accepted');
expect($client->balance()['data']['balances']['TZS'] === 1250, 'Balance response was not parsed');

// A pending deposit does not activate the account; a repeated submission sends no push.
$deposit = $service->initiatePayment(1, '0712345678');
expect($deposit['success'], 'Deposit creation failed');
expect(Database::fetchOne('SELECT status FROM users WHERE id = 1')['status'] === 'pending', 'Pending deposit activated account');
$before = $client->postCount();
$again = $service->initiatePayment(1, '0712345678');
expect($again['order_id'] === $deposit['order_id'] && $client->postCount() === $before, 'Duplicate deposit sent another push');
$completed = transactionFor('payments', $deposit['payment_id'], 'completed');
$badAmount = $completed; $badAmount['amount'] = 1;
rejects(fn() => $service->processWebhook(eventFor($badAmount, 'bad-amount')), 'Wrong amount credited');
expect(Database::count('abliner_webhook_events') === 0, 'Failed event was acknowledged');
$badCurrency = $completed; $badCurrency['currency'] = 'USD';
rejects(fn() => $service->processWebhook(eventFor($badCurrency, 'bad-currency')), 'Wrong currency credited');
$service->processWebhook(eventFor($completed, 'deposit-complete'));
expect(Database::fetchOne('SELECT status FROM users WHERE id = 1')['status'] === 'active', 'Completed deposit did not activate user');
expect(Database::count('commissions') === 1, 'Registration commission missing');
$wallet = Database::fetchOne('SELECT * FROM wallets WHERE user_id = 2');
expect((float) $wallet['balance'] === 52500.0, 'Referrer commission did not use admin settings');
$service->processWebhook(eventFor($completed, 'deposit-complete'));
$service->processWebhook(eventFor($completed, 'deposit-complete-different-event'));
expect(Database::count('commissions') === 1, 'Duplicate callbacks paid commissions twice');
$late = $completed; $late['status'] = 'failed';
$service->processWebhook(eventFor($late, 'late-failure'));
expect(Database::fetchOne('SELECT status FROM payments WHERE id = ?', [$deposit['payment_id']])['status'] === 'completed', 'Late failure regressed payment');

// A crash during commissions rolls back payment + activation + event receipt together.
$rollbackDeposit = $service->initiatePayment(4, '0712345678');
$db->exec('UPDATE users SET referred_by = 2 WHERE id = 4');
$db->exec("CREATE TRIGGER reject_commission BEFORE INSERT ON commissions BEGIN SELECT RAISE(ABORT, 'simulated failure'); END");
$rollbackEvent = eventFor(transactionFor('payments', $rollbackDeposit['payment_id'], 'completed'), 'rollback-event');
rejects(fn() => $service->processWebhook($rollbackEvent), 'Simulated accounting failure ignored');
expect(Database::fetchOne('SELECT status FROM users WHERE id = 4')['status'] === 'pending', 'Activation was not rolled back');
expect(!Database::fetchOne('SELECT event_id FROM abliner_webhook_events WHERE event_id = ?', ['rollback-event']), 'Failed transaction event retained');
$db->exec('DROP TRIGGER reject_commission');
$service->processWebhook($rollbackEvent);
expect(Database::fetchOne('SELECT status FROM users WHERE id = 4')['status'] === 'active', 'Retry after rollback did not activate user');

// Timeout recovery uses exact reference lookup and never automatically starts a new push.
$client->mode = 'timeout';
$uncertain = $service->initiatePayment(5, '0712345678');
$before = $client->postCount();
$service->initiatePayment(5, '0712345678');
expect($client->postCount() === $before, 'Timeout sent another push');
$client->transactions = [transactionFor('payments', $uncertain['payment_id'], 'completed')];
$service->reconcile('payments', $uncertain['payment_id']);
expect(Database::fetchOne('SELECT status FROM users WHERE id = 5')['status'] === 'active', 'Timeout payment was not reconciled');
$client->mode = 'html';
$html = $service->initiatePayment(6, '0712345678');
expect($html['success'] && $html['status'] === 'pending', 'Non-JSON response released uncertain payment');

// Name lookup failure and a name change must stop withdrawal before any hold or transfer.
$client->mode = 'pending'; $client->lookupMissing = true;
$before = Database::count('withdrawals');
rejects(fn() => $service->requestWithdrawal(2, 5000, '0712345678'), 'Unverified recipient accepted');
expect(Database::count('withdrawals') === $before, 'Lookup failure created withdrawal');
$client->lookupMissing = false;
rejects(fn() => $service->requestWithdrawal(2, 5000, '0712345678', 'DIFFERENT NAME'), 'Changed recipient accepted');
$wd = $service->requestWithdrawal(2, 5000, '0712345678', 'TEST RECIPIENT');
$payout = Database::fetchOne('SELECT * FROM payouts WHERE withdrawal_id = ?', [$wd['withdrawal_id']]);
$heldWallet = Database::fetchOne('SELECT * FROM wallets WHERE user_id = 2');
expect((float) $heldWallet['pending_amount'] === 5000.0, 'Withdrawal hold missing');
$before = $client->postCount();
$service->sendPayout($wd['withdrawal_id'], 2);
expect($client->postCount() === $before, 'Repeated payout dispatch sent money twice');
$payoutComplete = transactionFor('payouts', $payout['id'], 'completed');
$service->processWebhook(eventFor($payoutComplete, 'payout-complete'));
$service->processWebhook(eventFor($payoutComplete, 'payout-complete-again'));
$afterWallet = Database::fetchOne('SELECT * FROM wallets WHERE user_id = 2');
expect((float) $afterWallet['balance'] === (float) $heldWallet['balance'] - 5000, 'Payout debited incorrectly');
expect((float) $afterWallet['pending_amount'] === 0.0 && (float) $afterWallet['total_withdrawn'] === 5000.0, 'Payout hold settled incorrectly');

// Failure releases the hold exactly once; a transport timeout does not release it.
$failedWd = $service->requestWithdrawal(2, 5000, '0712345678');
$failedPayout = Database::fetchOne('SELECT * FROM payouts WHERE withdrawal_id = ?', [$failedWd['withdrawal_id']]);
$failure = transactionFor('payouts', $failedPayout['id'], 'failed');
$held = (float) Database::fetchOne('SELECT withdrawable_balance FROM wallets WHERE user_id = 2')['withdrawable_balance'];
$service->processWebhook(eventFor($failure, 'payout-failed'));
$service->processWebhook(eventFor($failure, 'payout-failed-again'));
expect((float) Database::fetchOne('SELECT withdrawable_balance FROM wallets WHERE user_id = 2')['withdrawable_balance'] === $held + 5000, 'Failure released balance more than once');
$client->mode = 'timeout';
$timeoutWd = $service->requestWithdrawal(2, 5000, '0712345678');
expect($timeoutWd['payout_status'] === 'pending', 'Timeout prematurely failed withdrawal');
expect((float) Database::fetchOne('SELECT pending_amount FROM wallets WHERE user_id = 2')['pending_amount'] === 5000.0, 'Timeout released hold');
Wallet::autoExpirePending(2);
expect((float) Database::fetchOne('SELECT pending_amount FROM wallets WHERE user_id = 2')['pending_amount'] === 5000.0, 'Elapsed time released hold');
rejects(fn() => $service->requestWithdrawal(2, 500000, '0712345678'), 'Overdraw allowed');

// Rejected submissions restore holds; funding company wallet never pays commissions.
$client->mode = 'reject';
$rejected = $service->requestWithdrawal(2, 5000, '0712345678');
expect($rejected['payout_status'] === 'failed', 'Definitive rejection not reflected');
expect((float) Database::fetchOne('SELECT pending_amount FROM wallets WHERE user_id = 2')['pending_amount'] === 5000.0, 'Rejected withdrawal affected unrelated hold');
$client->mode = 'completed';
$commissionCount = Database::count('commissions');
$funding = $service->initiateFunding(3, '0712345678', 1000);
expect($funding['status'] === 'completed', 'Admin funding failed');
expect(Database::count('commissions') === $commissionCount, 'Company funding paid referral commissions');
rejects(fn() => $service->initiateFunding(2, '0712345678', 1000), 'Non-admin funded via admin deposit flow');

// The current plan uses two levels even when an old L3 setting still exists.
$plan = CommissionPlan::fromInput(['registration_fee' => '15000', 'commission_l1' => '3000', 'commission_l2' => '2000', 'commission_l3' => '99999', 'company_earning' => '1', 'commission_total' => '1']);
expect($plan['commission_total'] === 5000 && $plan['company_earning'] === 10000 && $plan['commission_l3'] === 0, 'Two-level totals were not derived on the server');
rejects(fn() => CommissionPlan::fromInput(['registration_fee' => '500', 'commission_l1' => '400', 'commission_l2' => '400']), 'Commissions exceeding the fee were accepted');
rejects(fn() => CommissionPlan::fromInput(['registration_fee' => '10000', 'commission_l1' => '-1', 'commission_l2' => '0']), 'Negative commission accepted');
$db->exec("INSERT INTO users(id,status,role,referred_by) VALUES (10,'pending','user',11),(11,'active','user',12),(12,'active','user',13),(13,'active','user',NULL),(14,'pending','user',11)");
$twoLevelDeposit = $service->initiatePayment(10, '0712345678');
expect(Database::count('commissions', 'source_user_id = ?', [10]) === 2, 'New registration did not pay exactly two ancestors');
expect(Database::count('commissions', 'source_user_id = ? AND level = ?', [10, 3]) === 0, 'A Level 3 commission was paid');
expect((float) Database::fetchOne('SELECT balance FROM wallets WHERE user_id = 11')['balance'] === 2500.0, 'Level 1 did not use the admin amount');
expect((float) Database::fetchOne('SELECT balance FROM wallets WHERE user_id = 12')['balance'] === 1500.0, 'Level 2 did not use the admin amount');
expect(!Database::fetchOne('SELECT id FROM wallets WHERE user_id = 13'), 'Third ancestor was credited');
expect(CommissionEngine::getReferralChain(10) === [1 => 11, 2 => 12], 'Referral chain still includes Level 3');
$snapshot = json_decode(Database::fetchOne('SELECT metadata FROM payments WHERE id = ?', [$twoLevelDeposit['payment_id']])['metadata'], true)['commission_plan'];
expect($snapshot['registration_fee'] === 12000 && $snapshot['commission_l1'] === 2500 && $snapshot['commission_l2'] === 1500, 'Payment did not retain its original commission plan');
$db->exec('CREATE TABLE referrals (id INTEGER PRIMARY KEY, referrer_id INTEGER, referred_id INTEGER, level INTEGER)');
$createChain = new ReflectionMethod(Auth::class, 'createReferralChain');
$createChain->invoke(null, 14, 11);
expect(Database::count('referrals', 'referred_id = ?', [14]) === 2, 'Registration referral records still include a third level');

// The public registration design does not display fee or commission details.
ini_set('session.save_path', sys_get_temp_dir());
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/register';
$_GET = [];
Auth::initSession();
$_SESSION = [];
ob_start();
require __DIR__ . '/../register.php';
$registrationHtml = ob_get_clean();
expect(!str_contains($registrationHtml, 'Registration fee:'), 'Registration form still displays the fee');
expect(!str_contains($registrationHtml, 'Referral commissions:'), 'Registration form still displays commission details');
expect(!str_contains($registrationHtml, 'Level 3'), 'Registration form still advertises Level 3');

echo "Abliner offline integration checks passed. No live transactions were sent.\n";
