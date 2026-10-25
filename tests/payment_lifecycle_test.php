<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/abliner_payment_test.php';
class CompletedNetClient extends MockAbliner {
    public function withdraw(array $payload, string $key): array {
        $response = parent::withdraw($payload, $key);
        if ($response['success']) {
            $response['data']['amount'] = 15360;
            $response['data']['amount_cents'] = 1536000;
        }
        return $response;
    }
}
$db->exec("INSERT INTO users(id,status,role,referred_by) VALUES(101,'active','user',NULL),(102,'active','user',NULL)");
$db->exec('INSERT INTO wallets(id,user_id,balance,withdrawable_balance) VALUES(101,101,100000,100000),(102,102,100000,100000)');
$client = new CompletedNetClient(); $client->mode = 'completed'; $service = new AblinerPayment($client);
// Charge is fixed at 500 by fixture: 16500 gross requests 16000 from provider.
$key = str_repeat('a',64);
$paid = $service->requestWithdrawal(101,16500,'0712345678','TEST RECIPIENT',500,$key);
expect($paid['payout_status'] === 'completed','Provider-completed amount discrepancy stayed pending');
$wallet = Database::fetchOne('SELECT * FROM wallets WHERE user_id=101');
expect((float)$wallet['balance'] === 83500.0 && (float)$wallet['withdrawable_balance'] === 83500.0 && (float)$wallet['pending_amount'] === 0.0, 'Gross hold not settled exactly');
$payout = Database::fetchOne('SELECT * FROM payouts WHERE withdrawal_id=?',[$paid['withdrawal_id']]);
$review = json_decode($payout['metadata'],true)['settlement_review'];
expect($review['requires_review'] && $review['requested_tzs'] === 16000 && $review['provider_reported_tzs'] === 15360 && $review['difference_tzs'] === 640,'Discrepancy evidence missing');
$notice = Database::fetchOne('SELECT * FROM user_notifications WHERE event_key=?', ['withdrawal:' . $paid['withdrawal_id']]);
$noticePayload = json_decode($notice['payload'], true);
expect($noticePayload['system_charge'] === 500 && $noticePayload['provider_charge'] === 640 && $noticePayload['received_amount'] === 15360, 'Settled payout did not queue the exact fee snapshot');
$posts = $client->postCount();
$again = $service->requestWithdrawal(101,16500,'0712345678','TEST RECIPIENT',500,$key);
expect($again['withdrawal_id'] === $paid['withdrawal_id'] && $client->postCount() === $posts,'Repeated browser submission sent another payout');
rejects(fn() => $service->requestWithdrawal(101,17000,'0712345678','TEST RECIPIENT',500,$key),'Reused token changed amount');
$event = transactionFor('payouts',(int)$payout['id'],'completed');
$event['amount']=15360; $event['amount_cents']=1536000;
$service->processWebhook(eventFor($event,'net-completed-1'));
$service->processWebhook(eventFor($event,'net-completed-1'));
$service->processWebhook(eventFor($event,'net-completed-2'));
expect((float)Database::fetchOne('SELECT balance FROM wallets WHERE user_id=101')['balance'] === 83500.0,'Duplicate callback debited twice');
// A timeout retains its hold and blocks a new token while unresolved.
$plain = new MockAbliner(); $plain->mode='timeout'; $safe = new AblinerPayment($plain);
$timeout = $safe->requestWithdrawal(102,16500,'0712345678','TEST RECIPIENT',500,str_repeat('b',64));
$posts=$plain->postCount();
$safe->requestWithdrawal(102,16500,'0712345678','TEST RECIPIENT',500,str_repeat('b',64));
expect($plain->postCount()===$posts,'Timeout retry sent money twice');
rejects(fn() => $safe->requestWithdrawal(102,16500,'0712345678','TEST RECIPIENT',500,str_repeat('c',64)), 'Unresolved transfer allowed a new withdrawal');
$p=Database::fetchOne('SELECT * FROM payouts WHERE withdrawal_id=?',[$timeout['withdrawal_id']]);
$t=transactionFor('payouts',(int)$p['id'],'completed');
unset($t['customer_reference']); $t['reference']=$p['idempotency_key'];
$safe->processWebhook(eventFor($t,'timeout-reference-only'));
expect(Database::fetchOne('SELECT status FROM payouts WHERE id=?',[$p['id']])['status']==='completed','Reference-only callback after timeout was lost');
// Wrong currency, malformed amounts and inconsistent cents never settle money.
$t['currency']='USD'; rejects(fn() => $safe->applyTransaction('payouts',(int)$p['id'],$t),'Wrong currency accepted');
$t['currency']='TZS'; $t['amount']='nonsense'; rejects(fn() => $safe->applyTransaction('payouts',(int)$p['id'],$t),'Malformed amount accepted');
$t['amount']=16000; $t['amount_cents']=1; rejects(fn() => $safe->applyTransaction('payouts',(int)$p['id'],$t),'Contradictory amount fields accepted');
echo "Lifecycle: net discrepancy, duplicate POST/webhook, timeout hold, reference recovery and invalid payload checks passed.\n";
