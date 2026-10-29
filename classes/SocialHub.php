<?php
require_once __DIR__ . '/Wallet.php';
require_once __DIR__ . '/StaffAccess.php';
require_once __DIR__.'/DailyContent.php';

class SocialHub {
    public const PLATFORMS = ['youtube'=>'YouTube','tiktok'=>'TikTok','facebook'=>'Facebook','instagram'=>'Instagram','x'=>'X','linkedin'=>'LinkedIn'];
    private const HOSTS = ['youtube'=>['youtube.com','youtu.be'], 'tiktok'=>['tiktok.com'], 'facebook'=>['facebook.com','fb.watch'], 'instagram'=>['instagram.com'], 'x'=>['x.com','twitter.com'], 'linkedin'=>['linkedin.com']];
    public static function text($value, int $max, string $label): string {
        if (!is_string($value) || trim($value)==='' || mb_strlen(trim($value))>$max) throw new InvalidArgumentException($label . ' is required (maximum ' . $max . ' characters).');
        return trim($value);
    }
    public static function number($value, int $min, int $max, string $label): int {
        if (!is_scalar($value) || !preg_match('/^[0-9]+$/D',(string)$value) || (float)$value<$min || (float)$value>$max) throw new InvalidArgumentException($label . ' must be between ' . $min . ' and ' . $max . '.');
        return (int)$value;
    }
    public static function url($platform, $url): string {
        if (!is_string($platform) || !isset(self::PLATFORMS[$platform])) throw new InvalidArgumentException('Choose a supported platform.');
        $url = self::text($url,600,'Social link');
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme']??'')!=='https' || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port']!==443)) throw new InvalidArgumentException('Use an HTTPS link for the selected platform.');
        $host = strtolower($parts['host']??''); $valid=false;
        foreach (self::HOSTS[$platform] as $allowed) if ($host===$allowed || str_ends_with($host,'.'.$allowed)) $valid=true;
        if (!$valid) throw new InvalidArgumentException('Link does not belong to the selected platform.');
        return $url;
    }
    public static function activeUser(int $id): array {
        $user=Database::fetchOne('SELECT * FROM users WHERE id = ? FOR UPDATE',[$id]);
        if (!$user || $user['role']!=='user' || $user['status']!=='active') throw new InvalidArgumentException('An active paid user account is required.');
        return $user;
    }
    private static function staff(int $id): void {
        if (!StaffAccess::canForUser($id,'social')) throw new InvalidArgumentException('Social management permission required.');
    }
    public static function notification(string $key,int $userId,string $title,string $body): void {
        if (Database::fetchOne('SELECT id FROM user_notifications WHERE event_key = ?',[$key])) return;
        Database::insert('user_notifications',['event_key'=>$key,'user_id'=>$userId,'kind'=>'account_update','title'=>$title,'message'=>$body,'payload'=>'{}','sms_status'=>'skipped','created_at'=>gmdate('Y-m-d H:i:s')]);
    }
    public static function createCampaign(int $admin,array $in): int {
        self::staff($admin);
        $url=self::url($in['platform']??'', $in['url']??'');
        $title=self::text($in['title']??'',150,'Title');
        $instructions=self::text($in['instructions']??'',4000,'Instructions / feedback question');
        $reward=self::number($in['reward']??'',1,100000,'Reward');
        $slots=self::number($in['slots']??'',1,10000,'Slots');
        if (($in['budget_confirmed']??'')!=='1') throw new InvalidArgumentException('Confirm that the full reward budget is funded before publishing.');
        return Database::insert('social_campaigns',['platform'=>$in['platform'],'url'=>$url,'title'=>$title,'instructions'=>$instructions,'reward'=>$reward,'slots'=>$slots,'created_by'=>$admin,'created_at'=>gmdate('Y-m-d H:i:s')]);
    }
    public static function openTask(int $userId, int $campaignId): string {
        Database::beginTransaction();
        try {
            self::activeUser($userId);
            $campaign = Database::fetchOne('SELECT * FROM social_campaigns WHERE id = ? FOR UPDATE', [$campaignId]);
            if (!$campaign || $campaign['status'] !== 'active' || $campaign['approved_count'] >= $campaign['slots']) throw new InvalidArgumentException('This task is closed or full.');
            DailyContent::requireCurrent($campaign);
            if ((int)($campaign['owner_id'] ?? 0) === $userId) throw new InvalidArgumentException('You cannot open your own promotion as a task.');
            if (Database::fetchOne('SELECT id FROM social_task_opens WHERE campaign_id = ? AND user_id = ?', [$campaignId, $userId]) || Database::fetchOne('SELECT id FROM social_submissions WHERE campaign_id = ? AND user_id = ?', [$campaignId, $userId])) throw new InvalidArgumentException('Opened: you already opened this task.');
            $url = self::url($campaign['platform'], $campaign['url']);
            Database::insert('social_task_opens', ['campaign_id'=>$campaignId, 'user_id'=>$userId, 'opened_at'=>gmdate('Y-m-d H:i:s')]);
            Database::commit();
            return $url;
        } catch (Throwable $e) { Database::rollBack(); throw $e; }
    }
    public static function submit(int $userId,int $campaignId,$answer): void {
        $answer=self::text($answer,4000,'Your feedback / evidence');
        Database::beginTransaction();
        try {
            self::activeUser($userId);
            $campaign=Database::fetchOne('SELECT * FROM social_campaigns WHERE id = ? FOR UPDATE',[$campaignId]);
            if (!$campaign || $campaign['status']!=='active' || $campaign['approved_count'] >= $campaign['slots']) throw new InvalidArgumentException('This task is closed or full.');
            DailyContent::requireCurrent($campaign);
            if ((int)($campaign['owner_id']??0)===$userId) throw new InvalidArgumentException('You cannot earn rewards on your own promotion.');
            if (Database::fetchOne('SELECT id FROM social_submissions WHERE campaign_id = ? AND user_id = ?',[$campaignId,$userId])) throw new InvalidArgumentException('You already submitted this task.');
            Database::insert('social_submissions',['campaign_id'=>$campaignId,'user_id'=>$userId,'answer'=>$answer,'created_at'=>gmdate('Y-m-d H:i:s')]);
            Database::commit();
        } catch(Throwable $e) { if(Database::inTransaction())Database::rollback(); throw $e; }
    }
    // Lock the user then campaign then submission; ledger credit and review commit together.
    public static function review(int $admin,int $id,string $decision,$note): void {
        self::staff($admin);
        if (!in_array($decision,['approved','rejected'],true)) throw new InvalidArgumentException('Invalid review decision.');
        $note=self::text($note,1000,'Review note');
        Database::beginTransaction();
        try {
            $seed=Database::fetchOne('SELECT * FROM social_submissions WHERE id = ?',[$id]);
            if (!$seed) throw new InvalidArgumentException('Submission not found.');
            if ($decision==='approved') self::activeUser((int)$seed['user_id']);
            else Database::fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE',[(int)$seed['user_id']]);
            $campaign=Database::fetchOne('SELECT * FROM social_campaigns WHERE id = ? FOR UPDATE',[$seed['campaign_id']]);
            $row=Database::fetchOne('SELECT * FROM social_submissions WHERE id = ? FOR UPDATE',[$id]);
            if ($row['status']!=='pending') { Database::commit(); return; }
            if ($decision==='approved') {
                if (!$campaign || $campaign['status']!=='active' || $campaign['approved_count'] >= $campaign['slots'] || (int)($campaign['owner_id']??0)===(int)$row['user_id']) throw new InvalidArgumentException('Task is closed, full, or belongs to this user.');
                Wallet::credit((int)$row['user_id'],(float)$campaign['reward'],'social_reward','Social task: '.$campaign['title'],$id,'social_submission');
                $count=(int)$campaign['approved_count']+1;
                Database::update('social_campaigns',['approved_count'=>$count,'status'=>$count >= (int)$campaign['slots']?'completed':'active'], 'id = ?',[$campaign['id']]);
                if ($count >= (int)$campaign['slots'] && !empty($campaign['promotion_id'])) {
                    Database::update('social_promotions',['status'=>'completed'],'id = ? AND status = ?',[$campaign['promotion_id'],'published']);
                    self::notification('promotion-complete:'.$campaign['promotion_id'],(int)$campaign['owner_id'],'Promotion completed','Campaign yako imekamilisha washiriki '.$count.' waliokubaliwa.');
                }
            }
            Database::update('social_submissions',['status'=>$decision,'review_note'=>$note,'reviewed_by'=>$admin,'reviewed_at'=>gmdate('Y-m-d H:i:s')],'id = ?',[$id]);
            self::notification('social:'.$id,(int)$row['user_id'],'Task '.$decision, $decision==='approved' ? 'Kazi imekubaliwa. '.formatCurrency($campaign['reward']).' imeongezwa kwenye wallet yako. '.$note : 'Kazi haijakubaliwa. '.$note);
            Database::commit();
        } catch(Throwable $e) { if(Database::inTransaction())Database::rollback(); throw $e; }
    }
    public static function toggle(int $admin,int $id): void {
        self::staff($admin); Database::beginTransaction();
        try {
            $row=Database::fetchOne('SELECT * FROM social_campaigns WHERE id = ? FOR UPDATE',[$id]);
            if(!$row)throw new InvalidArgumentException('Task not found.');
            if(!in_array($row['status'],['active','paused'],true))throw new InvalidArgumentException('Completed tasks cannot be reopened.');
            DailyContent::requireCurrent($row);
            Database::update('social_campaigns',['status'=>$row['status']==='active'?'paused':'active'],'id = ?',[$id]);
            Database::commit();
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
    }
    public static function requestPromotion(int $userId,array $in): void {
        $url=self::url($in['platform']??'', $in['url']??'');
        $title=self::text($in['title']??'',150,'Campaign title');
        $brief=self::text($in['brief']??'',4000,'Promotion brief');
        $slots=self::number($in['slots']??'',1,10000,'Requested participants');
        $key=self::text($in['request_key']??'',64,'Request key');
        Database::beginTransaction();
        try {
            self::activeUser($userId);
            $existing=Database::fetchOne('SELECT id,user_id FROM social_promotions WHERE request_key = ?',[$key]);
            if($existing){if((int)$existing['user_id']!==$userId)throw new InvalidArgumentException('Invalid request key.');Database::commit();return;}
            if(Database::count('social_promotions','user_id = ? AND status IN (?,?)',[$userId,'requested','quoted'])>=3)throw new InvalidArgumentException('You already have three open promotion requests.');
            Database::insert('social_promotions',['user_id'=>$userId,'request_key'=>$key,'platform'=>$in['platform'],'url'=>$url,'title'=>$title,'brief'=>$brief,'requested_slots'=>$slots,'created_at'=>gmdate('Y-m-d H:i:s')]);
            Database::commit();
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
    }
    public static function quote(int $admin,int $id,array $in): void {
        self::staff($admin);
        $price=self::number($in['price']??'',1,100000000,'Price');
        $reward=self::number($in['reward']??'',1,100000,'Reward per approved participant');
        $slots=self::number($in['slots']??'',1,10000,'Participants');
        $terms=self::text($in['terms']??'',4000,'Quotation terms / task instructions');
        if($price < $reward*$slots) throw new InvalidArgumentException('Price must cover all rewards: minimum TZS '.number_format($reward*$slots).'.');
        Database::beginTransaction();
        try {
            $row=Database::fetchOne('SELECT * FROM social_promotions WHERE id = ? FOR UPDATE',[$id]);
            if(!$row || !in_array($row['status'],['requested','quoted'],true))throw new InvalidArgumentException('Only unpaid requests can be quoted.');
            $version=(int)$row['quote_version']+1;
            Database::update('social_promotions',['status'=>'quoted','quote_price'=>$price,'quote_reward'=>$reward,'quote_slots'=>$slots,'quote_terms'=>$terms,'quote_version'=>$version,'reviewed_by'=>$admin],'id = ?',[$id]);
            self::notification('quote:'.$id.':'.$version,(int)$row['user_id'],'Promotion quotation','Quotation: '.formatCurrency($price).' kwa washiriki '.$slots.'. Fungua Promote account kukagua kabla ya kulipa.');
            Database::commit();
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
    }
    // Spend available earnings, never a payout hold. Do not inflate total_withdrawn.
    public static function payPromotion(int $userId,int $id,int $version): void {
        Database::beginTransaction();
        try {
            self::activeUser($userId);
            $row=Database::fetchOne('SELECT * FROM social_promotions WHERE id = ? AND user_id = ? FOR UPDATE',[$id,$userId]);
            if(!$row)throw new InvalidArgumentException('Promotion not found.');
            if(in_array($row['status'],['paid','published','completed'],true)){Database::commit();return;}
            if($row['status']!=='quoted' || (int)$row['quote_version']!==$version)throw new InvalidArgumentException('Quotation changed. Review the current price and terms before paying.');
            $wallet=Wallet::getWallet($userId); $price=(float)$row['quote_price'];
            if($price<=0 || $price < (int)$row['quote_reward']*(int)$row['quote_slots'])throw new InvalidArgumentException('Invalid campaign budget.');
            if((float)$wallet['withdrawable_balance']<$price || (float)$wallet['balance']-$wallet['pending_amount']<$price)throw new InvalidArgumentException('Insufficient available earnings. No money was deducted.');
            Database::update('wallets',['balance'=>(float)$wallet['balance']-$price,'withdrawable_balance'=>(float)$wallet['withdrawable_balance']-$price],'id = ?',[$wallet['id']]);
            Database::insert('wallet_transactions',['wallet_id'=>$wallet['id'],'user_id'=>$userId,'type'=>'promotion_payment','amount'=>-$price,'balance_before'=>$wallet['balance'],'balance_after'=>(float)$wallet['balance']-$price,'description'=>'Promotion payment: '.$row['title'],'reference_id'=>$id,'reference_type'=>'social_promotion','status'=>'completed']);
            Database::update('social_promotions',['status'=>'paid','paid_at'=>gmdate('Y-m-d H:i:s')],'id = ?',[$id]);
            self::notification('promotion-paid:'.$id,$userId,'Promotion paid','Malipo ya promotion '.formatCurrency($price).' yamepokelewa. Admin atakagua na kuchapisha campaign yako.');
            Database::commit();
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
    }
    public static function publish(int $admin,int $id): void {
        self::staff($admin); Database::beginTransaction();
        try {
            $row=Database::fetchOne('SELECT * FROM social_promotions WHERE id = ? FOR UPDATE',[$id]);
            if(!$row)throw new InvalidArgumentException('Promotion not found.');
            if($row['status']==='published'){Database::commit();return;}
            if($row['status']!=='paid')throw new InvalidArgumentException('Customer must accept and pay the quotation first.');
            Database::insert('social_campaigns',['platform'=>$row['platform'],'title'=>$row['title'],'url'=>$row['url'],'instructions'=>$row['quote_terms'],'reward'=>$row['quote_reward'],'slots'=>$row['quote_slots'],'promotion_id'=>$id,'owner_id'=>$row['user_id'],'created_by'=>$admin,'created_at'=>gmdate('Y-m-d H:i:s')]);
            Database::update('social_promotions',['status'=>'published'],'id = ?',[$id]);
            self::notification('promotion-published:'.$id,(int)$row['user_id'],'Promotion live','Campaign yako imechapishwa kwenye Social tasks.');
            Database::commit();
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
    }
    // Cancellation and publication share the same row lock; only unpublished fees refund.
    public static function cancelPromotion(int $userId,int $id): void {
        Database::beginTransaction();
        try {
            self::activeUser($userId);
            $row=Database::fetchOne('SELECT * FROM social_promotions WHERE id = ? AND user_id = ? FOR UPDATE',[$id,$userId]);
            if(!$row)throw new InvalidArgumentException('Promotion not found.');
            if(in_array($row['status'],['cancelled','refunded'],true)){Database::commit();return;}
            if(!in_array($row['status'],['requested','quoted','paid'],true))throw new InvalidArgumentException('Published promotions require support review before cancellation.');
            if($row['status']==='paid') {
                $wallet=Wallet::getWallet($userId); $price=(float)$row['quote_price'];
                Database::update('wallets',['balance'=>(float)$wallet['balance']+$price,'withdrawable_balance'=>(float)$wallet['withdrawable_balance']+$price],'id = ?',[$wallet['id']]);
                Database::insert('wallet_transactions',['wallet_id'=>$wallet['id'],'user_id'=>$userId,'type'=>'promotion_refund','amount'=>$price,'balance_before'=>$wallet['balance'],'balance_after'=>(float)$wallet['balance']+$price,'description'=>'Unpublished promotion refund','reference_id'=>$id,'reference_type'=>'social_promotion','status'=>'completed']);
            }
            Database::update('social_promotions',['status'=>$row['status']==='paid'?'refunded':'cancelled'],'id = ?',[$id]);
            Database::commit();
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
    }
}
