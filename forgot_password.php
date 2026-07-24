<?php
/**
 * EarnSphere - Forgot Password
 * Step 1: User enters email -> OTP sent
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/OTP.php';
require_once __DIR__ . '/includes/helpers.php';

Auth::initSession();

if (Auth::isLoggedIn()) {
    header('Location: ' . SITE_URL . '/dashboard.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCSRF($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'Security: Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $user = Database::fetchOne(
                "SELECT id, email, full_name FROM users WHERE email = ? AND role = 'user'",
                [$email]
            );

            if (!$user) {
                // Anti-email enumeration: show success even if not found
                $success = 'If the email exists in our system, a reset code has been sent.';
            } else {
                if (OTP::sendUserOTP($user['id'], 'reset')) {
                    $_SESSION['reset_user_id'] = $user['id'];
                    $_SESSION['reset_email'] = $user['email'];
                    header('Location: ' . SITE_URL . '/otp_verify.php');
                    exit;
                } else {
                    $error = 'Failed to send code. Please try again.';
                }
            }
        }
    }
}

$csrf = Auth::generateCSRF();
$pageTitle = 'Forgot Password';
$pageDesc = 'Reset your EarnSphere account password. Enter your email to receive a verification code.';
$pageKeywords = 'EarnSphere forgot password, reset password, recover account, password reset';
include __DIR__ . '/includes/public_head.php';
?>

<div class="auth-page">
    <div class="auth-header">
        <div class="brand-icon">
            <i class="fas fa-key"></i>
        </div>
        <h2>Forgot Password</h2>
        <p>Enter your email and we'll send you a code</p>
    </div>
    
    <div class="auth-body">
        <div class="auth-card">
            <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-center" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i> <?= sanitize($error) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success d-flex align-items-center" role="alert">
                    <i class="fas fa-check-circle me-2"></i> <?= $success ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                
                <div class="form-floating mb-3">
                    <input type="email" class="form-control" id="email" name="email" 
                           placeholder="Email" required
                           value="<?= sanitize($_POST['email'] ?? '') ?>">
                    <label for="email"><i class="fas fa-envelope me-1"></i> Email</label>
                </div>
                
                <button type="submit" class="btn btn-primary btn-block btn-lg">
                    <i class="fas fa-paper-plane me-1"></i> Send Code
                </button>
            </form>
        </div>
        
        <div class="auth-footer">
            <p>Remember your password? <a href="login.php" style="font-weight:700;">Login here</a></p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/public_foot.php'; ?>
