<?php
/**
 * EarnSphere - Admin Profile
 * Change password and view profile info
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

Auth::initSession();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ' . SITE_URL . '/admin/login');
    exit;
}

$success = '';
$error = '';

$admin = Database::fetchOne(
    "SELECT id, full_name, email, phone, role, status, created_at FROM users WHERE id = ?",
    [$_SESSION['user_id']]
);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'update_email') {
        $newEmail = strtolower(trim($_POST['new_email'] ?? ''));
        if (empty($newEmail)) {
            $error = 'Email is required.';
        } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } elseif ($newEmail === ($admin['email'] ?? '')) {
            $error = 'New email is the same as current email.';
        } else {
            $existing = Database::fetchOne("SELECT id FROM users WHERE email = ? AND id != ?", [$newEmail, $_SESSION['user_id']]);
            if ($existing) {
                $error = 'This email is already in use by another account.';
            } else {
                Database::update('users', ['email' => $newEmail], 'id = ?', [$_SESSION['user_id']]);
                Auth::logActivity($_SESSION['user_id'], 'email_changed', 'Admin updated their email to ' . $newEmail);
                $admin['email'] = $newEmail;
                $success = 'Email updated successfully!';
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'update_password') {
    if (!Auth::validateCSRF($_POST[CSRF_TOKEN_NAME] ?? '')) {
        $error = 'Security: Please try again.';
    } else {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $error = 'All password fields are required.';
        } elseif (strlen($newPassword) < 6) {
            $error = 'New password must be at least 6 characters.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New passwords do not match.';
        } elseif ($newPassword === $currentPassword) {
            $error = 'New password must be different from current password.';
        } else {
            $user = Database::fetchOne("SELECT password FROM users WHERE id = ?", [$_SESSION['user_id']]);
            if (!$user || !password_verify($currentPassword, $user['password'])) {
                $error = 'Current password is incorrect.';
            } else {
                Database::update('users', [
                    'password' => password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]),
                ], 'id = ?', [$_SESSION['user_id']]);

                Auth::logActivity($_SESSION['user_id'], 'password_changed', 'Admin changed their password');
                $success = 'Password updated successfully!';
            }
        }
    }
}

$csrf = Auth::generateCSRF();
$pageTitle = 'Profile';
include __DIR__ . '/admin_header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-user-cog me-2" style="color:var(--primary);"></i>Profile</h1>
    <p>Manage your account</p>
</div>

<?php if ($success): ?>
    <div class="alert alert-success d-flex align-items-center" role="alert">
        <i class="fas fa-check-circle me-2"></i> <?= $success ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i> <?= $error ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Profile Info -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-body text-center py-4">
                <div style="width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:white;display:inline-flex;align-items:center;justify-content:center;font-size:2rem;font-weight:800;margin-bottom:1rem;">
                    <?= strtoupper(substr($admin['full_name'] ?? 'A', 0, 1)) ?>
                </div>
                <h5 style="font-weight:800;"><?= sanitize($admin['full_name']) ?></h5>
                <span class="badge bg-primary mb-2">Administrator</span>
                <div class="text-start mt-3" style="font-size:0.85rem;">
                    <p class="mb-2"><i class="fas fa-envelope me-2 text-muted"></i><?= sanitize($admin['email'] ?: 'Not set') ?></p>
                    <p class="mb-2"><i class="fas fa-phone me-2 text-muted"></i><?= sanitize($admin['phone'] ?: 'Not set') ?></p>
                    <p class="mb-0"><i class="fas fa-calendar me-2 text-muted"></i>Joined <?= date('d M Y', strtotime($admin['created_at'])) ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Change Password -->
    <div class="col-lg-8">
        <!-- Update Email -->
        <div class="card mb-4">
            <div class="card-header">
                <h6><i class="fas fa-envelope me-1"></i> Update Email</h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                    <input type="hidden" name="form_type" value="update_email">

                    <div class="mb-3">
                        <label class="form-label" style="font-weight:700;font-size:0.85rem;">Current Email</label>
                        <input type="text" class="form-control" value="<?= sanitize($admin['email'] ?: 'Not set') ?>" disabled>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" style="font-weight:700;font-size:0.85rem;">New Email</label>
                        <input type="email" class="form-control" name="new_email" required placeholder="admin@example.com" value="<?= sanitize($admin['email'] ?? '') ?>">
                    </div>

                    <div class="text-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> Update Email
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h6><i class="fas fa-lock me-1"></i> Change Password</h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                    <input type="hidden" name="form_type" value="update_password">

                    <div class="mb-3">
                        <label class="form-label" style="font-weight:700;font-size:0.85rem;">Current Password</label>
                        <input type="password" class="form-control" name="current_password" required autocomplete="current-password">
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" style="font-weight:700;font-size:0.85rem;">New Password</label>
                            <input type="password" class="form-control" name="new_password" required minlength="6" autocomplete="new-password">
                            <small class="text-muted">Min 6 characters</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" style="font-weight:700;font-size:0.85rem;">Confirm New Password</label>
                            <input type="password" class="form-control" name="confirm_password" required minlength="6" autocomplete="new-password">
                        </div>
                    </div>

                    <div class="text-end mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> Update Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/admin_footer.php'; ?>
