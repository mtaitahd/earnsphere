<?php
require_once dirname(__DIR__).'/classes/LoanApplications.php';
Auth::initSession();StaffAccess::requirePage('loans');require_once dirname(__DIR__).'/includes/service_view.php';
$admin=(int)$_SESSION['user_id'];
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try {
        if(($_POST['action']??'')==='policy'){LoanApplications::savePolicy($admin,$_POST);setFlash('success','Loan terms updated. Existing applications retain the terms they accepted.');}
        elseif(($_POST['action']??'')==='review'){LoanApplications::review($admin,(int)($_POST['id']??0),(string)($_POST['decision']??''),$_POST['note']??'');setFlash('success','Application reviewed. No funds were sent.');}
        else throw new InvalidArgumentException('Unknown action.');
    }catch(InvalidArgumentException $e){setFlash('error',$e->getMessage());}
    catch(Throwable $e){error_log('Admin loan action: '.$e->getMessage());setFlash('error','Action could not be completed. Please check status.');}
    header('Location: '.SITE_URL.'/admin/loans');exit;
}
$policy=LoanApplications::policy();$rows=[];$available=true;$page=max(1,min(100000,(int)($_GET['page']??1)));$offset=($page-1)*25;
try{$rows=Database::fetchAll("SELECT l.*,u.full_name,u.phone FROM loan_applications l JOIN users u ON u.id=l.user_id ORDER BY CASE WHEN l.status='pending' THEN 0 ELSE 1 END,l.id DESC LIMIT 26 OFFSET $offset");}catch(Throwable $e){$available=false;error_log('Loan listing: '.$e->getMessage());}
$hasMore=count($rows)>25;$rows=array_slice($rows,0,25);$pageTitle='Loan applications';include __DIR__.'/admin_header.php'; ?>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/member-services.css"><h1>Loan applications</h1><?php displayFlash(); ?>
<p class="service-notice">Publish clear eligibility, repayment period, interest/charges and next steps before enabling applications. Approval here records an application decision; it does not disburse money, create a repayment ledger or confirm funds received.</p>
<details class="service-card"><summary>Publish eligibility and loan terms</summary><form method="POST"><?php featureToken(); ?><input type="hidden" name="action" value="policy"><label><input type="checkbox" name="enabled" value="1" <?= $policy['enabled']?'checked':'' ?>>Enable new loan applications</label><div class="service-grid">
<?php foreach(['min_amount'=>'Minimum amount (TZS)','max_amount'=>'Maximum amount (TZS)','min_days'=>'Minimum account age (days)','min_referrals'=>'Minimum paid direct referrals'] as $key=>$label): ?><div><label for="<?= $key ?>"><?= $label ?></label><input id="<?= $key ?>" name="<?= $key ?>" type="number" min="<?= in_array($key,['min_amount','max_amount'],true)?1000:0 ?>" value="<?= (int)$policy[$key] ?>" required></div><?php endforeach; ?></div><label for="loan-terms">Full terms, costs, repayment and review process</label><textarea id="loan-terms" name="terms" maxlength="10000" rows="8"><?= sanitize($policy['terms']) ?></textarea><button class="btn btn-primary">Save loan policy</button></form></details>
<?php if(!$available): ?><div class="alert alert-warning">Run the social/loans migration in DEPLOYMENT.md to enable applications.</div><?php elseif(!$rows): ?><p class="service-empty">No applications yet.</p><?php endif; ?>
<?php foreach($rows as $row): ?><article class="service-card"><h2>#<?= (int)$row['id'] ?> · <?= sanitize($row['full_name']) ?> · <?= formatCurrency($row['amount']) ?></h2><p><?= sanitize($row['phone']) ?> · <?= featureStatus($row['status']) ?> · <?= formatLedgerTime($row['created_at']) ?> EAT</p><p><?= sanitize($row['purpose']) ?></p>
<details><summary>Terms accepted at application time</summary><?php $snapshot=json_decode($row['terms_snapshot'],true)?:[]; ?><p class="service-note"><?= sanitize($snapshot['terms']??'') ?></p><p>Amounts: <?= formatCurrency($snapshot['min_amount']??0) ?> – <?= formatCurrency($snapshot['max_amount']??0) ?> · Minimum days <?= (int)($snapshot['min_days']??0) ?> · Paid referrals <?= (int)($snapshot['min_referrals']??0) ?></p></details>
<?php if(in_array($row['status'],['pending','approved'],true)): ?><form method="POST"><?php featureToken(); ?><input type="hidden" name="action" value="review"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><label>Decision / next steps</label><textarea name="note" rows="3" maxlength="1000" required></textarea><?php if($row['status']==='pending'): ?><button name="decision" value="approved" class="btn btn-success">Approve for follow-up</button><button name="decision" value="rejected" class="btn btn-outline-danger">Reject</button><?php else: ?><button name="decision" value="closed" class="btn btn-outline-primary">Close follow-up</button><?php endif; ?></form><?php endif; ?>
<?php if($row['review_note']): ?><p>Previous decision: <?= sanitize($row['review_note']) ?></p><?php endif; ?></article><?php endforeach; ?>
<nav class="feature-nav"><?php if($page>1): ?><a class="btn btn-outline-primary" href="?page=<?= $page-1 ?>">Previous</a><?php endif; ?><?php if($hasMore): ?><a class="btn btn-outline-primary" href="?page=<?= $page+1 ?>">Next</a><?php endif; ?></nav>
<?php include __DIR__.'/admin_footer.php'; ?>
