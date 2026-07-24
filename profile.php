<?php
/**
 * EarnSphere - Profile Page
 * User profile management
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Wallet.php';
require_once __DIR__ . '/includes/helpers.php';

Auth::initSession();
Auth::requireAuth();

$user = Auth::getUser();
$wallet = Wallet::getWallet($_SESSION['user_id']);

$success = '';
$error = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCSRF($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'Security: Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'update_profile') {
            $fullName = trim($_POST['full_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            
            if (strlen($fullName) < 3) {
                $error = 'Name is too short.';
            } else {
                // Check email uniqueness
                if (!empty($email)) {
                    $existing = Database::fetchOne(
                        "SELECT id FROM users WHERE email = ? AND id != ?",
                        [$email, $_SESSION['user_id']]
                    );
                    if ($existing) {
                        $error = 'Email already in use by another person.';
                    }
                }
                
                if (empty($error)) {
                    Database::update('users', [
                        'full_name' => $fullName,
                        'email'     => $email ?: null,
                    ], 'id = ?', [$_SESSION['user_id']]);
                    
                    $_SESSION['user_name'] = $fullName;
                    $success = 'Profile updated!';
                    $user = Auth::getUser();
                }
            }
        } elseif ($action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            if (!password_verify($currentPassword, $user_full['password'] ?? '')) {
                // Need full user data with password
                $user_full = Database::fetchOne("SELECT password FROM users WHERE id = ?", [$_SESSION['user_id']]);
                if (!password_verify($currentPassword, $user_full['password'])) {
                    $error = 'Current password is incorrect.';
                }
            }
            
            if (empty($error)) {
                if (strlen($newPassword) < 6) {
                    $error = 'New password is too short.';
                } elseif ($newPassword !== $confirmPassword) {
                    $error = 'Passwords do not match.';
                } else {
                    Database::update('users', [
                        'password' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]),
                    ], 'id = ?', [$_SESSION['user_id']]);
                    $success = 'Password changed!';
                }
            }
        }
    }
}

$csrf = Auth::generateCSRF();
$pageTitle = 'Profile';
include __DIR__ . '/includes/public_head.php';
?>

<!-- Profile Header -->
<div class="profile-header">
    <a href="dashboard.php" style="color:white;text-decoration:none;position:absolute;left:1rem;top:1rem;">
        <i class="fas fa-arrow-left"></i>
    </a>
    <div class="profile-avatar">
        <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
    </div>
    <h5 style="font-weight:800;"><?= sanitize($user['full_name']) ?></h5>
    <p style="opacity:0.8;font-size:0.85rem;margin:0;"><?= formatPhone($user['phone']) ?></p>
    <?= statusBadge($user['status']) ?>
</div>

<div class="dash-content mb-safe" style="margin-top:-1rem;">
    
    <?php if ($success): ?>
        <div class="alert alert-success d-flex align-items-center">
            <i class="fas fa-check-circle me-2"></i> <?= $success ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-danger d-flex align-items-center">
            <i class="fas fa-exclamation-circle me-2"></i> <?= $error ?>
        </div>
    <?php endif; ?>
    
    <!-- Quick Stats -->
    <div class="stat-grid mb-3">
        <div class="stat-box">
            <div class="stat-number"><?= formatCurrency($wallet['withdrawable_balance'] ?? 0) ?></div>
            <div class="stat-label">Withdrawable</div>
        </div>
        <div class="stat-box">
            <div class="stat-number" style="color:var(--secondary);"><?= formatCurrency($wallet['total_earned']) ?></div>
            <div class="stat-label">Total Earnings</div>
        </div>
    </div>
    
    <!-- Profile Info Card -->
    <div class="dash-section">
        <h6 class="section-title"><i class="fas fa-id-card me-1"></i> Personal Information</h6>
        
        <div class="auth-card">
            <form method="POST" action="">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="update_profile">
                
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="full_name" name="full_name" 
                           value="<?= sanitize($user['full_name']) ?>" required>
                    <label for="full_name">Full Name</label>
                </div>
                
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" value="<?= formatPhone($user['phone']) ?>" readonly disabled>
                    <label>Phone Number</label>
                </div>
                
                <div class="form-floating mb-3">
                    <input type="email" class="form-control" id="email" name="email" 
                           value="<?= sanitize($user['email'] ?? '') ?>">
                    <label for="email">Email</label>
                </div>
                
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" value="<?= $user['referral_code'] ?>" readonly disabled>
                    <label>Referral Code</label>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-save me-1"></i> Save Changes
                </button>
            </form>
        </div>
    </div>
    
    <!-- Change Password -->
    <div class="dash-section">
        <h6 class="section-title"><i class="fas fa-lock me-1"></i> Change Password</h6>
        
        <div class="auth-card">
            <form method="POST" action="">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="change_password">
                
                <div class="form-floating mb-3">
                    <input type="password" class="form-control" name="current_password" 
                           placeholder="Current Password" required>
                    <label>Current Password</label>
                </div>
                
                <div class="form-floating mb-3">
                    <input type="password" class="form-control" name="new_password" 
                           placeholder="New Password" required minlength="6">
                    <label>New Password (at least 6 characters)</label>
                </div>
                
                <div class="form-floating mb-3">
                    <input type="password" class="form-control" name="confirm_password" 
                           placeholder="Confirm Password" required>
                    <label>Confirm New Password</label>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-key me-1"></i> Change Password
                </button>
            </form>
        </div>
    </div>
    
    <!-- Menu Items -->
    <div class="dash-section">
        <h6 class="section-title"><i class="fas fa-cog me-1"></i> Options</h6>
        
        <a href="transactions.php" class="profile-menu-item">
            <i class="fas fa-receipt"></i>
            <div class="menu-info">
                <div class="menu-title">Transaction History</div>
                <div class="menu-desc">View all your transactions</div>
            </div>
            <i class="fas fa-chevron-right menu-arrow"></i>
        </a>
        
        <a href="wallet.php" class="profile-menu-item">
            <i class="fas fa-wallet"></i>
            <div class="menu-info">
                <div class="menu-title">Wallet</div>
                <div class="menu-desc">Manage your wallet</div>
            </div>
            <i class="fas fa-chevron-right menu-arrow"></i>
        </a>
        
        <a href="referrals.php" class="profile-menu-item">
            <i class="fas fa-users"></i>
            <div class="menu-info">
                <div class="menu-title">Referrals</div>
                <div class="menu-desc">View your network</div>
            </div>
            <i class="fas fa-chevron-right menu-arrow"></i>
        </a>
        
        <a href="withdrawal.php" class="profile-menu-item">
            <i class="fas fa-money-bill-wave"></i>
            <div class="menu-info">
                <div class="menu-title">Request Withdrawal</div>
                <div class="menu-desc">Withdraw money from your wallet</div>
            </div>
            <i class="fas fa-chevron-right menu-arrow"></i>
        </a>
        
        <a href="logout.php" class="profile-menu-item" onclick="return confirm('Leave your account?')">
            <i class="fas fa-sign-out-alt" style="background:#fef2f2;color:var(--danger);"></i>
            <div class="menu-info">
                <div class="menu-title" style="color:var(--danger);">Logout</div>
                <div class="menu-desc">Sign out of your account</div>
            </div>
            <i class="fas fa-chevron-right menu-arrow"></i>
        </a>
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
    <a href="wallet.php" class="nav-item center-action">
        <i class="fas fa-wallet"></i>
        <span>Wallet</span>
    </a>
    <a href="transactions.php" class="nav-item">
        <i class="fas fa-receipt"></i>
        <span>History</span>
    </a>
    <a href="profile.php" class="nav-item active">
        <i class="fas fa-user"></i>
        <span>Profile</span>
    </a>
</nav>

<?php include __DIR__ . '/includes/public_foot.php'; ?>
