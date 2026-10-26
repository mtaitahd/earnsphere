<?php
require_once __DIR__.'/includes/member_feature.php';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try {
        $action=$_POST['action']??'';
        if($action==='request'){SocialHub::requestPromotion((int)$user['id'],$_POST);setFlash('success','Promotion request received. Admin will send a quotation.');}
        elseif($action==='pay'){
            if(($_POST['accepted']??'')!=='1')throw new InvalidArgumentException('Accept the price and quotation terms before paying.');
            SocialHub::payPromotion((int)$user['id'],(int)($_POST['id']??0),(int)($_POST['version']??0));setFlash('success','Promotion payment recorded. Admin will publish your campaign.');
        }elseif($action==='cancel'){SocialHub::cancelPromotion((int)$user['id'],(int)($_POST['id']??0));setFlash('success','Request cancelled. Any paid, unpublished quotation was refunded to your wallet.');}
        else throw new InvalidArgumentException('Unknown action.');
    }catch(InvalidArgumentException $e){setFlash('error',$e->getMessage());}
    catch(Throwable $e){error_log('Promotion request: '.$e->getMessage());setFlash('error','Your request could not be completed. Refresh and check its status before trying again.');}
    header('Location: '.SITE_URL.'/promote');exit;
}
$page=max(1,min(100000,(int)($_GET['page']??1)));$offset=($page-1)*25;
$available=true;$orders=[];
try{$orders=Database::fetchAll("SELECT p.*, c.approved_count FROM social_promotions p LEFT JOIN social_campaigns c ON c.promotion_id=p.id WHERE p.user_id=? ORDER BY p.id DESC LIMIT 26 OFFSET $offset",[$user['id']]);}catch(Throwable $e){$available=false;error_log('Promotions unavailable: '.$e->getMessage());}
$hasMore=count($orders)>25;$orders=array_slice($orders,0,25);
$wallet=Wallet::getWallet((int)$user['id']);$pageTitle='Promote account';include __DIR__.'/includes/public_head.php'; ?>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/member-services.css">
<main class="services-page"><h1>Promote your account</h1><p>Weka maudhui yako mbele ya jamii ya EarnSphere.</p><?php featureNavigation('promote');displayFlash(); ?>
<div class="service-notice">Admin hupanga bei, idadi ya washiriki na reward kabla ya kulipa. Hii ni content discovery / feedback campaign; idadi ya subscribers, likes au followers haijahakikishwa. Malipo yanatumia available earnings kwenye wallet. Fedha zilizohifadhiwa kwa withdrawal hazitumiki.</div>
<p>Available earnings: <strong><?= formatCurrency($wallet['withdrawable_balance']) ?></strong></p>
<?php if(!$available): ?><p class="service-empty">Promotion requests are temporarily unavailable.</p><?php else: ?>
<details class="service-card" open><summary>Request a promotion quotation</summary><form method="POST"><?php featureToken(); ?><input type="hidden" name="action" value="request"><input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(24)) ?>">
<label for="platform">Platform</label><select id="platform" name="platform"><?php foreach(SocialHub::PLATFORMS as $key=>$label): ?><option value="<?= $key ?>"><?= $label ?></option><?php endforeach; ?></select>
<label for="title">Campaign title</label><input id="title" name="title" maxlength="150" required>
<label for="url">Your HTTPS social link</label><input id="url" name="url" type="url" maxlength="600" required>
<label for="slots">Requested participants</label><input id="slots" name="slots" type="number" min="1" max="10000" value="100" required>
<label for="brief">What content or feedback do you want to promote?</label><textarea id="brief" name="brief" maxlength="4000" rows="3" required></textarea><button class="btn btn-primary">Request price from admin</button></form></details>
<?php endif; ?>
<h2>My promotion requests</h2><?php if(!$orders): ?><p>No requests yet.</p><?php endif; ?>
<?php foreach($orders as $order): ?><article class="service-card"><h2><?= sanitize($order['title']) ?></h2><p>#<?= (int)$order['id'] ?> · <?= featureStatus($order['status']) ?> · <?= formatLedgerTime($order['created_at']) ?> EAT</p><a href="<?= sanitize($order['url']) ?>" target="_blank" rel="noopener noreferrer">View your link</a><p class="service-note"><?= sanitize($order['brief']) ?></p>
<?php if($order['quote_price']!==null): ?><div class="service-budget"><strong>Price: <?= formatCurrency($order['quote_price']) ?></strong><br><?= (int)$order['quote_slots'] ?> approved participants × <?= formatCurrency($order['quote_reward']) ?> reward<br>Approved so far: <?= (int)($order['approved_count']??0) ?></div><p class="service-note"><?= sanitize($order['quote_terms']) ?></p><?php endif; ?>
<?php if($order['status']==='quoted'): ?><form method="POST"><?php featureToken(); ?><input type="hidden" name="action" value="pay"><input type="hidden" name="id" value="<?= (int)$order['id'] ?>"><input type="hidden" name="version" value="<?= (int)$order['quote_version'] ?>"><label><input type="checkbox" name="accepted" value="1" required>I accept this quotation and payment from my available wallet earnings.</label><button class="btn btn-primary">Pay <?= formatCurrency($order['quote_price']) ?></button></form><?php endif; ?>
<?php if(in_array($order['status'],['requested','quoted','paid'],true)): ?><form method="POST"><?php featureToken(); ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$order['id'] ?>"><button class="btn btn-outline-danger">Cancel<?= $order['status']==='paid'?' and refund unpublished campaign':'' ?></button></form><?php endif; ?>
</article><?php endforeach; ?><?php featurePages($page,$hasMore); ?>
</main><?php include __DIR__.'/includes/public_foot.php'; ?>
