<?php
require_once dirname(__DIR__).'/classes/SocialHub.php';
Auth::initSession();StaffAccess::requirePage('social');
require_once dirname(__DIR__).'/includes/service_view.php';
$admin=(int)$_SESSION['user_id'];
$tab=in_array($_GET['tab']??'', ['tasks','reviews','promotions'],true)?$_GET['tab']:'tasks';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try {
        $action=$_POST['action']??'';$id=(int)($_POST['id']??0);
        if($action==='create'){SocialHub::createCampaign($admin,$_POST);setFlash('success','Task published. The full reward budget is committed to this task.');}
        elseif($action==='toggle'){SocialHub::toggle($admin,$id);setFlash('success','Task visibility updated.');}
        elseif($action==='review'){SocialHub::review($admin,$id,(string)($_POST['decision']??''),$_POST['note']??'');setFlash('success','Submission reviewed. Approved rewards are credited once.');}
        elseif($action==='quote'){SocialHub::quote($admin,$id,$_POST);setFlash('success','Quotation sent to customer. No money deducted.');}
        elseif($action==='publish'){SocialHub::publish($admin,$id);setFlash('success','Paid promotion published.');}
        else throw new InvalidArgumentException('Unknown action.');
    }catch(InvalidArgumentException $e){setFlash('error',$e->getMessage());}
    catch(Throwable $e){error_log('Admin social action: '.$e->getMessage());setFlash('error','Action could not be completed. Check status before trying again.');}
    header('Location: '.SITE_URL.'/admin/social?tab='.$tab);exit;
}
$page=max(1,min(100000,(int)($_GET['page']??1)));$offset=($page-1)*25;$rows=[];$available=true;
try {
    if($tab==='tasks')$rows=Database::fetchAll("SELECT * FROM social_campaigns ORDER BY id DESC LIMIT 26 OFFSET $offset");
    elseif($tab==='reviews')$rows=Database::fetchAll("SELECT s.*,c.title,c.reward,c.slots,c.approved_count,u.full_name,u.phone FROM social_submissions s JOIN social_campaigns c ON c.id=s.campaign_id JOIN users u ON u.id=s.user_id ORDER BY CASE WHEN s.status='pending' THEN 0 ELSE 1 END,s.id DESC LIMIT 26 OFFSET $offset");
    else $rows=Database::fetchAll("SELECT p.*,u.full_name,u.phone,c.approved_count FROM social_promotions p JOIN users u ON u.id=p.user_id LEFT JOIN social_campaigns c ON c.promotion_id=p.id ORDER BY p.id DESC LIMIT 26 OFFSET $offset");
}catch(Throwable $e){$available=false;error_log('Admin social list: '.$e->getMessage());}
$hasMore=count($rows)>25;$rows=array_slice($rows,0,25);$pageTitle='Social campaigns';include __DIR__.'/admin_header.php'; ?>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/member-services.css"><h1>Social campaigns & promotions</h1><?php displayFlash(); ?>
<nav class="feature-nav"><?php foreach(['tasks'=>'Published tasks','reviews'=>'Review submissions','promotions'=>'Promotion quotations'] as $key=>$label): ?><a class="btn <?= $tab===$key?'btn-primary':'btn-outline-primary' ?>" href="?tab=<?= $key ?>"><?= $label ?></a><?php endforeach; ?></nav>
<p class="service-notice">Publish content discovery, research or feedback tasks. Do not promise paid subscriptions, likes or followers. Opening a link alone is not proof of completion. Rewards must be funded before a campaign is published.</p>
<?php if(!$available): ?><div class="alert alert-warning">Feature is not ready. Run the social/loans migration listed in DEPLOYMENT.md.</div><?php endif; ?>
<?php if($tab==='tasks' && $available): ?>
<details class="service-card"><summary>Publish a new admin-funded task</summary><form method="POST"><?php featureToken(); ?><input type="hidden" name="action" value="create">
<div class="service-grid"><div><label for="platform">Platform</label><select id="platform" name="platform"><?php foreach(SocialHub::PLATFORMS as $key=>$label): ?><option value="<?= $key ?>"><?= $label ?></option><?php endforeach; ?></select></div><div><label for="reward">Reward per accepted answer (TZS)</label><input id="reward" type="number" name="reward" min="1" max="100000" value="100" required></div><div><label for="slots">Maximum paid participants</label><input id="slots" type="number" name="slots" min="1" max="10000" value="100" required></div></div>
<label for="title">Title</label><input id="title" name="title" maxlength="150" required><label for="url">HTTPS social link</label><input id="url" name="url" type="url" maxlength="600" required><label for="instructions">Task instructions / feedback question</label><textarea id="instructions" name="instructions" rows="4" maxlength="4000" required></textarea>
<p class="service-budget" id="task-budget">100 participants × TZS 100 = TZS 10,000 reward budget.</p><label><input type="checkbox" name="budget_confirmed" value="1" required>I have funded the full reward budget and will review answers before payment.</label><button class="btn btn-primary">Publish task</button></form></details>
<?php endif; ?>
<?php if(!$rows && $available): ?><p class="service-empty">No records yet.</p><?php endif; ?>
<?php foreach($rows as $row): ?><article class="service-card">
<?php if($tab==='tasks'): ?>
<h2><?= sanitize($row['title']) ?></h2><p>#<?= (int)$row['id'] ?> · <?= sanitize(SocialHub::PLATFORMS[$row['platform']]??$row['platform']) ?> · <?= featureStatus($row['status']) ?></p><a href="<?= sanitize($row['url']) ?>" target="_blank" rel="noopener noreferrer">Open content</a><p class="service-note"><?= sanitize($row['instructions']) ?></p><p class="service-budget"><?= (int)$row['approved_count'] ?> / <?= (int)$row['slots'] ?> paid · <?= formatCurrency($row['reward']) ?> each · Total budget <?= formatCurrency($row['reward']*$row['slots']) ?></p>
<form method="POST"><?php featureToken(); ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-outline-primary"><?= $row['status']==='active'?'Pause':'Resume' ?></button></form>
<?php elseif($tab==='reviews'): ?>
<h2><?= sanitize($row['title']) ?> · <?= formatCurrency($row['reward']) ?></h2><p><?= sanitize($row['full_name']) ?> · <?= sanitize($row['phone']) ?> · <?= featureStatus($row['status']) ?></p><p class="service-note"><?= sanitize($row['answer']) ?></p><p><?= (int)$row['approved_count'] ?> / <?= (int)$row['slots'] ?> reward slots used</p>
<?php if($row['status']==='pending'): ?><form method="POST"><?php featureToken(); ?><input type="hidden" name="action" value="review"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><label for="note-<?= (int)$row['id'] ?>">Review findings</label><textarea id="note-<?= (int)$row['id'] ?>" name="note" maxlength="1000" rows="2" required></textarea><button name="decision" value="approved" class="btn btn-success">Approve and credit <?= formatCurrency($row['reward']) ?></button><button name="decision" value="rejected" class="btn btn-outline-danger">Reject</button></form><?php else: ?><p>Admin: <?= sanitize($row['review_note']) ?></p><?php endif; ?>
<?php else: ?>
<h2><?= sanitize($row['title']) ?></h2><p>#<?= (int)$row['id'] ?> · <?= sanitize($row['full_name']) ?> · <?= sanitize($row['phone']) ?> · <?= featureStatus($row['status']) ?></p><a href="<?= sanitize($row['url']) ?>" target="_blank" rel="noopener noreferrer"><?= sanitize(SocialHub::PLATFORMS[$row['platform']]??$row['platform']) ?> link</a><p class="service-note"><?= sanitize($row['brief']) ?></p><p>Requested participants: <?= (int)$row['requested_slots'] ?></p>
<?php if(in_array($row['status'],['requested','quoted'],true)): ?><form method="POST"><?php featureToken(); ?><input type="hidden" name="action" value="quote"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><div class="service-grid"><div><label>Total price (TZS)</label><input type="number" name="price" min="1" max="100000000" value="<?= (int)($row['quote_price']??0)?:'' ?>" required></div><div><label>Reward per participant (TZS)</label><input type="number" name="reward" min="1" max="100000" value="<?= (int)($row['quote_reward']??100) ?>" required></div><div><label>Paid participants</label><input type="number" name="slots" min="1" max="10000" value="<?= (int)($row['quote_slots']??$row['requested_slots']) ?>" required></div></div><label>Deliverables, feedback question, duration and terms</label><textarea name="terms" rows="4" maxlength="4000" required><?= sanitize($row['quote_terms']??'') ?></textarea><p>Price must cover participants × reward. Customer must accept the latest quotation before payment.</p><button class="btn btn-primary">Send quotation</button></form>
<?php else: ?><p class="service-budget">Price <?= formatCurrency($row['quote_price']??0) ?> · <?= (int)$row['quote_slots'] ?> participants × <?= formatCurrency($row['quote_reward']??0) ?> · <?= (int)($row['approved_count']??0) ?> approved</p><p class="service-note"><?= sanitize($row['quote_terms']??'') ?></p><?php if($row['status']==='paid'): ?><form method="POST"><?php featureToken(); ?><input type="hidden" name="action" value="publish"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-success">Publish paid campaign to user tasks</button></form><?php endif; ?><?php endif; ?>
<?php endif; ?></article><?php endforeach; ?>
<nav class="feature-nav"><?php if($page>1): ?><a class="btn btn-outline-primary" href="?tab=<?= $tab ?>&page=<?= $page-1 ?>">Previous</a><?php endif; ?><?php if($hasMore): ?><a class="btn btn-outline-primary" href="?tab=<?= $tab ?>&page=<?= $page+1 ?>">Next</a><?php endif; ?></nav>
<script src="<?= SITE_URL ?>/assets/js/social-budget.js"></script>
<?php include __DIR__.'/admin_footer.php'; ?>
