<?php
/**
 * EarnSphere - Admin: Commissions
 * Supports single delete, bulk delete with checkboxes
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

// Handle delete actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Auth::validateCSRF($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'Security token invalid');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    $action = $_POST['action'];

    if ($action === 'delete_single') {
        $commId = (int)($_POST['commission_id'] ?? 0);
        if ($commId > 0) {
            Database::delete('commissions', 'id = ?', [$commId]);
            setFlash('success', 'Commission record deleted');
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($action === 'delete_selected') {
        $ids = $_POST['commission_ids'] ?? [];
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            Database::query("DELETE FROM commissions WHERE id IN ({$placeholders})", array_map('intval', $ids));
            setFlash('success', count($ids) . ' commission record(s) deleted');
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

$level = $_GET['level'] ?? '';
$page = getCurrentPage();
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = "1=1";
$params = [];

if ($level) {
    $where .= " AND c.level = ?";
    $params[] = (int) $level;
}

$total = Database::count('commissions c', $where, $params);

$commissions = Database::fetchAll(
    "SELECT c.*, 
            e.full_name as earner_name, e.phone as earner_phone,
            s.full_name as source_name, s.phone as source_phone
     FROM commissions c
     JOIN users e ON c.earner_id = e.id
     JOIN users s ON c.source_user_id = s.id
     WHERE {$where}
     ORDER BY c.created_at DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$summary = CommissionEngine::getCommissionSummary();
$csrf = Auth::generateCSRF();
$pageTitle = 'Commissions';
include __DIR__ . '/admin_header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-coins me-2" style="color:var(--primary);"></i>Commissions</h1>
    <p>Track referral earnings</p>
</div>

<?php displayFlash(); ?>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3 mb-2">
        <div class="card">
            <div class="card-body text-center">
                <div style="font-size:1.5rem;font-weight:800;color:var(--primary);"><?= formatCurrency($summary['total_commissions'] ?? 0) ?></div>
                <div style="font-size:0.8rem;color:#6b7280;">Total Commission</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-2">
        <div class="card">
            <div class="card-body text-center">
                <div style="font-size:1.5rem;font-weight:800;color:var(--primary);"><?= formatCurrency($summary['total_l1'] ?? 0) ?></div>
                <div style="font-size:0.8rem;color:#6b7280;">Level 1</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-2">
        <div class="card">
            <div class="card-body text-center">
                <div style="font-size:1.5rem;font-weight:800;color:#10b981;"><?= formatCurrency($summary['total_l2'] ?? 0) ?></div>
                <div style="font-size:0.8rem;color:#6b7280;">Level 2</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-2">
        <div class="card">
            <div class="card-body text-center">
                <div style="font-size:1.5rem;font-weight:800;color:#f59e0b;"><?= formatCurrency($summary['total_l3'] ?? 0) ?></div>
                <div style="font-size:0.8rem;color:#6b7280;">Level 3</div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex gap-2 flex-wrap">
            <a href="?level=" class="btn <?= !$level ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm">All</a>
            <a href="?level=1" class="btn <?= $level === '1' ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm">Level 1</a>
            <a href="?level=2" class="btn <?= $level === '2' ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm">Level 2</a>
            <a href="?level=3" class="btn <?= $level === '3' ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm">Level 3</a>
        </div>
    </div>
</div>

<!-- Bulk Delete Form -->
<form method="POST" id="bulkDeleteForm">
    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="delete_selected">

    <?php if (!empty($commissions)): ?>
    <div class="card mb-3" style="display:none;" id="bulkActions">
        <div class="card-body py-2 d-flex align-items-center">
            <input type="checkbox" id="selectAll" class="form-check-input me-2">
            <label for="selectAll" class="me-3" style="font-size:0.85rem;font-weight:700;">
                Select All (<span id="selectedCount">0</span> selected)
            </label>
            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete selected commission records?');">
                <i class="fas fa-trash me-1"></i> Delete Selected
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Commissions Table -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th style="width:40px;"><input type="checkbox" class="form-check-input" id="selectAllHeader"></th>
                            <th>#</th>
                            <th>Earner</th>
                            <th>Source</th>
                            <th>Level</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th style="width:50px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($commissions as $i => $c): ?>
                        <tr>
                            <td><input type="checkbox" name="commission_ids[]" value="<?= $c['id'] ?>" class="form-check-input item-checkbox"></td>
                            <td><?= $offset + $i + 1 ?></td>
                            <td>
                                <strong><?= sanitize($c['earner_name']) ?></strong>
                                <br><small style="color:#9ca3af;"><?= formatPhone($c['earner_phone']) ?></small>
                            </td>
                            <td>
                                <strong><?= sanitize($c['source_name']) ?></strong>
                                <br><small style="color:#9ca3af;"><?= formatPhone($c['source_phone']) ?></small>
                            </td>
                            <td>
                                <span class="badge" style="background:<?= $c['level'] === 1 ? '#72578B' : ($c['level'] === 2 ? '#10b981' : '#f59e0b') ?>; color:white;">
                                    Level <?= $c['level'] ?>
                                </span>
                            </td>
                            <td><strong style="color:#10b981;"><?= formatCurrency($c['amount']) ?></strong></td>
                            <td><?= statusBadge($c['status']) ?></td>
                            <td><small><?= timeAgo($c['created_at']) ?></small></td>
                            <td>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this commission record?');">
                                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                                    <input type="hidden" name="action" value="delete_single">
                                    <input type="hidden" name="commission_id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="btn btn-link btn-sm p-0" style="color:#9ca3af;" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($commissions)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-4">
                                <p class="text-muted mb-0">No commissions found</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</form>

<div class="mt-3">
    <?= paginate($total, $page, $perPage, SITE_URL . '/admin/commissions?' . http_build_query(['level' => $level, 'page' => ''])) ?>
</div>

<script>
const selectAll = document.getElementById('selectAll');
const selectAllHeader = document.getElementById('selectAllHeader');
const checkboxes = document.querySelectorAll('.item-checkbox');
const bulkActions = document.getElementById('bulkActions');
const selectedCount = document.getElementById('selectedCount');

function updateBulkUI() {
    const checked = document.querySelectorAll('.item-checkbox:checked').length;
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
