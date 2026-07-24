<?php
/**
 * EarnSphere - Wallet Page
 * Shows wallet balance, earnings breakdown, and quick actions
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Wallet.php';
require_once __DIR__ . '/classes/CommissionEngine.php';
require_once __DIR__ . '/includes/helpers.php';

Auth::initSession();
Auth::requireAuth();

$wallet = Wallet::getWallet($_SESSION['user_id']);
$stats = CommissionEngine::getReferralStats($_SESSION['user_id']);

// Earnings breakdown
$earnings = Database::fetchOne(
    "SELECT 
        COALESCE(SUM(CASE WHEN level = 1 THEN amount ELSE 0 END), 0) as l1_total,
        COALESCE(SUM(CASE WHEN level = 2 THEN amount ELSE 0 END), 0) as l2_total,
        COALESCE(SUM(CASE WHEN level = 3 THEN amount ELSE 0 END), 0) as l3_total
     FROM commissions WHERE earner_id = ? AND status != 'cancelled'",
    [$_SESSION['user_id']]
);

$pageTitle = 'Wallet';
include __DIR__ . '/includes/public_head.php';
?>

<div class="dash-header">
    <div class="top-bar">
        <a href="dashboard.php" style="color:white;text-decoration:none;">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h5 class="mb-0" style="font-weight:800;">Wallet</h5>
        <div></div>
    </div>
</div>

<div class="dash-content mb-safe">
    
    <!-- Balance Card -->
    <div class="balance-card" style="margin-top:-1rem;">
        <div class="balance-label">Commission Earnings</div>
        <div class="balance-amount"><?= formatCurrency($wallet['withdrawable_balance'] ?? 0) ?></div>
        <div class="balance-sublabel">Referral earnings only · Withdrawable</div>
        <div class="balance-actions">
            <a href="withdrawal.php" class="btn btn-light btn-sm" <?= ($wallet['withdrawable_balance'] ?? 0) < app_setting('min_withdrawal', MIN_WITHDRAWAL) ? 'disabled' : '' ?>>
                <i class="fas fa-money-bill-wave me-1"></i> Withdraw
            </a>
            <a href="transactions.php" class="btn btn-outline-light btn-sm">
                <i class="fas fa-history me-1"></i> History
            </a>
        </div>
    </div>
    
    <!-- Earnings Summary -->
    <div class="dash-section">
        <h6 class="section-title"><i class="fas fa-chart-pie me-1"></i> Earnings by Level</h6>
        
        <div class="commission-card">
            <div class="commission-level">L1</div>
            <div class="commission-info">
                <h6>Level 1 - Direct</h6>
                <div class="amount"><?= formatCurrency($earnings['l1_total'] ?? 0) ?></div>
                <small><?= number_format($stats['level_1']) ?> referrals</small>
            </div>
        </div>
        
        <div class="commission-card">
            <div class="commission-level" style="background:var(--secondary);">L2</div>
            <div class="commission-info">
                <h6>Level 2 - Grand</h6>
                <div class="amount" style="color:var(--secondary);"><?= formatCurrency($earnings['l2_total'] ?? 0) ?></div>
                <small><?= number_format($stats['level_2']) ?> referrals</small>
            </div>
        </div>
        
        <div class="commission-card">
            <div class="commission-level" style="background:var(--accent);">L3</div>
            <div class="commission-info">
                <h6>Level 3 - Great-grand</h6>
                <div class="amount" style="color:var(--accent);"><?= formatCurrency($earnings['l3_total'] ?? 0) ?></div>
                <small><?= number_format($stats['level_3']) ?> referrals</small>
            </div>
        </div>
    </div>
    
    <!-- Wallet Summary -->
    <div class="dash-section">
        <h6 class="section-title"><i class="fas fa-info-circle me-1"></i> Summary</h6>
        
        <div class="list-item">
            <div class="item-icon" style="background:#ecfdf5;color:var(--secondary);">
                <i class="fas fa-plus-circle"></i>
            </div>
            <div class="item-info">
                <p class="item-title">Commission Earnings</p>
                <small class="text-muted">From referrals · withdrawable</small>
            </div>
            <div class="item-amount credit"><?= formatCurrency($wallet['withdrawable_balance'] ?? 0) ?></div>
        </div>
        
        <div class="list-item">
            <div class="item-icon" style="background:#eff6ff;color:var(--info);">
                <i class="fas fa-coins"></i>
            </div>
            <div class="item-info">
                <p class="item-title">Total Earned</p>
                <small class="text-muted">All credits combined</small>
            </div>
            <div class="item-amount" style="color:var(--info);"><?= formatCurrency($wallet['total_earned']) ?></div>
        </div>
        
        <div class="list-item">
            <div class="item-icon" style="background:#fef2f2;color:var(--danger);">
                <i class="fas fa-minus-circle"></i>
            </div>
            <div class="item-info">
                <p class="item-title">Total Withdrawn</p>
            </div>
            <div class="item-amount debit"><?= formatCurrency($wallet['total_withdrawn']) ?></div>
        </div>
        
        <div class="list-item">
            <div class="item-icon" style="background:#fffbeb;color:var(--accent);">
                <i class="fas fa-clock"></i>
            </div>
            <div class="item-info">
                <p class="item-title">Pending Confirmation</p>
            </div>
            <div class="item-amount" style="color:var(--accent);"><?= formatCurrency($wallet['pending_amount']) ?></div>
        </div>
    </div>
</div>

<!-- Bottom Navigation -->
<nav class="mobile-nav">
    <a href="dashboard.php" class="nav-item">
        <i class="fas fa-home"></i>
        <span>Home</span>
    </a>
    <a href="referrals.php" class="nav-item">
        <i class="fas fa-users"></i>
        <span>Referrals</span>
    </a>
    <a href="wallet.php" class="nav-item center-action active">
        <i class="fas fa-wallet"></i>
        <span>Wallet</span>
    </a>
    <a href="transactions.php" class="nav-item">
        <i class="fas fa-receipt"></i>
        <span>History</span>
    </a>
    <a href="profile.php" class="nav-item">
        <i class="fas fa-user"></i>
        <span>Profile</span>
    </a>
</nav>

<?php include __DIR__ . '/includes/public_foot.php'; ?>
