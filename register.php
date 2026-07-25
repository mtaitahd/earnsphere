<?php
/**
 * EarnSphere - Registration Page
 * Referral tracking is handled silently via ?ref= referral links only.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/includes/helpers.php';

Auth::initSession();

if (Auth::isLoggedIn()) {
    header('Location: ' . SITE_URL . '/dashboard');
    exit;
}

// Capture referral code from URL and store in session (silent tracking)
$refParam = trim($_GET['ref'] ?? '');
if (!empty($refParam)) {
    $referrer = Auth::getUserByReferralCode($refParam);
    if ($referrer) {
        $_SESSION['referral_code'] = $refParam;
    }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCSRF($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $errors[] = 'Security: Please try again.';
    } else {
        // Inject stored referral code from session (user never sees this)
        if (!isset($_POST['referral_code']) || empty($_POST['referral_code'])) {
            $_POST['referral_code'] = $_SESSION['referral_code'] ?? '';
        }

        $result = Auth::register($_POST);

        if ($result['success']) {
            // Clear referral from session after use
            unset($_SESSION['referral_code']);

            header('Location: ' . SITE_URL . '/payment?user_id=' . $result['user_id']);
            exit;
        } else {
            $errors = $result['errors'];
        }
    }
}

$csrf = Auth::generateCSRF();
$pageTitle = 'Register';
$pageDesc = 'Join EarnSphere today. Sign up, build your referral network, and start earning commissions instantly.';
$pageKeywords = 'EarnSphere register, join EarnSphere, EarnSphere signup, create account, referral network, earn money online Tanzania, EarnSphere referral, passive income';
include __DIR__ . '/includes/public_head.php';
?>

<div class="auth-page">
    <div class="auth-header">
        <div class="brand-icon">
            <i class="fas fa-user-plus"></i>
        </div>
        <h2>Create Account</h2>
        <p>Join EarnSphere today</p>
    </div>
    
    <div class="auth-body">
        <div class="auth-card">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <ul class="mb-0 mt-1" style="list-style:none;padding-left:0;">
                        <?php foreach ($errors as $err): ?>
                            <li><?= sanitize($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="full_name" name="full_name" 
                           placeholder="Full Name" required
                           value="<?= sanitize($_POST['full_name'] ?? '') ?>">
                    <label for="full_name"><i class="fas fa-user me-1"></i> Full Name</label>
                </div>
                
                <div class="form-floating mb-3">
                    <input type="tel" class="form-control" id="phone" name="phone" 
                           placeholder="Phone Number" required
                           value="<?= sanitize($_POST['phone'] ?? '') ?>"
                           pattern="^(?:\+255|0)(61|71|74|75|76|78)\d{8}$">
                    <label for="phone"><i class="fas fa-phone me-1"></i> Phone Number</label>
                    <small class="text-muted ms-1">Example: 0712345678 or +255712345678</small>
                </div>
                
                <div class="form-floating mb-3">
                    <input type="email" class="form-control" id="email" name="email" 
                           placeholder="Email (Optional)"
                           value="<?= sanitize($_POST['email'] ?? '') ?>">
                    <label for="email"><i class="fas fa-envelope me-1"></i> Email (Optional)</label>
                </div>
                
                <div class="position-relative mb-3">
                    <div class="form-floating">
                        <input type="password" class="form-control" id="password" name="password" 
                               placeholder="Password" required minlength="6" style="padding-right:45px;">
                        <label for="password"><i class="fas fa-lock me-1"></i> Password</label>
                    </div>
                    <button type="button" class="password-toggle" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--gray-400);z-index:5;">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                
                <div class="form-floating mb-3">
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                           placeholder="Confirm Password" required minlength="6">
                    <label for="confirm_password"><i class="fas fa-lock me-1"></i> Confirm Password</label>
                </div>
                
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="terms" required>
                    <label class="form-check-label" for="terms" style="font-size:0.85rem;">
                        I agree to the <a href="#" target="_blank">Terms of Service</a> and <a href="#" target="_blank">Privacy Policy</a>
                    </label>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block btn-lg">
                    <i class="fas fa-rocket me-1"></i> Sign Up
                </button>
                
                <p class="text-center mt-3 mb-0" style="font-size:0.85rem; color: var(--gray-500);">
                    Registration fee: <strong style="color:var(--primary);"><?= formatCurrency(app_setting('registration_fee', REGISTRATION_FEE)) ?></strong>
                </p>
            </form>
        </div>
        
        <div class="auth-footer">
            <p>Already have an account? <a href="login" style="font-weight:700;">Login here</a></p>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.password-toggle').forEach(btn => {
    btn.addEventListener('click', () => {
        const input = btn.parentElement.querySelector('input');
        if (input) {
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            btn.querySelector('i').className = isPassword ? 'fas fa-eye-slash' : 'fas fa-eye';
        }
    });
});

const password = document.getElementById('password');
const confirmPassword = document.getElementById('confirm_password');
if (confirmPassword) {
    confirmPassword.addEventListener('input', () => {
        if (confirmPassword.value && confirmPassword.value !== password.value) {
            confirmPassword.setCustomValidity('Passwords do not match');
        } else {
            confirmPassword.setCustomValidity('');
        }
    });
}
</script>

<?php include __DIR__ . '/includes/public_foot.php'; ?>
