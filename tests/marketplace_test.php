<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/marketplace_fixture.php';
function marketBlocked(callable $fn,string $message):void{try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException($message);}
marketBlocked(fn()=>Marketplace::savePolicy(204,['enabled'=>'1','fee'=>'1500','days'=>'7','rules'=>'Rules']),'Support agent changed advertising policy');
enableMarketplace();$images=new FixtureProductImages();$input=sampleAd('product-one');
$id=Marketplace::create(200,$input,[],$images);Marketplace::create(200,$input,[],$images);
expect($images->calls===1 && Database::count('marketplace_listings')===1,'Duplicate request created images or ad twice');
expect(Marketplace::publicListing($id)===null,'Unpaid ad was publicly visible');
marketBlocked(fn()=>Marketplace::review(3,$id,'approved','Approved'),'Unpaid ad was approved');
marketBlocked(fn()=>Marketplace::pay(201,$id),'Another user paid seller ad');
marketBlocked(fn()=>Marketplace::pay(200,$id),'Insufficient earnings were charged');
Wallet::credit(200,10000,'social_reward','Test funds');$before=Wallet::getWallet(200);
Marketplace::pay(200,$id);Marketplace::pay(200,$id);$paid=Wallet::getWallet(200);
expect((float)$before['balance']-(float)$paid['balance']===1500.0,'Ad fee was not exactly once');
expect((float)$before['withdrawable_balance']-(float)$paid['withdrawable_balance']===1500.0,'Ad fee did not consume available earnings');
expect($before['total_withdrawn']===$paid['total_withdrawn'] && $before['total_earned']===$paid['total_earned'],'Ad payment inflated withdrawal/earning totals');
expect(Marketplace::publicListing($id)===null,'Paid but unreviewed ad was publicly visible');
marketBlocked(fn()=>Marketplace::review(204,$id,'approved','Approved'),'Unprivileged agent approved ad');
Database::insert('agent_permissions',['user_id'=>204,'permission'=>'marketplace']);StaffAccess::clearCache();
marketBlocked(fn()=>Marketplace::savePolicy(204,['enabled'=>'1','fee'=>'100','days'=>'90','rules'=>'Changed rules']),'Moderation permission changed global ad fee');
Database::update('marketplace_listings',['paid_at'=>'2020-01-01 00:00:00'],'id = ?',[$id]);
Marketplace::review(204,$id,'approved','Photos, price and contact reviewed');$public=Marketplace::publicListing($id);
expect($public!==null && strtotime($public['expires_at'].' UTC')>time()+6*86400,'Ad duration started at payment instead of approval');
Marketplace::review(204,$id,'approved','Repeated action');expect(Marketplace::publicListing($id)['expires_at']===$public['expires_at'],'Duplicate approval extended ad for free');
expect(str_starts_with(Marketplace::whatsappLink($public),'https://wa.me/255712345678?text='),'WhatsApp URL incorrect');
marketBlocked(fn()=>Marketplace::sellerAction(201,$id,'sold'),'Another seller changed ad status');
marketBlocked(fn()=>Marketplace::sellerAction(200,$id,'cancel'),'Published ad was refunded without review');
Marketplace::report(201,$id,'Incorrect product details');Marketplace::report(201,$id,'Duplicate');
expect(Database::count('marketplace_reports')===1,'Report duplicated');
marketBlocked(fn()=>Marketplace::report(200,$id,'Own ad'),'Seller reported own ad');
Marketplace::resolveReport(204,1,'Contacted seller');
expect(Database::fetchOne('SELECT status FROM marketplace_reports')['status']==='resolved','Agent could not resolve report');
$balance=Wallet::getWallet(200)['balance'];Marketplace::review(204,$id,'removed','Misleading details');
expect(Marketplace::publicListing($id)===null && Wallet::getWallet(200)['balance']===$balance,'Removal left public ad or issued automatic refund');
$reject=Marketplace::create(200,sampleAd('reject-product'),[],$images);Marketplace::pay(200,$reject);$before=Wallet::getWallet(200);Marketplace::review(3,$reject,'rejected','Photo does not match product');Marketplace::review(3,$reject,'rejected','Duplicate');
expect((float)Wallet::getWallet(200)['balance']-(float)$before['balance']===1500.0,'Rejected ad refund not exactly once');
expect(Database::count('wallet_transactions','type=? AND reference_id=?',['marketplace_refund',$reject])===1,'Duplicate ad refund ledger');
$cancel=Marketplace::create(200,sampleAd('cancel-product'),[],$images);Marketplace::pay(200,$cancel);$before=Wallet::getWallet(200);Marketplace::sellerAction(200,$cancel,'cancel');Marketplace::sellerAction(200,$cancel,'cancel');
expect((float)Wallet::getWallet(200)['balance']-(float)$before['balance']===1500.0,'Cancellation did not refund exactly once');
$expired=Marketplace::create(200,sampleAd('expire-product'),[],$images);Marketplace::pay(200,$expired);Marketplace::review(3,$expired,'approved','Accepted');Database::update('marketplace_listings',['expires_at'=>'2020-01-01 00:00:00'],'id = ?',[$expired]);expect(Marketplace::publicListing($expired)===null,'Expired ad stayed public');
$sold=Marketplace::create(200,sampleAd('sold-product'),[],$images);Marketplace::pay(200,$sold);Marketplace::review(3,$sold,'approved','Accepted');Marketplace::sellerAction(200,$sold,'sold');expect(Marketplace::publicListing($sold)===null,'Sold product stayed public');
$held=Marketplace::create(202,sampleAd('held-product'),[],$images);Wallet::credit(202,10000,'social_reward','Test funds');Database::update('wallets',['pending_amount'=>9500],'user_id = ?',[202]);$before=Wallet::getWallet(202);marketBlocked(fn()=>Marketplace::pay(202,$held),'Held withdrawal funds paid an ad');expect(Wallet::getWallet(202)===$before,'Blocked fee changed wallet');
$stale=sampleAd('stale-terms');$stale['policy_hash']='stale';marketBlocked(fn()=>Marketplace::create(201,$stale,[],$images),'Changed ad terms accepted');
marketBlocked(fn()=>Marketplace::phone('javascript:alert(1)'),'Unsafe WhatsApp number accepted');
foreach(['../test.php','https://evil.test/a.jpg','uploads/marketplace/test.php'] as $path)expect(!MarketplaceImages::validPath($path),'Unsafe product image path accepted');
$temp=tempnam(sys_get_temp_dir(),'market-image-');file_put_contents($temp,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg=='));
try{expect(MarketplaceImages::inspect($temp)==='png','Valid product photo rejected');marketBlocked(fn()=>(new MarketplaceImages())->store(['error'=>[UPLOAD_ERR_OK],'tmp_name'=>[$temp]]),'Non-uploaded local file accepted');file_put_contents($temp,'<script>alert(1)</script>');marketBlocked(fn()=>MarketplaceImages::inspect($temp),'Script accepted as image');}finally{unlink($temp);}
// Ledger failure must roll back fee, listing state and events.
$failed=Marketplace::create(201,sampleAd('failed-payment'),[],$images);Wallet::credit(201,5000,'social_reward','Test funds');$before=Wallet::getWallet(201);
$db->exec("CREATE TRIGGER fail_market_fee BEFORE INSERT ON wallet_transactions WHEN NEW.type='marketplace_fee' BEGIN SELECT RAISE(ABORT,'Simulated fee failure'); END");
try{Marketplace::pay(201,$failed);throw new RuntimeException('Expected ledger failure');}catch(PDOException $e){}
expect(Wallet::getWallet(201)===$before && Database::fetchOne('SELECT status FROM marketplace_listings WHERE id=?',[$failed])['status']==='draft','Failed fee write left partial debit');
echo "Marketplace: pay-before-approval, private drafts, exactly-once fees/refunds, expiry, moderation roles, reports and image validation passed. No external money moved.\n";
