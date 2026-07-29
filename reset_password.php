<?php
/**
 * EarnSphere - Reset Password
 * Step 3: User sets new password (after OTP verification)
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/includes/helpers.php';

Auth::initSession();

$userId = (int)($_SESSION['reset_user_id'] ?? 0);
$verified = $_SESSION['reset_verified'] ?? false;

if (!$userId || !$verified) {
    header('Location: ' . SITE_URL . '/forgot_password');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCSRF($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'Security: Please try again.';
        ErrorLogger::log('login', 'Password reset failed: invalid CSRF token', [], $userId, 'warning', 'reset_password.php');
    } else {
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        if (strlen($password) < 6) {
            $error = 'Password is too short (minimum 6 characters).';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            try {
                Database::beginTransaction();

                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                Database::update('users', ['password' => $hash], 'id = ?', [$userId]);

                Database::commit();

                // Clear reset session
                unset($_SESSION['reset_user_id'], $_SESSION['reset_verified'], $_SESSION['reset_email']);

                header('Location: ' . SITE_URL . '/login');
                exit;

            } catch (Exception $e) {
                Database::rollback();
                error_log("Reset password error: " . $e->getMessage());
                ErrorLogger::logException($e, 'login', $userId, 'reset_password.php');
                $error = 'System error. Please try again.';
            }
        }
    }
}

$csrf = Auth::generateCSRF();
$pageTitle = 'Set New Password';
$pageDesc = 'Create a new password for your EarnSphere account after OTP verification.';
$pageKeywords = 'EarnSphere reset password, new password, password reset, account recovery';
include __DIR__ . '/includes/public_head.php';
?>

<div class="auth-page">
    <div class="auth-header">
        <div class="brand-icon">
            <i class="fas fa-lock"></i>
        </div>
        <h2>New Password</h2>
        <p>Set a new password for your account</p>
    </div>
    
    <div class="auth-body">
        <div class="auth-card">
            <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-center" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i> <?= sanitize($error) ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                
                <div class="position-relative mb-3">
                    <div class="form-floating">
                        <input type="password" class="form-control" id="password" name="password" 
                               placeholder="New Password" required minlength="6" style="padding-right:45px;">
                        <label for="password"><i class="fas fa-lock me-1"></i> New Password</label>
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
                
                <button type="submit" class="btn btn-primary btn-block btn-lg">
                    <i class="fas fa-save me-1"></i> Save New Password
                </button>
            </form>
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
