<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/abliner_payment_test.php';
class ReferenceOnlyPayoutClient extends MockAbliner {
    public bool $wrongReference = false;
    public function withdraw(array $payload, string $key): array {
        $result = parent::withdraw($payload, $key);
        unset($result['data']['customer_reference']);
        $result['data']['reference'] = $this->wrongReference ? 'another-order' : $payload['reference'];
        return $result;
    }
}
$db->exec("INSERT INTO users(id,status,role) VALUES (99,'active','user')");
$db->exec('INSERT INTO wallets(id,user_id,balance,withdrawable_balance) VALUES (99,99,30000,30000)');
$client = new ReferenceOnlyPayoutClient();
$client->mode = 'completed';
$service = new AblinerPayment($client);
$paid = $service->requestWithdrawal(99, 5000, '0712345678', 'TEST RECIPIENT');
expect($paid['payout_status'] === 'completed', 'Exact echoed payout reference was rejected');
$wallet = Database::fetchOne('SELECT * FROM wallets WHERE user_id=99');
expect((float)$wallet['balance'] === 25000.0 && (float)$wallet['withdrawable_balance'] === 25000.0 && (float)$wallet['pending_amount'] === 0.0, 'Successful payout did not settle wallet exactly');
$payout = Database::fetchOne('SELECT * FROM payouts WHERE withdrawal_id=?', [$paid['withdrawal_id']]);
$service->applyTransaction('payouts', (int)$payout['id'], transactionFor('payouts', (int)$payout['id'], 'completed'));
expect((float)Database::fetchOne('SELECT balance FROM wallets WHERE user_id=99')['balance'] === 25000.0, 'Duplicate confirmation debited twice');
$client->wrongReference = true;
$uncertain = $service->requestWithdrawal(99, 5000, '0712345678', 'TEST RECIPIENT');
expect($uncertain['payout_status'] === 'pending', 'Unmatched transfer was marked failed or paid');
$wallet = Database::fetchOne('SELECT * FROM wallets WHERE user_id=99');
expect((float)$wallet['balance'] === 25000.0 && (float)$wallet['withdrawable_balance'] === 20000.0 && (float)$wallet['pending_amount'] === 5000.0, 'Unmatched transfer lost its hold');
$payout = Database::fetchOne('SELECT * FROM payouts WHERE withdrawal_id=?', [$uncertain['withdrawal_id']]);
expect(!empty(json_decode($payout['metadata'], true)['verification_error']), 'Mismatch diagnostic was not preserved');
$client->transactions = [transactionFor('payouts', (int)$payout['id'], 'completed')];
expect($service->reconcile('payouts', (int)$payout['id'])['status'] === 'completed', 'Existing payout could not be reconciled');
expect((float)Database::fetchOne('SELECT balance FROM wallets WHERE user_id=99')['balance'] === 20000.0, 'Reconciled payout did not settle');
echo "Payout reference, mismatch hold, reconciliation and duplicate checks passed. No live transfers sent.\n";
