<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/staff_fixture.php';require_once __DIR__.'/../classes/SecurityAlerts.php';require_once __DIR__.'/../classes/ReferralLookup.php';
function checkAdmin(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
Database::insert('settings',['setting_key'=>'admin_email','setting_value'=>'security@example.test']);
$_SERVER['REMOTE_ADDR']='203.0.113.'.random_int(1,200);$_SERVER['REQUEST_URI']='/login?password=DO_NOT_LOG&otp=SECRET';
$base=Database::count('error_logs',"category='security'");
for($i=0;$i<4;$i++)SecurityAlerts::loginFailure('target@example.test');
checkAdmin(Database::count('error_logs',"category='security'")===$base,'Ordinary mistakes generated alert');
SecurityAlerts::loginFailure('target@example.test');SecurityAlerts::loginFailure('target@example.test');
checkAdmin(Database::count('error_logs',"category='security'")===$base+1,'Fifth failure did not alert or duplicate flood');
$row=Database::fetchOne("SELECT * FROM error_logs WHERE category='security' ORDER BY id DESC LIMIT 1");
checkAdmin(!str_contains(json_encode($row),'SECRET')&&!str_contains(json_encode($row),'DO_NOT_LOG'),'Secrets logged');
checkAdmin(json_decode($row['context'],true)['email_status']==='disabled_nonproduction','Test environment sent mail');
$calls=[];$sender=function($to,$subject,$html)use(&$calls){$calls[]=[$to,$subject,$html];return true;};
for($i=0;$i<5;$i++){$_SERVER['REMOTE_ADDR']='198.51.100.'.($i+1);SecurityAlerts::record('webhook_signature',$sender);}
checkAdmin(count($calls)===4,'Global email flood cap failed');checkAdmin($calls[0][0]==='security@example.test','Configured admin email ignored');
checkAdmin(!str_contains($calls[0][2],'SECRET'),'Secrets emailed');
checkAdmin(str_contains($calls[0][2],'/admin/security-alerts'),'Email lacks admin review link');
$latest=SecurityAlerts::listing(1)['items'][0];checkAdmin(json_decode($latest['context'],true)['email_status']==='rate_limited','Suppressed email outcome missing');
checkAdmin(SecurityAlerts::unread(1)===6,'Unread count wrong');
SecurityAlerts::markRead(1,(int)$latest['id']);checkAdmin(SecurityAlerts::unread(1)===0,'Mark read failed');
Database::insert('users',['id'=>4,'full_name'=>'Second Admin','phone'=>'255744444444','role'=>'admin','status'=>'active']);
$_SESSION=['user_id'=>4,'user_role'=>'admin'];StaffAccess::clearCache();checkAdmin(SecurityAlerts::unread(4)===6,'Admin read states leaked');
$_SESSION=['user_id'=>1,'user_role'=>'admin'];StaffAccess::clearCache();
// Exercise a rejected email too, without contacting a real mail service.
file_put_contents(APP_ROOT.'/logs/security-limits/'.hash('sha256','security-alert-email-global').'.json',json_encode(['count'=>4,'until'=>time()-1]));
$_SERVER['REMOTE_ADDR']='198.51.100.220';SecurityAlerts::record('csrf_failures',fn()=>false);
$failed=SecurityAlerts::listing(1)['items'][0];checkAdmin(json_decode($failed['context'],true)['email_status']==='failed','Failed email status missing');
$staffDb->exec('ALTER TABLE users ADD COLUMN referred_by INTEGER');$staffDb->exec('ALTER TABLE users ADD COLUMN created_at TEXT');
Database::update('users',['referred_by'=>null,'created_at'=>'2026-10-27 10:00:00'],'id = ?',[2]);
foreach([[10,'Direct <script>alert(1)</script>','CHILD123',2],[11,'Grand Child','GRAND123',10],[12,'Other Root','OTHER123',null]] as [$id,$name,$code,$parent])Database::insert('users',['id'=>$id,'full_name'=>$name,'phone'=>'2557000000'.$id,'email'=>$id.'@example.test','role'=>'user','status'=>'active','referral_code'=>$code,'referred_by'=>$parent,'created_at'=>'2026-10-27']);
$r=ReferralLookup::find('grand123','USER');checkAdmin($r['level']===2&&$r['chain'][1]['referral_code']==='CHILD123','Incorrect L2/inviter');
checkAdmin(ReferralLookup::find('CHILD123','USER')['level']===1,'Direct referral not L1');
checkAdmin(ReferralLookup::find('USER','USER')['level']===0,'Self comparison wrong');
checkAdmin(ReferralLookup::find('GRAND123','OTHER123')['level']===null,'Unrelated account assigned level');
$r=ReferralLookup::find('USER');checkAdmin($r['direct']===1&&$r['grand']===1,'Network counts wrong');
checkAdmin(ReferralLookup::find('NONE123')===null,'Unknown code found');
try{ReferralLookup::find("' OR 1=1 --");throw new RuntimeException('Injection accepted');}catch(InvalidArgumentException $e){}
Database::update('users',['referred_by'=>11],'id = ?',[2]);checkAdmin(str_contains(ReferralLookup::find('GRAND123')['warning'],'circular'),'Cycle unbounded');
Database::update('users',['referred_by'=>999],'id = ?',[2]);checkAdmin(str_contains(ReferralLookup::find('USER')['warning'],'missing'),'Missing parent hidden');
Database::update('users',['referred_by'=>null],'id = ?',[2]);
$_GET=['code'=>'GRAND123','relative'=>'USER'];$_SERVER['PHP_SELF']='/admin/referral-lookup.php';$_SERVER['REQUEST_METHOD']='GET';
ob_start();include __DIR__.'/../admin/referral-lookup.php';$html=ob_get_clean();
checkAdmin(str_contains($html,'Level 2')&&str_contains($html,'CHILD123'),'Actual lookup page missing lineage');
checkAdmin(!str_contains($html,'<script>alert(1)</script>'),'Lookup page XSS');
$direct=ReferralLookup::members('USER',1);$grand=ReferralLookup::members('USER',2);
checkAdmin($direct['total']===1&&(int)$direct['items'][0]['id']===10&&$direct['items'][0]['inviter_code']==='USER','Direct members list incorrect');
checkAdmin($grand['total']===1&&(int)$grand['items'][0]['id']===11&&$grand['items'][0]['inviter_code']==='CHILD123','Grand members/inviter list incorrect');
checkAdmin(ReferralLookup::members('OTHER123',1)['total']===0,'Different network leaked into list');
checkAdmin(ReferralLookup::members('NONE123',1)===null,'Unknown code returned members');
try{ReferralLookup::members('USER',3);throw new RuntimeException('Invalid level accepted');}catch(InvalidArgumentException $e){}
Database::update('users',['referred_by'=>2],'id = ?',[4]);
for($i=100;$i<130;$i++)Database::insert('users',['id'=>$i,'full_name'=>'Team Member '.$i,'phone'=>'25570000'.$i,'referral_code'=>'TEAM'.$i,'role'=>'user','status'=>'active','referred_by'=>2,'created_at'=>'2026-10-27']);
$first=ReferralLookup::members('USER',1,1);$last=ReferralLookup::members('USER',1,999);
checkAdmin($first['total']===31&&count($first['items'])===25&&$last['page']===2&&count($last['items'])===6,'Pagination or staff exclusion failed');
checkAdmin(count(array_intersect(array_column($first['items'],'id'),array_column($last['items'],'id')))===0,'Duplicate members across pages');
$_GET=['code'=>'USER','level'=>'1','page'=>'2'];
ob_start();include __DIR__.'/../admin/referral-lookup.php';$memberHtml=ob_get_clean();
checkAdmin(str_contains($memberHtml,'id="referral-members"')&&str_contains($memberHtml,'Aliunganishwa na:')&&str_contains($memberHtml,'CHILD123'),'Clicked level page omitted members/inviters');
checkAdmin(str_contains($memberHtml,'Page 2 / 2')&&!str_contains($memberHtml,'<script>alert(1)</script>'),'Unsafe or incorrect paginated list');
$_GET=['code'=>'OTHER123','level'=>'2'];ob_start();include __DIR__.'/../admin/referral-lookup.php';$emptyHtml=ob_get_clean();
checkAdmin(str_contains($emptyHtml,'Hakuna watu kwenye Level 2'),'Empty level not explained');
echo "Referral member lists: Level 1/2, inviters, network isolation, empty state, pagination, staff exclusion and XSS passed.\n";

echo "Admin alerts/referrals: thresholds, dedup, privacy, email cap/status, independent read state, exact lookup, inviter chain, levels, cycles and safe page passed.\n";
