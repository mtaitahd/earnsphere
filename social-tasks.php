<?php
require_once __DIR__.'/includes/member_feature.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'open') {
    try {
        $url = SocialHub::openTask((int)$user['id'], (int)($_POST['campaign_id'] ?? 0));
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) jsonResponse(['success'=>true, 'url'=>$url]);
        header('Location: '.$url, true, 303); exit;
    } catch (InvalidArgumentException $e) {
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) jsonResponse(['success'=>false, 'message'=>$e->getMessage()], 409);
        setFlash('error', $e->getMessage());
    } catch (Throwable $e) {
        error_log('Social task open: '.$e->getMessage());
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) jsonResponse(['success'=>false, 'message'=>'Could not open this task. Please refresh and try again.'], 503);
        setFlash('error', 'Could not open this task. Please refresh and try again.');
    }
    header('Location: '.SITE_URL.'/social-tasks'); exit;
}
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try { SocialHub::submit((int)$user['id'],(int)($_POST['campaign_id']??0),$_POST['answer']??''); setFlash('success','Jibu limepokelewa. Admin akikubali, reward itaongezwa kwenye wallet yako.'); }
    catch(InvalidArgumentException $e){setFlash('error',$e->getMessage());}
    catch(Throwable $e){error_log('Social submission: '.$e->getMessage());setFlash('error','Task could not be submitted. Please try again.');}
    header('Location: '.SITE_URL.'/social-tasks');exit;
}
$platform=$_GET['platform']??''; if(!is_string($platform) || !isset(SocialHub::PLATFORMS[$platform]))$platform='';
$page=max(1,min(100000,(int)($_GET['page']??1)));$offset=($page-1)*25;
$historyPage=max(1,min(100000,(int)($_GET['history_page']??1)));$historyOffset=($historyPage-1)*25;
$available=true; $tasks=[]; $history=[];
try {
    $where="c.status = 'active' AND c.approved_count < c.slots AND (c.owner_id IS NULL OR c.owner_id != ?)"; $params=[$user['id'],$user['id'],$user['id']];
    if($platform!==''){$where.=' AND c.platform = ?';$params[]=$platform;}
    $tasks=Database::fetchAll("SELECT c.*, s.status AS submission_status, o.id AS opened_id FROM social_campaigns c LEFT JOIN social_submissions s ON s.campaign_id=c.id AND s.user_id=? LEFT JOIN social_task_opens o ON o.campaign_id=c.id AND o.user_id=? WHERE $where ORDER BY c.id DESC LIMIT 26 OFFSET $offset",$params);
    $history=Database::fetchAll("SELECT s.*, c.title,c.reward,c.platform FROM social_submissions s JOIN social_campaigns c ON c.id=s.campaign_id WHERE s.user_id=? ORDER BY s.id DESC LIMIT 26 OFFSET $historyOffset",[$user['id']]);
}catch(Throwable $e){$available=false;error_log('Social tasks unavailable: '.$e->getMessage());}
$hasMore=count($tasks)>25;$tasks=array_slice($tasks,0,25);$historyMore=count($history)>25;$history=array_slice($history,0,25);
$pageTitle='Social tasks';include __DIR__.'/includes/public_head.php'; ?>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/member-services.css">
<main class="services-page"><h1>Social tasks</h1><p>Fungua maudhui, fuata maelekezo na tuma jibu lako kwa ukaguzi.</p>
<?php featureNavigation('social-tasks'); displayFlash(); ?>
<p><a class="btn btn-primary" href="quizzes">Maswali ya uelewa — jibu yote sahihi ulipwe automatic</a></p>
<div class="service-notice">Reward hulipwa baada ya admin kukagua na kukubali kazi. Kufungua link pekee hakuthibitishi subscribe, like au follow. Ushiriki wa mitandaoni ni wa hiari; reward ni kwa jibu/kazi iliyokubaliwa, si ununuzi wa followers.</div>
<form method="GET" class="service-card"><label for="platform">Platform</label><select id="platform" name="platform"><option value="">All platforms</option><?php foreach(SocialHub::PLATFORMS as $key=>$label): ?><option value="<?= $key ?>" <?= $platform===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select><button class="btn btn-outline-primary">Filter</button></form>
<?php if(!$available): ?><p class="service-empty">Tasks are temporarily unavailable. Please try again later.</p><?php elseif(!$tasks): ?><p class="service-empty">Hakuna kazi mpya kwa sasa. Tafadhali rudi baadaye.</p><?php endif; ?>
<?php foreach($tasks as $task): ?>
<article class="service-card"><div class="service-meta"><?= featurePlatformBadge((string)$task['platform']) ?><strong style="color:#15803d"><?= formatCurrency($task['reward']) ?></strong><span><?= (int)$task['slots']-(int)$task['approved_count'] ?> approvals remaining</span></div>
<h2><?= sanitize($task['title']) ?></h2><p class="service-note"><?= sanitize($task['instructions']) ?></p>
<?php if ($task['opened_id'] || $task['submission_status']): ?>
<button type="button" class="btn btn-outline-primary" disabled>Opened</button>
<?php else: ?>
<form method="POST" action="<?= sanitize(SITE_URL) ?>/social-tasks" target="_blank" rel="noopener" data-social-open><?php featureToken(); ?><input type="hidden" name="action" value="open"><input type="hidden" name="campaign_id" value="<?= (int)$task['id'] ?>"><button class="btn btn-outline-primary" type="submit">Open <?= SocialHub::PLATFORMS[$task['platform']]??'content' ?></button><p role="status" data-open-message></p></form>
<?php endif; ?>
<?php if($task['submission_status']): ?><p>Your submission: <?= featureStatus($task['submission_status']) ?></p><?php else: ?>
<form method="POST"><?php featureToken(); ?><input type="hidden" name="campaign_id" value="<?= (int)$task['id'] ?>"><label for="answer-<?= (int)$task['id'] ?>">Your feedback / completed work</label><textarea id="answer-<?= (int)$task['id'] ?>" name="answer" maxlength="4000" rows="3" required placeholder="Jibu swali la admin au eleza kazi uliyofanya. Usiweke password au taarifa za siri."></textarea><button class="btn btn-primary">Submit for review</button></form>
<?php endif; ?></article>
<?php endforeach; ?>
<?php featurePages($page,$hasMore,'page',['platform'=>$platform]); ?>
<h2>My submissions</h2><?php if(!$history): ?><p>No submissions yet.</p><?php endif; ?>
<?php foreach($history as $item): ?><article class="service-card"><?= featurePlatformBadge((string)$item['platform']) ?><h3><?= sanitize($item['title']) ?></h3><p><?= featureStatus($item['status']) ?> · <?= formatCurrency($item['reward']) ?> · <?= formatLedgerTime($item['created_at']) ?> EAT</p><p class="service-note"><?= sanitize($item['answer']) ?></p><?php if($item['review_note']): ?><p>Admin: <?= sanitize($item['review_note']) ?></p><?php endif; ?></article><?php endforeach; ?>
<?php featurePages($historyPage,$historyMore,'history_page',['platform'=>$platform]); ?>
</main><script src="<?= sanitize(SITE_URL) ?>/assets/js/social-task-open.js?v=<?= filemtime(__DIR__.'/assets/js/social-task-open.js') ?>" defer></script><?php include __DIR__.'/includes/public_foot.php'; ?>
