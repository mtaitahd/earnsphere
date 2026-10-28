<?php
require_once __DIR__.'/SnippeClient.php';
require_once __DIR__.'/Auth.php';
require_once __DIR__.'/Wallet.php';
require_once __DIR__.'/RegistrationPayment.php';
class SnippePayment {
    private SnippeClient $client;
    public function __construct(?SnippeClient $client=null) {$this->client=$client??new SnippeClient();}
    public function ready(): bool {return $this->client->ready();}
    private function result(array $row,string $message=''): array {
        return ['success'=>true,'order_id'=>$row['order_id'],'status'=>$row['status'],'phone'=>$row['phone'],
            'message'=>$message?:'Ombi hili linasubiri uthibitisho. Hakuna USSD push nyingine iliyotumwa.'];
    }
    public function initiate(int $userId,string $phone,string $name='',string $email='',bool $confirmNumberChange=false,string $previousOrder=''): array {
        if (!$this->ready())return ['success'=>false,'error'=>'Snippe USSD payments are not configured. Please use Abliner or contact support.'];
        $phone=AblinerClient::normalizePhone($phone);
        $plan=CommissionPlan::values();
        $previous=Database::fetchOne("SELECT * FROM payments WHERE user_id = ? AND purpose = 'registration' AND status = 'pending' ORDER BY id DESC LIMIT 1",[$userId]);
        if ($previous && $previous['gateway']==='snippe') {
            $this->reconcile((int)$previous['id']);
            $checked=Database::fetchOne('SELECT * FROM payments WHERE id = ?',[$previous['id']]);
            if($checked['status']==='completed')return $this->result($checked,'Malipo yamethibitishwa.');
        }
        Database::beginTransaction();
        try {
            $user=Database::fetchOne('SELECT * FROM users WHERE id = ? FOR UPDATE',[$userId]);
            if (!$user || $user['role']!=='user' || $user['status']!=='pending')throw new InvalidArgumentException('Only an unpaid member can start registration payment.');
            $name=trim($name!==''?$name:(string)$user['full_name']);
            $names=preg_split('/\s+/', $name,2);
            if (count($names)!==2 || strlen($name)>200)throw new InvalidArgumentException('Enter your first and last name for Snippe payment.');
            $email=trim($email!==''?$email:(string)($user['email']??''));
            if (strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid email address for Snippe payment.');
            $old=Database::fetchOne("SELECT * FROM payments WHERE user_id = ? AND purpose = 'registration' AND status = 'pending' ORDER BY id DESC LIMIT 1",[$userId]);
            if ($old) {
                if ($old['gateway']!=='snippe' || empty((json_decode($old['metadata']??'{}',true)?:[])['snippe_ussd'])) {
                    Database::commit();return ['success'=>false,'error'=>'An earlier payment is still pending. Check its status before changing payment providers.'];
                }
                if ($old['phone']===$phone) {Database::commit();return $this->result($old);}
                if (!$confirmNumberChange || !hash_equals($old['order_id'],$previousOrder)) {
                    Database::commit();return ['success'=>false,'error'=>'Confirm below that you have not paid and will not approve the old prompt before changing the number.'];
                }
                $same=Database::fetchOne("SELECT * FROM payments WHERE user_id = ? AND purpose = 'registration' AND gateway = 'snippe' AND status = 'pending' AND phone = ? ORDER BY id DESC LIMIT 1",[$userId,$phone]);
                if ($same) {Database::commit();return $this->result($same);}
            }
            $order='SN-'.bin2hex(random_bytes(12));
            $id=Database::insert('payments',['user_id'=>$userId,'order_id'=>$order,'amount'=>$plan['registration_fee'],'currency'=>'TZS','phone'=>$phone,'payment_method'=>'mobile_money','status'=>'pending','gateway'=>'snippe','purpose'=>'registration','idempotency_key'=>$order,'metadata'=>json_encode(['commission_plan'=>$plan,'snippe_ussd'=>true],JSON_THROW_ON_ERROR)]);
            Database::commit();
        } catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
        $payload=['payment_type'=>'mobile','details'=>['amount'=>(int)$plan['registration_fee'],'currency'=>'TZS'],'phone_number'=>$phone,
            'customer'=>['firstname'=>$names[0],'lastname'=>$names[1],'email'=>$email],
            'webhook_url'=>$this->client->callbackUrl(),'metadata'=>['order_id'=>$order]];
        return $this->sendRequest($id,$order,$payload);
    }
    private function sendRequest(int $id,string $order,array $payload): array {
        $response=$this->client->createPayment($payload,$order);
        Database::beginTransaction();
        try {
            $row=Database::fetchOne('SELECT * FROM payments WHERE id = ? FOR UPDATE',[$id]);
            $meta=json_decode($row['metadata'],true)?:[];
            if (!$response['success']) {
                $meta['creation_uncertain']=!empty($response['uncertain']);
                $status=$row['status']==='pending' && empty($response['uncertain'])?'failed':$row['status'];
                Database::update('payments',['status'=>$status,'metadata'=>json_encode($meta,JSON_THROW_ON_ERROR)],'id = ?',[$id]);
                Database::commit();
                if($row['status']==='completed' || !empty($response['uncertain']))return $this->result($row,'Ombi linathibitishwa. Usilipe tena kabla ya kuangalia status.');
                return ['success'=>false,'error'=>$response['error'].' Order: '.$order];
            }
            $data=$response['data'];$amount=$data['amount']??null;
            if (!is_string($data['reference']??null) || strlen($data['reference'])>100 || $data['reference']==='' || !is_array($amount)
                || !is_numeric($amount['value']??null) || (float)$amount['value']!==(float)$row['amount'] || ($amount['currency']??'')!=='TZS'
                || (!empty($row['transaction_ref']) && $row['transaction_ref']!==$data['reference'])
                || (isset($data['payment_type']) && $data['payment_type']!=='mobile'))throw new RuntimeException('Snippe payment identity or amount mismatch.');
            Database::update('payments',['gateway_id'=>$data['reference'],'gateway_reference'=>$data['reference'],'transaction_ref'=>$data['reference']],'id = ?',[$id]);
            Database::commit();
            if(($data['status']??'')==='completed')$this->reconcile($id);
            return $this->result(Database::fetchOne('SELECT * FROM payments WHERE id = ?',[$id]),'Ombi la USSD limetumwa kwa '.$row['phone'].'. Thibitisha kwa PIN kwenye simu yako likifika.');
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
    }
    public function testReady(): bool {return $this->client->configured();}
    public function testPush(int $actor,array $input): array {
        $admin=Database::fetchOne('SELECT * FROM users WHERE id = ?',[$actor]);
        if (!$admin || $admin['role']!=='admin' || $admin['status']!=='active')throw new InvalidArgumentException('Administrator access required.');
        if (!$this->testReady())throw new InvalidArgumentException('Save the Snippe API key and webhook secret first.');
        if (($input['confirmed']??'')!=='1')throw new InvalidArgumentException('Confirm that this is a real USSD payment request.');
        $phone=AblinerClient::normalizePhone(is_string($input['phone']??null)?$input['phone']:'');
        $amount=filter_var($input['amount']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>500,'max_range'=>10000]]);
        if ($amount===false)throw new InvalidArgumentException('Test amount must be whole TZS from 500 to 10,000.');
        $name=trim(is_string($input['name']??null)?$input['name']:'');$names=preg_split('/\s+/',$name,2);
        if (count($names)!==2 || strlen($name)>200)throw new InvalidArgumentException('Enter your first and last name.');
        $email=trim(is_string($input['email']??null)?$input['email']:'');
        if (strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid email address.');
        $order=$input['request_key']??'';
        if (!is_string($order) || !preg_match('/^SN-[a-f0-9]{24}$/D',$order))throw new InvalidArgumentException('Refresh the test form before sending.');
        $old=Database::fetchOne("SELECT * FROM payments WHERE user_id = ? AND purpose = 'gateway_test' AND gateway = 'snippe' AND status = 'pending' ORDER BY id DESC LIMIT 1",[$actor]);
        if($old)$this->reconcile((int)$old['id']);
        Database::beginTransaction();
        try {
            $locked=Database::fetchOne('SELECT * FROM users WHERE id = ? FOR UPDATE',[$actor]);
            if (!$locked || $locked['role']!=='admin' || $locked['status']!=='active')throw new InvalidArgumentException('Administrator access required.');
            $prior=Database::fetchOne('SELECT * FROM payments WHERE order_id = ?',[$order]);
            if ($prior) {
                if ((int)$prior['user_id']!==$actor || $prior['purpose']!=='gateway_test' || $prior['gateway']!=='snippe' || $prior['phone']!==$phone || (int)$prior['amount']!==$amount)throw new InvalidArgumentException('This request key already belongs to different payment details.');
                Database::commit();return $this->result($prior);
            }
            $pending=Database::fetchOne("SELECT * FROM payments WHERE user_id = ? AND purpose = 'gateway_test' AND gateway = 'snippe' AND status = 'pending' ORDER BY id DESC LIMIT 1",[$actor]);
            if($pending) {
                if($pending['phone']!==$phone || (int)$pending['amount']!==$amount)throw new InvalidArgumentException('Check the earlier pending test before sending to another number or amount.');
                Database::commit();return $this->result($pending);
            }
            $id=Database::insert('payments',['user_id'=>$actor,'order_id'=>$order,'amount'=>$amount,'currency'=>'TZS','phone'=>$phone,'payment_method'=>'mobile_money','status'=>'pending','gateway'=>'snippe','purpose'=>'gateway_test','idempotency_key'=>$order,'metadata'=>json_encode(['snippe_ussd'=>true,'admin_test'=>true],JSON_THROW_ON_ERROR)]);
            Database::commit();
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
        return $this->sendRequest($id,$order,['payment_type'=>'mobile','details'=>['amount'=>$amount,'currency'=>'TZS'],'phone_number'=>$phone,
            'customer'=>['firstname'=>$names[0],'lastname'=>$names[1],'email'=>$email],'webhook_url'=>$this->client->callbackUrl(),'metadata'=>['order_id'=>$order]]);
    }
    public function reconcile(int $id): void {
        $row=Database::fetchOne("SELECT * FROM payments WHERE id = ? AND gateway = 'snippe'",[$id]);
        if (!$row || $row['status']!=='pending')return;
        $meta=json_decode($row['metadata']??'{}',true)?:[];
        if (empty($meta['snippe_ussd']) && empty($meta['snippe_hosted']))return;
        if (empty($row['transaction_ref']))return;
        $response=$this->client->payment($row['transaction_ref']);
        if (!$response['success'])throw new RuntimeException('Snippe payment verification unavailable.');
        $this->applyVerified($id,$response['data'],$row['transaction_ref']);
    }
    public function processWebhook(array $event): array {
        $type=$event['type']??'';$data=$event['data']??null;
        if (!in_array($type,['payment.completed','payment.failed','payment.voided','payment.expired'],true))return ['ignored'=>true];
        if (!is_array($data) || ($data['status']??'')!==substr($type,8))throw new InvalidArgumentException('Invalid payment event.');
        $order=$data['metadata']['order_id']??null;
        if (!is_string($order) || !preg_match('/^SN-[a-f0-9]{24}$/D',$order))return ['ignored'=>true];
        $row=Database::fetchOne("SELECT * FROM payments WHERE order_id = ? AND gateway = 'snippe' AND purpose IN ('registration','gateway_test')",[$order]);
        if (!$row)return ['ignored'=>true];
        $stored=json_decode($row['metadata']??'{}',true)?:[];
        if (empty($stored['snippe_ussd']) && empty($stored['snippe_hosted']))return ['ignored'=>true];
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
            if (is_string($data['customer']['phone']??null)) {
                $payer=AblinerClient::normalizePhone($data['customer']['phone']);
                if (!empty($meta['snippe_ussd']) && $payer!==$row['phone'])throw new RuntimeException('Snippe payer phone mismatch.');
                Database::update('payments',['phone'=>$payer],'id = ?',[$id]);
            }
            if ($status==='completed' && $row['purpose']==='registration')RegistrationPayment::activate($row);
            Database::update('payments',['status'=>in_array($status,['voided','expired'],true)?'failed':$status,'gateway_id'=>!empty($meta['snippe_ussd'])?$reference:$row['gateway_id'],'transaction_ref'=>$reference,'gateway_reference'=>$reference,'completed_at'=>$status==='completed'?date('Y-m-d H:i:s'):null,'metadata'=>json_encode($meta,JSON_THROW_ON_ERROR)],'id = ?',[$id]);
            Database::commit();
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
    }
}
