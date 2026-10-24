<?php
require_once __DIR__ . '/../classes/AblinerPayment.php';
Auth::initSession();
require_once dirname(__DIR__) . '/classes/StaffAccess.php';
StaffAccess::requirePage('abliner-wallet');
$error = '';
$message = '';
$service = new AblinerPayment();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!Auth::validateCSRF()) { http_response_code(403); exit('Invalid security token.'); }
    try {
        $result = $service->initiateFunding((int) $_SESSION['user_id'], (string) ($_POST['phone'] ?? ''), (float) ($_POST['amount'] ?? 0));
        if ($result['success']) $message = $result['message'];
        else $error = $result['error'];
    } catch (Throwable $e) { error_log('Abliner wallet deposit failed: ' . $e->getMessage()); $error = 'Deposit could not be confirmed. Check its status before another attempt.'; }
}
$deposits = Database::fetchAll("SELECT * FROM payments WHERE gateway = 'abliner' AND purpose = 'admin_funding' AND user_id = ? ORDER BY id DESC LIMIT 20", [$_SESSION['user_id']]);
$csrf = Auth::generateCSRF();
$pageTitle = 'Abliner Wallet';
include __DIR__ . '/admin_header.php';
?>
<div id="abliner-wallet" data-balance-url="<?= sanitize(SITE_URL) ?>/api/abliner_balance.php" data-status-url="<?= sanitize(SITE_URL) ?>/api/check_payment.php">
<h1 class="h3 mb-4">Abliner Wallet</h1>
<div class="card mb-4"><div class="card-body">
    <h6>Available company balance</h6>
    <div id="wallet-balance" class="h4" aria-live="polite">Loading…</div>
    <button type="button" class="btn btn-outline-primary btn-sm" id="refresh-balance">Refresh balance</button>
    <p class="text-muted mt-2 mb-0">This is the company payment wallet at Abliner. User commission balances are managed separately.</p>
</div></div>
<?php if ($error): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>
<?php if ($message): ?><div id="deposit-notice" class="alert alert-info" role="status"><?= sanitize($message) ?></div><?php endif; ?>
<?php if (StaffAccess::can('wallet_manage')): ?>
<div class="card mb-4"><div class="card-header"><h6>Add money by mobile money</h6></div><div class="card-body">
    <form method="POST">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
        <div class="mb-3"><label for="funding-amount" class="form-label">Amount (TZS)</label>
            <input id="funding-amount" type="number" name="amount" min="500" max="3000000" step="1" class="form-control" required></div>
        <div class="mb-3"><label for="funding-phone" class="form-label">Phone number to charge</label>
            <input id="funding-phone" type="tel" name="phone" class="form-control" required data-recipient-lookup
                data-lookup-url="<?= sanitize(SITE_URL) ?>/api/lookup_phone.php" data-csrf="<?= $csrf ?>" data-csrf-name="<?= CSRF_TOKEN_NAME ?>"></div>
        <p class="text-muted">Approve the prompt on your phone. This deposit funds the company wallet and does not award referral commissions.</p>
        <button class="btn btn-primary" type="submit">Request deposit</button>
    </form>
</div></div>
<?php endif; ?>
<div class="card"><div class="card-header"><h6>Your recent company deposits</h6></div><div class="card-body table-responsive">
    <table class="table"><thead><tr><th>Reference</th><th>Amount</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($deposits as $deposit): ?>
        <tr><td><?= sanitize($deposit['order_id']) ?></td><td><?= formatCurrency((float) $deposit['amount']) ?></td>
            <td data-status-for="<?= sanitize($deposit['order_id']) ?>"><?= sanitize($deposit['status']) ?></td>
            <td><button type="button" class="btn btn-sm btn-outline-primary check-deposit" data-order="<?= sanitize($deposit['order_id']) ?>" data-status="<?= sanitize($deposit['status']) ?>">Check status</button></td></tr>
    <?php endforeach; ?>
    <?php if (!$deposits): ?><tr><td colspan="4">No company deposits yet.</td></tr><?php endif; ?>
    </tbody></table>
    <p id="deposit-message" role="status"></p>
</div></div>
<script src="<?= sanitize(SITE_URL) ?>/assets/js/recipient-lookup.js"></script>
</div>
<script src="<?= sanitize(SITE_URL) ?>/assets/js/admin-wallet.js"></script>
<?php include __DIR__ . '/admin_footer.php'; ?>
