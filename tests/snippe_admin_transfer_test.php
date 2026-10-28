<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/social_loans_fixture.php';require dirname(__DIR__).'/classes/SnippeAdminTransfer.php';
$sql=file_get_contents(dirname(__DIR__).'/database/snippe_admin_transfers.sql');
$sql=str_replace('INT UNSIGNED AUTO_INCREMENT PRIMARY KEY','INTEGER PRIMARY KEY AUTOINCREMENT',$sql);
$sql=preg_replace('/,\s*INDEX idx_admin_transfer_status \(user_id,status\)/','',$sql);
$sql=str_replace('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4','',$sql);$db->exec($sql);
class MockCompanySnippe extends SnippePayoutClient {
    public array $posts=[];public array $records=[];public string $mode='pending';public int $fee=100;
    public function __construct() {parent::__construct(['api_key'=>'company-key','webhook_secret'=>'test-secret','enabled'=>false,'base_url'=>'https://api.snippe.sh','callback_url'=>'https://example.test/webhooks/snippe.php'],new MockAbliner());}
    protected function sendHttp(string $method,string $url,array $headers,string $body): array {
        expect(in_array('Authorization: Bearer company-key',$headers,true),'Missing company API key');
        if(str_contains($url,'/payouts/fee?')) {$amount=(int)substr($url,strrpos($url,'=')+1);$data=['amount'=>$amount,'fee_amount'=>$this->fee,'total_amount'=>$amount+$this->fee,'currency'=>'TZS'];}
        elseif($method==='POST') {
            expect($url==='https://api.snippe.sh/v1/payouts/send','Wrong company payout endpoint');
            $payload=json_decode($body,true);$this->posts[]=$payload;$key=$payload['metadata']['request_key'];
            expect(strlen($key)===30 && in_array('Idempotency-Key: '.$key,$headers,true),'Invalid company idempotency key');
            expect($payload['amount']===5000 && $payload['recipient_phone']==='255712345678' && $payload['recipient_name']==='TEST RECIPIENT' && $payload['channel']==='mobile','Wrong company payout details');
            $data=['reference'=>'company-'.$key,'status'=>$this->mode==='timeout'?'pending':$this->mode,'amount'=>['value'=>$payload['amount'],'currency'=>'TZS'],
                'recipient'=>['phone'=>$payload['recipient_phone']],'fees'=>['value'=>$this->fee,'currency'=>'TZS'],'total'=>['value'=>$payload['amount']+$this->fee,'currency'=>'TZS'],'metadata'=>$payload['metadata']];
            $this->records[$data['reference']]=$data;
            if($this->mode==='timeout')return ['http_code'=>0,'error'=>'timeout','body'=>''];
        } else $data=$this->records[rawurldecode(basename($url))]??[];
        return ['http_code'=>200,'error'=>'','body'=>json_encode(['status'=>'success','data'=>$data])];
    }
}
$client=new MockCompanySnippe();$service=new SnippeAdminTransfer($client);
$input=['amount'=>'5000','phone'=>'0712345678'];
foreach([200,204] as $actor) {rejects(fn()=>$service->preview($actor,$input),'Non-admin preview allowed');rejects(fn()=>$service->history($actor),'Non-admin history allowed');}
$quote=$service->preview(3,$input);expect($quote['fees']===100.0 && $quote['total']===5100.0,'Wrong company fee preview');
$wallets=Database::fetchAll('SELECT * FROM wallets ORDER BY id');$payments=Database::count('payments');$commissions=Database::count('commissions');
$key='SN-CA-'.bin2hex(random_bytes(12));
rejects(fn()=>$service->send(3,$quote,$key,false),'Unconfirmed company payout sent');
$client->fee=200;rejects(fn()=>$service->send(3,$quote,$key,true),'Fee change silently accepted');$client->fee=100;
expect(count($client->posts)===0,'Preview sent real payout');
$row=$service->send(3,$quote,$key,true);expect($row['status']==='pending','Company payout not pending');
$service->send(3,$quote,$key,true);$service->send(3,$quote,'SN-CA-'.bin2hex(random_bytes(12)),true);expect(count($client->posts)===1,'Company duplicate payout');
rejects(fn()=>$service->check(204,(int)$row['id']),'Agent checked company payout');
$ref=$row['reference'];$client->records[$ref]['status']='completed';$client->records[$ref]['amount']['value']=1;
rejects(fn()=>$service->check(3,(int)$row['id']),'Wrong company payout amount accepted');
$client->records[$ref]['amount']['value']=5000;$client->records[$ref]['recipient']['phone']='255713000111';
rejects(fn()=>$service->check(3,(int)$row['id']),'Wrong company recipient accepted');$client->records[$ref]['recipient']['phone']='255712345678';
expect($service->check(3,(int)$row['id'])['status']==='completed','Company payout not completed');
$event=['type'=>'payout.completed','data'=>$client->records[$ref]];$service->processWebhook($event);$service->processWebhook($event);
$client->mode='failed';$failed=$service->send(3,$quote,'SN-CA-'.bin2hex(random_bytes(12)),true);expect($failed['status']==='failed','Company failure not recorded');
$client->mode='timeout';$uncertain=$service->send(3,$quote,'SN-CA-'.bin2hex(random_bytes(12)),true);expect($uncertain['status']==='pending','Timeout became failed');
$service->send(3,$quote,'SN-CA-'.bin2hex(random_bytes(12)),true);expect(count($client->posts)===3,'Timeout retried payout');
$record=end($client->records);$record['status']='completed';$client->records[$record['reference']]=$record;$service->processWebhook(['type'=>'payout.completed','data'=>$record]);
expect($service->check(3,(int)$uncertain['id'])['status']==='completed','Company timeout callback did not recover');
expect(Database::fetchAll('SELECT * FROM wallets ORDER BY id')===$wallets && Database::count('payments')===$payments && Database::count('commissions')===$commissions,'Company payout changed member finances');
echo "Company Snippe withdrawals: admin permission, fee consent, duplicates, identity checks, failures and timeout recovery passed. Member balances unchanged; no live transfers.\n";
