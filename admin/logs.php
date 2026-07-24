<?php
/**
 * EarnSphere - Admin: Activity Logs
 * Matching Mtaita Tech design system
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

Auth::initSession();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ' . SITE_URL . '/admin/login.php');
    exit;
}

// Handle DELETE (single or bulk)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Auth::validateCSRF($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'Security token invalid');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
    
    $action = $_POST['action'];
    
    if ($action === 'delete_single') {
        $logId = (int)($_POST['log_id'] ?? 0);
        if ($logId > 0) {
            Database::delete('activity_logs', 'id = ?', [$logId]);
            setFlash('success', 'Log deleted');
        }
    }
    
    if ($action === 'delete_selected') {
        $ids = $_POST['log_ids'] ?? [];
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            Database::query("DELETE FROM activity_logs WHERE id IN ({$placeholders})", $ids);
            setFlash('success', count($ids) . ' logs deleted');
        }
    }
    
    if ($action === 'clear_all') {
        Database::query("TRUNCATE TABLE activity_logs", []);
        setFlash('success', 'All logs cleared');
    }
    
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// Filters
$search      = trim($_GET['search'] ?? '');
$filterUser  = (int)($_GET['user_id'] ?? 0);
$filterAction = $_GET['action_filter'] ?? '';
$dateFrom    = $_GET['date_from'] ?? '';
$dateTo      = $_GET['date_to'] ?? '';
$page        = getCurrentPage();
$perPage     = 25;
$offset      = ($page - 1) * $perPage;

// Build query
$where = "1=1";
$params = [];

if ($search) {
    $where .= " AND (al.description LIKE ? OR u.full_name LIKE ? OR u.phone LIKE ? OR al.action LIKE ?)";
    $searchParam = "%{$search}%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam]);
}

if ($filterUser > 0) {
    $where .= " AND al.user_id = ?";
    $params[] = $filterUser;
}

if ($filterAction) {
    $where .= " AND al.action = ?";
    $params[] = $filterAction;
}

if ($dateFrom) {
    $where .= " AND DATE(al.created_at) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $where .= " AND DATE(al.created_at) <= ?";
    $params[] = $dateTo;
}

// Count total
$total = Database::fetchOne(
    "SELECT COUNT(*) as cnt FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id WHERE {$where}",
    $params
)['cnt'] ?? 0;

$logs = Database::fetchAll(
    "SELECT al.*, u.full_name, u.phone, u.role
     FROM activity_logs al
     LEFT JOIN users u ON al.user_id = u.id
     WHERE {$where}
     ORDER BY al.created_at DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$actions = Database::fetchAll("SELECT DISTINCT action FROM activity_logs ORDER BY action ASC");

$logUsers = Database::fetchAll(
    "SELECT u.id, u.full_name, u.phone 
     FROM users u 
     INNER JOIN activity_logs al ON u.id = al.user_id 
     GROUP BY u.id 
     ORDER BY u.full_name ASC"
);

$csrf = Auth::generateCSRF();

$actionConfig = [
    'login'              => ['icon' => 'sign-in-alt',   'color' => '#1CC88A', 'bg' => 'rgba(28,200,138,0.12)'],
    'logout'             => ['icon' => 'sign-out-alt',  'color' => '#858796', 'bg' => 'rgba(133,135,150,0.12)'],
    'registration'       => ['icon' => 'user-plus',    'color' => 'rgb(114,87,139)', 'bg' => 'rgba(114,87,139,0.12)'],
    'payment_initiated'  => ['icon' => 'credit-card',  'color' => '#F6C23E', 'bg' => 'rgba(246,194,62,0.12)'],
    'account_activated'  => ['icon' => 'check-circle', 'color' => '#1CC88A', 'bg' => 'rgba(28,200,138,0.12)'],
    'commission_earned'  => ['icon' => 'coins',        'color' => '#F6C23E', 'bg' => 'rgba(246,194,62,0.12)'],
    'withdrawal_requested'=> ['icon' => 'money-bill-wave', 'color' => '#4E73DF', 'bg' => 'rgba(78,115,223,0.12)'],
    'payout_sent'        => ['icon' => 'paper-plane',  'color' => 'rgb(114,87,139)', 'bg' => 'rgba(114,87,139,0.12)'],
    'payout_completed'   => ['icon' => 'check-double', 'color' => '#1CC88A', 'bg' => 'rgba(28,200,138,0.12)'],
    'payout_failed'      => ['icon' => 'times-circle', 'color' => '#E74A3B', 'bg' => 'rgba(231,74,59,0.12)'],
    'withdrawal_processed'=> ['icon' => 'cog',         'color' => '#858796', 'bg' => 'rgba(133,135,150,0.12)'],
];

function getActionStyle(string $action): array {
    global $actionConfig;
    return $actionConfig[$action] ?? ['icon' => 'circle', 'color' => '#858796', 'bg' => 'rgba(133,135,150,0.12)'];
}

$pageTitle = 'Activity Logs';
include __DIR__ . '/admin_header.php';
?>

<!-- Page Header -->
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0" style="font-weight:700;">
        <i class="fas fa-list-alt me-2" style="color:var(--accent);"></i>Activity Logs
    </h1>
    <div class="d-flex gap-2">
        <form method="POST" onsubmit="return confirm('Clear ALL logs? This cannot be undone.');">
            <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="clear_all">
            <button type="submit" class="btn btn-danger btn-sm">
                <i class="fas fa-trash me-1"></i> Clear All
            </button>
        </form>
    </div>
</div>

<!-- Filters Card -->
<div class="card mb-4">
    <div class="card-body" style="padding:1rem 1.25rem;">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label style="font-size:0.75rem;font-weight:600;color:var(--text-muted);">Search</label>
                <input type="text" name="search" class="form-control form-control-sm" 
                       placeholder="Name, phone, description..." value="<?= sanitize($search) ?>">
            </div>
            <div class="col-md-2">
                <label style="font-size:0.75rem;font-weight:600;color:var(--text-muted);">User</label>
                <select name="user_id" class="form-select form-select-sm">
                    <option value="">All Users</option>
                    <?php foreach ($logUsers as $lu): ?>
                        <option value="<?= $lu['id'] ?>" <?= $filterUser == $lu['id'] ? 'selected' : '' ?>>
                            <?= sanitize($lu['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label style="font-size:0.75rem;font-weight:600;color:var(--text-muted);">Action</label>
                <select name="action_filter" class="form-select form-select-sm">
                    <option value="">All Actions</option>
                    <?php foreach ($actions as $act): ?>
                        <option value="<?= sanitize($act['action']) ?>" <?= $filterAction === $act['action'] ? 'selected' : '' ?>>
                            <?= ucfirst(str_replace('_', ' ', $act['action'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label style="font-size:0.75rem;font-weight:600;color:var(--text-muted);">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= sanitize($dateFrom) ?>">
            </div>
            <div class="col-md-2">
                <label style="font-size:0.75rem;font-weight:600;color:var(--text-muted);">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= sanitize($dateTo) ?>">
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="fas fa-filter"></i>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Stats Row -->
<div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="margin-bottom:0;">
            <div class="stat-icon" style="background:rgba(114,87,139,0.12);color:rgb(114,87,139);">
                <i class="fas fa-list"></i>
            </div>
            <div class="stat-info">
                <h3><?= number_format($total) ?></h3>
                <p>Total Logs</p>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="margin-bottom:0;">
            <div class="stat-icon" style="background:rgba(28,200,138,0.12);color:#1CC88A;">
                <i class="fas fa-sign-in-alt"></i>
            </div>
            <div class="stat-info">
                <h3><?= Database::fetchOne("SELECT COUNT(*) as cnt FROM activity_logs WHERE action = 'login' AND DATE(created_at) = CURDATE()")['cnt'] ?? 0 ?></h3>
                <p>Logins Today</p>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="margin-bottom:0;">
            <div class="stat-icon" style="background:rgba(246,194,62,0.12);color:#F6C23E;">
                <i class="fas fa-user-plus"></i>
            </div>
            <div class="stat-info">
                <h3><?= Database::fetchOne("SELECT COUNT(*) as cnt FROM activity_logs WHERE action = 'registration' AND DATE(created_at) = CURDATE()")['cnt'] ?? 0 ?></h3>
                <p>Registrations Today</p>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="margin-bottom:0;">
            <div class="stat-icon" style="background:rgba(78,115,223,0.12);color:#4E73DF;">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="stat-info">
                <h3><?= Database::fetchOne("SELECT COUNT(*) as cnt FROM activity_logs WHERE action = 'withdrawal_requested' AND DATE(created_at) = CURDATE()")['cnt'] ?? 0 ?></h3>
                <p>Withdrawals Today</p>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Delete Form -->
<form method="POST" id="bulkDeleteForm">
    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="delete_selected">
    
    <?php if (!empty($logs)): ?>
    <div class="card mb-3" style="display:none;" id="bulkActions">
        <div class="card-body py-2 d-flex align-items-center">
            <input type="checkbox" id="selectAll" class="form-check-input me-2">
            <label for="selectAll" class="me-3" style="font-size:0.85rem;font-weight:700;">
                Select All (<span id="selectedCount">0</span> selected)
            </label>
            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete selected logs?');">
                <i class="fas fa-trash me-1"></i> Delete Selected
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Logs Table -->
    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($logs)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-inbox" style="font-size:3rem;color:var(--text-muted);opacity:0.4;"></i>
                    <p class="mt-3" style="color:var(--text-muted);">No activity logs found</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th style="width:40px;"><input type="checkbox" class="form-check-input" id="selectAllHeader"></th>
                                <th style="width:180px;">User</th>
                                <th style="width:140px;">Action</th>
                                <th>Description</th>
                                <th style="width:120px;">IP Address</th>
                                <th style="width:150px;">Time</th>
                                <th style="width:50px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <?php $style = getActionStyle($log['action']); ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="log_ids[]" value="<?= $log['id'] ?>" 
                                               class="form-check-input log-checkbox">
                                    </td>
                                    <td>
                                        <div style="font-weight:700;">
                                            <?= sanitize($log['full_name'] ?? 'Unknown') ?>
                                        </div>
                                        <div style="font-size:0.75rem;color:var(--text-muted);">
                                            <?= sanitize($log['phone'] ?? '') ?>
                                            <?php if (($log['role'] ?? '') === 'admin'): ?>
                                                <span class="badge badge-primary" style="font-size:0.6rem;">ADMIN</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.25rem 0.6rem;border-radius:var(--radius);font-size:0.75rem;font-weight:700;background:<?= $style['bg'] ?>;color:<?= $style['color'] ?>;">
                                            <i class="fas fa-<?= $style['icon'] ?>" style="font-size:0.7rem;"></i>
                                            <?= ucfirst(str_replace('_', ' ', $log['action'])) ?>
                                        </span>
                                    </td>
                                    <td style="max-width:300px;">
                                        <span style="color:var(--text-muted);"><?= sanitize($log['description'] ?? '') ?></span>
                                    </td>
                                    <td>
                                        <code style="font-size:0.75rem;background:rgba(133,135,150,0.08);padding:0.15rem 0.4rem;border-radius:var(--radius);">
                                            <?= sanitize($log['ip_address'] ?? '-') ?>
                                        </code>
                                    </td>
                                    <td>
                                        <div style="font-size:0.8rem;">
                                            <?= date('M d, Y', strtotime($log['created_at'])) ?>
                                        </div>
                                        <div style="font-size:0.75rem;color:var(--text-muted);">
                                            <?= date('H:i:s', strtotime($log['created_at'])) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this log?');">
                                            <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="delete_single">
                                            <input type="hidden" name="log_id" value="<?= $log['id'] ?>">
                                            <button type="submit" class="btn btn-link btn-sm p-0" style="color:var(--text-muted);font-size:0.8rem;" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Pagination -->
        <?php if ($total > $perPage): ?>
        <div class="card-footer d-flex justify-content-between align-items-center" style="border-top:1px solid var(--card-border);">
            <small style="color:var(--text-muted);">
                Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?> of <?= number_format($total) ?>
            </small>
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php
                    $totalPages = ceil($total / $perPage);
                    
                    if ($page > 1): ?>
                        <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">&laquo;</a></li>
                    <?php endif;
                    
                    for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor;
                    
                    if ($page < $totalPages): ?>
                        <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">&raquo;</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</form>

<script>
// Bulk select logic
const selectAll = document.getElementById('selectAll');
const selectAllHeader = document.getElementById('selectAllHeader');
const checkboxes = document.querySelectorAll('.log-checkbox');
const bulkActions = document.getElementById('bulkActions');
const selectedCount = document.getElementById('selectedCount');

function updateBulkUI() {
    const checked = document.querySelectorAll('.log-checkbox:checked').length;
    selectedCount.textContent = checked;
    bulkActions.style.display = checked > 0 ? 'block' : 'none';
}

selectAll?.addEventListener('change', () => {
    checkboxes.forEach(cb => cb.checked = selectAll.checked);
    selectAllHeader.checked = selectAll.checked;
    updateBulkUI();
});

selectAllHeader?.addEventListener('change', () => {
    checkboxes.forEach(cb => cb.checked = selectAllHeader.checked);
    selectAll.checked = selectAllHeader.checked;
    updateBulkUI();
});

checkboxes.forEach(cb => cb.addEventListener('change', updateBulkUI));
</script>

<?php include __DIR__ . '/admin_footer.php'; ?>
