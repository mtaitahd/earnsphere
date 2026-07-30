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

$user = Auth::getUser();
$stats = CommissionEngine::getReferralStats($_SESSION['user_id']);
$referralLink = getReferralLink($user['referral_code']);
$level = (int)($_GET['level'] ?? 1);

// Get referred users by level
$users = Database::fetchAll(
    "SELECT u.id, u.full_name, u.phone, u.status, u.created_at,
            CASE 
                WHEN u.referred_by = ? THEN 1
                WHEN u.referred_by IN (SELECT id FROM users WHERE referred_by = ?) THEN 2
                WHEN u.referred_by IN (SELECT id FROM users WHERE referred_by IN (SELECT id FROM users WHERE referred_by = ?)) THEN 3
            END as ref_level
     FROM users u
     WHERE (u.referred_by = ? 
            OR u.referred_by IN (SELECT id FROM users WHERE referred_by = ?)
            OR u.referred_by IN (SELECT id FROM users WHERE referred_by IN (SELECT id FROM users WHERE referred_by = ?)))
     AND u.status = 'active'
     ORDER BY u.created_at DESC",
    [$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'],
     $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]
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
    
    <!-- Referral Link Card -->
    <div class="referral-link-card" style="margin-top:-1rem;">
        <h6><i class="fas fa-share-alt me-1"></i> Share Your Link</h6>
        
        <div class="referral-code-display">
            <span class="code"><?= $user['referral_code'] ?></span>
            <button class="btn btn-sm btn-primary" onclick="App.copyToClipboard('<?= $referralLink ?>').then(()=>App.showToast('Copied!','success'))">
                <i class="fas fa-copy me-1"></i> Copy
            </button>
        </div>
        
        <div class="referral-link-box mb-3">
            <input type="text" value="<?= $referralLink ?>" readonly>
            <button class="btn-copy" onclick="App.copyToClipboard('<?= $referralLink ?>').then(()=>App.showToast('Link copied!','success'))">
                <i class="fas fa-copy"></i>
            </button>
        </div>
        
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
            <div class="stat-number" style="color:var(--accent);"><?= number_format($stats['level_3']) ?></div>
            <div class="stat-label">Level 3</div>
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
        <a href="?level=3" class="btn <?= $level == 3 ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm">
            Level 3 (<?= $stats['level_3'] ?>)
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
