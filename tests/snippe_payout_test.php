<?php
require __DIR__.'/social_loans_fixture.php';
require dirname(__DIR__).'/classes/SnippePayout.php';
class MockSnippePayout extends SnippePayoutClient {
    public array $posts=[];public array $records=[];public string $mode='pending';
    public function __construct() {parent::__construct(['api_key'=>'payout-key','webhook_secret'=>'test-secret','enabled'=>true,'base_url'=>'https://api.snippe.sh','callback_url'=>'https://example.test/webhooks/snippe.php'],new MockAbliner());}
    protected function sendHttp(string $method,string $url,array $headers,string $body): array {
        expect(in_array('Authorization: Bearer payout-key',$headers,true),'Missing API key');
        if($method==='POST') {
            expect($url==='https://api.snippe.sh/v1/payouts/send','Wrong payout endpoint');
            $p=json_decode($body,true);$this->posts[]=$p;
            expect($p['channel']==='mobile' && $p['amount']===4500 && $p['recipient_phone']==='255712345678' && $p['recipient_name']==='TEST RECIPIENT','Wrong payout payload/net amount');
            $key=$p['metadata']['request_key'];expect(strlen($key)<=30 && in_array('Idempotency-Key: '.$key,$headers,true),'Invalid idempotency key');
            $data=['reference'=>'ref-'.$key,'id'=>'id-'.$key,'status'=>$this->mode==='timeout'?'pending':$this->mode,'amount'=>['value'=>4500,'currency'=>'TZS'],'recipient'=>['phone'=>$p['recipient_phone'],'name'=>$p['recipient_name']],'fees'=>['value'=>100,'currency'=>'TZS'],'total'=>['value'=>4600,'currency'=>'TZS'],'metadata'=>$p['metadata']];
            $this->records[$data['reference']]=$data;
            if($this->mode==='timeout') return ['http_code'=>0,'error'=>'timeout','body'=>''];
        } else $data=$this->records[rawurldecode(basename($url))]??[];
        return ['http_code'=>200,'error'=>'','body'=>json_encode(['status'=>'success','data'=>$data])];
    }
}
Database::insert('users',['id'=>300,'status'=>'active','role'=>'user','full_name'=>'Payout Tester']);
Database::insert('wallets',['user_id'=>300,'balance'=>50000,'withdrawable_balance'=>50000]);
$client=new MockSnippePayout();$service=new SnippePayout($client);$key=bin2hex(random_bytes(32));
$before=Database::fetchOne('SELECT * FROM wallets WHERE user_id=300');
$result=$service->requestWithdrawal(300,5000,'0712345678','TEST RECIPIENT',500,$key);
$p=Database::fetchOne('SELECT * FROM payouts WHERE withdrawal_id=?',[$result['withdrawal_id']]);
expect($p['gateway']==='snippe' && $p['status']==='pending','Wrong payout gateway/status');
$service->requestWithdrawal(300,5000,'0712345678','TEST RECIPIENT',500,$key);$service->sendPayout((int)$p['withdrawal_id'],300);
expect(count($client->posts)===1,'Duplicate payout dispatched');
rejects(fn()=>(new AblinerPayment(new MockAbliner()))->requestWithdrawal(300,5000,'0712345678','TEST RECIPIENT',500,bin2hex(random_bytes(32))),'Cross-provider duplicate hold accepted');
$ref=$p['gateway_id'];$client->records[$ref]['status']='completed';
$client->records[$ref]['amount']['value']=1;rejects(fn()=>$service->reconcile('payouts',(int)$p['id']),'Wrong amount settled');
$client->records[$ref]['amount']['value']=4500;$client->records[$ref]['recipient']['phone']='255713000111';$service->reconcile('payouts',(int)$p['id']);expect(Database::fetchOne('SELECT status FROM payouts WHERE id=?',[$p['id']])['status']==='pending','Wrong recipient settled');
$client->records[$ref]['recipient']['phone']='255712345678';
$service->reconcile('payouts',(int)$p['id']);$service->reconcile('payouts',(int)$p['id']);
$wallet=Database::fetchOne('SELECT * FROM wallets WHERE user_id=300');expect((float)$wallet['balance']===45000.0 && (float)$wallet['pending_amount']===0.0 && (float)$wallet['total_withdrawn']===5000.0,'Completion double debit or hold mismatch');
$client->mode='failed';$service->requestWithdrawal(300,5000,'0712345678','TEST RECIPIENT',500,bin2hex(random_bytes(32)));
$wallet=Database::fetchOne('SELECT * FROM wallets WHERE user_id=300');expect((float)$wallet['balance']===45000.0 && (float)$wallet['withdrawable_balance']===45000.0 && (float)$wallet['pending_amount']===0.0,'Failure did not release hold');
$client->mode='timeout';$result=$service->requestWithdrawal(300,5000,'0712345678','TEST RECIPIENT',500,bin2hex(random_bytes(32)));
$record=end($client->records);$record['status']='completed';$client->records[$record['reference']]=$record;
$service->processWebhook(['type'=>'payout.completed','data'=>$record]);$service->processWebhook(['type'=>'payout.completed','data'=>$record]);
$wallet=Database::fetchOne('SELECT * FROM wallets WHERE user_id=300');expect((float)$wallet['balance']===40000.0 && (float)$wallet['pending_amount']===0.0,'Timeout callback recovery failed');
rejects(fn()=>$service->processWebhook(['type'=>'payout.reversed','data'=>$record]),'Reversal silently released settled funds');
echo "Snippe payouts: documented request, net amount, identity verification, duplicate/cross-gateway protection, success/failure and timeout webhook recovery passed. No live transfers.\n";
