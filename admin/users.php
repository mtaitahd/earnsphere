<?php
/**
 * EarnSphere - Admin: User Management
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

Auth::initSession();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ' . SITE_URL . '/admin/login');
    exit;
}

$search = trim($_GET['search'] ?? '');
$status = $_GET['status'] ?? '';
$page = getCurrentPage();
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build query
$where = "role = 'user'";
$params = [];

if ($search) {
    $where .= " AND (full_name LIKE ? OR phone LIKE ? OR email LIKE ? OR referral_code LIKE ?)";
    $searchParam = "%{$search}%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
}

if ($status) {
    $where .= " AND status = ?";
    $params[] = $status;
}

$total = Database::count('users', $where, $params);

$users = Database::fetchAll(
    "SELECT u.*, 
            (SELECT COUNT(*) FROM users WHERE referred_by = u.id) as direct_referrals,
            (SELECT COALESCE(SUM(amount),0) FROM commissions WHERE earner_id = u.id AND status != 'cancelled') as total_earned
     FROM users u 
     WHERE {$where} 
     ORDER BY u.created_at DESC 
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $userId = (int)($_POST['user_id'] ?? 0);
    $action = $_POST['action'];
    
    if ($action === 'update_status') {
        $newStatus = $_POST['new_status'] ?? 'active';
        Database::update('users', ['status' => $newStatus], 'id = ?', [$userId]);
        setFlash('success', 'User status updated to ' . ucfirst($newStatus));
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
    
    if ($action === 'delete') {
        $user = Database::fetchOne("SELECT id, full_name FROM users WHERE id = ? AND role = 'user'", [$userId]);
        if ($user) {
            Database::beginTransaction();
            Database::delete('commissions', "earner_id = ? OR source_user_id = ?", [$userId, $userId]);
            Database::delete('payments', "user_id = ?", [$userId]);
            Database::delete('withdrawals', "user_id = ?", [$userId]);
            Database::delete('wallet', "user_id = ?", [$userId]);
            Database::delete('password_resets', "user_id = ?", [$userId]);
            Database::delete('users', "id = ?", [$userId]);
            Database::commit();
            setFlash('success', 'User ' . sanitize($user['full_name']) . ' deleted permanently');
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

$pageTitle = 'Users';
include __DIR__ . '/admin_header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1><i class="fas fa-users me-2" style="color:var(--primary);"></i>Users</h1>
        <p>Manage all system users (<?= number_format($total) ?>)</p>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-6">
                <input type="text" class="form-control" name="search" placeholder="Search name, phone, email, or code..." value="<?= sanitize($search) ?>">
            </div>
            <div class="col-md-4">
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="suspended" <?= $status === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                    <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search me-1"></i> Search</button>
            </div>
        </form>
    </div>
</div>

<!-- Users Table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Phone</th>
                        <th>Code</th>
                        <th>Referrals</th>
                        <th>Earnings</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $i => $u): ?>
                    <tr>
                        <td><?= $offset + $i + 1 ?></td>
                        <td>
                            <strong><?= sanitize($u['full_name']) ?></strong>
                            <?php if ($u['email']): ?>
                                <br><small style="color:#9ca3af;"><?= sanitize($u['email']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= formatPhone($u['phone']) ?></td>
                        <td><code style="font-size:0.75rem;"><?= $u['referral_code'] ?></code></td>
                        <td><strong><?= number_format($u['direct_referrals']) ?></strong></td>
                        <td><strong style="color:#10b981;"><?= formatCurrency($u['total_earned']) ?></strong></td>
                        <td><?= statusBadge($u['status']) ?></td>
                        <td><small><?= date('d M Y', strtotime($u['created_at'])) ?></small></td>
                        <td>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                                <ul class="dropdown-menu">
                                    <li><h6 class="dropdown-header">Change Status</h6></li>
                                    <?php foreach (['active' => 'Activate', 'pending' => 'Pending', 'suspended' => 'Suspend', 'paid' => 'Paid'] as $val => $label): ?>
                                    <?php if ($u['status'] !== $val): ?>
                                    <li>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <input type="hidden" name="new_status" value="<?= $val ?>">
                                            <button type="submit" class="dropdown-item <?= $val === 'suspended' ? 'text-danger' : ($val === 'active' ? 'text-success' : '') ?>">
                                                <i class="fas fa-<?= $val === 'active' ? 'check' : ($val === 'suspended' ? 'ban' : ($val === 'paid' ? 'money-bill' : 'clock')) ?> me-1"></i> <?= $label ?>
                                            </button>
                                        </form>
                                    </li>
                                    <?php endif; ?>
                                    <?php endforeach; ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this user permanently? All related data (payments, withdrawals, commissions) will also be removed.')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" class="dropdown-item text-danger">
                                                <i class="fas fa-trash me-1"></i> Delete
                                            </button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4">
                            <i class="fas fa-users fa-2x mb-2" style="color:#d1d5db;"></i>
                            <p class="text-muted mb-0">No users found</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Pagination -->
<div class="mt-3">
    <?= paginate($total, $page, $perPage, SITE_URL . '/admin/users?' . http_build_query(['search' => $search, 'status' => $status, 'page' => ''])) ?>
</div>

<?php include __DIR__ . '/admin_footer.php'; ?>
