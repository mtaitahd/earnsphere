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
require_once __DIR__ . '/includes/helpers.php';

Auth::initSession();
Auth::requireAuth();

Wallet::autoExpirePending((int) $_SESSION['user_id']);

$user = Auth::getUser();
$wallet = Wallet::getWallet($_SESSION['user_id']);
$stats = CommissionEngine::getReferralStats($_SESSION['user_id']);
$referralLink = getReferralLink($user['referral_code']);

// Recent transactions
$recentTransactions = Database::fetchAll(
    "SELECT * FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 5",
    [$_SESSION['user_id']]
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
    Auth::logActivity($_SESSION['user_id'], 'referral_link_copied', 'Copied referral link');
    jsonResponse(['success' => true]);
}

include __DIR__ . '/includes/public_head.php';
?>

<!-- Dashboard Header -->
<div class="dash-header">
    <div class="top-bar">
        <div>
            <p class="greeting mb-0">Hello, 👋</p>
            <h4 class="user-name"><?= sanitize($user['full_name']) ?></h4>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="ai-assistant" class="notification-btn" style="text-decoration:none;position:relative;" title="AI Share Assistant">
                <i class="fas fa-wand-magic-sparkles" style="color:#D4A843;"></i>
                <span style="position:absolute;top:-4px;right:-6px;width:10px;height:10px;background:#D4A843;border-radius:50%;border:2px solid #72578B;"></span>
            </a>
            <a href="profile" class="notification-btn" style="text-decoration:none;">
                <i class="fas fa-bell"></i>
            </a>
            <a href="profile" style="text-decoration:none;">
                <div class="avatar" style="background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;color:white;font-weight:800;">
                    <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                </div>
            </a>
        </div>
    </div>
    
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

    <?php if ($registrationPayment && $user['status'] === 'active'): ?>
    <div style="background:linear-gradient(135deg,#10b981,#059669);border-radius:12px;padding:1rem;margin-top:0.5rem;color:white;">
        <div class="d-flex align-items-center justify-content-between">
            <div>
                <div style="font-size:0.8rem;opacity:0.85;"><i class="fas fa-check-circle me-1"></i> Registration Paid</div>
                <div style="font-size:1.3rem;font-weight:800;"><?= formatCurrency($registrationPayment['amount']) ?></div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:0.75rem;opacity:0.8;">Paid on</div>
                <div style="font-size:0.85rem;font-weight:600;"><?= date('d M Y', strtotime($registrationPayment['completed_at'])) ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Stats Cards -->
<div class="stat-cards">
    <div class="stat-card">
        <div class="stat-icon purple">
            <i class="fas fa-wallet"></i>
        </div>
        <div class="stat-info">
            <div class="stat-value"><?= formatCurrency($wallet['withdrawable_balance'] ?? 0) ?></div>
            <div class="stat-label">Withdrawable Balance</div>
        </div>
    </div>
    
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

<!-- Daily Mission -->
<?php if ($user['status'] === 'active' && $missionStatus && $missionStatus['has_mission']): ?>
<div class="dash-section px-3" style="margin-top:1rem;">
    <div class="mission-card" id="missionCard" data-completed="<?= $missionStatus['completed'] ? '1' : '0' ?>">
        <div class="mission-header">
            <div class="mission-icon">
                <i class="fas fa-trophy"></i>
            </div>
            <div class="mission-info">
                <h6 class="mission-title" id="missionTitle"><?= sanitize($missionStatus['title']) ?></h6>
                <p class="mission-desc" id="missionDesc"><?= sanitize($missionStatus['description']) ?></p>
            </div>
            <div class="mission-reward">
                <div class="reward-label">Reward</div>
                <div class="reward-amount" id="missionReward"><?= formatCurrency($missionStatus['reward_amount']) ?></div>
            </div>
        </div>

        <?php if ($missionStatus['completed']): ?>
        <div class="mission-completed" id="missionCompleted">
            <div class="completed-icon"><i class="fas fa-check-circle"></i></div>
            <div>
                <strong>Mission Complete!</strong>
                <div style="font-size:0.75rem;color:var(--gray-500);">Tafadhali subiri mission ya kesho</div>
            </div>
            <div class="completed-badge">Done</div>
        </div>
        <?php else: ?>
        <div class="mission-progress" id="missionProgress">
            <div class="progress-header">
                <span>Progress</span>
                <span id="missionProgressText"><?= $missionStatus['progress'] ?? '0/0' ?></span>
            </div>
            <div class="progress-track">
                <div class="progress-fill" id="missionProgressFill" style="width:<?= $missionStatus['percentage'] ?>%"></div>
            </div>
            <div class="progress-footer">
                <span><i class="fas fa-users me-1"></i> <span id="missionPaidCount"><?= $missionStatus['completed_count'] ?? 0 ?></span> paid referrals today</span>
                <span>Target: <?= $missionStatus['requirement_count'] ?? 2 ?></span>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Dashboard Content -->
<div class="dash-content mb-safe">
    
    <!-- Referral Link & Share -->
    <div class="referral-link-card">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <h6 class="mb-0"><i class="fas fa-link me-1 text-primary"></i> Referral Link</h6>
        </div>
        
        <p style="font-size:0.8rem;color:var(--gray-500);margin-bottom:0.75rem;">
            Copy this link and share with friends. You earn money for every person who joins.
        </p>
        
        <div class="referral-link-box mb-3">
            <input type="text" id="referral-link-input" value="<?= $referralLink ?>" readonly>
            <button class="btn-copy" onclick="App.copyToClipboard('<?= $referralLink ?>').then(()=>App.showToast('Link copied!','success'))">
                <i class="fas fa-copy"></i>
            </button>
        </div>
        
        <!-- Commission Earnings per Level -->
        <div style="background:var(--primary-bg);border-radius:var(--radius-md);padding:1rem;margin-bottom:1rem;">
            <h6 style="font-weight:700;font-size:0.8rem;color:var(--gray-600);margin-bottom:0.5rem;">
                <i class="fas fa-coins me-1"></i> Earnings Per Referral
            </h6>
            <div class="d-flex justify-content-between mb-1" style="font-size:0.85rem;">
                <span style="color:var(--gray-600);"><i class="fas fa-user-plus me-1" style="color:var(--primary);"></i> Level 1 (Direct)</span>
                <strong style="color:var(--primary);">TZS <?= number_format((int) app_setting('commission_l1', COMMISSION_L1)) ?></strong>
            </div>
            <div class="d-flex justify-content-between mb-1" style="font-size:0.85rem;">
                <span style="color:var(--gray-600);"><i class="fas fa-user-group me-1" style="color:var(--secondary);"></i> Level 2 (Grand)</span>
                <strong style="color:var(--secondary);">TZS <?= number_format((int) app_setting('commission_l2', COMMISSION_L2)) ?></strong>
            </div>
            <div class="d-flex justify-content-between" style="font-size:0.85rem;">
                <span style="color:var(--gray-600);"><i class="fas fa-people-arrows me-1" style="color:var(--accent);"></i> Level 3 (Great-grand)</span>
                <strong style="color:var(--accent);">TZS <?= number_format((int) app_setting('commission_l3', COMMISSION_L3)) ?></strong>
            </div>
        </div>
        
        <!-- QR Code -->
        <div class="qr-container">
            <canvas id="referralQR"></canvas>
            <p>Scan QR code to join</p>
        </div>
    </div>
    
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
                <div class="stat-number" style="color:var(--accent);"><?= number_format($stats['level_3']) ?></div>
                <div class="stat-label">Level 3 (Great-grand)</div>
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
            <a href="wallet" class="btn btn-outline-primary" style="padding:0.75rem;">
                <i class="fas fa-wallet me-1"></i> Wallet
            </a>
            <a href="withdrawal" class="btn btn-outline-primary" style="padding:0.75rem;">
                <i class="fas fa-money-bill-wave me-1"></i> Withdraw
            </a>
            <a href="referrals" class="btn btn-outline-primary" style="padding:0.75rem;">
                <i class="fas fa-share me-1"></i> Referrals
            </a>
            <a href="transactions" class="btn btn-outline-primary" style="padding:0.75rem;">
                <i class="fas fa-history me-1"></i> History
            </a>
            <?php if ($user['status'] === 'active'): ?>
            <a href="ai-assistant" class="btn btn-outline-primary" style="padding:0.75rem;border-color:#D4A843;color:#0A3622;">
                <i class="fas fa-wand-magic-sparkles me-1" style="color:#D4A843;"></i> AI Assistant
            </a>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Recent Transactions -->
    <div class="dash-section">
        <div class="section-header">
            <h6><i class="fas fa-history me-1"></i> Transactions</h6>
            <a href="transactions">View all</a>
        </div>
        
        <?php if (empty($recentTransactions)): ?>
            <div class="empty-state">
                <i class="fas fa-receipt"></i>
                <h5>No transactions</h5>
                <p>Your earnings will appear here.</p>
            </div>
        <?php else: ?>
            <?php foreach ($recentTransactions as $tx): ?>
                <div class="list-item">
                    <div class="item-icon" style="background:<?= $tx['amount'] > 0 ? '#ecfdf5' : '#fef2f2' ?>; color:<?= $tx['amount'] > 0 ? 'var(--secondary)' : 'var(--danger)' ?>;">
                        <i class="fas fa-<?= $tx['amount'] > 0 ? 'arrow-down' : 'arrow-up' ?>"></i>
                    </div>
                    <div class="item-info">
                        <p class="item-title"><?= sanitize(truncate($tx['description'] ?: ucfirst($tx['type']), 40)) ?></p>
                        <p class="item-subtitle"><?= timeAgo($tx['created_at']) ?></p>
                    </div>
                    <div class="item-amount <?= $tx['amount'] > 0 ? 'credit' : 'debit' ?>">
                        <?= $tx['amount'] > 0 ? '+' : '' ?><?= formatCurrency(abs($tx['amount'])) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Bottom Navigation -->
<nav class="mobile-nav">
    <a href="dashboard" class="nav-item active">
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

<style>
.mission-card {
    background: linear-gradient(135deg, #0A3622, #0d4a2e);
    border-radius: var(--radius-lg);
    padding: 1.25rem;
    color: #fff;
    box-shadow: 0 4px 15px rgba(10, 54, 34, 0.3);
    margin-bottom: 1rem;
    position: relative;
    overflow: hidden;
}
.mission-card::before {
    content: '';
    position: absolute;
    top: -30%;
    right: -20%;
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: rgba(212, 168, 67, 0.1);
}
.mission-header {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
}
.mission-icon {
    width: 44px;
    height: 44px;
    background: rgba(212, 168, 67, 0.2);
    border-radius: var(--radius-md);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: #D4A843;
    flex-shrink: 0;
}
.mission-info {
    flex: 1;
    min-width: 0;
}
.mission-title {
    font-weight: 800;
    font-size: 0.95rem;
    margin: 0 0 0.15rem;
}
.mission-desc {
    font-size: 0.75rem;
    opacity: 0.85;
    margin: 0;
}
.mission-reward {
    text-align: right;
    flex-shrink: 0;
}
.reward-label {
    font-size: 0.6rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    opacity: 0.7;
}
.reward-amount {
    font-size: 1rem;
    font-weight: 800;
    color: #D4A843;
}
.mission-progress {
    margin-top: 1rem;
    padding-top: 0.75rem;
    border-top: 1px solid rgba(255,255,255,0.15);
}
.progress-header {
    display: flex;
    justify-content: space-between;
    font-size: 0.75rem;
    margin-bottom: 0.4rem;
    opacity: 0.9;
}
.progress-track {
    height: 6px;
    background: rgba(255,255,255,0.15);
    border-radius: 3px;
    overflow: hidden;
}
.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #D4A843, #F5D77B);
    border-radius: 3px;
    transition: width 0.5s ease;
}
.progress-footer {
    display: flex;
    justify-content: space-between;
    font-size: 0.7rem;
    margin-top: 0.4rem;
    opacity: 0.7;
}
.mission-completed {
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px solid rgba(255,255,255,0.15);
    display: flex;
    align-items: center;
    gap: 0.75rem;
}
.completed-icon {
    width: 40px;
    height: 40px;
    background: rgba(16, 185, 129, 0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: #10b981;
    flex-shrink: 0;
}
.completed-badge {
    margin-left: auto;
    background: #10b981;
    color: #fff;
    padding: 0.2rem 0.75rem;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 700;
}
.confetti-container {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
    z-index: 9999;
    overflow: hidden;
}
.confetti-piece {
    position: absolute;
    width: 10px;
    height: 10px;
    animation: confettiFall linear forwards;
}
@keyframes confettiFall {
    0% { transform: translateY(-10px) rotate(0deg); opacity: 1; }
    100% { transform: translateY(100vh) rotate(720deg); opacity: 0; }
}
</style>

<script>
if (typeof QRCode !== 'undefined') {
    QRCode.toCanvas(document.getElementById('referralQR'), <?= json_encode($referralLink) ?>, {
        width: 160,
        margin: 1,
        color: { dark: '#72578B', light: '#FFFFFF' }
    });
}

<?php if ($showMissionAnimation): ?>
showMissionConfetti();
<?php endif; ?>

function showMissionConfetti() {
    const container = document.createElement('div');
    container.className = 'confetti-container';
    document.body.appendChild(container);
    const colors = ['#D4A843', '#0A3622', '#F5D77B', '#10b981', '#72578B', '#fff'];

    for (let i = 0; i < 60; i++) {
        const piece = document.createElement('div');
        piece.className = 'confetti-piece';
        piece.style.left = Math.random() * 100 + '%';
        piece.style.top = '-10px';
        piece.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
        piece.style.width = (Math.random() * 8 + 4) + 'px';
        piece.style.height = (Math.random() * 8 + 4) + 'px';
        piece.style.borderRadius = Math.random() > 0.5 ? '50%' : '2px';
        piece.style.animationDuration = (Math.random() * 2 + 1.5) + 's';
        piece.style.animationDelay = (Math.random() * 1.5) + 's';
        container.appendChild(piece);
    }

    setTimeout(() => {
        container.remove();
    }, 4000);

    const toast = document.createElement('div');
    toast.className = 'custom-toast';
    toast.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#0A3622;color:#fff;padding:1.5rem 2rem;border-radius:16px;text-align:center;z-index:10000;box-shadow:0 10px 40px rgba(0,0,0,0.3);min-width:280px;';
    toast.innerHTML = '<div style="font-size:3rem;margin-bottom:0.5rem;">🎉</div>' +
        '<div style="font-size:1.25rem;font-weight:800;color:#D4A843;">Mission Complete!</div>' +
        '<div style="font-size:0.9rem;margin-top:0.25rem;">Hongera! Umepata bonus ya leo!</div>';
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.transition = 'all 0.3s ease';
        toast.style.opacity = '0';
        toast.style.transform = 'translate(-50%, -50%) scale(0.8)';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

<?php if ($missionStatus && !$missionStatus['completed'] && $missionStatus['has_mission']): ?>
function checkMissionProgress() {
    fetch('api/missions.php?action=status', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.has_mission && data.completed && data.just_completed) {
            const card = document.getElementById('missionCard');
            if (card) {
                card.dataset.completed = '1';
                document.getElementById('missionProgress')?.remove();
                const completedDiv = document.createElement('div');
                completedDiv.className = 'mission-completed';
                completedDiv.id = 'missionCompleted';
                completedDiv.innerHTML = '<div class="completed-icon"><i class="fas fa-check-circle"></i></div>' +
                    '<div><strong>Mission Complete!</strong><div style="font-size:0.75rem;color:rgba(255,255,255,0.7);">Hongera! Umepata bonus ya leo!</div></div>' +
                    '<div class="completed-badge">Done</div>';
                card.appendChild(completedDiv);
                showMissionConfetti();
            }
        } else if (data.has_mission && !data.completed) {
            if (document.getElementById('missionProgressText')) {
                document.getElementById('missionProgressText').textContent = data.progress || (data.completed_count + '/' + data.requirement_count);
                document.getElementById('missionProgressFill').style.width = data.percentage + '%';
                document.getElementById('missionPaidCount').textContent = data.completed_count || 0;
            }
        }
    })
    .catch(() => {});
}

setInterval(checkMissionProgress, 30000);
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/public_foot.php'; ?>
