<?php
require_once __DIR__.'/SnippeClient.php';
require_once __DIR__.'/AblinerPayment.php';

/** Translate the documented Snippe payout response into the shared wallet settlement format. */
class SnippePayoutClient extends SnippeClient {
    private AblinerClient $recipientLookup;
    public function __construct(?array $config=null, ?AblinerClient $lookup=null) {
        if ($config===null) { $config=snippeConfig(); $config['enabled']=(string)app_setting('snippe_payout_enabled','0')==='1'; }
        parent::__construct($config); $this->recipientLookup=$lookup??new AblinerClient();
    }
    public function lookup(string $phone): array {return $this->recipientLookup->lookup($phone);}
    public function normalizePayout(array $data): array {
        $reference=$data['reference']??null; $amount=$data['amount']??null; $phone=$data['recipient']['phone']??null;
        if (!is_string($reference) || $reference==='' || strlen($reference)>100 || !is_array($amount)
            || !is_numeric($amount['value']??null) || (float)$amount['value']<=0 || ($amount['currency']??'')!=='TZS'
            || !is_string($phone) || !in_array($data['status']??'', ['pending','completed','failed'],true)) throw new RuntimeException('Invalid Snippe payout response. Funds remain reserved.');
        $result=['id'=>$reference,'reference'=>$reference,'type'=>'withdrawal','amount'=>(float)$amount['value'],'currency'=>'TZS',
            'status'=>$data['status'],'recipient'=>self::normalizePhone($phone),'provider_reference'=>$data['external_reference']??null];
        if ($data['status']==='failed') {
            $result['failure_code']=SnippeErrors::reason(is_string($data['failure_reason']??null)?$data['failure_reason']:'');
            $result['failure_reason']=SnippeErrors::message($result['failure_code']);
        }
        if (isset($data['metadata']['request_key'])) $result['customer_reference']=$data['metadata']['request_key'];
        foreach (['fees'=>'fee_tzs','total'=>'total_debited_tzs'] as $field=>$target) {
            if (isset($data[$field])) {
                if (!is_array($data[$field]) || ($data[$field]['currency']??'')!=='TZS' || !is_numeric($data[$field]['value']??null) || (float)$data[$field]['value']<0) throw new RuntimeException('Invalid payout fee response.');
                $result[$target]=(float)$data[$field]['value'];
            }
        }
        return $result;
    }
    public function withdraw(array $payload,string $key): array {
        $result=$this->createPayout(['amount'=>$payload['amount'],'channel'=>'mobile','recipient_phone'=>$payload['recipient'],
            'recipient_name'=>$payload['recipient_name'],'narration'=>'EarnSphere withdrawal','webhook_url'=>$this->callbackUrl(),
            'metadata'=>['request_key'=>$key]],$key);
        if ($result['success']) $result['data']=$this->normalizePayout($result['data']);
        return $result;
    }
    public function transactions(array $filter): array {
        if (empty($filter['id'])) return ['success'=>false,'error'=>'Transfer reference is not confirmed yet. Funds remain reserved; contact support.'];
        $result=$this->payout($filter['id']);
        if ($result['success']) $result['data']=[$this->normalizePayout($result['data'])];
        return $result;
    }
}
class SnippePayout extends AblinerPayment {
    private SnippePayoutClient $snippe;
    public function __construct(?SnippePayoutClient $client=null) {$this->snippe=$client??new SnippePayoutClient();parent::__construct($this->snippe);}
    protected function gateway(): string {return 'snippe';}
    public function applyTransaction(string $table,int $id,array $data,bool $nested=false): void {
        $row=Database::fetchOne('SELECT * FROM payouts WHERE id = ?',[$id]);
        if ($table!=='payouts' || !$row || $row['gateway']!=='snippe' || !isset($data['amount']) || (float)$data['amount']!==(float)$row['amount']) throw new RuntimeException('Snippe payout amount mismatch; funds remain reserved.');
        parent::applyTransaction($table,$id,$data,$nested);
    }
    public function processWebhook(array $body): array {
        if (!in_array($body['type']??'', ['payout.completed','payout.failed','payout.reversed'],true)) return ['ignored'=>true];
        $data=$body['data']??[]; $reference=$data['reference']??null;
        if (!is_string($reference) || $reference==='' || strlen($reference)>100) throw new InvalidArgumentException('Missing payout reference.');
        $verified=$this->snippe->payout($reference);
        if (!$verified['success']) throw new RuntimeException('Payout verification unavailable.');
        $record=$verified['data'];
        if (($record['reference']??'')!==$reference) throw new RuntimeException('Payout reference mismatch.');
        $key=$record['metadata']['request_key']??'';
        if (!is_string($key)) throw new RuntimeException('Invalid payout key.');
        $row=Database::fetchOne("SELECT * FROM payouts WHERE gateway = 'snippe' AND (gateway_id = ? OR idempotency_key = ?) ORDER BY id DESC LIMIT 1",[$reference,$key]);
        if (!$row) return ['ignored'=>true];
        // Reversals require manual review; never automatically release a previously settled payout.
        if (($body['type']??'')==='payout.reversed') throw new RuntimeException('Payout reversed; manual reconciliation required.');
        $normalized=$this->snippe->normalizePayout($record);
        if ($normalized['status']!==substr($body['type'],7)) throw new RuntimeException('Webhook and API status differ.');
        $this->applyTransaction('payouts',(int)$row['id'],$normalized);
        return ['success'=>true];
    }
}
