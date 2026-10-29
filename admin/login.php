<?php
/**
 * EarnSphere - Staff Login
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/classes/StaffAccess.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

Auth::initSession();
$isPrivateEntry=isset($privateEntry) && is_array($privateEntry);
$loginAction=$isPrivateEntry ? rtrim(SITE_URL,'/').'/staff-entry/'.$matches[1] : SITE_URL.'/admin/login';
require_once dirname(__DIR__).'/classes/AdminLoginOTP.php';
header('Cache-Control: no-store, private');

// Redirect if already logged in as admin
if (Auth::isLoggedIn() && StaffAccess::isStaff()) {
    header('Location: ' . StaffAccess::landingUrl());
    exit;
}

require_once dirname(__DIR__) . '/includes/security_headers.php';

$error = '';
$loginHelp = false;
$challenge=AdminLoginOTP::pending();
if ($challenge && (!$isPrivateEntry || (int)$challenge['user_id']!==(int)$privateEntry['user']['id'] || !hash_equals($privateEntry['config']['token_hash'],(string)($challenge['entry_hash']??'')))) {
    unset($_SESSION['admin_login_otp']);
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCSRF($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'Security: Please try again.';
        ErrorLogger::log('login', 'Staff login failed: invalid CSRF token', [], null, 'warning', 'admin/login.php');
    } elseif ($isPrivateEntry && ($_POST['action']??'')==='verify_otp') {
        $result=Auth::verifyAdminOTP(is_string($_POST['otp']??null)?$_POST['otp']:'');
        if ($result['success']) {header('Location: '.StaffAccess::landingUrl());exit;}
        $error=$result['errors'][0];
    } elseif ($isPrivateEntry && ($_POST['action']??'')==='resend_otp') {
        $result=AdminLoginOTP::resend();
        if (!empty($result['otp_required'])) $_SESSION['admin_login_otp']['entry_hash']=$privateEntry['config']['token_hash'];
        $error=$result['errors'][0]??'';
    } elseif (($_POST['action']??'')==='cancel_otp') {
        unset($_SESSION['admin_login_otp']);
    } elseif ($isPrivateEntry) {
        $result=AdminPIN::login($privateEntry,is_string($_POST['pin']??null)?$_POST['pin']:'');
        if (!empty($result['otp_required'])) {header('Location: '.$loginAction);exit;}
        $error=$result['errors'][0]??'Login failed.';
    } else {
        $identifier = trim($_POST['identifier'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($identifier) || empty($password)) {
            $error = 'Please fill all fields.';
            ErrorLogger::log('login', 'Staff login failed: missing identifier or password', [
                'identifier_present' => $identifier !== '',
                'password_present'   => $password !== '',
            ], null, 'notice', 'admin/login.php');
        } else {
            $result = Auth::login($identifier, $password);
            
            if (!empty($result['otp_required'])) {header('Location: '.$loginAction);exit;}
            if ($result['success']) {
                if (StaffAccess::isStaff()) {
                    header('Location: ' . StaffAccess::landingUrl());
                    exit;
                } else {
                    ErrorLogger::log('login', 'Staff login failed: non-admin user attempted admin panel', [
                        'identifier' => $identifier,
                    ], (int) $result['user']['id'], 'warning', 'admin/login.php');
                    Auth::logout();
                    $error = 'You cannot access the admin panel.';
                }
            } else {
                $loginHelp = !empty($result['help_required']);
                $error = $result['errors'][0] ?? 'Login error.';
            }
        }
    }
}

$pendingOTP=AdminLoginOTP::pending();
$csrf = Auth::generateCSRF();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Login | EarnSphere</title>
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <style>
        body { font-family: 'Nunito', sans-serif; background: linear-gradient(135deg, #5a4570 0%, #72578B 50%, #3d2660 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-card { max-width: 420px; width: 100%; background: white; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.15); overflow: hidden; }
        .login-header { padding: 2rem 2rem 1rem; text-align: center; }
        .login-header .icon { width: 72px; height: 72px; background: linear-gradient(135deg, #72578B, #5a4570); border-radius: 20px; display: inline-flex; align-items: center; justify-content: center; color: white; font-size: 2rem; margin-bottom: 1rem; }
        .login-body { padding: 1rem 2rem 2rem; }
        .form-floating .form-control { border-radius: 12px; border: 2px solid #e5e7eb; background: #f9fafb; }
        .form-floating .form-control:focus { border-color: #72578B; box-shadow: 0 0 0 3px rgba(114,87,139,0.1); }
        .btn-admin { background: linear-gradient(135deg, #72578B, #5a4570); border: none; color: white; border-radius: 12px; padding: 0.8rem; font-weight: 800; font-size: 1rem; width: 100%; }
        .btn-admin:hover { background: linear-gradient(135deg, #5a4570, #4a3560); transform: translateY(-1px); color: white; }
        *{box-sizing:border-box} body{margin:0;padding:20px}.form-control{display:block;width:100%;padding:14px;margin:8px 0 16px;border:1px solid #ddd;border-radius:10px}.form-floating label{font-size:14px;color:#554464}.mb-3,.mb-4{margin-bottom:16px}.mt-3{margin-top:16px}.btn{padding:10px 14px;cursor:pointer}.alert-danger{color:#a51f2a;background:#fff0f0;padding:12px;margin-bottom:14px}.btn-outline-secondary{background:white;border:1px solid #aaa;border-radius:8px}.btn-link{background:none;border:0;color:#72578B}
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <div class="icon"><i class="fas fa-gem"></i></div>
            <h4 style="font-weight:800;margin:0;"><?= $isPrivateEntry?'Administrator Login':'Agent Login' ?></h4>
            <p style="color:#6b7280;font-size:0.9rem;">EarnSphere Management</p>
        </div>
        <div class="login-body">
            <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-center py-2" role="alert" style="border-radius:12px;font-size:0.9rem;">
                    <i class="fas fa-exclamation-circle me-2"></i> <?= sanitize($error) ?>
                </div>
            <?php endif; ?>
            <?php if ($loginHelp): ?><a class="btn btn-outline-primary d-block mb-3" href="<?= sanitize(SITE_URL) ?>/#help"><i class="fas fa-life-ring me-1"></i> Help — Contact administrator</a><?php endif; ?>
            
            <?php if ($pendingOTP): ?>
            <p>Enter the 6-digit code sent to your administrator account email. The code expires in <?= AdminLoginOTP::EXPIRY_SECONDS ?> seconds.</p>
            <form method="POST" action="<?= sanitize($loginAction) ?>">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= sanitize($csrf) ?>">
                <input type="hidden" name="action" value="verify_otp">
                <label for="otp">Login code</label>
                <input class="form-control mb-3" id="otp" name="otp" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus>
                <button class="btn btn-admin" type="submit">Verify and login</button>
            </form>
            <form method="POST" action="<?= sanitize($loginAction) ?>" class="mt-3">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= sanitize($csrf) ?>">
                <button type="submit" name="action" value="resend_otp" class="btn btn-outline-secondary">Resend code</button>
                <button type="submit" name="action" value="cancel_otp" class="btn btn-link">Back to login</button>
            </form>
            <?php else: ?>
            <form method="POST" action="<?= sanitize($loginAction) ?>">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                <?php if ($isPrivateEntry): ?>
                <div class="form-floating mb-4">
                    <input type="password" class="form-control" id="pin" name="pin" inputmode="numeric" pattern="[0-9]{12}" minlength="12" maxlength="12" autocomplete="current-password" required autofocus>
                    <label for="pin">Administrator PIN</label>
                </div>
                <?php else: ?>
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="identifier" name="identifier" placeholder="Username or Email" required autofocus>
                    <label for="identifier"><i class="fas fa-user me-1"></i> Username or Email</label>
                </div>
                <div class="form-floating mb-4">
                    <input type="password" class="form-control" id="password" name="password" placeholder="Password" required>
                    <label for="password"><i class="fas fa-lock me-1"></i> Password</label>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-admin">
                    <i class="fas fa-sign-in-alt me-1"></i> Login
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
