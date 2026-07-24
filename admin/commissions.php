<?php
/**
 * EarnSphere - Admin: Commissions
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/classes/CommissionEngine.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

Auth::initSession();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ' . SITE_URL . '/admin/login.php');
    exit;
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

$pageTitle = 'Commissions';
include __DIR__ . '/admin_header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-coins me-2" style="color:var(--primary);"></i>Commissions</h1>
    <p>Track referral earnings</p>
</div>

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

<!-- Commissions Table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Earner</th>
                        <th>Source</th>
                        <th>Level</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($commissions as $i => $c): ?>
                    <tr>
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
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($commissions)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-4">
                            <p class="text-muted mb-0">No commissions found</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mt-3">
    <?= paginate($total, $page, $perPage, SITE_URL . '/admin/commissions.php?' . http_build_query(['level' => $level, 'page' => ''])) ?>
</div>

<?php include __DIR__ . '/admin_footer.php'; ?>
