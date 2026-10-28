<?php
require_once __DIR__.'/SnippeClient.php';
require_once __DIR__.'/Auth.php';
require_once __DIR__.'/Wallet.php';
require_once __DIR__.'/RegistrationPayment.php';
class SnippePayment {
    private SnippeClient $client;
    public function __construct(?SnippeClient $client=null) {$this->client=$client??new SnippeClient();}
    public function ready(): bool {return $this->client->ready();}
    private function result(array $row): array {
        if ($row['status']==='completed')return ['success'=>true,'order_id'=>$row['order_id'],'status'=>'completed','checkout_url'=>''];
        $meta=json_decode($row['metadata']??'{}',true)?:[];
        if (empty($meta['checkout_url'])) return ['success'=>false,'order_id'=>$row['order_id'],'error'=>'Checkout creation is awaiting verification. Contact support with order '.$row['order_id'].'. Do not create another payment.'];
        return ['success'=>true,'order_id'=>$row['order_id'],'checkout_url'=>SnippeClient::checkoutUrl($meta['checkout_url']),'status'=>$row['status']];
    }
    public function initiate(int $userId): array {
        if (!$this->ready())return ['success'=>false,'error'=>'Snippe checkout is not configured. Please use Abliner or contact support.'];
        $plan=CommissionPlan::values();
        Database::beginTransaction();
        try {
            $user=Database::fetchOne('SELECT * FROM users WHERE id = ? FOR UPDATE',[$userId]);
            if (!$user || $user['role']!=='user' || $user['status']!=='pending')throw new InvalidArgumentException('Only an unpaid member can start registration payment.');
            $old=Database::fetchOne("SELECT * FROM payments WHERE user_id = ? AND purpose = 'registration' AND status = 'pending' ORDER BY id DESC LIMIT 1",[$userId]);
            if ($old) {
                Database::commit();
                if ($old['gateway']!=='snippe' || empty((json_decode($old['metadata']??'{}',true)?:[])['snippe_hosted']))return ['success'=>false,'error'=>'An earlier payment is still pending. Check its status before changing payment providers.'];
                return $this->result($old);
            }
            $order='SN-'.bin2hex(random_bytes(12));
            $id=Database::insert('payments',['user_id'=>$userId,'order_id'=>$order,'amount'=>$plan['registration_fee'],'currency'=>'TZS','phone'=>$user['phone'],'payment_method'=>'mobile_money','status'=>'pending','gateway'=>'snippe','purpose'=>'registration','idempotency_key'=>$order,'metadata'=>json_encode(['commission_plan'=>$plan,'snippe_hosted'=>true],JSON_THROW_ON_ERROR)]);
            Database::commit();
        } catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
        $return=rtrim(SITE_URL,'/').'/payment?action=waiting&order_id='.rawurlencode($order);
        $payload=['amount'=>(int)$plan['registration_fee'],'currency'=>'TZS','allowed_methods'=>['mobile_money'],'allow_custom_amount'=>false,
            'customer'=>['name'=>$user['full_name'],'phone'=>$user['phone']], 'redirect_url'=>$return,'webhook_url'=>$this->client->callbackUrl(),
            'description'=>'EarnSphere registration','metadata'=>['order_id'=>$order],'expires_in'=>3600];
        if (filter_var($user['email']??'',FILTER_VALIDATE_EMAIL))$payload['customer']['email']=$user['email'];
        $response=$this->client->createSession($payload,$order);
        Database::beginTransaction();
        try {
            $row=Database::fetchOne('SELECT * FROM payments WHERE id = ? FOR UPDATE',[$id]);
            $meta=json_decode($row['metadata'],true)?:[];
            if (!$response['success']) {
                $meta['creation_uncertain']=!empty($response['uncertain']);
                Database::update('payments',['status'=>$row['status']==='pending' && empty($response['uncertain'])?'failed':$row['status'],'metadata'=>json_encode($meta,JSON_THROW_ON_ERROR)],'id = ?',[$id]);
                Database::commit();if($row['status']==='completed')return $this->result($row);return ['success'=>false,'error'=>$response['error'].' Order: '.$order];
            }
            $data=$response['data'];
            if (!is_string($data['reference']??null) || strlen($data['reference'])>100 || $data['reference']===''
                || (int)($data['amount']??0)!==(int)$row['amount'] || ($data['currency']??'')!=='TZS')throw new RuntimeException('Snippe session identity or amount mismatch.');
            $meta['checkout_url']=SnippeClient::checkoutUrl((string)($data['checkout_url']??''));
            $meta['session_reference']=$data['reference'];
            Database::update('payments',['gateway_id'=>$data['reference'],'metadata'=>json_encode($meta,JSON_THROW_ON_ERROR)],'id = ?',[$id]);
            Database::commit();$row['metadata']=json_encode($meta,JSON_THROW_ON_ERROR);return $this->result($row);
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
    }
    public function reconcile(int $id): void {
        $row=Database::fetchOne("SELECT * FROM payments WHERE id = ? AND gateway = 'snippe'",[$id]);
        if (!$row || $row['status']!=='pending')return;
        $meta=json_decode($row['metadata']??'{}',true)?:[];
        if (empty($meta['snippe_hosted']))return;
        if (!empty($row['transaction_ref'])) {
            $response=$this->client->payment($row['transaction_ref']);
            if (!$response['success'])throw new RuntimeException('Snippe payment verification unavailable.');
            $this->applyVerified($id,$response['data'],$row['transaction_ref']);return;
        }
        if (empty($meta['session_reference']))return;
        $response=$this->client->session($meta['session_reference']);
        if (!$response['success'])throw new RuntimeException('Snippe session verification unavailable.');
        $data=$response['data'];
        if (($data['reference']??'')!==$meta['session_reference'] || ($data['currency']??'')!=='TZS' || (int)($data['amount']??0)!==(int)$row['amount'])throw new RuntimeException('Snippe session mismatch.');
        // A completed checkout alone is not proof of a verified payment reference.
        if (in_array($data['status']??'',['expired','cancelled'],true)) {
            Database::update('payments',['status'=>'failed'],'id = ? AND status = ?',[$id,'pending']);
        }
    }
    public function processWebhook(array $event): array {
        $type=$event['type']??'';$data=$event['data']??null;
        if (!in_array($type,['payment.completed','payment.failed','payment.voided','payment.expired'],true))return ['ignored'=>true];
        if (!is_array($data) || ($data['status']??'')!==substr($type,8))throw new InvalidArgumentException('Invalid payment event.');
        $order=$data['metadata']['order_id']??null;
        if (!is_string($order) || !preg_match('/^SN-[a-f0-9]{24}$/D',$order))return ['ignored'=>true];
        $row=Database::fetchOne("SELECT * FROM payments WHERE order_id = ? AND gateway = 'snippe' AND purpose = 'registration'",[$order]);
        if (!$row)return ['ignored'=>true];
        if (empty((json_decode($row['metadata']??'{}',true)?:[])['snippe_hosted']))return ['ignored'=>true];
        $reference=$data['reference']??'';
        if (!is_string($reference) || $reference==='' || strlen($reference)>100)throw new InvalidArgumentException('Missing reference.');
        $response=$this->client->payment($reference);
        if (!$response['success'])throw new RuntimeException('Could not verify Snippe payment.');
        if (($response['data']['status']??'')!==$data['status'])throw new RuntimeException('Webhook and verified status differ.');
        $this->applyVerified((int)$row['id'],$response['data'],$reference);
        return ['success'=>true];
    }
    private function applyVerified(int $id,array $data,string $reference): void {
        Database::beginTransaction();
        try {
            $row=Database::fetchOne('SELECT * FROM payments WHERE id = ? FOR UPDATE',[$id]);
            $amount=$data['amount']??null;
            if (!$row || $row['gateway']!=='snippe' || ($data['reference']??'')!==$reference || !is_array($amount)
                || !is_numeric($amount['value']??null) || (float)$amount['value']!==(float)$row['amount'] || ($amount['currency']??'')!=='TZS'
                || (!empty($row['transaction_ref']) && $row['transaction_ref']!==$reference)
                || (isset($data['metadata']['order_id']) && $data['metadata']['order_id']!==$row['order_id']))throw new RuntimeException('Snippe payment identity or amount mismatch.');
            $status=$data['status']??'';
            if (!in_array($status,['completed','pending','failed','voided','expired'],true))throw new RuntimeException('Unknown Snippe status.');
            $other=Database::fetchOne("SELECT id FROM payments WHERE gateway = 'snippe' AND transaction_ref = ? AND id <> ?",[$reference,$id]);
            if ($other)throw new RuntimeException('Payment reference already belongs to another order.');
            if ($row['status']==='completed') {Database::commit();return;}
            if (!in_array($row['status'],['pending','failed'],true))throw new RuntimeException('Snippe payment requires manual review.');
            if ($row['status']!=='pending' && $status!=='completed'){Database::commit();return;}
            $meta=json_decode($row['metadata']??'{}',true)?:[];$meta['verified_payment_status']=$status;
            if (is_string($data['customer']['phone']??null)) Database::update('payments',['phone'=>AblinerClient::normalizePhone($data['customer']['phone'])],'id = ?',[$id]);
            if ($status==='completed')RegistrationPayment::activate($row);
            Database::update('payments',['status'=>in_array($status,['voided','expired'],true)?'failed':$status,'transaction_ref'=>$reference,'gateway_reference'=>$reference,'completed_at'=>$status==='completed'?date('Y-m-d H:i:s'):null,'metadata'=>json_encode($meta,JSON_THROW_ON_ERROR)],'id = ?',[$id]);
            Database::commit();
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
    }
}
