<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/abliner_payment_test.php';
class GeneratedReferenceClient extends MockAbliner {
    public function withdraw(array $payload, string $key): array {
        $result = parent::withdraw($payload, $key);
        if ($result['success']) {
            unset($result['data']['customer_reference']);
            $result['data']['reference'] = 'NETWORK-REFERENCE';
            $result['data']['amount'] = $payload['amount'] * 0.96;
            $result['data']['amount_cents'] = $result['data']['amount'] * 100;
            $this->transactions = [$result['data']];
            $this->transactions[0]['status'] = 'completed';
        }
        return $result;
    }
}
$db->exec("INSERT INTO users(id,status,role) VALUES (120,'active','user'),(121,'active','user')");
$db->exec('INSERT INTO wallets(id,user_id,balance,withdrawable_balance) VALUES (120,120,50000,50000),(121,121,50000,50000)');
$client = new GeneratedReferenceClient(); $client->mode='pending'; $service=new AblinerPayment($client);
$result=$service->requestWithdrawal(120,5500,'0712345678','TEST RECIPIENT',500,str_repeat('d',64));
expect($result['payout_status']==='completed','Generated provider reference did not verify automatically');
expect($client->postCount()===1,'Automatic verification resent payout');
$row=Database::fetchOne('SELECT * FROM payouts WHERE withdrawal_id=?',[$result['withdrawal_id']]);
expect(!empty($row['gateway_id']),'Provider response ID not bound');
expect((float)Database::fetchOne('SELECT balance FROM wallets WHERE user_id=120')['balance']===44500.0,'Automatic lookup did not settle wallet');
$event=$client->transactions[0];
unset($event['customer_reference']);
$service->processWebhook(eventFor($event,'generated-reference-webhook'));
expect((float)Database::fetchOne('SELECT balance FROM wallets WHERE user_id=120')['balance']===44500.0,'Duplicate webhook settled twice');
// Recover an old request from saved outbound response evidence, never a second POST.
$oldClient = new MockAbliner(); $oldClient->mode='timeout'; $oldService=new AblinerPayment($oldClient);
$old=$oldService->requestWithdrawal(121,5500,'0712345678','TEST RECIPIENT',500,str_repeat('e',64));
$oldRow=Database::fetchOne('SELECT * FROM payouts WHERE withdrawal_id=?',[$old['withdrawal_id']]);
$evidence=['id'=>'provider-old-transaction','type'=>'withdrawal','reference'=>'PROVIDER-ONLY','amount'=>4800,'amount_cents'=>480000,'currency'=>'TZS','status'=>'completed'];
Database::update('payouts',['metadata'=>json_encode(['submission_response'=>$evidence])],'id=?',[$oldRow['id']]);
$oldClient->transactions=[$evidence]; $before=$oldClient->postCount();
expect($oldService->reconcile('payouts',(int)$oldRow['id'])['status']==='completed','Saved response identity did not recover old transfer');
expect($oldClient->postCount()===$before,'Old transfer recovery resent money');
expect(!is_file(__DIR__.'/../cron/verify-pending-payments.php'),'Payment cron was not removed');
echo "Generated reference, automatic lookup, saved identity recovery and no-cron checks passed.\n";
