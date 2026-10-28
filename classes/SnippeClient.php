<?php
require_once __DIR__.'/AblinerClient.php';
require_once dirname(__DIR__).'/config/snippe.php';
class SnippeClient extends AblinerClient {
    public function __construct(?array $config=null) {parent::__construct($config??snippeConfig());}
    public function ready(): bool {return !empty($this->config['enabled']) && parent::ready() && !empty($this->config['checkout_token']);}
    public function createSession(array $payload,string $key): array {return $this->snippeRequest('POST','/api/v1/sessions',$payload,$key,true);}
    public function session(string $reference): array {return $this->snippeRequest('GET','/api/v1/sessions/'.rawurlencode($reference),[],null,true);}
    public function payment(string $reference): array {return $this->snippeRequest('GET','/v1/payments/'.rawurlencode($reference));}
    private function snippeRequest(string $method,string $path,array $payload=[],?string $key=null,bool $session=false): array {
        $token=$this->config[$session?'checkout_token':'api_key']??'';
        if (!$token) return ['success'=>false,'uncertain'=>false,'error'=>'Snippe credentials are not configured. Contact support.'];
        if ($key!==null && strlen($key)>30) throw new InvalidArgumentException('Snippe idempotency key is too long.');
        $headers=['Authorization: Bearer '.$token,'Content-Type: application/json','Accept: application/json'];
        if ($key!==null)$headers[]='Idempotency-Key: '.$key;
        $body=$method==='POST'?json_encode($payload,JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES):'';
        $response=$this->sendHttp($method,$this->config['base_url'].$path,$headers,$body);
        $data=json_decode($response['body'],true);
        if ($response['error']!=='' || !is_array($data)) return ['success'=>false,'uncertain'=>true,'error'=>'Snippe verification is unavailable. Do not pay again until this request is checked.'];
        if ($response['http_code']>=200 && $response['http_code']<300 && is_array($data['data']??null) && ($data['status']??'success')==='success') return ['success'=>true,'data'=>$data['data']];
        return ['success'=>false,'uncertain'=>$response['http_code']>=500 || in_array($response['http_code'],[408,409,429],true),
            'error'=>'Snippe could not complete this request. Contact support with your order ID.'];
    }
    public static function checkoutUrl(string $url): string {
        $p=parse_url($url);
        if (!$p || ($p['scheme']??'')!=='https' || !in_array(strtolower($p['host']??''),['snippe.me','www.snippe.me'],true) || isset($p['user']) || isset($p['pass']) || (isset($p['port']) && $p['port']!==443)
            || !preg_match('~^/(?:[a-z]{2}/)?(?:checkout|p)/[A-Za-z0-9_-]+$~D',$p['path']??'')) throw new RuntimeException('Invalid Snippe checkout URL.');
        return $url;
    }
}
