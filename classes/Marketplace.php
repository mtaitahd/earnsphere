<?php
require_once __DIR__.'/SocialHub.php';
require_once __DIR__.'/MarketplaceImages.php';
require_once __DIR__.'/ErrorLogger.php';
class Marketplace {
    public const CATEGORIES=['electronics'=>'Electronics','fashion'=>'Fashion & beauty','home'=>'Home & furniture','vehicles'=>'Vehicles & parts','food'=>'Food & agriculture','services'=>'Services','other'=>'Other goods'];
    private static function staff(int $actor): void {
        if (!StaffAccess::canForUser($actor,'marketplace')) throw new InvalidArgumentException('Marketplace management permission required.');
    }
    public static function policy(bool $lock=false): array {
        $row=Database::fetchOne('SELECT setting_value FROM settings WHERE setting_key = ?'.($lock?' FOR UPDATE':''),['marketplace_policy']);
        $policy=$row?json_decode($row['setting_value'],true):null;
        return is_array($policy)?$policy:['enabled'=>false,'fee'=>1500,'days'=>7,'rules'=>''];
    }
    public static function policyHash(array $policy): string {return hash('sha256',json_encode($policy,JSON_THROW_ON_ERROR));}
    public static function savePolicy(int $actor,array $input): void {
        self::staff($actor);
        if(!StaffAccess::canForUser($actor,'marketplace_settings'))throw new InvalidArgumentException('Marketplace fee settings permission required.');
        $policy=['enabled'=>($input['enabled']??'')==='1','fee'=>SocialHub::number($input['fee']??'',100,1000000,'Advertising fee'),'days'=>SocialHub::number($input['days']??'',1,90,'Advertising days'),'rules'=>SocialHub::text($input['rules']??'',8000,'Advertising rules')];
        Database::beginTransaction();
        try {
            if(Database::fetchOne('SELECT id FROM settings WHERE setting_key = ? FOR UPDATE',['marketplace_policy']))Database::update('settings',['setting_value'=>json_encode($policy,JSON_THROW_ON_ERROR)],'setting_key = ?',['marketplace_policy']);
            else Database::insert('settings',['setting_key'=>'marketplace_policy','setting_value'=>json_encode($policy,JSON_THROW_ON_ERROR),'description'=>'Product listing fees and rules']);
            Database::commit();
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
    }
    public static function phone($value): string {
        $phone=SocialHub::text($value,30,'WhatsApp number');
        $phone=preg_replace('/[\s()+-]/','',$phone);
        if(preg_match('/^0[67][0-9]{8}$/D',$phone))$phone='255'.substr($phone,1);
        elseif(preg_match('/^[67][0-9]{8}$/D',$phone))$phone='255'.$phone;
        if(!preg_match('/^255[67][0-9]{8}$/D',$phone))throw new InvalidArgumentException('Enter a Tanzanian WhatsApp number, for example 0712345678 or +255712345678.');
        return $phone;
    }
    public static function images(string $json): array {
        $images=json_decode($json,true);
        return is_array($images)?array_values(array_filter($images,fn($path)=>is_string($path)&&MarketplaceImages::validPath($path))):[];
    }
    private static function event(int $id,int $actor,string $action,string $note=''): void {
        Database::insert('marketplace_events',['listing_id'=>$id,'actor_id'=>$actor,'action'=>$action,'note'=>$note,'created_at'=>gmdate('Y-m-d H:i:s')]);
    }
    public static function create(int $userId,array $input,array $files,?MarketplaceImages $storage=null): int {
        $title=SocialHub::text($input['title']??'',150,'Product name');
        $description=SocialHub::text($input['description']??'',5000,'Description');
        $location=SocialHub::text($input['location']??'',150,'Location');
        $price=SocialHub::number($input['product_price']??'',1,1000000000,'Product price');
        $phone=self::phone($input['whatsapp']??'');$category=$input['category']??'';
        if(!is_string($category)||!isset(self::CATEGORIES[$category]))throw new InvalidArgumentException('Choose a product category.');
        $key=SocialHub::text($input['request_key']??'',64,'Request key');
        if(($input['accepted']??'')!=='1')throw new InvalidArgumentException('Accept the advertising rules and publication of your contact number.');
        $paths=[];Database::beginTransaction();
        try {
            SocialHub::activeUser($userId);
            $existing=Database::fetchOne('SELECT id,user_id FROM marketplace_listings WHERE request_key = ?',[$key]);
            if($existing){if((int)$existing['user_id']!==$userId)throw new InvalidArgumentException('Invalid request key.');Database::commit();return (int)$existing['id'];}
            $policy=self::policy(true);
            if(!$policy['enabled'])throw new InvalidArgumentException('New advertising requests are currently closed.');
            if(!is_string($input['policy_hash']??null)||!hash_equals(self::policyHash($policy),$input['policy_hash']))throw new InvalidArgumentException('Advertising terms changed. Refresh and read the current fee and rules.');
            if(Database::count('marketplace_listings','user_id = ? AND status IN (?,?)',[$userId,'draft','pending_review'])>=5)throw new InvalidArgumentException('You already have five unpublished listings. Complete or cancel them first.');
            if(Database::count('marketplace_listings','user_id = ? AND created_at >= ?',[$userId,gmdate('Y-m-d 00:00:00')])>=10)throw new InvalidArgumentException('You can create up to ten product listings per day.');
            $paths=($storage??new MarketplaceImages())->store($files);
            if(count($paths)<1||count($paths)>4)throw new InvalidArgumentException('Upload 1–4 product photos.');
            foreach($paths as $path)if(!is_string($path)||!MarketplaceImages::validPath($path))throw new InvalidArgumentException('Invalid product photo.');
            $id=Database::insert('marketplace_listings',['user_id'=>$userId,'request_key'=>$key,'title'=>$title,'description'=>$description,'category'=>$category,'product_price'=>$price,'location'=>$location,'whatsapp'=>$phone,'images'=>json_encode($paths,JSON_THROW_ON_ERROR),'policy_snapshot'=>json_encode($policy,JSON_THROW_ON_ERROR),'ad_fee'=>$policy['fee'],'duration_days'=>$policy['days'],'created_at'=>gmdate('Y-m-d H:i:s')]);
            self::event($id,$userId,'created');Database::commit();return $id;
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();MarketplaceImages::cleanup($paths);throw $e;}
    }
    public static function pay(int $userId,int $id): void {
        Database::beginTransaction();
        try {
            SocialHub::activeUser($userId);
            $listing=Database::fetchOne('SELECT * FROM marketplace_listings WHERE id = ? AND user_id = ? FOR UPDATE',[$id,$userId]);
            if(!$listing)throw new InvalidArgumentException('Listing not found.');
            if($listing['fee_status']==='paid'){Database::commit();return;}
            if($listing['status']!=='draft'||$listing['fee_status']!=='unpaid')throw new InvalidArgumentException('This listing cannot be paid.');
            if(!self::policy()['enabled'])throw new InvalidArgumentException('New advertising payments are currently closed.');
            $wallet=Wallet::getWallet($userId);$fee=(int)$listing['ad_fee'];
            if($fee<100||$wallet['withdrawable_balance']<$fee||(float)$wallet['balance']-(float)$wallet['pending_amount']<$fee)throw new InvalidArgumentException('Insufficient available wallet earnings. No advertising fee was charged.');
            Database::update('wallets',['balance'=>(float)$wallet['balance']-$fee,'withdrawable_balance'=>(float)$wallet['withdrawable_balance']-$fee],'id = ?',[$wallet['id']]);
            $transaction=Database::insert('wallet_transactions',['wallet_id'=>$wallet['id'],'user_id'=>$userId,'type'=>'marketplace_fee','amount'=>-$fee,'balance_before'=>$wallet['balance'],'balance_after'=>(float)$wallet['balance']-$fee,'description'=>'Product advertising fee: '.$listing['title'],'reference_id'=>$id,'reference_type'=>'marketplace_listing','status'=>'completed']);
            Database::update('marketplace_listings',['status'=>'pending_review','fee_status'=>'paid','fee_transaction_id'=>$transaction,'paid_at'=>gmdate('Y-m-d H:i:s')],'id = ?',[$id]);
            self::event($id,$userId,'paid');SocialHub::notification('listing-paid:'.$id,$userId,'Advertisement payment received','Ada ya tangazo '.formatCurrency($fee).' imelipwa. Tangazo linasubiri ukaguzi kabla ya kuchapishwa.');
            Database::commit();
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
    }
    private static function confirmedFee(array $listing): void {
        $tx=Database::fetchOne('SELECT * FROM wallet_transactions WHERE id = ? AND user_id = ? AND reference_id = ? AND reference_type = ? AND type = ? AND status = ?',[$listing['fee_transaction_id'],$listing['user_id'],$listing['id'],'marketplace_listing','marketplace_fee','completed']);
        if(!$tx || (float)$tx['amount']!==-(float)$listing['ad_fee'])throw new InvalidArgumentException('Confirmed advertising payment is missing.');
    }
    private static function refund(array $listing): void {
        if($listing['fee_status']!=='paid')return;
        self::confirmedFee($listing);
        $wallet=Wallet::getWallet((int)$listing['user_id']);$fee=(int)$listing['ad_fee'];
        Database::update('wallets',['balance'=>(float)$wallet['balance']+$fee,'withdrawable_balance'=>(float)$wallet['withdrawable_balance']+$fee],'id = ?',[$wallet['id']]);
        Database::insert('wallet_transactions',['wallet_id'=>$wallet['id'],'user_id'=>$listing['user_id'],'type'=>'marketplace_refund','amount'=>$fee,'balance_before'=>$wallet['balance'],'balance_after'=>(float)$wallet['balance']+$fee,'description'=>'Unpublished product ad refund: '.$listing['title'],'reference_id'=>$listing['id'],'reference_type'=>'marketplace_listing','status'=>'completed']);
        Database::update('marketplace_listings',['fee_status'=>'refunded'],'id = ?',[$listing['id']]);
    }
    public static function review(int $actor,int $id,string $decision,$note): void {
        self::staff($actor);
        if(!in_array($decision,['approved','rejected','removed'],true))throw new InvalidArgumentException('Invalid listing decision.');
        $note=SocialHub::text($note,1000,'Review note');Database::beginTransaction();
        try {
            $seed=Database::fetchOne('SELECT user_id FROM marketplace_listings WHERE id = ?',[$id]);
            if(!$seed)throw new InvalidArgumentException('Listing not found.');
            $seller=Database::fetchOne('SELECT status,role FROM users WHERE id = ? FOR UPDATE',[$seed['user_id']]);
            $listing=Database::fetchOne('SELECT * FROM marketplace_listings WHERE id = ? FOR UPDATE',[$id]);
            if($actor===(int)$listing['user_id'])throw new InvalidArgumentException('Another moderator must review your own listing.');
            if($listing['status']===$decision){Database::commit();return;}
            $updates=['status'=>$decision,'review_note'=>$note,'reviewed_by'=>$actor];
            if($decision==='removed') {
                if($listing['status']!=='approved')throw new InvalidArgumentException('Only published ads can be removed.');
            }else {
                if($listing['status']!=='pending_review'||$listing['fee_status']!=='paid')throw new InvalidArgumentException('Only a paid, pending advertisement can be reviewed.');
                self::confirmedFee($listing);
                if($decision==='approved') {
                    if(!$seller||$seller['status']!=='active'||$seller['role']!=='user')throw new InvalidArgumentException('Seller must have an active user account.');
                    $updates['approved_at']=gmdate('Y-m-d H:i:s');$updates['expires_at']=gmdate('Y-m-d H:i:s',time()+(int)$listing['duration_days']*86400);
                }else self::refund($listing);
            }
            Database::update('marketplace_listings',$updates,'id = ?',[$id]);self::event($id,$actor,$decision,$note);
            $message=$decision==='approved'?'Tangazo lako limekubaliwa na kuonekana kwenye Soko kwa siku '.$listing['duration_days'].'.':($decision==='rejected'?'Tangazo limekataliwa. Ada '.formatCurrency($listing['ad_fee']).' imerudishwa kwenye wallet yako.':'Tangazo limeondolewa kwenye Soko.');
            SocialHub::notification('listing:'.$id.':'.$decision,(int)$listing['user_id'],'Advertisement '.$decision,$message.' '.$note);Database::commit();
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
    }
    public static function sellerAction(int $userId,int $id,string $action): void {
        if(!in_array($action,['cancel','sold'],true))throw new InvalidArgumentException('Invalid listing action.');
        Database::beginTransaction();
        try {
            SocialHub::activeUser($userId);
            $row=Database::fetchOne('SELECT * FROM marketplace_listings WHERE id = ? AND user_id = ? FOR UPDATE',[$id,$userId]);
            if(!$row)throw new InvalidArgumentException('Listing not found.');
            $target=$action==='sold'?'sold':'cancelled';
            if($row['status']===$target){Database::commit();return;}
            if($action==='cancel') {
                if(!in_array($row['status'],['draft','pending_review'],true))throw new InvalidArgumentException('Only unpublished ads can be cancelled and refunded.');
                self::refund($row);
            }elseif($row['status']!=='approved')throw new InvalidArgumentException('Only a published product can be marked sold.');
            Database::update('marketplace_listings',['status'=>$target],'id = ?',[$id]);self::event($id,$userId,$target);Database::commit();
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
    }
    public static function publicListing(int $id): ?array {
        return Database::fetchOne("SELECT l.id,l.user_id,l.title,l.description,l.category,l.product_price,l.location,l.whatsapp,l.images,l.approved_at,l.expires_at FROM marketplace_listings l JOIN users u ON u.id=l.user_id WHERE l.id=? AND l.status='approved' AND l.fee_status='paid' AND l.expires_at>? AND u.status='active' AND u.role='user'",[$id,gmdate('Y-m-d H:i:s')]);
    }
    public static function report(int $userId,int $id,$reason): void {
        $reason=SocialHub::text($reason,1000,'Report reason');Database::beginTransaction();
        try {
            SocialHub::activeUser($userId);$listing=self::publicListing($id);
            if(!$listing)throw new InvalidArgumentException('This product is no longer publicly available.');
            if((int)$listing['user_id']===$userId)throw new InvalidArgumentException('Use My products to manage your own advertisement.');
            if(Database::fetchOne('SELECT id FROM marketplace_reports WHERE listing_id = ? AND user_id = ?',[$id,$userId])){Database::commit();return;}
            Database::insert('marketplace_reports',['listing_id'=>$id,'user_id'=>$userId,'reason'=>$reason,'created_at'=>gmdate('Y-m-d H:i:s')]);self::event($id,$userId,'reported');Database::commit();
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
    }
    public static function resolveReport(int $actor,int $id,$note): void {
        self::staff($actor);$note=SocialHub::text($note,1000,'Resolution note');Database::beginTransaction();
        try {
            $row=Database::fetchOne('SELECT * FROM marketplace_reports WHERE id = ? FOR UPDATE',[$id]);
            if(!$row)throw new InvalidArgumentException('Report not found.');
            if($row['status']==='open'){
                Database::update('marketplace_reports',['status'=>'resolved','review_note'=>$note,'reviewed_by'=>$actor],'id = ?',[$id]);self::event((int)$row['listing_id'],$actor,'report_resolved',$note);
            }
            Database::commit();
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
    }
    public static function whatsappLink(array $listing): string {
        return 'https://wa.me/'.self::phone($listing['whatsapp']).'?text='.rawurlencode('Habari, nimeona '.$listing['title'].' kwenye EarnSphere Soko. Je, bado inapatikana?');
    }
}
