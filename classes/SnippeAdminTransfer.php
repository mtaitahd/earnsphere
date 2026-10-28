<?php
require_once __DIR__.'/SnippePayout.php';
/** Company transfers are separate from member withdrawals and never debit a member wallet. */
final class SnippeAdminTransfer {
    private SnippePayoutClient $client;
    public function __construct(?SnippePayoutClient $client=null) {$this->client=$client??new SnippePayoutClient();}
    private function administrator(int $actor): void {
        $user=Database::fetchOne('SELECT role,status FROM users WHERE id = ?',[$actor]);
        if (!$user || $user['role']!=='admin' || $user['status']!=='active') throw new InvalidArgumentException('Administrator access required.');
    }
    public function ready(): bool {
        if (!$this->client->configured()) return false;
        try {Database::fetchOne('SELECT id FROM snippe_admin_transfers LIMIT 1');return true;}
        catch(Throwable $e) {return false;}
    }
    public function preview(int $actor,array $input): array {
        $this->administrator($actor);
        if (!$this->ready()) throw new InvalidArgumentException('Configure Snippe and run the admin transfer migration first.');
        $amount=filter_var($input['amount']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>500,'max_range'=>3000000]]);
        if ($amount===false) throw new InvalidArgumentException('Enter a whole TZS amount from 500 to 3,000,000.');
        $phone=AblinerClient::normalizePhone(is_string($input['phone']??null)?$input['phone']:'');
        $lookup=$this->client->lookup($phone);
        if (empty($lookup['success']) || empty($lookup['data']['name']) || AblinerClient::normalizePhone((string)($lookup['data']['phone']??''))!==$phone) throw new RuntimeException('Recipient could not be verified. Check the number and try again.');
        $response=$this->client->payoutFee($amount);$fee=$response['data']??[];
        if (empty($response['success']) || ($fee['currency']??'')!=='TZS' || !is_numeric($fee['amount']??null) || (float)$fee['amount']!==(float)$amount
            || !is_numeric($fee['fee_amount']??null) || (float)$fee['fee_amount']<0 || !is_numeric($fee['total_amount']??null)
            || (float)$fee['total_amount']!==$amount+(float)$fee['fee_amount']) throw new RuntimeException('Snippe fee could not be confirmed. No transfer was sent.');
        return ['amount'=>$amount,'phone'=>$phone,'name'=>(string)$lookup['data']['name'],'fees'=>(float)$fee['fee_amount'],'total'=>(float)$fee['total_amount']];
    }
    public function send(int $actor,array $quote,string $key,bool $confirmed): array {
        $this->administrator($actor);
        if (!$confirmed) throw new InvalidArgumentException('Confirm the recipient, amount and Snippe fee first.');
        if (!preg_match('/^SN-CA-[a-f0-9]{24}$/D',$key)) throw new InvalidArgumentException('Refresh the transfer form.');
        $prior=Database::fetchOne('SELECT * FROM snippe_admin_transfers WHERE idempotency_key = ?',[$key]);
        if ($prior) {
            if ((int)$prior['user_id']!==$actor || (float)$prior['amount']!==(float)($quote['amount']??0) || $prior['recipient_phone']!==($quote['phone']??'')) throw new InvalidArgumentException('This request key belongs to different transfer details.');
            return $prior;
        }
        $fresh=$this->preview($actor,$quote);
        foreach(['amount','phone','name','fees','total'] as $field) if (($fresh[$field]??null)!==($quote[$field]??null)) throw new InvalidArgumentException('Recipient or fee changed. Preview and confirm the new details.');
        Database::beginTransaction();
        try {
            $user=Database::fetchOne('SELECT role,status FROM users WHERE id = ? FOR UPDATE',[$actor]);
            if (!$user || $user['role']!=='admin' || $user['status']!=='active') throw new InvalidArgumentException('Administrator access required.');
            $existing=Database::fetchOne("SELECT * FROM snippe_admin_transfers WHERE user_id = ? AND (status = 'pending' OR idempotency_key = ?) ORDER BY id DESC LIMIT 1",[$actor,$key]);
            if ($existing) {
                if ((float)$existing['amount']!==(float)$fresh['amount'] || $existing['recipient_phone']!==$fresh['phone']) throw new InvalidArgumentException('Company transfer #'.$existing['id'].' is still pending. Check it before sending another transfer.');
                Database::commit();return $existing;
            }
            $id=Database::insert('snippe_admin_transfers',['user_id'=>$actor,'amount'=>$fresh['amount'],'fees'=>$fresh['fees'],'total'=>$fresh['total'],
                'recipient_phone'=>$fresh['phone'],'recipient_name'=>$fresh['name'],'status'=>'pending','idempotency_key'=>$key,'metadata'=>'{}']);
            Database::commit();
        } catch(Throwable $e) {if(Database::inTransaction())Database::rollback();throw $e;}
        Auth::logActivity($actor,'snippe_company_transfer_requested','Company transfer #'.$id.' TZS '.$fresh['amount'].' to '.$fresh['phone']);
        $result=$this->client->createPayout(['amount'=>$fresh['amount'],'channel'=>'mobile','recipient_phone'=>$fresh['phone'],'recipient_name'=>$fresh['name'],
            'narration'=>'EarnSphere company withdrawal','webhook_url'=>$this->client->callbackUrl(),'metadata'=>['request_key'=>$key]],$key);
        // Preserve the authenticated response before parsing: money may already have moved.
        if (!empty($result['success'])) {
            Database::update('snippe_admin_transfers',['metadata'=>json_encode(['submission_response'=>$result['data']],JSON_THROW_ON_ERROR)],'id = ? AND status = ?',[$id,'pending']);
            try {$this->apply($id,$result['data']);}catch(Throwable $e) {error_log('Snippe company transfer requires review #'.$id.': '.$e->getMessage());}
        } elseif (empty($result['uncertain'])) {
            Database::update('snippe_admin_transfers',['status'=>'failed','metadata'=>json_encode(['error'=>'Provider rejected the request.'])],'id = ? AND status = ?',[$id,'pending']);
        }
        return Database::fetchOne('SELECT * FROM snippe_admin_transfers WHERE id = ?',[$id]);
    }
    private function apply(int $id,array $raw): void {
        $data=$this->client->normalizePayout($raw);
        Database::beginTransaction();
        try {
            $row=Database::fetchOne('SELECT * FROM snippe_admin_transfers WHERE id = ? FOR UPDATE',[$id]);
            if (!$row || (float)$row['amount']!==$data['amount'] || $row['recipient_phone']!==$data['recipient']
                || (!empty($data['customer_reference']) && !hash_equals($row['idempotency_key'],$data['customer_reference']))
                || (!empty($row['reference']) && !hash_equals($row['reference'],$data['reference']))) throw new RuntimeException('Company payout identity mismatch.');
            $other=Database::fetchOne('SELECT id FROM snippe_admin_transfers WHERE reference = ? AND id <> ?',[$data['reference'],$id]);
            if ($other) throw new RuntimeException('Payout reference already used.');
            if ($row['status']!=='pending') {
                if ($row['status']!==$data['status']) throw new RuntimeException('Conflicting final payout states require review.');
                Database::commit();return;
            }
            $changes=['reference'=>$data['reference'],'status'=>$data['status'],'metadata'=>json_encode(['verified_response'=>$raw],JSON_THROW_ON_ERROR)];
            if (isset($data['fee_tzs'])) $changes['fees']=$data['fee_tzs'];
            if (isset($data['total_debited_tzs'])) $changes['total']=$data['total_debited_tzs'];
            Database::update('snippe_admin_transfers',$changes,'id = ?',[$id]);Database::commit();
        } catch(Throwable $e) {if(Database::inTransaction())Database::rollback();throw $e;}
    }
    public function check(int $actor,int $id): array {
        $this->administrator($actor);
        $row=Database::fetchOne('SELECT * FROM snippe_admin_transfers WHERE id = ?',[$id]);
        if (!$row) throw new InvalidArgumentException('Transfer not found.');
        if ($row['status']!=='pending') return $row;
        $meta=json_decode($row['metadata']??'{}',true)?:[];
        $reference=$row['reference']?:($meta['submission_response']['reference']??'');
        if (!is_string($reference) || $reference==='') throw new RuntimeException('Snippe has not confirmed a reference. Contact Snippe with request '.$row['idempotency_key'].'. Do not send again.');
        $result=$this->client->payout($reference);
        if (empty($result['success']) || ($result['data']['reference']??'')!==$reference) throw new RuntimeException('Transfer status is unavailable. Do not send again.');
        $this->apply($id,$result['data']);return Database::fetchOne('SELECT * FROM snippe_admin_transfers WHERE id = ?',[$id]);
    }
    public function history(int $actor): array {$this->administrator($actor);return Database::fetchAll('SELECT * FROM snippe_admin_transfers ORDER BY id DESC LIMIT 30');}
    public function processWebhook(array $body): array {
        if (!in_array($body['type']??'', ['payout.completed','payout.failed','payout.reversed'],true)) return ['ignored'=>true];
        if (!$this->ready()) return ['ignored'=>true];
        $ref=$body['data']['reference']??'';$key=$body['data']['metadata']['request_key']??'';
        if (!is_string($ref) || !is_string($key) || $ref==='') return ['ignored'=>true];
        $row=Database::fetchOne('SELECT * FROM snippe_admin_transfers WHERE reference = ? OR idempotency_key = ? LIMIT 1',[$ref,$key]);
        if (!$row) return ['ignored'=>true];
        if ($body['type']==='payout.reversed') throw new RuntimeException('Company payout reversal requires manual review.');
        $result=$this->client->payout($ref);
        if (empty($result['success']) || ($result['data']['reference']??'')!==$ref || ($result['data']['status']??'')!==substr($body['type'],7)) throw new RuntimeException('Webhook does not match verified payout.');
        $this->apply((int)$row['id'],$result['data']);return ['success'=>true];
    }
}
