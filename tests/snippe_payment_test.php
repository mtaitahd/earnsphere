<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/social_loans_fixture.php';
require dirname(__DIR__).'/classes/SnippePayment.php';
class MockSnippe extends SnippeClient {
    public array $posts=[];public array $payments=[];public string $mode='ok';
    public function __construct(){parent::__construct(['api_key'=>'test-api-key','webhook_secret'=>'test-signing-secret','enabled'=>true,'base_url'=>'https://api.snippe.sh','callback_url'=>'https://example.test/webhooks/snippe.php']);}
    protected function sendHttp(string $method,string $url,array $headers,string $body): array {
        expect(in_array('Authorization: Bearer test-api-key',$headers,true),'API key not used');
        expect(!str_contains($url,'sessions'),'Hosted session API used');
        if ($method==='POST') {
            expect($url==='https://api.snippe.sh/v1/payments','Wrong payment endpoint');
            $p=json_decode($body,true);$this->posts[]=$p;
            $key=array_values(array_filter($headers,fn($h)=>str_starts_with($h,'Idempotency-Key: ')))[0]??'';
            expect(strlen(substr($key,17))<=30,'Idempotency key too long');
            expect($p['payment_type']==='mobile' && isset($p['details']['amount'],$p['details']['currency'],$p['phone_number'],$p['customer']['firstname'],$p['customer']['lastname'],$p['customer']['email']),'Invalid mobile request payload');
            if($this->mode==='completed_error'){Database::update('payments',['status'=>'completed'],'order_id = ?',[$p['metadata']['order_id']]);return ['body'=>'{"status":"error"}','http_code'=>400,'error'=>''];}
            if($this->mode==='timeout')return ['body'=>'','http_code'=>0,'error'=>'timeout'];
            $reference='pay-'.$p['metadata']['order_id'];
            $data=['reference'=>$reference,'amount'=>['value'=>$p['details']['amount'],'currency'=>$p['details']['currency']],'status'=>'pending','payment_type'=>'mobile','metadata'=>$p['metadata'],'customer'=>['phone'=>$p['phone_number']]];
            $this->payments[$reference]=$data;
            return ['body'=>json_encode(['status'=>'success','code'=>201,'data'=>$data]),'http_code'=>201,'error'=>''];
        }
        return ['body'=>json_encode(['status'=>'success','data'=>$this->payments[rawurldecode(basename($url))]??[]]),'http_code'=>200,'error'=>''];
    }
}
foreach([205,206,207,208,209] as $uid)Database::insert('users',['id'=>$uid,'status'=>'pending','role'=>'user','phone'=>'255712345678','full_name'=>'Checkout Tester','email'=>'tester@example.test','referral_code'=>'SN'.$uid,'referred_by'=>200]);
$client=new MockSnippe();$snippe=new SnippePayment($client);expect($snippe->ready(),'API key and webhook secret alone should enable USSD');
$start=$snippe->initiate(205,'0624404217');expect($start['success'],'USSD did not start');
$row=Database::fetchOne('SELECT * FROM payments WHERE order_id=?',[$start['order_id']]);$ref=$row['transaction_ref'];
expect($row['gateway']==='snippe' && $row['status']==='pending' && $row['phone']==='255624404217','USSD creation wrong or credited');
expect($client->posts[0]['details']===['amount'=>12000,'currency'=>'TZS'],'Amount not server controlled');
expect($client->posts[0]['phone_number']==='255624404217','Registered number replaced payer number');
expect($snippe->initiate(205,'0624404217')['order_id']===$row['order_id'] && count($client->posts)===1,'Repeated click sent another push');
expect(!$snippe->initiate(205,'0713000111')['success'] && count($client->posts)===1,'Unconfirmed number change sent push');
$changed=$snippe->initiate(205,'0713000111','','',true,$row['order_id']);expect($changed['success'] && count($client->posts)===2,'Confirmed number switch failed');
$changedRow=Database::fetchOne('SELECT * FROM payments WHERE order_id=?',[$changed['order_id']]);
$base=$client->payments[$ref];$base['status']='completed';$event=['type'=>'payment.completed','data'=>$base];
$client->payments[$ref]=$base;$client->payments[$ref]['amount']['value']=1;rejects(fn()=>$snippe->processWebhook($event),'Wrong amount credited');
$client->payments[$ref]=$base;$client->payments[$ref]['amount']['currency']='USD';rejects(fn()=>$snippe->processWebhook($event),'Wrong currency credited');
$client->payments[$ref]=$base;$client->payments[$ref]['metadata']['order_id']='other';rejects(fn()=>$snippe->processWebhook($event),'Wrong order credited');
$client->payments[$ref]=$base;$client->payments[$ref]['customer']['phone']='255713000111';rejects(fn()=>$snippe->processWebhook($event),'Wrong payer credited');
expect(Database::fetchOne('SELECT status FROM users WHERE id=205')['status']==='pending','Rejected webhook activated account');
$client->payments[$ref]=$base;
$old=Database::insert('payments',['user_id'=>205,'order_id'=>'old-abliner-205','amount'=>12000,'currency'=>'TZS','status'=>'pending','gateway'=>'abliner','purpose'=>'registration','phone'=>'255624404217','metadata'=>'{}']);
$snippe->processWebhook($event);$snippe->processWebhook($event);
expect(Database::fetchOne('SELECT status FROM users WHERE id=205')['status']==='active','Verified payment did not activate');
expect(Database::count('commissions','source_user_id=?',[205])===1,'Duplicate commission');
expect(Database::count('user_notifications','event_key=?',['payment:'.$row['id']])===1,'Duplicate notification');
$late=$client->payments[$changedRow['transaction_ref']];$late['status']='completed';$client->payments[$changedRow['transaction_ref']]=$late;
$snippe->processWebhook(['type'=>'payment.completed','data'=>$late]);
expect(Database::count('commissions','source_user_id=?',[205])===1,'Second payer duplicated commissions');
$abliner=new AblinerPayment(new MockAbliner());$abliner->applyTransaction('payments',$old,['id'=>'late-abliner','type'=>'deposit','customer_reference'=>'old-abliner-205','amount'=>12000,'currency'=>'TZS','status'=>'completed']);
expect(Database::count('commissions','source_user_id=?',[205])===1,'Late gateway duplicated commissions');
(new AblinerPayment(new MockAbliner()))->initiatePayment(206,'0624404217');expect(!$snippe->initiate(206,'0624404217')['success'],'Cross-gateway pending bypassed');
$client->mode='timeout';$timeout=$snippe->initiate(207,'0624404217');expect($timeout['success'] && $timeout['status']==='pending','Timeout treated as definitive failure');
$count=count($client->posts);$snippe->initiate(207,'0624404217');expect(count($client->posts)===$count,'Timeout repeated push');
$recovered=['reference'=>'recovered-timeout','status'=>'completed','amount'=>['value'=>12000,'currency'=>'TZS'],'metadata'=>['order_id'=>$timeout['order_id']],'customer'=>['phone'=>'255624404217']];
$client->payments['recovered-timeout']=$recovered;$snippe->processWebhook(['type'=>'payment.completed','data'=>$recovered]);
expect(Database::fetchOne('SELECT status FROM users WHERE id=207')['status']==='active','Timeout payment not recovered by signed/verified callback');
$client->mode='completed_error';expect($snippe->initiate(208,'0624404217')['status']==='completed','Concurrent completion downgraded');
rejects(fn()=>$snippe->initiate(209,'0624404217','Single','invalid'),'Invalid customer accepted');
$client->mode='ok';$poll=$snippe->initiate(209,'0624404217');$pollRow=Database::fetchOne('SELECT * FROM payments WHERE order_id=?',[$poll['order_id']]);
$client->payments[$pollRow['transaction_ref']]['status']='completed';$snippe->reconcile((int)$pollRow['id']);
expect(Database::fetchOne('SELECT status FROM users WHERE id=209')['status']==='active','Polling did not settle verified payment');
Database::update('payments',['status'=>'refunded'],'id = ?',[$row['id']]);Database::update('users',['status'=>'pending'],'id = ?',[205]);
rejects(fn()=>$snippe->processWebhook($event),'Refunded payment reactivated account');
$raw='{ "type": "payment.completed" }';$ts=(string)time();$signature=hash_hmac('sha256',$ts.'.'.$raw,'test-signing-secret');
expect($client->verifyWebhookSignature(['X-Webhook-Timestamp'=>$ts,'X-Webhook-Signature'=>$signature],$raw),'Valid signature failed');
expect(!$client->verifyWebhookSignature(['X-Webhook-Timestamp'=>$ts,'X-Webhook-Signature'=>$signature],$raw.' '),'Tampered body accepted');
expect(!$client->verifyWebhookSignature(['X-Webhook-Timestamp'=>(string)(time()-600),'X-Webhook-Signature'=>$signature],$raw),'Old signature accepted');
echo "Snippe USSD: documented payload, chosen phone, no JWT, status verification, duplicate/late settlement, number switches, timeout and signatures passed. No live requests.\n";
