<?php
/**
 * EarnSphere - Withdrawal Request Page
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Wallet.php';
require_once __DIR__ . '/classes/WithdrawalCharge.php';
require_once __DIR__ . '/includes/helpers.php';

Auth::initSession();
Auth::requireAuth();

header('Cache-Control: no-store, private');

Wallet::autoExpirePending((int) $_SESSION['user_id']);

$user = Auth::getUser();
$wallet = Wallet::getWallet($_SESSION['user_id']);

// Get pending withdrawals
$pendingWithdrawals = Database::fetchAll(
    "SELECT * FROM withdrawals WHERE user_id = ? ORDER BY created_at DESC LIMIT 10",
    [$_SESSION['user_id']]
);

$withdrawalCharge = WithdrawalCharge::configured();
$success = '';
$error = '';
$payoutResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCSRF($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'Security: Please try again.';
    } else {
        $amount = (float)($_POST['amount'] ?? 0);
        $phone = trim($_POST['phone'] ?? '');
        
        $result = Wallet::requestWithdrawal($_SESSION['user_id'], $amount, $phone);
        
        if ($result['success']) {
            $payoutResult = $result;
            $wallet = Wallet::getWallet($_SESSION['user_id']);
        } else {
            $error = $result['errors'][0] ?? 'An error occurred.';
        }
    }
}

$wallet = Wallet::getWallet($_SESSION['user_id']);
$pendingWithdrawals = Database::fetchAll("SELECT w.*, (SELECT p.metadata FROM payouts p WHERE p.withdrawal_id = w.id ORDER BY p.id DESC LIMIT 1) AS payout_metadata FROM withdrawals w WHERE w.user_id = ? ORDER BY w.created_at DESC LIMIT 10", [$_SESSION['user_id']]);
$requestKey = bin2hex(random_bytes(32));
$_SESSION['withdrawal_request_keys'] = array_slice($_SESSION['withdrawal_request_keys'] ?? [], -19, null, true);
$_SESSION['withdrawal_request_keys'][$requestKey] = time();
$csrf = Auth::generateCSRF();
$pageTitle = 'Request Withdrawal';
include __DIR__ . '/includes/public_head.php';
?>

<div class="dash-header">
    <div class="top-bar">
        <a href="wallet" style="color:white;text-decoration:none;">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h5 class="mb-0" style="font-weight:800;">Withdraw Money</h5>
        <div></div>
    </div>
</div>

<div class="dash-content mb-safe">
    
    <!-- Available Balance -->
    <div class="withdrawal-summary" style="margin-top:-1rem;">
        <div class="available">Commission Earnings</div>
        <div class="amount"><?= formatCurrency($wallet['withdrawable_balance'] ?? 0) ?></div>
        <small class="text-muted">Min: <?= formatCurrency(app_setting('min_withdrawal', MIN_WITHDRAWAL)) ?> | Max: <?= formatCurrency(app_setting('max_withdrawal', MAX_WITHDRAWAL)) ?></small>
        <small class="d-block mt-1" style="font-size:0.75rem;color:#9ca3af;">
            <i class="fas fa-info-circle me-1"></i> Referral earnings and approved task rewards can be withdrawn. Registration fees are not included.
        </small>
    </div>
    
    <?php if ($payoutResult): ?>
        <?php
            $payoutStatus = $payoutResult['payout_status'] ?? 'pending';
            $isFailed = ($payoutStatus === 'failed');
            $isPending = ($payoutStatus === 'pending');
            $isCompleted = ($payoutStatus === 'completed');
            $isSuccess = $isCompleted;

            if ($isCompleted) {
                $icon = 'check-circle';
                $iconColor = 'var(--secondary)';
                $iconBg = '#ecfdf5';
                $title = 'Payout Successful!';
            } elseif ($isPending) {
                $icon = 'clock';
                $iconColor = 'var(--accent)';
                $iconBg = '#fffbeb';
                $title = 'Payout Processing';
            } else {
                $icon = 'times-circle';
                $iconColor = 'var(--danger)';
                $iconBg = '#fef2f2';
                $title = 'Payout Failed';
            }
        ?>
        <div class="payout-result-card" style="background:var(--white);border-radius:var(--radius-lg);padding:2rem 1.5rem;box-shadow:var(--card-shadow-lg);text-align:center;margin-top:1rem;">
            <div style="width:72px;height:72px;border-radius:50%;background:<?= $iconBg ?>;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
                <i class="fas fa-<?= $icon ?>" style="font-size:2rem;color:<?= $iconColor ?>;"></i>
            </div>
            <h5 style="font-weight:800;color:var(--gray-800);margin-bottom:0.5rem;"><?= $title ?></h5>
            <p style="color:var(--gray-500);font-size:0.9rem;margin-bottom:1.5rem;">
                <?php if ($isCompleted): ?>
                    Money has been sent to your mobile money account.
                <?php elseif ($isPending): ?>
                    <?= $payoutResult['message'] ?? 'Payout is being processed. You will receive the money shortly.' ?>
                <?php else: ?>
                    <?= $payoutResult['message'] ?? 'Something went wrong. Please try again or contact support.' ?>
                <?php endif; ?>
            </p>
            
            <div style="background:var(--gray-50);border-radius:var(--radius-md);padding:1rem;text-align:left;">
                <div style="display:flex;justify-content:space-between;padding:0.4rem 0;border-bottom:1px solid var(--gray-100);">
                    <span style="color:var(--gray-500);font-size:0.85rem;">Amount</span>
                    <span style="font-weight:700;color:var(--gray-800);"><?= formatCurrency($payoutResult['amount']) ?></span>
                </div>
                <div class="d-flex justify-content-between py-2"><span>Withdrawal charge</span><strong><?= formatCurrency($payoutResult['withdrawal_charge'] ?? 0) ?></strong></div>
                <div class="d-flex justify-content-between py-2"><span>Requested payout</span><strong><?= formatCurrency($payoutResult['net_amount'] ?? $payoutResult['amount']) ?></strong></div>
                <div style="display:flex;justify-content:space-between;padding:0.4rem 0;border-bottom:1px solid var(--gray-100);">
                    <span style="color:var(--gray-500);font-size:0.85rem;">Phone</span>
                    <span style="font-weight:700;color:var(--gray-800);"><?= formatPhone($payoutResult['phone']) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:0.4rem 0;border-bottom:1px solid var(--gray-100);">
                    <span style="color:var(--gray-500);font-size:0.85rem;">Status</span>
                    <?= statusBadge($payoutStatus) ?>
                </div>
                <?php if (!empty($payoutResult['reference'])): ?>
                <div style="display:flex;justify-content:space-between;padding:0.4rem 0;">
                    <span style="color:var(--gray-500);font-size:0.85rem;">Reference</span>
                    <span style="font-weight:600;color:var(--gray-800);font-size:0.8rem;"><?= $payoutResult['reference'] ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($isFailed): ?>
            <a href="<?= SITE_URL ?>/withdrawal" class="btn btn-primary btn-block mt-3" style="border-radius:var(--radius-lg);">
                <i class="fas fa-redo me-1"></i> Try Again
            </a>
            <?php else: ?>
            <a href="<?= SITE_URL ?>/wallet" class="btn btn-primary btn-block mt-3" style="border-radius:var(--radius-lg);">
                <i class="fas fa-wallet me-1"></i> Back to Wallet
            </a>
            <?php endif; ?>
        </div>

        <?php if ($isCompleted): ?>
        <script>
            setTimeout(function() {
                window.location.href = '<?= SITE_URL ?>/wallet';
            }, 4000);
        </script>
        <?php endif; ?>
    
    <?php else: ?>
    
    <?php if ($error): ?>
        <div class="alert alert-danger d-flex align-items-center">
            <i class="fas fa-exclamation-circle me-2"></i> <?= $error ?>
        </div>
    <?php endif; ?>
    
    <!-- Withdrawal Form (always visible) -->
    <div class="dash-section">
        <h6 class="section-title"><i class="fas fa-money-bill-wave me-1"></i> New Request</h6>
        
        <form method="POST" action="">
            <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
            <input type="hidden" name="withdrawal_request_key" value="<?= $requestKey ?>">
            <input type="hidden" name="withdrawal_charge_quote" value="<?= $withdrawalCharge ?>">
            
            <div class="form-floating mb-3">
                <input type="number" class="form-control" id="amount" name="amount" 
                       placeholder="Amount" required min="<?= app_setting('min_withdrawal', MIN_WITHDRAWAL) ?>" max="<?= app_setting('max_withdrawal', MAX_WITHDRAWAL) ?>"
                       value="<?= app_setting('min_withdrawal', MIN_WITHDRAWAL) ?>">
                <label for="amount"><i class="fas fa-coins me-1"></i> Amount (TZS)</label>
            </div>
            
            <div class="mb-3">
                <label class="form-label" for="withdrawal-gateway">Withdraw via</label>
                <select id="withdrawal-gateway" name="withdrawal_gateway" class="form-select" required>
                    <option value="abliner">Abliner</option>
                    <option value="snippe" <?= (string)app_setting('snippe_payout_enabled','0')==='1' ? '' : 'disabled' ?>>Snippe<?= (string)app_setting('snippe_payout_enabled','0')==='1' ? '' : ' (currently unavailable)' ?></option>
                </select>
            </div>
            <!-- Quick Amounts -->
            <div class="quick-amounts">
                <button type="button" class="quick-amount-btn" onclick="setAmount(5000)">5,000</button>
                <button type="button" class="quick-amount-btn" onclick="setAmount(10000)">10,000</button>
                <button type="button" class="quick-amount-btn" onclick="setAmount(20000)">20,000</button>
                <button type="button" class="quick-amount-btn" onclick="setAmount(50000)">50,000</button>
                <button type="button" class="quick-amount-btn" onclick="setAmount(100000)">100,000</button>
                <button type="button" class="quick-amount-btn" onclick="setMax()">All</button>
            </div>
            
            <div class="form-floating mb-3">
                <input type="tel" class="form-control" id="phone" name="phone" data-recipient-lookup data-require-confirmation="1" data-lookup-url="<?= htmlspecialchars(SITE_URL, ENT_QUOTES) ?>/api/lookup_phone.php" data-csrf="<?= $csrf ?>" data-csrf-name="<?= CSRF_TOKEN_NAME ?>"
                       placeholder="Phone Number" required
                       value="<?= sanitize($user['phone']) ?>">
                <label for="phone"><i class="fas fa-mobile-alt me-1"></i> Payment Phone Number</label>
                <small class="text-muted ms-1">Money will be sent to this number</small>
            </div>
            
            <p class="text-muted small">Confirm the registered name and network before withdrawing.</p>
            <p id="withdrawal-quote" class="alert alert-info small" data-charge="<?= $withdrawalCharge ?>" aria-live="polite">Withdrawal charge: <?= formatCurrency($withdrawalCharge) ?>, deducted from the requested amount.</p>
            <label class="d-block mb-3"><input type="checkbox" id="confirm-withdrawal-terms" name="confirm_withdrawal_terms" value="1" required> I agree to the wallet debit and withdrawal charge. I understand provider charges may affect the amount received.</label>
            <button type="submit" class="btn btn-primary btn-block btn-lg" id="submitBtn" <?= ($wallet['withdrawable_balance'] ?? 0) < app_setting('min_withdrawal', MIN_WITHDRAWAL) ? 'disabled' : '' ?>>
                <i class="fas fa-paper-plane me-1"></i> Submit Request
            </button>
            
            <?php if (($wallet['withdrawable_balance'] ?? 0) < app_setting('min_withdrawal', MIN_WITHDRAWAL)): ?>
                <div class="text-center mt-3 p-3" style="background:#fffbeb;border-radius:12px;">
                    <i class="fas fa-info-circle mb-2" style="color:var(--accent);font-size:1.5rem;"></i>
                    <p class="mb-1" style="font-size:0.9rem;font-weight:600;">
                        You need at least <?= formatCurrency(app_setting('min_withdrawal', MIN_WITHDRAWAL)) ?> to withdraw
                    </p>
                    <p class="text-muted mb-0" style="font-size:0.8rem;">
                        You need <?= formatCurrency(app_setting('min_withdrawal', MIN_WITHDRAWAL) - ($wallet['withdrawable_balance'] ?? 0)) ?> more in referral earnings to reach the minimum.
                    </p>
                </div>
            <?php endif; ?>
        </form>
    </div>
    
    <?php endif; /* end payoutResult else */ ?>
    
    <!-- Recent Withdrawals -->
    <?php if (!empty($pendingWithdrawals)): ?>
    <div class="dash-section">
        <h6 class="section-title"><i class="fas fa-history me-1"></i> Withdrawal History</h6>
        
        <?php foreach ($pendingWithdrawals as $wd): ?>
            <div class="list-item withdrawal-history-item">
                <div class="item-icon" style="background:<?= $wd['status'] === 'failed' || $wd['status'] === 'rejected' ? '#fef2f2' : ($wd['status'] === 'completed' ? '#ecfdf5' : '#fffbeb') ?>;color:<?= $wd['status'] === 'failed' || $wd['status'] === 'rejected' ? 'var(--danger)' : ($wd['status'] === 'completed' ? 'var(--secondary)' : 'var(--accent)') ?>;">
                    <i class="fas fa-<?= $wd['status'] === 'failed' || $wd['status'] === 'rejected' ? 'times-circle' : ($wd['status'] === 'completed' ? 'check-circle' : 'spinner fa-spin') ?>"></i>
                </div>
                <div class="item-info">
                    <p class="item-title"><?= formatCurrency($wd['amount']) ?></p>
                    <small>Charge: <?= formatCurrency($wd['withdrawal_charge'] ?? 0) ?> · Requested payout: <?= formatCurrency((float) $wd['amount'] - (float) ($wd['withdrawal_charge'] ?? 0)) ?></small>
                    <p class="item-subtitle">
                        <?= formatPhone($wd['phone']) ?><br>Requested: <?= sanitize($wd['created_at']) ?>
                    </p>
                </div>
                <div class="withdrawal-history-status">
                    <?= statusBadge($wd['status']) ?><small class="d-block"><?= sanitize(ucfirst($wd['gateway'] ?? '')) ?></small>
                    <?php if (in_array($wd['gateway'] ?? '', ['abliner','snippe'],true) && in_array($wd['status'], ['pending', 'approved', 'processing'], true)): ?>
                    <button type="button" class="btn btn-sm btn-outline-primary d-block mt-2" data-check-withdrawal="<?= (int) $wd['id'] ?>">Check status</button>
                    <small class="d-block" role="status" data-withdrawal-message="<?= (int) $wd['id'] ?>"></small>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
const amountInput = document.getElementById('amount');
const submitBtn = document.getElementById('submitBtn');
const maxBalance = <?= $wallet['withdrawable_balance'] ?? 0 ?>;
const minWithdrawal = <?= app_setting('min_withdrawal', MIN_WITHDRAWAL) ?>;

function toggleSubmit() {
    const val = parseFloat(amountInput.value);
    submitBtn.disabled = !val || val < minWithdrawal || val > maxBalance;
}

if (amountInput && submitBtn) {
amountInput.addEventListener('input', toggleSubmit);
toggleSubmit();

amountInput.closest('form').addEventListener('submit', function() {
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Processing...';
});
}

function setAmount(amount) {
    if (amount > maxBalance) amount = maxBalance;
    amountInput.value = amount;
    amountInput.dispatchEvent(new Event('input', { bubbles: true }));
    document.querySelectorAll('.quick-amount-btn').forEach(b => b.classList.remove('active'));
    event.target.classList.add('active');
    toggleSubmit();
}

function setMax() {
    amountInput.value = maxBalance;
    amountInput.dispatchEvent(new Event('input', { bubbles: true }));
    document.querySelectorAll('.quick-amount-btn').forEach(b => b.classList.remove('active'));
    event.target.classList.add('active');
    toggleSubmit();
}
</script>

<!-- Bottom Navigation -->
<nav class="mobile-nav">
    <a href="dashboard" class="nav-item">
        <i class="fas fa-home"></i>
        <span>Home</span>
    </a>
    <a href="referrals" class="nav-item">
        <i class="fas fa-users"></i>
        <span>Referrals</span>
    </a>
    <a href="wallet" class="nav-item center-action">
        <i class="fas fa-wallet"></i>
        <span>Wallet</span>
    </a>
    <a href="transactions" class="nav-item">
        <i class="fas fa-receipt"></i>
        <span>History</span>
    </a>
    <a href="profile" class="nav-item">
        <i class="fas fa-user"></i>
        <span>Profile</span>
    </a>
</nav>

<script src="<?= htmlspecialchars(SITE_URL, ENT_QUOTES) ?>/assets/js/recipient-lookup.js"></script>
<script src="<?= htmlspecialchars(SITE_URL, ENT_QUOTES) ?>/assets/js/withdrawal-charge.js?v=<?= filemtime(__DIR__ . '/assets/js/withdrawal-charge.js') ?>"></script>
<script>
document.querySelectorAll('[data-check-withdrawal]').forEach(function (button) {
    button.addEventListener('click', async function () {
        var message = document.querySelector('[data-withdrawal-message="' + button.dataset.checkWithdrawal + '"]');
        button.disabled = true;
        message.textContent = 'Checking with Abliner...';
        try {
            var response = await fetch(<?= json_encode(SITE_URL . '/api/check_withdrawal.php?withdrawal_id=') ?> + encodeURIComponent(button.dataset.checkWithdrawal), {cache: 'no-store', signal: AbortSignal.timeout(25000)});
            var result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.error || 'Verification unavailable. Do not withdraw again.');
            if (result.status === 'completed' || result.status === 'failed') { window.location.replace(<?= json_encode(SITE_URL . '/withdrawal') ?>); return; }
            message.textContent = result.message || 'Awaiting confirmation. Funds remain reserved; do not submit again.';
        } catch (error) { message.textContent = error.message || 'Verification unavailable. Try checking again.'; }
        finally { button.disabled = false; }
    });
});
var checksRemaining = 12;
var historyPoll = setInterval(function () {
    if (--checksRemaining < 0) { clearInterval(historyPoll); return; }
    if (document.hidden) return;
    var pending = document.querySelector('[data-check-withdrawal]:not(:disabled)');
    if (pending) pending.click();
}, 10000);
</script>
<?php include __DIR__ . '/includes/public_foot.php'; ?>
<?php
if ($payoutResult) {
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    try { (new AblinerPayment())->flushNotifications(1); }
    catch (Throwable $e) { error_log('Withdrawal success notification deferred: ' . $e->getMessage()); }
}
?>
