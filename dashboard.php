<?php
/**
 * EarnSphere - User Dashboard
 * Main dashboard showing stats, referral link, and quick actions
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/CommissionEngine.php';
require_once __DIR__ . '/classes/Wallet.php';
require_once __DIR__ . '/includes/helpers.php';

Auth::initSession();
Auth::requireAuth();

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
    "SELECT amount, status, completed_at FROM payments WHERE user_id = ? AND payment_type = 'registration' ORDER BY id DESC LIMIT 1",
    [$_SESSION['user_id']]
);

$csrf = Auth::generateCSRF();
$pageTitle = 'Dashboard';

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
    <div style="background:linear-gradient(135deg,#f59e0b,#ef4444);border-radius:12px;padding:1rem;margin-top:0.5rem;color:white;">
        <div class="d-flex align-items-center mb-2">
            <i class="fas fa-exclamation-triangle me-2" style="font-size:1.2rem;"></i>
            <strong>Your account is not yet activated!</strong>
        </div>
        <p style="font-size:0.85rem;margin:0 0 0.75rem;opacity:0.9;">Complete your <?= formatCurrency(app_setting('registration_fee', REGISTRATION_FEE)) ?> payment to activate your account and start earning commissions.</p>
        <a href="payment?user_id=<?= $user['id'] ?>" style="display:inline-block;background:white;color:#f59e0b;padding:0.5rem 1.5rem;border-radius:8px;font-weight:800;text-decoration:none;">
            <i class="fas fa-credit-card me-1"></i> Pay Now
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
                <strong style="color:var(--primary);">TZS 2,500</strong>
            </div>
            <div class="d-flex justify-content-between mb-1" style="font-size:0.85rem;">
                <span style="color:var(--gray-600);"><i class="fas fa-user-group me-1" style="color:var(--secondary);"></i> Level 2 (Grand)</span>
                <strong style="color:var(--secondary);">TZS 1,500</strong>
            </div>
            <div class="d-flex justify-content-between" style="font-size:0.85rem;">
                <span style="color:var(--gray-600);"><i class="fas fa-people-arrows me-1" style="color:var(--accent);"></i> Level 3 (Great-grand)</span>
                <strong style="color:var(--accent);">TZS 1,000</strong>
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

<script>
if (typeof QRCode !== 'undefined') {
    QRCode.toCanvas(document.getElementById('referralQR'), <?= json_encode($referralLink) ?>, {
        width: 160,
        margin: 1,
        color: { dark: '#72578B', light: '#FFFFFF' }
    });
}
</script>

<?php include __DIR__ . '/includes/public_foot.php'; ?>
