<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/social_loans_fixture.php';
require dirname(__DIR__).'/classes/SnippePayment.php';
class MockSnippe extends SnippeClient {
    public array $posts=[];public array $payments=[];public string $sessionStatus='pending';public string $mode='ok';
    public function __construct(){parent::__construct(['api_key'=>'test-api-key','checkout_token'=>'test-session-token','webhook_secret'=>'test-signing-secret','enabled'=>true,'base_url'=>'https://api.snippe.sh','callback_url'=>'https://example.test/webhooks/snippe.php']);}
    protected function sendHttp(string $method,string $url,array $headers,string $body): array {
        if ($method==='POST') {
            $p=json_decode($body,true);$this->posts[]=$p;
            $key=array_values(array_filter($headers,fn($h)=>str_starts_with($h,'Idempotency-Key: ')))[0]??'';
            expect(strlen(substr($key,17))<=30,'Idempotency key too long');
            expect(in_array('Authorization: Bearer test-session-token',$headers,true),'Wrong checkout credential');
            if($this->mode==='completed_error'){Database::update('payments',['status'=>'completed'],'order_id = ?',[$p['metadata']['order_id']]);return ['body'=>'{"status":"error"}','http_code'=>400,'error'=>''];}
            if($this->mode==='timeout')return ['body'=>'','http_code'=>0,'error'=>'timeout'];
            return ['body'=>json_encode(['code'=>201,'data'=>['reference'=>'sess_'.$p['metadata']['order_id'],'checkout_url'=>'https://snippe.me/checkout/abc123','amount'=>$p['amount'],'currency'=>'TZS','status'=>'pending']]),'http_code'=>201,'error'=>''];
        }
        if(str_contains($url,'/api/v1/sessions/')) {
            $ref=rawurldecode(basename($url));$order=substr($ref,5);$row=Database::fetchOne('SELECT amount FROM payments WHERE order_id=?',[$order]);
            $data=['reference'=>$ref,'amount'=>(int)$row['amount'],'currency'=>'TZS','status'=>$this->sessionStatus];
        }else {
            expect(in_array('Authorization: Bearer test-api-key',$headers,true),'Wrong verification credential');
            $data=$this->payments[rawurldecode(basename($url))]??[];
        }
        return ['body'=>json_encode(['status'=>'success','data'=>$data]),'http_code'=>200,'error'=>''];
    }
}
function snippeEvent(string $order,string $reference,int $amount): array {return ['id'=>'evt-'.$reference,'type'=>'payment.completed','data'=>['reference'=>$reference,'status'=>'completed','amount'=>['value'=>$amount,'currency'=>'TZS'],'metadata'=>['order_id'=>$order]]];}
foreach([205,206,207,208] as $uid)Database::insert('users',['id'=>$uid,'status'=>'pending','role'=>'user','phone'=>'255624404217','full_name'=>'Checkout Tester','email'=>'tester@example.test','referral_code'=>'SN'.$uid,'referred_by'=>200]);
$client=new MockSnippe();$snippe=new SnippePayment($client);
$start=$snippe->initiate(205);expect($start['success'],'Checkout did not start');
$row=Database::fetchOne('SELECT * FROM payments WHERE order_id=?',[$start['order_id']]);
expect($row['gateway']==='snippe' && $row['status']==='pending','Checkout creation credited account');
expect($client->posts[0]['amount']==12000 && !$client->posts[0]['allow_custom_amount'],'Amount not server controlled');
expect(str_contains($client->posts[0]['redirect_url'],'order_id='.$row['order_id']),'Return URL not bound');
expect($snippe->initiate(205)['order_id']===$row['order_id'] && count($client->posts)===1,'Repeated click created checkout');
$client->sessionStatus='completed';$snippe->reconcile((int)$row['id']);
expect(Database::fetchOne('SELECT status FROM users WHERE id=205')['status']==='pending','Session return alone activated account');
$client->sessionStatus='pending';
$event=snippeEvent($row['order_id'],'pay-205',12000);$event['data']['customer']=['phone'=>'+255713000111'];$client->payments['pay-205']=$event['data'];
$client->payments['pay-205']['amount']['value']=1;
rejects(fn()=>$snippe->processWebhook($event),'Wrong amount credited');
expect(Database::fetchOne('SELECT status FROM users WHERE id=205')['status']==='pending','Rejected webhook activated account');
$client->payments['pay-205']=$event['data'];$client->payments['pay-205']['amount']['currency']='USD';
rejects(fn()=>$snippe->processWebhook($event),'Wrong currency credited');
$client->payments['pay-205']=$event['data'];$client->payments['pay-205']['metadata']['order_id']='other-order';
rejects(fn()=>$snippe->processWebhook($event),'Wrong order credited');
$client->payments['pay-205']=$event['data'];
$old=Database::insert('payments',['user_id'=>205,'order_id'=>'old-abliner-205','amount'=>12000,'currency'=>'TZS','status'=>'pending','gateway'=>'abliner','purpose'=>'registration','phone'=>'255624404217','metadata'=>'{}']);
$snippe->processWebhook($event);$snippe->processWebhook($event);
expect(Database::fetchOne('SELECT status FROM users WHERE id=205')['status']==='active','Verified payment did not activate');
expect(Database::fetchOne('SELECT phone FROM payments WHERE id=?',[$row['id']])['phone']==='255713000111','Verified payer number was not recorded');
expect(Database::count('commissions','source_user_id=?',[205])===1,'Duplicate commission');
expect(Database::count('user_notifications','event_key=?',['payment:'.$row['id']])===1,'Duplicate notification');
expect(Database::fetchOne('SELECT status FROM payments WHERE id=?',[$old])['status']==='failed','Other gateway request not superseded');
$abliner=new AblinerPayment(new MockAbliner());
$abliner->applyTransaction('payments',$old,['id'=>'late-abliner','type'=>'deposit','customer_reference'=>'old-abliner-205','amount'=>12000,'currency'=>'TZS','status'=>'completed']);
expect(Database::count('commissions','source_user_id=?',[205])===1,'Late second gateway duplicated commissions');
expect(Database::fetchOne('SELECT status FROM payments WHERE id=?',[$old])['status']==='completed','Late real payment was lost');
$first=(new AblinerPayment(new MockAbliner()))->initiatePayment(206,'0624404217');
expect(!$snippe->initiate(206)['success'],'Cross-gateway pending request bypassed');
$client->mode='timeout';$timeout=$snippe->initiate(207);expect(!$timeout['success'],'Timeout pretended checkout success');
$count=count($client->posts);$snippe->initiate(207);expect(count($client->posts)===$count,'Ambiguous creation sent again');
$client->mode='completed_error';$raced=$snippe->initiate(208);
expect($raced['success'] && $raced['status']==='completed','Creation response downgraded a concurrent completion');
Database::update('payments',['status'=>'refunded'],'id = ?',[$row['id']]);Database::update('users',['status'=>'pending'],'id = ?',[205]);
rejects(fn()=>$snippe->processWebhook($event),'Refunded payment reactivated account');
expect(Database::fetchOne('SELECT status FROM users WHERE id=205')['status']==='pending','Refunded payment bypassed manual review');
$raw='{ "type": "payment.completed" }';$ts=(string)time();$signature=hash_hmac('sha256',$ts.'.'.$raw,'test-signing-secret');
expect($client->verifyWebhookSignature(['X-Webhook-Timestamp'=>$ts,'X-Webhook-Signature'=>$signature],$raw),'Valid signature failed');
expect(!$client->verifyWebhookSignature(['X-Webhook-Timestamp'=>$ts,'X-Webhook-Signature'=>$signature],$raw.' '),'Tampered body accepted');
expect(!$client->verifyWebhookSignature(['X-Webhook-Timestamp'=>(string)(time()-600),'X-Webhook-Signature'=>$signature],$raw),'Old signature accepted');
rejects(fn()=>SnippeClient::checkoutUrl('https://evil.test/checkout/abc'),'Untrusted checkout allowed');
echo "Snippe hosted checkout: exact amount, gateway isolation, duplicate/late settlement, timeout safety, credentials, signatures and return verification passed. No live requests.\n";
