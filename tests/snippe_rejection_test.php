<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/social_loans_fixture.php';require dirname(__DIR__).'/classes/SnippePayout.php';require_once dirname(__DIR__).'/includes/helpers.php';
class RejectingSnippe extends SnippePayoutClient {
    public int $http=403;public int $posts=0;
    public function __construct() {parent::__construct(['api_key'=>'secret-test-key','webhook_secret'=>'secret','enabled'=>true,'base_url'=>'https://api.snippe.sh','callback_url'=>'https://example.test/webhooks/snippe.php'],new MockAbliner());}
    protected function sendHttp(string $method,string $url,array $headers,string $body): array {
        $this->posts++;
        return ['http_code'=>$this->http,'error'=>'','body'=>json_encode(['status'=>'error','error_code'=>$this->http===403?'insufficient_scope':'payment_failed','message'=>$this->http===403?'API key secret-test-key missing disbursement:create':'insufficient balance: available 100, required 5100'])];
    }
}
foreach([301,302] as $uid) {Database::insert('users',['id'=>$uid,'status'=>'active','role'=>'user']);Database::insert('wallets',['user_id'=>$uid,'balance'=>25000,'withdrawable_balance'=>25000]);}
$client=new RejectingSnippe();$service=new SnippePayout($client);$result=$service->requestWithdrawal(301,5000,'0712345678','TEST RECIPIENT',500,bin2hex(random_bytes(32)));
expect($result['payout_status']==='failed' && str_contains($result['message'],'ruhusa'),'403 not explained');
$p=Database::fetchOne('SELECT * FROM payouts WHERE withdrawal_id=?',[$result['withdrawal_id']]);$meta=json_decode($p['metadata'],true);
expect($meta['code']==='insufficient_scope' && $meta['http_status']===403,'Provider error diagnosis lost');
expect(!str_contains($p['metadata'],'secret-test-key') && !str_contains($result['message'],'secret-test-key'),'Provider secret leaked');
$w=Database::fetchOne('SELECT * FROM wallets WHERE user_id=301');expect((float)$w['withdrawable_balance']===25000.0 && (float)$w['pending_amount']===0.0,'Definitive rejection did not release hold');
$service->sendPayout((int)$p['withdrawal_id'],301);expect($client->posts===1,'Existing failed payout resent');
expect(str_contains(snippePayoutFailureText($p['metadata']),'ruhusa'),'History lacks rejection reason');
$client->http=500;$result=$service->requestWithdrawal(302,5000,'0712345678','TEST RECIPIENT',500,bin2hex(random_bytes(32)));
expect($result['payout_status']==='pending' && str_contains($result['message'],'Balance'),'Uncertain insufficient balance response not explained');
$w=Database::fetchOne('SELECT * FROM wallets WHERE user_id=302');expect((float)$w['withdrawable_balance']===20000.0 && (float)$w['pending_amount']===5000.0,'Uncertain failure released hold');
$data=$client->normalizePayout(['reference'=>'failed-reference','amount'=>['value'=>4500,'currency'=>'TZS'],'recipient'=>['phone'=>'255712345678'],'status'=>'failed','failure_reason'=>'insufficient balance secret-test-key']);
expect($data['failure_code']==='insufficient_balance' && !str_contains(json_encode($data),'secret-test-key'),'Provider failure reason not safely classified');
echo "Snippe rejection reasons: HTTP/code retained, scope errors explained, secret text excluded, definitive refunds once and uncertain holds retained. No live requests.\n";
