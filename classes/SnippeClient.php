<?php
require_once __DIR__.'/AblinerClient.php';
require_once __DIR__.'/SnippeErrors.php';
require_once dirname(__DIR__).'/config/snippe.php';
class SnippeClient extends AblinerClient {
    public function __construct(?array $config=null) {parent::__construct($config??snippeConfig());}
    public function configured(): bool {return parent::ready();}
    public function ready(): bool {return !empty($this->config['enabled']) && $this->configured();}
    public function createPayment(array $payload,string $key): array {return $this->snippeRequest('POST','/v1/payments',$payload,$key);}
    public function payment(string $reference): array {return $this->snippeRequest('GET','/v1/payments/'.rawurlencode($reference));}
    public function createPayout(array $payload,string $key): array {return $this->snippeRequest('POST','/v1/payouts/send',$payload,$key);}
    public function payoutFee(int $amount): array {return $this->snippeRequest('GET','/v1/payouts/fee?amount='.$amount);}
    public function payout(string $reference): array {return $this->snippeRequest('GET','/v1/payouts/'.rawurlencode($reference));}
    private function snippeRequest(string $method,string $path,array $payload=[],?string $key=null): array {
        $token=$this->config['api_key']??'';
        if (!$token) return ['success'=>false,'code'=>'snippe_error','uncertain'=>false,'error'=>'Snippe credentials are not configured. Contact support.'];
        if ($key!==null && strlen($key)>30) throw new InvalidArgumentException('Snippe idempotency key is too long.');
        $headers=['Authorization: Bearer '.$token,'Content-Type: application/json','Accept: application/json'];
        if ($key!==null)$headers[]='Idempotency-Key: '.$key;
        $body=$method==='POST'?json_encode($payload,JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES):'';
        $response=$this->sendHttp($method,$this->config['base_url'].$path,$headers,$body);
        $data=json_decode($response['body'],true);
        if ($response['error']!=='' || !is_array($data)) return ['success'=>false,'code'=>'snippe_error','uncertain'=>true,'error'=>'Snippe verification is unavailable. Do not pay again until this request is checked.'];
        if ($response['http_code']>=200 && $response['http_code']<300 && is_array($data['data']??null) && ($data['status']??'success')==='success') return ['success'=>true,'data'=>$data['data']];
        $code=SnippeErrors::code((int)$response['http_code'],$data);
        return ['success'=>false,'code'=>$code,'http_status'=>(int)$response['http_code'],
            'uncertain'=>$response['http_code']>=500 || in_array($response['http_code'],[408,409,429],true),
            'error'=>SnippeErrors::message($code)];
    }
}
