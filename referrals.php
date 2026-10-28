<?php
/**
 * EarnSphere - Referrals Page
 * Shows referral tree and referred users
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/CommissionEngine.php';
require_once __DIR__ . '/includes/helpers.php';

Auth::initSession();
Auth::requireAuth();

header('Cache-Control: no-store, private');
$user = Auth::getUser();
if (!$user || $user['role']!=='user' || $user['status']!=='active') {
    $pageTitle='Referrals';
    include __DIR__.'/includes/public_head.php';
    ?>
    <main class="container py-5"><div class="card mx-auto" style="max-width:520px"><div class="card-body text-center p-4">
        <i class="fas fa-lock mb-3" style="font-size:2rem;color:var(--primary)" aria-hidden="true"></i>
        <h1 class="h4">Referrals</h1>
        <?php if (($user['status']??'')==='pending'): ?>
            <p>Lipia akaunti yako kwanza ili kuona referral link yako na kuanza kuwaalika marafiki.</p>
            <a class="btn btn-primary" href="<?= sanitize(SITE_URL) ?>/payment">Pay now / Lipia akaunti</a>
        <?php else: ?>
            <p>Akaunti yako haijaruhusiwa kutumia referrals. Wasiliana na support.</p>
            <a class="btn btn-primary" href="<?= sanitize(SITE_URL) ?>/#help">Contact support</a>
        <?php endif; ?>
        <a class="btn btn-outline-primary d-block mt-3" href="<?= sanitize(SITE_URL) ?>/dashboard">Back to dashboard</a>
    </div></div></main>
    <?php include __DIR__.'/includes/public_foot.php';exit;
}

$stats = CommissionEngine::getReferralStats($_SESSION['user_id']);
$referralLink = getReferralLink($user['referral_code']);
$level = (int)($_GET['level'] ?? 1);

// Only the two supported referral levels are part of the current network.
$level = in_array($level, [1, 2], true) ? $level : 1;
$users = Database::fetchAll(
    "SELECT u.id, u.full_name, u.phone, u.status, u.created_at,
            CASE WHEN u.referred_by = ? THEN 1 ELSE 2 END AS ref_level
     FROM users u WHERE (u.referred_by = ? OR u.referred_by IN (SELECT id FROM users WHERE referred_by = ?))
     AND u.status = 'active' ORDER BY u.created_at DESC",
    [$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]
);

$pageTitle = 'Referrals';
include __DIR__ . '/includes/public_head.php';
?>

<!-- Header -->
<div class="dash-header">
    <div class="top-bar">
        <a href="dashboard" style="color:white;text-decoration:none;">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h5 class="mb-0" style="font-weight:800;">Referrals</h5>
        <div></div>
    </div>
</div>

<div class="dash-content mb-safe">

    <!-- Referral Link & Share -->
    <div class="referral-link-card" id="referral-share">
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

        <nav class="account-share-links" aria-label="Share referral link">
            <a href="https://wa.me/?text=<?= rawurlencode($referralLink) ?>" target="_blank" rel="noopener noreferrer"><i class="fab fa-whatsapp" aria-hidden="true"></i> WhatsApp</a>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= rawurlencode($referralLink) ?>" target="_blank" rel="noopener noreferrer"><i class="fab fa-facebook" aria-hidden="true"></i> Facebook</a>
            <a href="https://t.me/share/url?url=<?= rawurlencode($referralLink) ?>" target="_blank" rel="noopener noreferrer"><i class="fab fa-telegram" aria-hidden="true"></i> Telegram</a>
            <a href="https://twitter.com/intent/tweet?url=<?= rawurlencode($referralLink) ?>" target="_blank" rel="noopener noreferrer"><i class="fab fa-x-twitter" aria-hidden="true"></i> X</a>
        </nav>
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

        </div>

        <!-- QR Code -->
        <div class="qr-container">
            <img src="<?= generateQRCodeUrl($referralLink, 180) ?>" alt="QR Code" width="180" height="180">
            <p>Scan QR code to join</p>
        </div>
    </div>

    <!-- Stats -->
    <div class="stat-grid mb-3">
        <div class="stat-box">
            <div class="stat-number"><?= number_format($stats['level_1']) ?></div>
            <div class="stat-label">Level 1</div>
        </div>
        <div class="stat-box">
            <div class="stat-number" style="color:var(--secondary);"><?= number_format($stats['level_2']) ?></div>
            <div class="stat-label">Level 2</div>
        </div>

        <div class="stat-box">
            <div class="stat-number" style="color:var(--info);"><?= number_format($stats['total_referrals']) ?></div>
            <div class="stat-label">Total</div>
        </div>
    </div>

    <!-- Level Filter Tabs -->
    <div class="d-flex gap-2 mb-3" style="overflow-x:auto;">
        <a href="?level=1" class="btn <?= $level == 1 ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm">
            Level 1 (<?= $stats['level_1'] ?>)
        </a>
        <a href="?level=2" class="btn <?= $level == 2 ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm">
            Level 2 (<?= $stats['level_2'] ?>)
        </a>
    </div>

    <!-- Users List -->
    <div class="dash-section">
        <h6 class="section-title">Connected People</h6>

        <?php
        $filteredUsers = array_filter($users, fn($u) => $u['ref_level'] == $level);

        if (empty($filteredUsers)): ?>
            <div class="empty-state">
                <i class="fas fa-user-friends"></i>
                <h5>No people at Level <?= $level ?></h5>
                <p>Share your link to get new people.</p>
            </div>
        <?php else: ?>
            <?php foreach ($filteredUsers as $u): ?>
                <div class="user-item">
                    <div class="user-avatar">
                        <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                    </div>
                    <div class="user-info">
                        <p class="user-name"><?= sanitize($u['full_name']) ?></p>
                        <p class="user-meta">
                            <?= formatPhone($u['phone']) ?>
                            <span class="level-badge">L<?= $u['ref_level'] ?></span>
                        </p>
                    </div>
                    <div>
                        <?= statusBadge($u['status']) ?>
                        <div style="font-size:0.7rem;color:var(--gray-400);text-align:right;margin-top:4px;">
                            <?= timeAgo($u['created_at']) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Bottom Navigation -->
<nav class="mobile-nav">
    <a href="dashboard" class="nav-item">
        <i class="fas fa-home"></i>
        <span>Home</span>
    </a>
    <a href="referrals" class="nav-item active">
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

<?php include __DIR__ . '/includes/public_foot.php'; ?>
