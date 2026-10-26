<?php
require_once __DIR__ . '/SocialHub.php';
class LoanApplications {
    public static function policy(bool $lock=false): array {
        $row=Database::fetchOne('SELECT setting_value FROM settings WHERE setting_key = ?'.($lock?' FOR UPDATE':''),['loan_policy']);
        $policy=$row ? json_decode($row['setting_value'],true) : null;
        return is_array($policy) ? $policy : ['enabled'=>false,'terms'=>'','min_amount'=>1000,'max_amount'=>100000,'min_days'=>30,'min_referrals'=>0];
    }
    public static function hash(array $policy): string { return hash('sha256',json_encode($policy,JSON_THROW_ON_ERROR)); }
    public static function savePolicy(int $admin,array $in): void {
        if(!StaffAccess::canForUser($admin,'loans'))throw new InvalidArgumentException('Loan management permission required.');
        $enabled=($in['enabled']??'')==='1';
        $terms=trim((string)($in['terms']??''));
        if(($enabled && $terms==='') || mb_strlen($terms)>10000)throw new InvalidArgumentException('Publish loan terms, repayment period, charges and eligibility (maximum 10,000 characters).');
        $policy=['enabled'=>$enabled,'terms'=>$terms,'min_amount'=>SocialHub::number($in['min_amount']??'',1000,100000000,'Minimum amount'), 'max_amount'=>SocialHub::number($in['max_amount']??'',1000,100000000,'Maximum amount'), 'min_days'=>SocialHub::number($in['min_days']??'',0,3650,'Minimum account age in days'), 'min_referrals'=>SocialHub::number($in['min_referrals']??'',0,100000,'Minimum paid direct referrals')];
        if($policy['min_amount']>$policy['max_amount'])throw new InvalidArgumentException('Minimum amount cannot exceed maximum.');
        Database::beginTransaction();
        try {
            if(Database::fetchOne('SELECT id FROM settings WHERE setting_key = ? FOR UPDATE',['loan_policy']))Database::update('settings',['setting_value'=>json_encode($policy,JSON_THROW_ON_ERROR)],'setting_key = ?',['loan_policy']);
            else Database::insert('settings',['setting_key'=>'loan_policy','setting_value'=>json_encode($policy,JSON_THROW_ON_ERROR),'description'=>'Published loan application eligibility and terms']);
            Database::commit();
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
    }
    public static function eligibility(array $user,array $policy): array {
        $fail=[];
        if(!$policy['enabled'])$fail[]='Loan applications are currently closed.';
        if($user['role']!=='user' || $user['status']!=='active')$fail[]='Your account must be paid and active.';
        $joined=isset($user['created_at'])?strtotime($user['created_at'].' UTC'):false;
        if(!$joined || $joined > time()-(int)$policy['min_days']*86400)$fail[]='Account must be at least '.$policy['min_days'].' days old.';
        $paid=Database::count('users','referred_by = ? AND role = ? AND status = ?',[$user['id'],'user','active']);
        if($paid < (int)$policy['min_referrals'])$fail[]='At least '.$policy['min_referrals'].' active paid direct referrals are required.';
        return $fail;
    }
    public static function apply(int $userId,array $in): void {
        $purpose=SocialHub::text($in['purpose']??'',1500,'Loan purpose');
        $key=SocialHub::text($in['request_key']??'',64,'Request key');
        if(($in['accepted']??'')!=='1')throw new InvalidArgumentException('Read and accept the published terms first.');
        Database::beginTransaction();
        try {
            $user=SocialHub::activeUser($userId);
            $existing=Database::fetchOne('SELECT id,user_id FROM loan_applications WHERE request_key = ?',[$key]);
            if($existing){if((int)$existing['user_id']!==$userId)throw new InvalidArgumentException('Invalid request key.');Database::commit();return;}
            $policy=self::policy(true);
            if(!is_string($in['terms_hash']??null) || !hash_equals(self::hash($policy),$in['terms_hash']))throw new InvalidArgumentException('Loan terms changed. Read the latest terms before applying.');
            $fails=self::eligibility($user,$policy); if($fails)throw new InvalidArgumentException(implode(' ',$fails));
            if(Database::count('loan_applications','user_id = ? AND status IN (?,?)',[$userId,'pending','approved']))throw new InvalidArgumentException('You already have an open loan application. Contact support about that application.');
            $amount=SocialHub::number($in['amount']??'',$policy['min_amount'],$policy['max_amount'],'Loan amount');
            $id=Database::insert('loan_applications',['user_id'=>$userId,'request_key'=>$key,'amount'=>$amount,'purpose'=>$purpose,'terms_hash'=>self::hash($policy),'terms_snapshot'=>json_encode($policy,JSON_THROW_ON_ERROR),'created_at'=>gmdate('Y-m-d H:i:s')]);
            SocialHub::notification('loan-request:'.$id,$userId,'Loan application received','Ombi lako la mkopo '.formatCurrency($amount).' limepokelewa kwa ukaguzi. Hakuna fedha iliyotumwa bado.');
            Database::commit();
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
    }
    // Application decisions only: no wallet credit or provider disbursement here.
    public static function review(int $admin,int $id,string $decision,$note): void {
        if(!StaffAccess::canForUser($admin,'loans'))throw new InvalidArgumentException('Loan management permission required.');
        if(!in_array($decision,['approved','rejected','closed'],true))throw new InvalidArgumentException('Invalid loan decision.');
        $note=SocialHub::text($note,1000,'Decision / next steps');
        Database::beginTransaction();
        try {
            $row=Database::fetchOne('SELECT * FROM loan_applications WHERE id = ? FOR UPDATE',[$id]);
            if(!$row)throw new InvalidArgumentException('Application not found.');
            if($row['status']===$decision){Database::commit();return;}
            if(($row['status']!=='pending' && !($row['status']==='approved' && $decision==='closed')) || ($row['status']==='pending' && $decision==='closed'))throw new InvalidArgumentException('This application has already been reviewed.');
            if($decision==='approved') {
                $user=Database::fetchOne('SELECT status,role FROM users WHERE id = ?',[$row['user_id']]);
                if(!$user || $user['status']!=='active' || $user['role']!=='user')throw new InvalidArgumentException('Only an active user can be approved.');
            }
            Database::update('loan_applications',['status'=>$decision,'review_note'=>$note,'reviewed_by'=>$admin,'reviewed_at'=>gmdate('Y-m-d H:i:s')],'id = ?',[$id]);
            SocialHub::notification('loan:'.$id.':'.$decision,(int)$row['user_id'],'Loan application '.$decision,$note.($decision==='approved'?' Approval is for follow-up; no money has been sent by the system.':''));
            Database::commit();
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
    }
}
