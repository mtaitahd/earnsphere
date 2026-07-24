<?php
/**
 * EarnSphere - Admin: Referral Tree
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/classes/CommissionEngine.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

Auth::initSession();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ' . SITE_URL . '/admin/login');
    exit;
}

$search = trim($_GET['search'] ?? '');
$userId = (int)($_GET['user_id'] ?? 0);

// Find user by search
$searchedUser = null;
$tree = [];

if ($userId) {
    $searchedUser = Database::fetchOne("SELECT * FROM users WHERE id = ? AND role = 'user'", [$userId]);
} elseif ($search) {
    $searchedUser = Database::fetchOne(
        "SELECT * FROM users WHERE role = 'user' AND (full_name LIKE ? OR phone LIKE ? OR referral_code LIKE ?) LIMIT 1",
        ["%{$search}%", "%{$search}%", "%{$search}%"]
    );
}

if ($searchedUser) {
    $tree = CommissionEngine::getReferralTree($searchedUser['id'], 3);
}

$pageTitle = 'Referral Network';
include __DIR__ . '/admin_header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-sitemap me-2" style="color:var(--primary);"></i>Referral Network</h1>
    <p>View user referral structures</p>
</div>

<!-- Search -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-10">
                <input type="text" class="form-control" name="search" placeholder="Search name, phone, or referral code..." value="<?= sanitize($search) ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search me-1"></i> Search</button>
            </div>
        </form>
    </div>
</div>

<?php if ($searchedUser): ?>
<!-- User Info -->
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex align-items-center">
            <div style="width:56px;height:56px;border-radius:50%;background:#f3eef7;color:#72578B;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.25rem;margin-right:1rem;">
                <?= strtoupper(substr($searchedUser['full_name'], 0, 1)) ?>
            </div>
            <div>
                <h5 style="font-weight:800;margin:0;"><?= sanitize($searchedUser['full_name']) ?></h5>
                <p style="margin:0;color:#6b7280;font-size:0.85rem;">
                    <?= formatPhone($searchedUser['phone']) ?> &middot; 
                    <code><?= $searchedUser['referral_code'] ?></code> &middot;
                    <?= statusBadge($searchedUser['status']) ?>
                </p>
            </div>
        </div>
    </div>
</div>

<!-- Referral Tree -->
<div class="card">
    <div class="card-header">
        <h6><i class="fas fa-sitemap me-1"></i> Network (3 Levels)</h6>
    </div>
    <div class="card-body">
        <?php if (empty($tree)): ?>
            <div class="text-center py-4">
                <i class="fas fa-sitemap fa-3x mb-3" style="color:#d1d5db;"></i>
                <p class="text-muted">This user has no referrals yet</p>
            </div>
        <?php else: ?>
            <!-- Render tree -->
            <div style="overflow-x:auto;">
                <?php renderTree($searchedUser, $tree, 0); ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($search): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="fas fa-search fa-3x mb-3" style="color:#d1d5db;"></i>
        <h5 class="text-muted">No user found</h5>
        <p style="color:#9ca3af;">Try a different name, phone, or code</p>
    </div>
</div>
<?php endif; ?>

<?php
/**
 * Render referral tree recursively
 */
function renderTree(array $user, array $children, int $depth): void {
    $indent = $depth * 30;
    $colors = ['#72578B', '#10b981', '#f59e0b', '#3b82f6'];
    $color = $colors[$depth] ?? '#6b7280';
    
    if ($depth > 0): ?>
        <div style="margin-left:<?= $indent ?>px;margin-bottom:8px;padding-left:15px;border-left:3px solid <?= $color ?>;">
            <div class="d-flex align-items-center">
                <div style="width:36px;height:36px;border-radius:50%;background:<?= $color ?>15;color:<?= $color ?>;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:0.85rem;margin-right:0.5rem;">
                    <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                </div>
                <div>
                    <strong style="font-size:0.9rem;"><?= sanitize($user['full_name']) ?></strong>
                    <br><small style="color:#9ca3af;"><?= formatPhone($user['phone']) ?> &middot; L<?= $depth + 1 ?></small>
                </div>
                <?= statusBadge($user['status']) ?>
            </div>
        </div>
    <?php endif;
    
    foreach ($children as $child): ?>
        <div style="margin-left:<?= ($depth + 1) * 30 ?>px;margin-bottom:8px;padding-left:15px;border-left:3px solid <?= $colors[$depth + 1] ?? '#d1d5db' ?>;">
            <div class="d-flex align-items-center">
                <div style="width:32px;height:32px;border-radius:50%;background:<?= $colors[$depth + 1] ?? '#d1d5db' ?>15;color:<?= $colors[$depth + 1] ?? '#6b7280' ?>;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:0.75rem;margin-right:0.5rem;">
                    <?= strtoupper(substr($child['name'], 0, 1)) ?>
                </div>
                <div>
                    <a href="?user_id=<?= $child['id'] ?>" style="font-weight:700;font-size:0.85rem;color:<?= $colors[$depth + 1] ?? '#374151' ?>;">
                        <?= sanitize($child['name']) ?>
                    </a>
                    <br><small style="color:#9ca3af;"><?= formatPhone($child['phone']) ?> &middot; L<?= $depth + 2 ?></small>
                </div>
                <?= statusBadge($child['status']) ?>
            </div>
        </div>
        <?php if (!empty($child['children'])): ?>
            <?php renderTree($child, $child['children'], $depth + 1); ?>
        <?php endif; ?>
    <?php endforeach;
}
?>

<?php include __DIR__ . '/admin_footer.php'; ?>
