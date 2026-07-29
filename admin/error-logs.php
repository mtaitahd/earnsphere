<?php
/**
 * EarnSphere - Admin: Error Logs
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/classes/ErrorLogger.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

Auth::initSession();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ' . SITE_URL . '/admin/login');
    exit;
}

ErrorLogger::ensureTable();

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
            Database::delete('error_logs', 'id = ?', [$logId]);
            setFlash('success', 'Error log deleted');
        }
    }

    if ($action === 'delete_selected') {
        $ids = array_map('intval', $_POST['log_ids'] ?? []);
        $ids = array_values(array_filter($ids));

        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            Database::query("DELETE FROM error_logs WHERE id IN ({$placeholders})", $ids);
            setFlash('success', count($ids) . ' error log(s) deleted');
        }
    }

    if ($action === 'clear_all') {
        Database::query('TRUNCATE TABLE error_logs');
        setFlash('success', 'All error logs cleared');
    }

    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

$search = trim($_GET['search'] ?? '');
$filterUser = (int)($_GET['user_id'] ?? 0);
$filterCategory = trim($_GET['category'] ?? '');
$filterSeverity = trim($_GET['severity'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$page = getCurrentPage();
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = '1=1';
$params = [];

if ($search !== '') {
    $where .= ' AND (el.message LIKE ? OR el.source LIKE ? OR el.request_uri LIKE ? OR CAST(el.context AS CHAR) LIKE ? OR u.full_name LIKE ? OR u.phone LIKE ? OR u.email LIKE ?)';
    $searchParam = "%{$search}%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
}

if ($filterUser > 0) {
    $where .= ' AND el.user_id = ?';
    $params[] = $filterUser;
}

if ($filterCategory !== '') {
    $where .= ' AND el.category = ?';
    $params[] = $filterCategory;
}

if ($filterSeverity !== '') {
    $where .= ' AND el.severity = ?';
    $params[] = $filterSeverity;
}

if ($dateFrom !== '') {
    $where .= ' AND DATE(el.created_at) >= ?';
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $where .= ' AND DATE(el.created_at) <= ?';
    $params[] = $dateTo;
}

$total = Database::fetchOne(
    "SELECT COUNT(*) AS cnt FROM error_logs el LEFT JOIN users u ON el.user_id = u.id WHERE {$where}",
    $params
)['cnt'] ?? 0;

$logs = Database::fetchAll(
    "SELECT el.*, u.full_name, u.phone, u.email, u.role
     FROM error_logs el
     LEFT JOIN users u ON el.user_id = u.id
     WHERE {$where}
     ORDER BY el.created_at DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$categories = Database::fetchAll('SELECT DISTINCT category FROM error_logs ORDER BY category ASC');
$severities = Database::fetchAll('SELECT DISTINCT severity FROM error_logs ORDER BY severity ASC');
$logUsers = Database::fetchAll(
    "SELECT u.id, u.full_name, u.phone
     FROM users u
     INNER JOIN error_logs el ON u.id = el.user_id
     GROUP BY u.id
     ORDER BY u.full_name ASC"
);

$stats = [
    'today'      => Database::count('error_logs', 'DATE(created_at) = CURDATE()'),
    'login'      => Database::count('error_logs', 'category = ?', ['login']),
    'payment'    => Database::count('error_logs', 'category = ?', ['payment']),
    'withdrawal' => Database::count('error_logs', 'category = ?', ['withdrawal']),
];

$csrf = Auth::generateCSRF();

function getErrorSeverityStyle(string $severity): array {
    return match ($severity) {
        'critical' => ['icon' => 'skull-crossbones', 'color' => '#7f1d1d', 'bg' => '#fee2e2'],
        'error'    => ['icon' => 'times-circle', 'color' => '#E74A3B', 'bg' => 'rgba(231,74,59,0.12)'],
        'warning'  => ['icon' => 'exclamation-triangle', 'color' => '#F6C23E', 'bg' => 'rgba(246,194,62,0.16)'],
        'notice'   => ['icon' => 'info-circle', 'color' => '#4E73DF', 'bg' => 'rgba(78,115,223,0.12)'],
        default    => ['icon' => 'circle', 'color' => '#858796', 'bg' => 'rgba(133,135,150,0.12)'],
    };
}

function getErrorCategoryStyle(string $category): array {
    return match ($category) {
        'login'      => ['icon' => 'sign-in-alt', 'color' => 'rgb(114,87,139)', 'bg' => 'rgba(114,87,139,0.12)'],
        'payment'    => ['icon' => 'credit-card', 'color' => '#E74A3B', 'bg' => 'rgba(231,74,59,0.12)'],
        'withdrawal' => ['icon' => 'money-bill-wave', 'color' => '#F6C23E', 'bg' => 'rgba(246,194,62,0.16)'],
        'api'        => ['icon' => 'plug', 'color' => '#4E73DF', 'bg' => 'rgba(78,115,223,0.12)'],
        'webhook'    => ['icon' => 'satellite-dish', 'color' => '#36B9CC', 'bg' => 'rgba(54,185,204,0.12)'],
        'wallet'     => ['icon' => 'wallet', 'color' => '#1CC88A', 'bg' => 'rgba(28,200,138,0.12)'],
        default      => ['icon' => 'bug', 'color' => '#858796', 'bg' => 'rgba(133,135,150,0.12)'],
    };
}

function prettyContext(?string $context): string {
    if (!$context) {
        return '';
    }

    $decoded = json_decode($context, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return $context;
    }

    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

$pageTitle = 'Error Logs';
include __DIR__ . '/admin_header.php';
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0" style="font-weight:700;">
        <i class="fas fa-bug me-2" style="color:var(--danger);"></i>Error Logs
    </h1>
    <div class="d-flex gap-2">
        <a href="<?= SITE_URL ?>/admin/logs" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-list-alt me-1"></i> Activity Logs
        </a>
        <form method="POST" onsubmit="return confirm('Clear ALL error logs? This cannot be undone.');">
            <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="clear_all">
            <button type="submit" class="btn btn-danger btn-sm">
                <i class="fas fa-trash me-1"></i> Clear All
            </button>
        </form>
    </div>
</div>

<?php displayFlash(); ?>

<div class="row row-cols-2 row-cols-md-4 g-3 mb-4">
    <div class="col">
        <div class="stat-card" style="margin-bottom:0;">
            <div class="stat-icon" style="background:rgba(231,74,59,0.12);color:#E74A3B;"><i class="fas fa-bug"></i></div>
            <div class="stat-info"><h3><?= number_format($total) ?></h3><p>Filtered Errors</p></div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="margin-bottom:0;">
            <div class="stat-icon" style="background:rgba(78,115,223,0.12);color:#4E73DF;"><i class="fas fa-calendar-day"></i></div>
            <div class="stat-info"><h3><?= number_format($stats['today']) ?></h3><p>Today</p></div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="margin-bottom:0;">
            <div class="stat-icon" style="background:rgba(114,87,139,0.12);color:rgb(114,87,139);"><i class="fas fa-sign-in-alt"></i></div>
            <div class="stat-info"><h3><?= number_format($stats['login']) ?></h3><p>Login</p></div>
        </div>
    </div>
    <div class="col">
        <div class="stat-card" style="margin-bottom:0;">
            <div class="stat-icon" style="background:rgba(246,194,62,0.16);color:#F6C23E;"><i class="fas fa-money-bill-wave"></i></div>
            <div class="stat-info"><h3><?= number_format($stats['payment'] + $stats['withdrawal']) ?></h3><p>Money Flow</p></div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body" style="padding:1rem 1.25rem;">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label style="font-size:0.75rem;font-weight:600;color:var(--text-muted);">Search</label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Message, source, user, phone..." value="<?= sanitize($search) ?>">
            </div>
            <div class="col-md-2">
                <label style="font-size:0.75rem;font-weight:600;color:var(--text-muted);">User</label>
                <select name="user_id" class="form-select form-select-sm">
                    <option value="">All Users</option>
                    <?php foreach ($logUsers as $lu): ?>
                        <option value="<?= $lu['id'] ?>" <?= $filterUser === (int)$lu['id'] ? 'selected' : '' ?>>
                            <?= sanitize($lu['full_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label style="font-size:0.75rem;font-weight:600;color:var(--text-muted);">Category</label>
                <select name="category" class="form-select form-select-sm">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= sanitize($cat['category']) ?>" <?= $filterCategory === $cat['category'] ? 'selected' : '' ?>>
                            <?= ucfirst(str_replace('_', ' ', $cat['category'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label style="font-size:0.75rem;font-weight:600;color:var(--text-muted);">Severity</label>
                <select name="severity" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($severities as $sev): ?>
                        <option value="<?= sanitize($sev['severity']) ?>" <?= $filterSeverity === $sev['severity'] ? 'selected' : '' ?>>
                            <?= ucfirst($sev['severity']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label style="font-size:0.75rem;font-weight:600;color:var(--text-muted);">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= sanitize($dateFrom) ?>">
            </div>
            <div class="col-md-1">
                <label style="font-size:0.75rem;font-weight:600;color:var(--text-muted);">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= sanitize($dateTo) ?>">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-fill"><i class="fas fa-filter me-1"></i> Filter</button>
                <a href="<?= SITE_URL ?>/admin/error-logs" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a>
            </div>
        </form>
    </div>
</div>

<form method="POST" id="bulkDeleteForm">
    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="delete_selected">

    <?php if (!empty($logs)): ?>
    <div class="card mb-3" style="display:none;" id="bulkActions">
        <div class="card-body py-2 d-flex align-items-center">
            <input type="checkbox" id="selectAll" class="form-check-input me-2">
            <label for="selectAll" class="me-3" style="font-size:0.85rem;font-weight:700;">Select All (<span id="selectedCount">0</span> selected)</label>
            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete selected error logs?');">
                <i class="fas fa-trash me-1"></i> Delete Selected
            </button>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body p-0">
            <?php if (empty($logs)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-check-circle" style="font-size:3rem;color:#1CC88A;opacity:0.7;"></i>
                    <p class="mt-3" style="color:var(--text-muted);">No error logs found</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th style="width:40px;"><input type="checkbox" class="form-check-input" id="selectAllHeader"></th>
                                <th style="width:110px;">Severity</th>
                                <th style="width:120px;">Category</th>
                                <th style="width:190px;">User</th>
                                <th>Error</th>
                                <th style="width:180px;">Request</th>
                                <th style="width:145px;">Time</th>
                                <th style="width:50px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <?php $sevStyle = getErrorSeverityStyle($log['severity']); ?>
                                <?php $catStyle = getErrorCategoryStyle($log['category']); ?>
                                <?php $context = prettyContext($log['context'] ?? null); ?>
                                <tr>
                                    <td><input type="checkbox" name="log_ids[]" value="<?= $log['id'] ?>" class="form-check-input log-checkbox"></td>
                                    <td>
                                        <span style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.25rem 0.55rem;border-radius:var(--radius);font-size:0.72rem;font-weight:800;background:<?= $sevStyle['bg'] ?>;color:<?= $sevStyle['color'] ?>;">
                                            <i class="fas fa-<?= $sevStyle['icon'] ?>"></i><?= ucfirst($log['severity']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.25rem 0.55rem;border-radius:var(--radius);font-size:0.72rem;font-weight:800;background:<?= $catStyle['bg'] ?>;color:<?= $catStyle['color'] ?>;">
                                            <i class="fas fa-<?= $catStyle['icon'] ?>"></i><?= ucfirst(str_replace('_', ' ', $log['category'])) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($log['user_id']): ?>
                                            <a href="<?= SITE_URL ?>/admin/error-logs?<?= http_build_query(['user_id' => $log['user_id']]) ?>" style="font-weight:800;text-decoration:none;color:var(--text-dark);">
                                                <?= sanitize($log['full_name'] ?? 'Deleted User') ?>
                                            </a>
                                            <div style="font-size:0.75rem;color:var(--text-muted);">
                                                <?= sanitize($log['phone'] ? formatPhone($log['phone']) : ($log['email'] ?? '')) ?>
                                                <?php if (($log['role'] ?? '') === 'admin'): ?>
                                                    <span class="badge badge-primary" style="font-size:0.6rem;">ADMIN</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color:var(--text-muted);">Guest / Unknown</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="min-width:300px;">
                                        <div style="font-weight:700;color:var(--text-dark);"><?= sanitize($log['message']) ?></div>
                                        <div style="font-size:0.75rem;color:var(--text-muted);margin-top:0.2rem;">
                                            Source: <code><?= sanitize($log['source'] ?? '-') ?></code>
                                            <?php if ($log['ip_address']): ?> | IP: <code><?= sanitize($log['ip_address']) ?></code><?php endif; ?>
                                        </div>
                                        <?php if ($context): ?>
                                            <details style="margin-top:0.4rem;">
                                                <summary style="font-size:0.75rem;color:var(--accent);font-weight:700;cursor:pointer;">View context</summary>
                                                <pre style="white-space:pre-wrap;background:#111827;color:#e5e7eb;border-radius:8px;padding:0.75rem;margin:0.5rem 0 0;font-size:0.72rem;max-height:260px;overflow:auto;"><?= sanitize($context) ?></pre>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <code style="font-size:0.72rem;background:rgba(133,135,150,0.08);padding:0.15rem 0.35rem;border-radius:var(--radius);">
                                            <?= sanitize($log['request_method'] ?? '-') ?>
                                        </code>
                                        <div style="font-size:0.72rem;color:var(--text-muted);max-width:160px;word-break:break-word;margin-top:0.25rem;">
                                            <?= sanitize($log['request_uri'] ?? '-') ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="font-size:0.8rem;font-weight:700;"><?= date('M d, Y', strtotime($log['created_at'])) ?></div>
                                        <div style="font-size:0.75rem;color:var(--text-muted);"><?= date('H:i:s', strtotime($log['created_at'])) ?></div>
                                        <div style="font-size:0.72rem;color:var(--text-muted);"><?= sanitize(timeAgo($log['created_at'])) ?></div>
                                    </td>
                                    <td>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this error log?');">
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

        <?php if ($total > $perPage): ?>
            <div class="card-footer d-flex justify-content-between align-items-center" style="border-top:1px solid var(--card-border);">
                <small style="color:var(--text-muted);">Showing <?= $offset + 1 ?>-<?= min($offset + $perPage, $total) ?> of <?= number_format($total) ?></small>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <?php $totalPages = (int)ceil($total / $perPage); ?>
                        <?php if ($page > 1): ?>
                            <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">&laquo;</a></li>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a></li>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?>
                            <li class="page-item"><a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">&raquo;</a></li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</form>

<script>
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
