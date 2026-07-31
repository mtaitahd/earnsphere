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
$stats = CommissionEngine::getReferralStats($_SESSION['user_id']);
$referralLink = getReferralLink($user['referral_code']);

// Weekly Referral Contest data
$contest = Contest::getFeaturedContest();
$contestStandings = [];
$contestUserRank = null;
$contestWinners = [];
if ($contest) {
    $contestStandings = Contest::getStandings($contest['id'], 5);
    $contestUserRank = Contest::getUserRank($contest['id'], (int) $_SESSION['user_id']);
    $contestWinners = Contest::getWinners($contest['id']);
}

// Live proof of payment data
$liveProof = Database::fetchAll(
    "SELECT w.id, w.amount, w.processed_at, w.created_at, u.full_name
     FROM withdrawals w
     INNER JOIN users u ON u.id = w.user_id
     WHERE w.status = 'completed'
     ORDER BY COALESCE(w.processed_at, w.created_at) DESC
     LIMIT 5"
);

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
            <a href="javascript:void(0)" class="notification-btn" style="text-decoration:none;position:relative;" onclick="openAnnouncements()" title="Announcements" id="announcementBell">
                <i class="fas fa-bell"></i>
                <span id="annBadge" class="ann-badge"></span>
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
    
    <!-- Weekly Referral Contest -->
    <?php if ($contest): ?>
    <div class="dash-section px-3" style="margin-top:1rem;">
        <div class="contest-card">
            <div class="contest-header">
                <div class="contest-trophy"><i class="fas fa-trophy"></i></div>
                <div class="contest-info">
                    <div class="contest-badge">WEEKLY CONTEST</div>
                    <h6 class="contest-title"><?= sanitize($contest['title']) ?></h6>
                    <p class="contest-desc"><?= sanitize($contest['description']) ?></p>
                </div>
                <div class="contest-ends">
                    <div class="ends-label">Ends</div>
                    <div class="ends-date"><?= date('d M', strtotime($contest['end_date'])) ?></div>
                </div>
            </div>

            <div class="prize-row">
                <div class="prize-item gold">
                    <span class="prize-icon">🥇</span>
                    <div class="prize-val"><?= formatCurrency($contest['prize1']) ?></div>
                    <div class="prize-label">1st</div>
                </div>
                <div class="prize-item silver">
                    <span class="prize-icon">🥈</span>
                    <div class="prize-val"><?= formatCurrency($contest['prize2']) ?></div>
                    <div class="prize-label">2nd</div>
                </div>
                <div class="prize-item bronze">
                    <span class="prize-icon">🥉</span>
                    <div class="prize-val"><?= formatCurrency($contest['prize3']) ?></div>
                    <div class="prize-label">3rd</div>
                </div>
            </div>

            <div class="lb-title"><i class="fas fa-fire me-1"></i> Wanaoongoza</div>
            <div class="lb-list">
                <?php if (empty($contestStandings)): ?>
                    <div class="lb-empty">Hakuna wateja wapya bado. Kuwa wa kwanza!</div>
                <?php else: ?>
                    <?php foreach ($contestStandings as $s): ?>
                    <div class="lb-row <?= $s['user_id'] == $_SESSION['user_id'] ? 'me-row' : '' ?>">
                        <span class="lb-pos"><?= $s['position'] ?></span>
                        <span class="lb-name"><?= sanitize($s['name']) ?></span>
                        <span class="lb-count"><?= $s['count'] ?> referrals</span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="contest-cta">
                <?php if ($contestUserRank && $contestUserRank['count'] > 0): ?>
                    <div class="user-rank"><i class="fas fa-medal me-1"></i> Wewe uko <strong>#<?= $contestUserRank['rank'] ?></strong> — <?= $contestUserRank['count'] ?> referral<?= $contestUserRank['count'] > 1 ? 's' : '' ?></div>
                <?php else: ?>
                    <div class="user-rank muted"><i class="fas fa-rocket me-1"></i> Leta wateja <?= (int) $contest['min_referrals'] ?>+ waliolipa na ushinde zawadi!</div>
                <?php endif; ?>
                <a href="referrals" class="btn btn-light btn-sm w-100" style="background:#fff;color:#72578B;font-weight:800;border-radius:10px;">
                    <i class="fas fa-share me-1"></i> Share Link na Kushindana
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

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
            <img src="<?= generateQRCodeUrl($referralLink, 180) ?>" alt="QR Code" width="180" height="180">
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
            <a href="ai-assistant" class="btn btn-outline-primary" style="padding:0.75rem;border-color:#D4A843;color:#72578B;">
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

<!-- Announcement Modal -->
<div id="annModal" class="ann-modal" style="display:none;">
    <div class="ann-modal-overlay" onclick="closeAnnModal()"></div>
    <div class="ann-modal-content">
        <div class="ann-modal-header">
            <div class="ann-modal-header-icon"><i class="fas fa-bullhorn"></i></div>
            <h5>Announcements</h5>
            <button class="ann-modal-close" onclick="closeAnnModal()"><i class="fas fa-times"></i></button>
        </div>
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
.mission-card {
    background: linear-gradient(135deg, #72578B, #5a3f72);
    border-radius: var(--radius-lg);
    padding: 1.25rem;
    color: #fff;
    box-shadow: 0 4px 15px rgba(114, 87, 139, 0.3);
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
/* --- Weekly Contest Card --- */
.contest-card {
    background: linear-gradient(135deg, #72578B, #4a2f63);
    border-radius: var(--radius-lg);
    padding: 1.25rem;
    color: #fff;
    box-shadow: 0 4px 15px rgba(114, 87, 139, 0.3);
    position: relative;
    overflow: hidden;
}
.contest-card::before {
    content: '';
    position: absolute;
    top: -30%;
    right: -20%;
    width: 140px;
    height: 140px;
    border-radius: 50%;
    background: rgba(212, 168, 67, 0.12);
}
.contest-header {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
}
.contest-trophy {
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
.contest-info {
    flex: 1;
    min-width: 0;
}
.contest-badge {
    font-size: 0.6rem;
    letter-spacing: 1.5px;
    font-weight: 800;
    color: #D4A843;
    margin-bottom: 0.15rem;
}
.contest-title {
    font-weight: 800;
    font-size: 0.95rem;
    margin: 0 0 0.15rem;
}
.contest-desc {
    font-size: 0.75rem;
    opacity: 0.85;
    margin: 0;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.contest-ends {
    text-align: right;
    flex-shrink: 0;
}
.ends-label {
    font-size: 0.6rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    opacity: 0.7;
}
.ends-date {
    font-size: 1rem;
    font-weight: 800;
    color: #D4A843;
}
.prize-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.5rem;
    margin: 1rem 0;
}
.prize-item {
    background: rgba(255,255,255,0.08);
    border-radius: var(--radius-md);
    padding: 0.6rem 0.4rem;
    text-align: center;
}
.prize-item.gold { border-top: 3px solid #D4A843; }
.prize-item.silver { border-top: 3px solid #c0c4cc; }
.prize-item.bronze { border-top: 3px solid #cd7f32; }
.prize-icon { font-size: 1.1rem; }
.prize-val { font-size: 0.85rem; font-weight: 800; margin-top: 0.2rem; }
.prize-label { font-size: 0.65rem; opacity: 0.8; text-transform: uppercase; letter-spacing: 1px; }
.lb-title {
    font-size: 0.7rem;
    font-weight: 800;
    letter-spacing: 1px;
    text-transform: uppercase;
    color: #D4A843;
    margin-bottom: 0.5rem;
}
.lb-list { display: flex; flex-direction: column; gap: 0.35rem; }
.lb-row {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    background: rgba(255,255,255,0.07);
    border-radius: 10px;
    padding: 0.45rem 0.6rem;
    font-size: 0.8rem;
}
.lb-row.me-row {
    background: rgba(212, 168, 67, 0.25);
    border: 1px solid rgba(212, 168, 67, 0.6);
}
.lb-pos {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: rgba(212, 168, 67, 0.25);
    color: #D4A843;
    font-weight: 800;
    font-size: 0.7rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.lb-name { flex: 1; font-weight: 600; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lb-count { font-size: 0.7rem; opacity: 0.85; flex-shrink: 0; }
.lb-empty {
    background: rgba(255,255,255,0.07);
    border-radius: 10px;
    padding: 0.6rem;
    font-size: 0.75rem;
    opacity: 0.85;
    text-align: center;
}
.contest-cta {
    margin-top: 0.9rem;
    padding-top: 0.75rem;
    border-top: 1px solid rgba(255,255,255,0.15);
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
}
.user-rank { font-size: 0.8rem; text-align: center; }
.user-rank.muted { opacity: 0.85; }
.user-rank strong { color: #D4A843; }
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
<?php if ($showMissionAnimation): ?>
showMissionConfetti();
<?php endif; ?>

function showMissionConfetti() {
    const container = document.createElement('div');
    container.className = 'confetti-container';
    document.body.appendChild(container);
    const colors = ['#D4A843', '#5a3f72', '#F5D77B', '#10b981', '#72578B', '#fff'];

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
    toast.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#72578B;color:#fff;padding:1.5rem 2rem;border-radius:16px;text-align:center;z-index:10000;box-shadow:0 10px 40px rgba(0,0,0,0.3);min-width:280px;';
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
                    '<div class="ann-item-date">' + a.created_at + '</div>' +
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
        '<div style="font-size:0.75rem;color:var(--gray-400);margin-bottom:0.75rem;">' + a.created_at + '</div>' +
        '<p>' + escapeHtml(a.content) + '</p>' +
    '</div>';
    if (!a.viewed) {
        fetch('api/announcements.php?action=mark_read&id=' + id, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
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


<?php include __DIR__ . '/includes/public_foot.php'; ?>
