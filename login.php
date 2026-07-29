<?php
/**
 * EarnSphere - Login Page
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/includes/helpers.php';

Auth::initSession();

// Redirect if already logged in
if (Auth::isLoggedIn()) {
                    header('Location: ' . SITE_URL . '/dashboard');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCSRF($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'Security: Please try again.';
        ErrorLogger::log('login', 'Login failed: invalid CSRF token', [], null, 'warning', 'login.php');
    } else {
        $identifier = trim($_POST['identifier'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($identifier) || empty($password)) {
            $error = 'Please fill in all fields.';
            ErrorLogger::log('login', 'Login failed: missing identifier or password', [
                'identifier_present' => $identifier !== '',
                'password_present'   => $password !== '',
            ], null, 'notice', 'login.php');
        } else {
            $result = Auth::login($identifier, $password);
            
            if ($result['success']) {
                $user = $result['user'];
                if ($user['role'] === 'admin') {
                    header('Location: ' . SITE_URL . '/admin/index');
                } else {
    header('Location: ' . SITE_URL . '/dashboard');
                }
                exit;
            } else {
                $error = $result['errors'][0] ?? 'Login failed.';
            }
        }
    }
}

$csrf = Auth::generateCSRF();
$pageTitle = 'Login';
$pageDesc = 'Login to your EarnSphere account. Manage your referral network, track commissions, and grow your income.';
$pageKeywords = 'EarnSphere login, login EarnSphere, EarnSphere sign in, account login, referral dashboard';
include __DIR__ . '/includes/public_head.php';
?>

<div class="auth-page">
    <div class="auth-header">
        <div class="brand-icon">
            <i class="fas fa-gem"></i>
        </div>
        <h2>Welcome Back</h2>
        <p>Login to your EarnSphere account</p>
    </div>
    
    <div class="auth-body">
        <div class="auth-card">
            <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-center" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?= sanitize($error) ?>
                </div>
            <?php endif; ?>
            
            <?php displayFlash(); ?>
            
            <form method="POST" action="">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="identifier" name="identifier" 
                           placeholder="Phone or Email" required autofocus
                           value="<?= sanitize($_POST['identifier'] ?? '') ?>">
                    <label for="identifier"><i class="fas fa-phone me-1"></i> Phone or Email</label>
                </div>
                
                <div class="position-relative mb-3">
                    <div class="form-floating">
                        <input type="password" class="form-control" id="password" name="password" 
                               placeholder="Password" required style="padding-right: 45px;">
                        <label for="password"><i class="fas fa-lock me-1"></i> Password</label>
                    </div>
                    <button type="button" class="password-toggle" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--gray-400);z-index:5;">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                
                <div class="d-flex justify-content-end mb-3">
                    <a href="forgot_password" style="font-size:0.85rem;font-weight:700;color:var(--primary);text-decoration:none;">
                        <i class="fas fa-key me-1"></i> Forgot Password?
                    </a>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block btn-lg">
                    <i class="fas fa-sign-in-alt me-1"></i> Sign In
                </button>
            </form>
        </div>
        
        <div class="auth-footer">
            <p>Don't have an account? <a href="register" style="font-weight:700;">Join now</a></p>
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
</script>

<?php include __DIR__ . '/includes/public_foot.php'; ?>
