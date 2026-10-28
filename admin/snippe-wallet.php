<?php
require_once dirname(__DIR__).'/classes/SnippeAdminTransfer.php';
require_once dirname(__DIR__).'/classes/StaffAccess.php';
require_once dirname(__DIR__).'/includes/helpers.php';
Auth::initSession();StaffAccess::requirePage('settings');
$actor=(int)$_SESSION['user_id'];$service=new SnippeAdminTransfer();
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    if (!Auth::validateCSRF()) {http_response_code(403);exit('Invalid security token.');}
    try {
        $action=$_POST['action']??'';
        if ($action==='preview') {
            $quote=$service->preview($actor,$_POST);
            $_SESSION['snippe_company_quote']=$quote+['key'=>'SN-CA-'.bin2hex(random_bytes(12)),'expires'=>time()+300];
        } elseif ($action==='send') {
            $quote=$_SESSION['snippe_company_quote']??[];
            if (($quote['expires']??0)<time() || !is_string($_POST['request_key']??null) || !hash_equals($quote['key']??'',$_POST['request_key'])) throw new InvalidArgumentException('Preview the transfer again before sending.');
            $result=$service->send($actor,array_intersect_key($quote,array_flip(['amount','phone','name','fees','total'])),$quote['key'],($_POST['confirmed']??'')==='1');
            unset($_SESSION['snippe_company_quote']);
            setFlash('success','Transfer #'.$result['id'].' status: '.$result['status'].'. Check its status before another transfer.');
        } elseif ($action==='check') {
            $result=$service->check($actor,(int)($_POST['transfer_id']??0));
            setFlash('success','Transfer #'.$result['id'].' status: '.$result['status'].'. No new transfer was sent.');
        } else throw new InvalidArgumentException('Unknown transfer action.');
    } catch(InvalidArgumentException $e) {setFlash('error',$e->getMessage());}
    catch(Throwable $e) {error_log('Snippe company wallet: '.$e->getMessage());setFlash('error',$e instanceof RuntimeException?$e->getMessage():'Transfer could not be confirmed. Check its status before another attempt.');}
    header('Location: '.SITE_URL.'/admin/snippe-wallet');exit;
}
$ready=$service->ready();$transfers=$ready?$service->history($actor):[];
$quote=$_SESSION['snippe_company_quote']??null;if ($quote && $quote['expires']<time()) {$quote=null;unset($_SESSION['snippe_company_quote']);}
$error=getFlash('error');$message=getFlash('success');$csrf=Auth::generateCSRF();$pageTitle='Snippe Wallet';include __DIR__.'/admin_header.php';
?>
<div class="page-header"><h1>Snippe Wallet</h1><p>Withdraw company funds to a mobile money number.</p></div>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= sanitize($error) ?></div><?php endif; ?>
<?php if ($message): ?><div class="alert alert-info" role="status"><?= sanitize($message) ?></div><?php endif; ?>
<?php if (!$ready): ?><div class="alert alert-warning">Complete the Snippe configuration and admin transfer migration first. <a href="<?= sanitize(SITE_URL) ?>/admin/settings#snippe-settings">Open Snippe settings</a></div><?php endif; ?>
<div class="card mb-4"><div class="card-header"><h6>Withdraw from Snippe</h6></div><div class="card-body">
    <p>The amount and Snippe fee are paid from the company balance at Snippe. Member wallets are unaffected.</p>
    <form method="POST">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="preview">
        <div class="row g-3 mb-3">
            <div class="col-md-6"><label class="form-label" for="company-phone">Recipient phone number</label><input type="tel" class="form-control" id="company-phone" name="phone" required placeholder="0712 345 678"></div>
            <div class="col-md-6"><label class="form-label" for="company-amount">Amount to receive (TZS)</label><input type="number" class="form-control" id="company-amount" name="amount" required min="500" max="3000000" step="1"></div>
        </div>
        <button class="btn btn-primary" <?= $ready?'':'disabled' ?>>Verify recipient &amp; preview fee</button>
    </form>
</div></div>
<?php if ($quote): ?>
<div class="card mb-4"><div class="card-header"><h6>Confirm transfer</h6></div><div class="card-body">
    <dl class="row"><dt class="col-sm-4">Recipient</dt><dd class="col-sm-8"><?= sanitize($quote['name']) ?> · <?= sanitize($quote['phone']) ?></dd>
        <dt class="col-sm-4">Amount to receive</dt><dd class="col-sm-8"><?= formatCurrency($quote['amount']) ?></dd>
        <dt class="col-sm-4">Snippe fee</dt><dd class="col-sm-8"><?= formatCurrency($quote['fees']) ?></dd>
        <dt class="col-sm-4">Total from Snippe</dt><dd class="col-sm-8"><strong><?= formatCurrency($quote['total']) ?></strong></dd></dl>
    <form method="POST"><input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="send"><input type="hidden" name="request_key" value="<?= sanitize($quote['key']) ?>">
        <label class="d-block mb-3"><input type="checkbox" name="confirmed" value="1" required> I confirm this recipient and authorize this amount plus Snippe fee from the company balance.</label>
        <button class="btn btn-primary">Send withdrawal</button>
    </form>
</div></div>
<?php endif; ?>
<div class="card"><div class="card-header"><h6>Recent company withdrawals</h6></div><div class="card-body table-responsive">
    <table class="table"><thead><tr><th>Request / reference</th><th>Recipient</th><th>Amount</th><th>Fee / total</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach($transfers as $transfer): ?><tr>
        <td><small><?= sanitize($transfer['idempotency_key']) ?><br><?= sanitize($transfer['reference']??'Awaiting reference') ?></small></td>
        <td><?= sanitize($transfer['recipient_name']) ?><br><small><?= sanitize($transfer['recipient_phone']) ?></small></td>
        <td><?= formatCurrency($transfer['amount']) ?></td><td><?= formatCurrency($transfer['fees']) ?> / <?= formatCurrency($transfer['total']) ?></td><td><?= statusBadge($transfer['status']) ?></td>
        <td><?php if ($transfer['status']==='pending'): ?><form method="POST"><input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="check"><input type="hidden" name="transfer_id" value="<?= (int)$transfer['id'] ?>"><button class="btn btn-sm btn-outline-primary">Check status</button></form><?php endif; ?></td>
    </tr><?php endforeach; ?>
    <?php if (!$transfers): ?><tr><td colspan="6">No company withdrawals yet.</td></tr><?php endif; ?>
    </tbody></table><p class="text-muted">If a transfer is pending or its reference is missing, contact Snippe using the request ID before sending again.</p>
</div></div>
<?php include __DIR__.'/admin_footer.php'; ?>
