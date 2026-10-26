<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/social_loans_fixture.php';
function denyOpen(callable $fn): void { try { $fn(); } catch (InvalidArgumentException $e) { return; } throw new RuntimeException('Repeated or unauthorized task opening allowed.'); }
$input=['platform'=>'youtube','url'=>'https://youtu.be/dQw4w9WgXcQ','title'=>'Open once','instructions'=>'Give feedback','reward'=>'100','slots'=>'10','budget_confirmed'=>'1'];
$id=SocialHub::createCampaign(3,$input);
expect(SocialHub::openTask(200,$id)===$input['url'],'Correct link not returned');
denyOpen(fn()=>SocialHub::openTask(200,$id));
expect(Database::count('social_task_opens')===1,'Duplicate open recorded');
expect(SocialHub::openTask(201,$id)===$input['url'],'Other user blocked');
denyOpen(fn()=>SocialHub::openTask(203,$id));
denyOpen(fn()=>SocialHub::openTask(204,$id));
denyOpen(fn()=>SocialHub::openTask(200,999999));
SocialHub::submit(200,$id,'Feedback after opening');
expect(Database::count('social_submissions','campaign_id=? AND user_id=?',[$id,200])===1,'Opening prevented feedback');
$id2=SocialHub::createCampaign(3,$input);
SocialHub::submit(202,$id2,'Previously submitted task');
denyOpen(fn()=>SocialHub::openTask(202,$id2));
Database::update('social_campaigns',['owner_id'=>202],'id=?',[$id2]);
denyOpen(fn()=>SocialHub::openTask(202,$id2));
SocialHub::toggle(3,$id2);
denyOpen(fn()=>SocialHub::openTask(201,$id2));
expect(Database::count('wallet_transactions',"type = ?",['social_reward'])===0,'Opening credited a reward');
echo "Social task opens: once per user, independent accounts, active access, submitted tasks, ownership and feedback passed. No rewards paid.\n";
