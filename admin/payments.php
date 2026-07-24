<?php
/**
 * EarnSphere - Admin: Payments Management
 * Shows snippe_reference and supports verification fallback
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

Auth::initSession();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ' . SITE_URL . '/admin/login');
    exit;
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

$pageTitle = 'Payments';
include __DIR__ . '/admin_header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-credit-card me-2" style="color:var(--primary);"></i>Payments</h1>
    <p>Manage all registration payments</p>
</div>

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

<!-- Payments Table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>User</th>
                        <th>Amount</th>
                        <th>Phone</th>
                        <th>Order ID</th>
                        <th>Snippe Ref</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $i => $p): ?>
                    <tr>
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
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4">
                            <p class="text-muted mb-0">No payments found</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mt-3">
    <?= paginate($total, $page, $perPage, SITE_URL . '/admin/payments?' . http_build_query(['status' => $status, 'page' => ''])) ?>
</div>

<?php include __DIR__ . '/admin_footer.php'; ?>
