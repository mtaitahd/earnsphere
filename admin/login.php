<?php
/**
 * EarnSphere - Admin Login
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

Auth::initSession();

// Redirect if already logged in as admin
if (Auth::isLoggedIn() && Auth::isAdmin()) {
    header('Location: ' . SITE_URL . '/admin/index');
    exit;
}

require_once dirname(__DIR__) . '/includes/security_headers.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCSRF($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'Security: Please try again.';
    } else {
        $identifier = trim($_POST['identifier'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($identifier) || empty($password)) {
            $error = 'Please fill all fields.';
        } else {
            $result = Auth::login($identifier, $password);
            
            if ($result['success']) {
                if ($result['user']['role'] === 'admin') {
                    header('Location: ' . SITE_URL . '/admin/index');
                    exit;
                } else {
                    Auth::logout();
                    $error = 'You cannot access the admin panel.';
                }
            } else {
                $error = $result['errors'][0] ?? 'Login error.';
            }
        }
    }
}

$csrf = Auth::generateCSRF();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | EarnSphere</title>
    <meta name="csrf-token" content="<?= $csrf ?>">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <div class="icon"><i class="fas fa-gem"></i></div>
            <h4 style="font-weight:800;margin:0;">Admin Panel</h4>
            <p style="color:#6b7280;font-size:0.9rem;">EarnSphere Management</p>
        </div>
        <div class="login-body">
            <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-center py-2" role="alert" style="border-radius:12px;font-size:0.9rem;">
                    <i class="fas fa-exclamation-circle me-2"></i> <?= $error ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                <div class="form-floating mb-3">
                    <input type="text" class="form-control" id="identifier" name="identifier" placeholder="Username or Email" required autofocus>
                    <label for="identifier"><i class="fas fa-user me-1"></i> Username or Email</label>
                </div>
                <div class="form-floating mb-4">
                    <input type="password" class="form-control" id="password" name="password" placeholder="Password" required>
                    <label for="password"><i class="fas fa-lock me-1"></i> Password</label>
                </div>
                <button type="submit" class="btn btn-admin">
                    <i class="fas fa-sign-in-alt me-1"></i> Login
                </button>
            </form>
        </div>
    </div>
</body>
</html>
