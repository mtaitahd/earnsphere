<?php
/**
 * EarnSphere - Admin: Payments Management
 * Supports single delete, bulk delete with checkboxes
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
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
        $payId = (int)($_POST['payment_id'] ?? 0);
        if ($payId > 0) {
            Database::delete('payments', 'id = ?', [$payId]);
            setFlash('success', 'Payment record deleted');
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($action === 'delete_selected') {
        $ids = $_POST['payment_ids'] ?? [];
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            Database::query("DELETE FROM payments WHERE id IN ({$placeholders})", array_map('intval', $ids));
            setFlash('success', count($ids) . ' payment record(s) deleted');
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

$status = $_GET['status'] ?? '';
$page = getCurrentPage();
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = "1=1";
$params = [];

if ($status) {
    $where .= " AND p.status = ?";
    $params[] = $status;
}

$total = Database::count('payments p', $where, $params);

$payments = Database::fetchAll(
    "SELECT p.*, u.full_name, u.phone as user_phone, u.referral_code
     FROM payments p 
     JOIN users u ON p.user_id = u.id
     WHERE {$where}
     ORDER BY p.created_at DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$csrf = Auth::generateCSRF();
$pageTitle = 'Payments';
include __DIR__ . '/admin_header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-credit-card me-2" style="color:var(--primary);"></i>Payments</h1>
    <p>Manage all registration payments</p>
</div>

<?php displayFlash(); ?>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex gap-2 flex-wrap">
            <a href="?status=" class="btn <?= !$status ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm">All (<?= Database::count('payments') ?>)</a>
            <a href="?status=pending" class="btn <?= $status === 'pending' ? 'btn-warning' : 'btn-outline-warning' ?> btn-sm">Pending</a>
            <a href="?status=completed" class="btn <?= $status === 'completed' ? 'btn-success' : 'btn-outline-success' ?> btn-sm">Completed</a>
            <a href="?status=failed" class="btn <?= $status === 'failed' ? 'btn-danger' : 'btn-outline-danger' ?> btn-sm">Failed</a>
        </div>
    </div>
</div>

<!-- Bulk Delete Form -->
<form method="POST" id="bulkDeleteForm">
    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="delete_selected">

    <?php if (!empty($payments)): ?>
    <div class="card mb-3" style="display:none;" id="bulkActions">
        <div class="card-body py-2 d-flex align-items-center">
            <input type="checkbox" id="selectAll" class="form-check-input me-2">
            <label for="selectAll" class="me-3" style="font-size:0.85rem;font-weight:700;">
                Select All (<span id="selectedCount">0</span> selected)
            </label>
            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete selected payment records?');">
                <i class="fas fa-trash me-1"></i> Delete Selected
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Payments Table -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th style="width:40px;"><input type="checkbox" class="form-check-input" id="selectAllHeader"></th>
                            <th>#</th>
                            <th>User</th>
                            <th>Amount</th>
                            <th>Phone</th>
                            <th>Order ID</th>
                            <th>Snippe Ref</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th style="width:50px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $i => $p): ?>
                        <tr>
                            <td><input type="checkbox" name="payment_ids[]" value="<?= $p['id'] ?>" class="form-check-input item-checkbox"></td>
                            <td><?= $offset + $i + 1 ?></td>
                            <td><strong><?= sanitize($p['full_name']) ?></strong></td>
                            <td><strong><?= formatCurrency($p['amount']) ?></strong></td>
                            <td><?= formatPhone($p['phone'] ?? $p['user_phone']) ?></td>
                            <td><code style="font-size:0.7rem;"><?= $p['order_id'] ?></code></td>
                            <td>
                                <?php if ($p['snippe_reference']): ?>
                                    <small style="font-size:0.7rem;" title="<?= $p['snippe_reference'] ?>"><?= truncate($p['snippe_reference'], 16) ?></small>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= statusBadge($p['status']) ?></td>
                            <td><small><?= date('d M Y H:i', strtotime($p['created_at'])) ?></small></td>
                            <td>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this payment record?');">
                                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                                    <input type="hidden" name="action" value="delete_single">
                                    <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-link btn-sm p-0" style="color:#9ca3af;" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($payments)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-4">
                                <p class="text-muted mb-0">No payments found</p>
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
    <?= paginate($total, $page, $perPage, SITE_URL . '/admin/payments?' . http_build_query(['status' => $status, 'page' => ''])) ?>
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
