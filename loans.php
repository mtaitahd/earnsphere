<?php
require_once __DIR__.'/includes/member_feature.php';require_once __DIR__.'/classes/LoanApplications.php';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try {LoanApplications::apply((int)$user['id'],$_POST);setFlash('success','Loan application received for admin review. No funds have been sent.');}
    catch(InvalidArgumentException $e){setFlash('error',$e->getMessage());}
    catch(Throwable $e){error_log('Loan application: '.$e->getMessage());setFlash('error','Application could not be submitted. Please try again.');}
    header('Location: '.SITE_URL.'/loans');exit;
}
$page=max(1,min(100000,(int)($_GET['page']??1)));$offset=($page-1)*25;
$available=true;$applications=[];$policy=LoanApplications::policy();$eligibility=LoanApplications::eligibility($user,$policy);
try{$applications=Database::fetchAll("SELECT * FROM loan_applications WHERE user_id=? ORDER BY id DESC LIMIT 26 OFFSET $offset",[$user['id']]);}catch(Throwable $e){$available=false;error_log('Loans unavailable: '.$e->getMessage());}
$hasMore=count($applications)>25;$applications=array_slice($applications,0,25);
$pageTitle='Loan applications';include __DIR__.'/includes/public_head.php'; ?>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/member-services.css"><main class="services-page"><h1>Loan applications</h1><?php featureNavigation('loans');displayFlash(); ?>
<div class="service-notice">Admin anakagua kila ombi. Kuomba au kuidhinishwa hapa hakumaanishi fedha zimetumwa. Usitume PIN au password kwenye ombi.</div>
<section class="service-card"><h2>Vigezo na masharti ya admin</h2>
<p>Amount: <?= formatCurrency($policy['min_amount']) ?> – <?= formatCurrency($policy['max_amount']) ?><br>Minimum account age: <?= (int)$policy['min_days'] ?> days<br>Paid direct referrals: <?= (int)$policy['min_referrals'] ?></p>
<p class="service-note"><?= sanitize($policy['terms']?:'Admin has not published loan terms yet.') ?></p>
<?php if($eligibility): ?><div class="service-errors"><?php foreach($eligibility as $failure): ?><p><?= sanitize($failure) ?></p><?php endforeach; ?></div><?php elseif($available): ?>
<form method="POST"><?php featureToken(); ?><input type="hidden" name="request_key" value="<?= bin2hex(random_bytes(24)) ?>"><input type="hidden" name="terms_hash" value="<?= LoanApplications::hash($policy) ?>"><label for="amount">Amount requested (TZS)</label><input id="amount" name="amount" type="number" min="<?= (int)$policy['min_amount'] ?>" max="<?= (int)$policy['max_amount'] ?>" required>
<label for="purpose">Purpose of loan</label><textarea id="purpose" name="purpose" rows="3" maxlength="1500" required></textarea><label><input type="checkbox" name="accepted" value="1" required>I have read and accept the published terms. This is an application for review.</label><button class="btn btn-primary">Submit loan application</button></form>
<?php endif; ?></section>
<h2>My applications</h2><?php if(!$available): ?><p>Applications are temporarily unavailable.</p><?php elseif(!$applications): ?><p>No applications yet.</p><?php endif; ?>
<?php foreach($applications as $item): ?><article class="service-card"><h2><?= formatCurrency($item['amount']) ?></h2><p>#<?= (int)$item['id'] ?> · <?= featureStatus($item['status']) ?> · <?= formatLedgerTime($item['created_at']) ?> EAT</p><p><?= sanitize($item['purpose']) ?></p><?php if($item['review_note']): ?><p>Admin: <?= sanitize($item['review_note']) ?></p><?php endif; ?>
<details><summary>Terms accepted with this application</summary><?php $accepted=json_decode($item['terms_snapshot'],true)?:[]; ?><p class="service-note"><?= sanitize($accepted['terms']??'') ?></p><p>Amount range: <?= formatCurrency($accepted['min_amount']??0) ?> – <?= formatCurrency($accepted['max_amount']??0) ?> · Account age: <?= (int)($accepted['min_days']??0) ?> days · Referrals: <?= (int)($accepted['min_referrals']??0) ?></p></details></article><?php endforeach; ?>
<?php featurePages($page,$hasMore); ?>
</main><?php include __DIR__.'/includes/public_foot.php'; ?>
