<?php
/**
 * EarnSphere - User Dashboard
 * Main dashboard showing stats, referral link, and quick actions
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/CommissionEngine.php';
require_once __DIR__ . '/classes/Wallet.php';
require_once __DIR__ . '/classes/DailyMission.php';
require_once __DIR__ . '/classes/Contest.php';
require_once __DIR__ . '/includes/helpers.php';

Auth::initSession();
Auth::requireAuth();

Wallet::autoExpirePending((int) $_SESSION['user_id']);

$user = Auth::getUser();
$wallet = Wallet::getWallet($_SESSION['user_id']);
$wallet['total_earned'] = Wallet::earnedTotal((int)$_SESSION['user_id']);
$stats = CommissionEngine::getReferralStats($_SESSION['user_id']);
// Referral links are generated only on the protected Referrals page.

// Live proof of payment data
$liveProof = Database::fetchAll(
    "SELECT w.id, w.amount, w.processed_at, w.created_at, u.full_name
     FROM withdrawals w
     INNER JOIN users u ON u.id = w.user_id
     WHERE w.status = 'completed'
       AND COALESCE(w.processed_at, w.created_at) > ?
     ORDER BY COALESCE(w.processed_at, w.created_at) DESC
     LIMIT 5",
    [date('Y-m-d H:i:s', time() - 5 * 60 * 60)]
);

// Registration payment info
$registrationPayment = Database::fetchOne(
    "SELECT amount, status, completed_at, metadata FROM payments WHERE user_id = ? AND payment_type = 'registration' ORDER BY id DESC LIMIT 1",
    [$_SESSION['user_id']]
);

// Check if latest payment failed
$paymentFailed = ($registrationPayment && $registrationPayment['status'] === 'failed');
$paymentFailureReason = '';
if ($paymentFailed && !empty($registrationPayment['metadata'])) {
    $meta = json_decode($registrationPayment['metadata'], true) ?: [];
    $paymentFailureReason = $meta['failure_reason'] ?? $meta['api_error'] ?? '';
}

$csrf = Auth::generateCSRF();
$pageTitle = 'Dashboard';

// Daily Mission
$missionStatus = null;
$showMissionAnimation = false;
if ($user['status'] === 'active') {
    $missionStatus = DailyMission::getMissionStatus($_SESSION['user_id']);
    if ($missionStatus && isset($missionStatus['just_completed']) && $missionStatus['just_completed']) {
        $showMissionAnimation = true;
    }
}

// Handle AJAX referral link copy logging
if (isAjax() && isset($_GET['action']) && $_GET['action'] === 'track_copy') {
    if ($user['status']!=='active' || $user['role']!=='user') jsonResponse(['success'=>false,'message'=>'Lipia akaunti yako kwanza.'],403);
    Auth::logActivity($_SESSION['user_id'], 'referral_link_copied', 'Copied referral link');
    jsonResponse(['success' => true]);
}

include __DIR__ . '/includes/public_head.php';
?>

<link rel="stylesheet" href="<?= sanitize(SITE_URL) ?>/assets/css/dashboard-layout.css?v=<?= filemtime(__DIR__.'/assets/css/dashboard-layout.css') ?>">
<header class="account-toolbar">
    <div class="account-brand"><button type="button" class="account-menu-toggle" data-account-menu aria-label="Open account menu" aria-haspopup="dialog" aria-controls="accountMenu"><i class="fas fa-bars" aria-hidden="true"></i></button><a href="dashboard">EarnSphere<span>My account</span></a></div>
        <div class="d-flex align-items-center gap-2">
            <a href="javascript:void(0)" class="notification-btn" style="text-decoration:none;position:relative;" onclick="openNotificationCenter()" title="Notifications" id="announcementBell" aria-haspopup="dialog" aria-controls="annModal">
                <i class="fas fa-bell"></i>
                <span id="annBadge" class="ann-badge"></span>
            </a>
            <a href="javascript:void(0)" class="notification-btn" style="text-decoration:none;position:relative;" onclick="openSupport()" title="Help / Support" id="supportBell">
                <i class="fas fa-life-ring"></i>
                <span id="supportBadge" class="ann-badge"></span>
            </a>
            <a href="profile" style="text-decoration:none;">
                <div class="avatar" style="background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;color:white;font-weight:800;">
                    <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                </div>
            </a>
        </div>
</header>
<?php include __DIR__.'/includes/dashboard_menu.php'; ?>
<!-- Dashboard Header -->
<div class="dash-header account-overview">
    <div class="account-greeting"><p>Hello,</p><h1><?= sanitize($user['full_name']) ?></h1></div>
    <nav class="account-primary-actions" aria-label="Main account actions">
        <a href="withdrawal"><i class="fas fa-arrow-up" aria-hidden="true"></i><span>Withdraw</span></a>
        <?php if ($user['status'] === 'active'): ?><a href="quizzes"><i class="fas fa-gift" aria-hidden="true"></i><span>Quiz</span></a><?php endif; ?>
        <a href="marketplace"><i class="fas fa-bag-shopping" aria-hidden="true"></i><span>Buy Goods</span></a>
        <a href="referrals" <?= $user['status']==='pending'?'data-referral-pay-required':'' ?>><i class="fas fa-users" aria-hidden="true"></i><span>Referrals</span></a>
        <a href="profile"><i class="fas fa-user" aria-hidden="true"></i><span>Profile</span></a>
    </nav>
    <div class="account-totals"><div><span>Total earned</span><strong><?= formatCurrency($wallet['total_earned'] ?? 0) ?></strong></div><div><span>Total withdrawn</span><strong><?= formatCurrency($wallet['total_withdrawn'] ?? 0) ?></strong></div></div>
    <div class="account-balance"><div><span>Withdrawable balance</span><strong data-account-balance><?= formatCurrency($wallet['withdrawable_balance'] ?? 0) ?></strong><strong data-account-masked hidden>••••••</strong></div><button type="button" data-balance-toggle aria-label="Hide balance" aria-pressed="false"><i class="fas fa-eye-slash" aria-hidden="true"></i></button></div>
    <!-- Account Status -->
    <?php if ($user['status'] !== 'active'): ?>
    <div style="background:linear-gradient(135deg,<?= $paymentFailed ? '#ef4444,#dc2626' : '#f59e0b,#ef4444' ?>);border-radius:12px;padding:1rem;margin-top:0.5rem;color:white;">
        <div class="d-flex align-items-center mb-2">
            <i class="fas fa-<?= $paymentFailed ? 'times-circle' : 'exclamation-triangle' ?> me-2" style="font-size:1.2rem;"></i>
            <strong><?= $paymentFailed ? 'Payment Failed!' : 'Your account is not yet activated!' ?></strong>
        </div>
        <?php if ($paymentFailed): ?>
        <p style="font-size:0.85rem;margin:0 0 0.75rem;opacity:0.9;">
            <?= $paymentFailureReason ? 'Reason: ' . sanitize($paymentFailureReason) . '.' : 'Your last payment was not successful.' ?>
            Please try again with a valid phone number.
        </p>
        <?php else: ?>
        <p style="font-size:0.85rem;margin:0 0 0.75rem;opacity:0.9;">Complete your <?= formatCurrency(app_setting('registration_fee', REGISTRATION_FEE)) ?> payment to activate your account and start earning commissions.</p>
        <?php endif; ?>
        <a href="payment?user_id=<?= $user['id'] ?>" style="display:inline-block;background:white;color:<?= $paymentFailed ? '#ef4444' : '#f59e0b' ?>;padding:0.5rem 1.5rem;border-radius:8px;font-weight:800;text-decoration:none;">
            <i class="fas fa-credit-card me-1"></i> <?= $paymentFailed ? 'Retry Payment' : 'Pay Now' ?>
        </a>
    </div>
    <?php endif; ?>


</div>

<section class="account-paid-status" aria-label="Registration payment">
    <?php if ($registrationPayment && $user['status'] === 'active' && $registrationPayment['status'] === 'completed'): ?>
    <div style="background:#15803d;border:1px solid #22c55e;border-radius:12px;padding:1rem;margin-top:0.5rem;color:#fff;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <div style="font-size:0.8rem;opacity:0.85;"><i class="fas fa-check-circle me-1"></i> Registration Paid</div>
                <div style="font-size:1.3rem;font-weight:800;"><?= formatCurrency($registrationPayment['amount']) ?></div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:0.75rem;opacity:0.75;">Paid on</div>
                <div style="font-size:0.85rem;font-weight:700;color:#fff;"><?= date('d M Y', strtotime($registrationPayment['completed_at'])) ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</section>

<?php include __DIR__ . '/includes/welcome_media.php'; ?>


<!-- Stats Cards -->
<div class="stat-cards dashboard-stats">
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= number_format($stats['total_referrals']) ?></div>
            <div class="stat-label">Total Referrals</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon amber">
            <i class="fas fa-coins"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= formatCurrency($wallet['total_earned']) ?></div>
            <div class="stat-label">Total Earnings</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="fas fa-calendar-check"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= date('d M', strtotime($user['created_at'])) ?></div>
            <div class="stat-label">Registered</div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/dashboard_shortcuts.php'; ?>

<!-- Dashboard Content -->
<div class="dash-content mb-safe">


    
    <!-- Live Proof of Payment -->
    <?php if (app_setting('live_proof_enabled', '1') !== '0'): ?>
    <div class="dash-section" id="liveProofSection">
        <div class="section-header">
            <h6><i class="fas fa-bolt me-1" style="color:var(--secondary);"></i> Live Proof of Payment</h6>
            <span class="live-dot"><span class="dot"></span> LIVE</span>
        </div>
        <div id="liveProofList">
            <?php if (empty($liveProof)): ?>
                <div class="empty-state">
                    <i class="fas fa-coins"></i>
                    <h5>Hakuna malipo bado</h5>
                    <p>Watumiaji wanapoanza kutoa pesa, malipo yataonekana hapa live.</p>
                </div>
            <?php else: ?>
                <?php foreach ($liveProof as $lp): ?>
                <div class="list-item">
                    <div class="item-icon" style="background:#ecfdf5;color:var(--secondary);">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="item-info">
                        <p class="item-title"><?= sanitize(Contest::maskName($lp['full_name'])) ?> amepokea <?= formatCurrency($lp['amount']) ?></p>
                        <p class="item-subtitle"><?= timeAgo($lp['processed_at'] ?: $lp['created_at']) ?></p>
                    </div>
                    <div class="item-amount credit"><?= formatCurrency($lp['amount']) ?></div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Referral Stats -->
    <div class="dash-section">
        <div class="section-header">
            <h6><i class="fas fa-chart-bar me-1"></i> Referral Stats</h6>
        </div>
        
        <div class="stat-grid">
            <div class="stat-box">
                <div class="stat-number"><?= number_format($stats['level_1']) ?></div>
                <div class="stat-label">Level 1 (Direct)</div>
            </div>
            <div class="stat-box">
                <div class="stat-number" style="color:var(--secondary);"><?= number_format($stats['level_2']) ?></div>
                <div class="stat-label">Level 2 (Grand)</div>
            </div>

            <div class="stat-box">
                <div class="stat-number" style="color:var(--info);"><?= formatCurrency($wallet['total_earned']) ?></div>
                <div class="stat-label">Total Earnings</div>
            </div>
        </div>
    </div>
    
    <!-- Quick Actions -->
    <div class="dash-section">
        <div class="section-header">
            <h6><i class="fas fa-bolt me-1"></i> Quick Actions</h6>
        </div>
        
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
            <button type="button" class="btn btn-outline-primary" style="padding:0.75rem;" data-welcome-open aria-haspopup="dialog" aria-controls="welcomeMediaDialog">
                <i class="fas fa-circle-play me-1" aria-hidden="true"></i> Know Your Account
            </button>
            <a href="wallet" class="btn btn-outline-primary" style="padding:0.75rem;">
                <i class="fas fa-wallet me-1"></i> Wallet
            </a>
            <a href="withdrawal" class="btn btn-outline-primary" style="padding:0.75rem;">
                <i class="fas fa-money-bill-wave me-1"></i> Withdraw
            </a>
            <a href="referrals" <?= $user['status']==='pending'?'data-referral-pay-required':'' ?> class="btn btn-outline-primary" style="padding:0.75rem;">
                <i class="fas fa-share me-1"></i> Referrals
            </a>
            <a href="transactions" class="btn btn-outline-primary" style="padding:0.75rem;">
                <i class="fas fa-history me-1"></i> History
            </a>
            <?php if ($user['status'] === 'active'): ?>
            <a href="ai-assistant" class="btn btn-outline-primary" style="padding:0.75rem;border-color:#D4A843;color:#72578B;">
                <i class="fas fa-wand-magic-sparkles me-1" style="color:#D4A843;"></i> AI Assistant
            </a>
            <?php endif; ?>
        </div>
    </div>
    


</div>

<!-- Bottom Navigation -->
<nav class="mobile-nav">
    <a href="dashboard" class="nav-item active">
        <i class="fas fa-home"></i>
        <span>Home</span>
    </a>
    <a href="referrals" <?= $user['status']==='pending'?'data-referral-pay-required':'' ?> class="nav-item">
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

<!-- Announcement Modal -->
<div id="annModal" class="ann-modal" role="dialog" aria-modal="true" aria-label="Notifications" style="display:none;">
    <div class="ann-modal-overlay" onclick="closeAnnModal()"></div>
    <div class="ann-modal-content">
        <div class="ann-modal-header">
            <div class="ann-modal-header-icon"><i class="fas fa-bullhorn"></i></div>
            <h5>Notifications</h5>
            <button class="ann-modal-close" onclick="closeAnnModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="d-flex gap-2 px-3 pt-3 pb-2" role="group" aria-label="Notification categories">
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="openAnnouncements()" id="notificationNewsTab">News</button>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="openFinancialNotifications()" id="notificationPaymentsTab">Account updates</button>
        </div>
    <section class="ann-modal-body" style="display:none;" id="financial-notifications" data-endpoint="<?= sanitize(SITE_URL) ?>/api/notifications.php" data-csrf-name="<?= CSRF_TOKEN_NAME ?>" data-csrf="<?= sanitize($csrf) ?>">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div><h6 class="mb-1"><i class="fas fa-bell me-1"></i> Your notifications</h6><small data-notification-count role="status">Loading...</small></div>
                <button type="button" class="btn btn-sm btn-outline-success" data-notification-read-all disabled>Mark all as read</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-notification-clear-all disabled>Clear all</button>
            </div>
            <div data-notification-list aria-live="polite"></div>
        </div>
    </section>
        <div class="ann-modal-body" id="annModalBody">
            <div class="ann-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
        </div>
    </div>
</div>

<style>
/* Announcement Modal */
.ann-modal {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    z-index: 10500;
    display: flex;
    align-items: flex-end;
    justify-content: center;
}
.ann-modal-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,0.4);
}
.ann-modal-content {
    position: relative;
    background: #fff;
    border-radius: 20px 20px 0 0;
    width: 100%;
    max-width: 500px;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    animation: annSlideUp 0.3s ease;
}
@keyframes annSlideUp {
    from { transform: translateY(100%); }
    to { transform: translateY(0); }
}
.ann-modal-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1.25rem 1.25rem 0.75rem;
    border-bottom: 1px solid var(--gray-100);
}
.ann-modal-header-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: #f0ebf5;
    color: #72578B;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
}
.ann-modal-header h5 {
    flex: 1;
    font-weight: 800;
    font-size: 1rem;
    margin: 0;
}
.ann-modal-close {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: var(--gray-100);
    color: var(--gray-500);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 0.85rem;
}
.ann-modal-close:hover { background: var(--gray-200); }
.ann-modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 1rem 1.25rem 1.5rem;
}
.ann-item {
    background: var(--gray-50);
    border-radius: 14px;
    padding: 1rem;
    margin-bottom: 0.75rem;
    border-left: 4px solid #72578B;
    cursor: pointer;
    transition: all 0.2s ease;
}
.ann-item:hover { background: #f0ebf5; }
.ann-item.unread {
    background: #f8f5fc;
    border-left-color: #D4A843;
}
.ann-item-title {
    font-weight: 700;
    font-size: 0.9rem;
    color: var(--gray-800);
    margin-bottom: 0.3rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.ann-item-date {
    font-size: 0.7rem;
    color: var(--gray-400);
    margin-bottom: 0.4rem;
}
.ann-item-preview {
    font-size: 0.8rem;
    color: var(--gray-500);
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.ann-badge {
    position: absolute;
    top: -4px;
    right: -6px;
    min-width: 18px;
    height: 18px;
    background: #ef4444;
    color: #fff;
    border-radius: 50%;
    font-size: 0.6rem;
    font-weight: 800;
    display: none;
    align-items: center;
    justify-content: center;
    border: 2px solid #72578B;
    line-height: 1;
}
.ann-loading {
    text-align: center;
    padding: 2rem;
    color: var(--gray-400);
}
.ann-empty {
    text-align: center;
    padding: 2rem;
    color: var(--gray-400);
}
.ann-empty i { font-size: 2rem; margin-bottom: 0.5rem; color: var(--gray-300); }
.ann-detail {
    padding: 0.5rem 0;
}
.ann-detail h4 {
    font-weight: 800;
    font-size: 1.1rem;
    color: var(--gray-800);
    margin-bottom: 0.5rem;
}
.ann-detail p {
    font-size: 0.9rem;
    color: var(--gray-600);
    line-height: 1.6;
    white-space: pre-wrap;
}
/* Support items */
.sp-status {
    font-size: 0.65rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 0.15rem 0.5rem;
    border-radius: 10px;
    flex-shrink: 0;
}
.sp-status.open { background: #fef3c7; color: #b45309; }
.sp-status.answered { background: #d1fae5; color: #047857; }
.sp-status.closed { background: #e5e7eb; color: #6b7280; }
.sp-reply {
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px dashed var(--gray-200);
}
.sp-reply-label {
    font-size: 0.65rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #047857;
    margin-bottom: 0.4rem;
}
.sp-reply-text {
    font-size: 0.85rem;
    color: var(--gray-700);
    line-height: 1.55;
    white-space: pre-wrap;
    margin-bottom: 0.6rem;
}
.sp-clear-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    background: #10b981;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 0.35rem 0.8rem;
    font-size: 0.75rem;
    font-weight: 700;
    cursor: pointer;
}
.sp-clear-btn:hover { background: #059669; }
/* --- Live Proof --- */
.live-dot {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.65rem;
    font-weight: 800;
    letter-spacing: 1px;
    color: var(--secondary);
}
.live-dot .dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #10b981;
    animation: livePulse 1.5s infinite;
}
@keyframes livePulse {
    0% { box-shadow: 0 0 0 0 rgba(16,185,129,0.6); }
    70% { box-shadow: 0 0 0 8px rgba(16,185,129,0); }
    100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); }
}
</style>


<script>
/* --- Live Proof of Payment auto-refresh --- */
function refreshLiveProof() {
    fetch('api/live_proof.php?limit=5', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success || !res.data) return;
        const list = document.getElementById('liveProofList');
        if (!list) return;
        if (res.data.length === 0) {
            list.innerHTML = '<div class="empty-state"><i class="fas fa-coins"></i><h5>Hakuna malipo bado</h5><p>Watumiaji wanapoanza kutoa pesa, malipo yataonekana hapa live.</p></div>';
            return;
        }
        let html = '';
        res.data.forEach(x => {
            html += '<div class="list-item">' +
                '<div class="item-icon" style="background:#ecfdf5;color:var(--secondary);"><i class="fas fa-check-circle"></i></div>' +
                '<div class="item-info">' +
                    '<p class="item-title">' + escapeHtml(x.name) + ' amepokea TZS ' + Number(x.amount).toLocaleString() + '</p>' +
                    '<p class="item-subtitle">' + escapeHtml(x.time) + '</p>' +
                '</div>' +
                '<div class="item-amount credit">TZS ' + Number(x.amount).toLocaleString() + '</div>' +
            '</div>';
        });
        list.innerHTML = html;
    })
    .catch(() => {});
}

setInterval(refreshLiveProof, 30000);

/* --- Announcement Functions --- */
let annData = [];

function openAnnouncements() {
    const modal = document.getElementById('annModal');
    const body = document.getElementById('annModalBody');
    modal.style.display = 'flex';
    if (window.showNotificationCategory) window.showNotificationCategory('news');
    body.innerHTML = '<div class="ann-loading"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    fetch('api/announcements.php?action=fetch', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(res => {
        if (res.success && res.data && res.data.length > 0) {
            annData = res.data;
            let html = '';
            const unread = res.data.filter(a => !a.viewed).length;
            res.data.forEach(a => {
                html += '<div class="ann-item' + (a.viewed ? '' : ' unread') + '" onclick="viewAnnouncement(' + a.id + ')">' +
                    '<div class="ann-item-title">' +
                        (!a.viewed ? '<i class="fas fa-circle" style="color:#D4A843;font-size:0.5rem;"></i>' : '') +
                        escapeHtml(a.title) +
                    '</div>' +
                    '<div class="ann-item-date">' + escapeHtml(a.created_at) + '</div>' +
                    '<div class="ann-item-preview">' + escapeHtml(a.content) + '</div>' +
                '</div>';
            });
            body.innerHTML = html;
            updateAnnBadge(unread);
        } else {
            body.innerHTML = '<div class="ann-empty"><i class="fas fa-bullhorn"></i><p>No announcements</p></div>';
        }
    })
    .catch(() => {
        body.innerHTML = '<div class="ann-empty"><i class="fas fa-exclamation-triangle" style="color:#ef4444;"></i><p>Failed to load</p></div>';
    });
}

function viewAnnouncement(id) {
    const a = annData.find(item => item.id === id);
    if (!a) return;
    const body = document.getElementById('annModalBody');
    body.innerHTML = '<div class="ann-detail">' +
        '<button onclick="openAnnouncements()" style="background:none;border:none;color:#72578B;font-size:0.85rem;font-weight:700;cursor:pointer;margin-bottom:0.75rem;padding:0;"><i class="fas fa-arrow-left me-1"></i> Back</button>' +
        '<h4>' + escapeHtml(a.title) + '</h4>' +
        '<div style="font-size:0.75rem;color:var(--gray-400);margin-bottom:0.75rem;">' + escapeHtml(a.created_at) + '</div>' +
        '<p>' + escapeHtml(a.content) + '</p>' +
    '</div>';
    if (!a.viewed) {
        fetch('api/announcements.php?action=mark_read&id=' + id, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content }
        }).then(r => r.json()).then(() => {
            a.viewed = true;
            updateAnnBadge(annData.filter(x => !x.viewed).length);
        }).catch(() => {});
    }
}

function closeAnnModal() {
    document.getElementById('annModal').style.display = 'none';
}

function updateAnnBadge(count) {
    window.announcementUnreadCount = count;
    if (window.updateNotificationBell) { window.updateNotificationBell(); return; }
    const badge = document.getElementById('annBadge');
    if (count > 0) {
        badge.style.display = 'flex';
        badge.textContent = count > 99 ? '99+' : count;
    } else {
        badge.style.display = 'none';
    }
}

function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

/* Check for unread announcements on load */
document.addEventListener('DOMContentLoaded', function() {
    fetch('api/announcements.php?action=fetch', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(res => {
        if (res.success && res.data) {
            const unread = res.data.filter(a => !a.viewed).length;
            updateAnnBadge(unread);
        }
    })
    .catch(() => {});
});

</script>


<script src="<?= sanitize(SITE_URL) ?>/assets/js/payment-notifications.js?v=<?= filemtime(__DIR__ . '/assets/js/payment-notifications.js') ?>"></script>
<script src="<?= sanitize(SITE_URL) ?>/assets/js/dashboard-layout.js?v=<?= filemtime(__DIR__.'/assets/js/dashboard-layout.js') ?>" defer></script>
<?php if ($user['status']==='pending'): ?>
<dialog id="referral-payment-notice" aria-labelledby="referral-payment-title" style="border:0;border-radius:16px;max-width:440px;width:calc(100% - 32px);padding:1.5rem;color:var(--gray-800)">
    <h2 id="referral-payment-title" class="h5">Lipia akaunti yako kwanza</h2>
    <p>Referral link yako itafunguka baada ya malipo ya kujiunga kuthibitishwa.</p>
    <a class="btn btn-primary" href="<?= sanitize(SITE_URL) ?>/payment">Pay now / Lipia akaunti</a>
    <button class="btn btn-outline-primary" type="button" data-close-referral-payment>Baadaye</button>
</dialog>
<style>#referral-payment-notice::backdrop{background:rgba(0,0,0,.45)}</style>
<script src="<?= sanitize(SITE_URL) ?>/assets/js/referral-payment.js" defer></script>
<?php endif; ?>
<?php include __DIR__ . '/includes/public_foot.php'; ?>
