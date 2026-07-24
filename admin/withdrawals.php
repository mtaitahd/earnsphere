<?php
/**
 * EarnSphere - Admin: Withdrawals Management
 * Supports approve/reject + Snippe payout dispatch
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/snippe.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/classes/Wallet.php';
require_once dirname(__DIR__) . '/classes/SnippePayment.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

Auth::initSession();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ' . SITE_URL . '/admin/login');
    exit;
}

$adminId = $_SESSION['user_id'];

// Handle approve/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $wdAction = $_POST['action'];
    $wdId = (int)($_POST['withdrawal_id'] ?? 0);
    $adminNote = trim($_POST['admin_note'] ?? '');

    if ($wdAction === 'approve' || $wdAction === 'reject' || $wdAction === 'complete') {
        $statusMap = [
            'approve'  => 'approved',
            'reject'   => 'rejected',
            'complete' => 'completed',
        ];

        $result = Wallet::processWithdrawal($wdId, $statusMap[$wdAction], $adminNote, $adminId);

        if ($result['success']) {
            setFlash('success', 'Withdrawal request processed');
        } else {
            setFlash('error', $result['errors'][0] ?? 'Error');
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
    $where .= " AND w.status = ?";
    $params[] = $status;
}

$total = Database::count('withdrawals w', $where, $params);

$withdrawals = Database::fetchAll(
    "SELECT w.*, u.full_name, u.phone as user_phone, u.email,
            p.full_name as processor_name,
            pay.id as payout_id, pay.status as payout_status, pay.reference as payout_reference,
            pay.fees as payout_fees, pay.provider as payout_provider, pay.error_message as payout_error
     FROM withdrawals w
     JOIN users u ON w.user_id = u.id
     LEFT JOIN users p ON w.processed_by = p.id
     LEFT JOIN payouts pay ON pay.withdrawal_id = w.id
     WHERE {$where}
     ORDER BY w.created_at DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$csrf = Auth::generateCSRF();
$pageTitle = 'Withdrawals';
include __DIR__ . '/admin_header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-money-bill-wave me-2" style="color:var(--primary);"></i>Withdrawals</h1>
    <p>Manage withdrawal requests</p>
</div>

<?php displayFlash(); ?>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex gap-2 flex-wrap">
            <a href="?status=" class="btn <?= !$status ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm">All</a>
            <a href="?status=pending" class="btn <?= $status === 'pending' ? 'btn-warning' : 'btn-outline-warning' ?> btn-sm">
                Pending
                <?php
                $pendingCount = Database::count('withdrawals', 'status = ?', ['pending']);
                if ($pendingCount > 0): ?>
                    <span class="badge bg-danger ms-1"><?= $pendingCount ?></span>
                <?php endif; ?>
            </a>
            <a href="?status=approved" class="btn <?= $status === 'approved' ? 'btn-info' : 'btn-outline-info' ?> btn-sm">Approved</a>
            <a href="?status=processing" class="btn <?= $status === 'processing' ? 'btn-secondary' : 'btn-outline-secondary' ?> btn-sm">Processing</a>
            <a href="?status=completed" class="btn <?= $status === 'completed' ? 'btn-success' : 'btn-outline-success' ?> btn-sm">Completed</a>
            <a href="?status=rejected" class="btn <?= $status === 'rejected' ? 'btn-danger' : 'btn-outline-danger' ?> btn-sm">Rejected</a>
            <a href="?status=failed" class="btn <?= $status === 'failed' ? 'btn-dark' : 'btn-outline-dark' ?> btn-sm">Failed</a>
        </div>
    </div>
</div>

<!-- Withdrawals -->
<?php foreach ($withdrawals as $wd): ?>
<div class="card mb-3 <?= $wd['status'] === 'pending' ? 'border-left-warning' : '' ?>" style="<?= $wd['status'] === 'pending' ? 'border-left:4px solid #f59e0b;' : '' ?>">
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="d-flex align-items-center mb-2">
                    <div style="width:40px;height:40px;border-radius:50%;background:#f3eef7;color:#72578B;display:flex;align-items:center;justify-content:center;font-weight:800;margin-right:0.75rem;">
                        <?= strtoupper(substr($wd['full_name'], 0, 1)) ?>
                    </div>
                    <div>
                        <strong style="font-size:1rem;"><?= sanitize($wd['full_name']) ?></strong>
                        <div style="font-size:0.8rem;color:#6b7280;">
                            <?= formatPhone($wd['phone']) ?> &middot; <?= $wd['payment_method'] ?>
                        </div>
                    </div>
                </div>
                <div class="mt-2">
                    <div style="font-size:1.25rem;font-weight:800;color:#72578B;"><?= formatCurrency($wd['amount']) ?></div>
                    <small style="color:#9ca3af;"><?= date('d M Y H:i', strtotime($wd['created_at'])) ?></small>
                    <?php if ($wd['admin_note']): ?>
                        <div class="mt-1"><small class="text-muted"><i class="fas fa-comment me-1"></i> <?= sanitize($wd['admin_note']) ?></small></div>
                    <?php endif; ?>
                </div>

                <!-- Payout info (if payout was sent) -->
                <?php if ($wd['payout_id']): ?>
                    <div class="mt-2 p-2" style="background:#f0fdf4;border-radius:8px;font-size:0.8rem;">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <i class="fas fa-paper-plane" style="color:#10b981;"></i>
                            <strong>Payout:</strong> <?= statusBadge($wd['payout_status']) ?>
                    </div>
                    <?php if ($wd['payout_reference']): ?>
                        <div><small class="text-muted">Ref: <?= $wd['payout_reference'] ?></small></div>
                    <?php endif; ?>
                    <?php if ($wd['payout_fees'] > 0): ?>
                        <div><small class="text-muted">Fees: <?= formatCurrency($wd['payout_fees']) ?> | Provider: <?= $wd['payout_provider'] ?></small></div>
                    <?php endif; ?>
                    <?php if ($wd['payout_error']): ?>
                        <div><small class="text-danger"><?= sanitize($wd['payout_error']) ?></small></div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="col-md-4 text-md-end mt-3 mt-md-0">
                <?= statusBadge($wd['status']) ?>

                <?php if ($wd['status'] === 'pending'): ?>
                <div class="mt-2 d-flex gap-2 justify-content-md-end">
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="withdrawal_id" value="<?= $wd['id'] ?>">
                        <input type="hidden" name="admin_note" value="Approved">
                        <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Approve this request?')">
                            <i class="fas fa-check me-1"></i> Approve
                        </button>
                    </form>
                    <button class="btn btn-danger btn-sm" data-bs-toggle="collapse" data-bs-target="#reject-<?= $wd['id'] ?>">
                        <i class="fas fa-times me-1"></i> Reject
                    </button>
                </div>

                <div class="collapse mt-2" id="reject-<?= $wd['id'] ?>">
                    <form method="POST">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="withdrawal_id" value="<?= $wd['id'] ?>">
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control" name="admin_note" placeholder="Reason for rejection...">
                            <button type="submit" class="btn btn-danger" onclick="return confirm('Reject this request?')">
                                Confirm
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <?php if (in_array($wd['status'], ['approved', 'failed']) && (empty($wd['payout_id']) || $wd['payout_status'] === 'failed')): ?>
                <!-- Send/Retry Payout via Snippe -->
                <div class="mt-2">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>" id="csrf-<?= $wd['id'] ?>">
                    <button type="button" class="btn <?= empty($wd['payout_id']) ? 'btn-primary' : 'btn-warning' ?> btn-sm send-payout-btn"
                            data-id="<?= $wd['id'] ?>"
                            data-amount="<?= $wd['amount'] ?>"
                            data-name="<?= sanitize($wd['full_name']) ?>"
                            onclick="sendPayout(this)">
                            <i class="fas fa-<?= empty($wd['payout_id']) ? 'paper-plane' : 'redo' ?> me-1"></i> <?= empty($wd['payout_id']) ? 'Send Payout' : 'Retry Payout' ?>
                    </button>
                    <small class="d-block text-muted mt-1" style="font-size:0.7rem;">Snippe Disbursement</small>
                </div>
                <?php endif; ?>

                <?php if ($wd['status'] === 'approved' && !empty($wd['payout_id']) && !in_array($wd['payout_status'], ['completed', 'failed'])): ?>
                <!-- Check payout status -->
                <div class="mt-2">
                    <button type="button" class="btn btn-outline-info btn-sm"
                            onclick="checkPayout(<?= $wd['payout_id'] ?>, '<?= $wd['payout_reference'] ?>', this)">
                            <i class="fas fa-sync me-1"></i> Check Status
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php if (empty($withdrawals)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="fas fa-money-bill-wave fa-3x mb-3" style="color:#d1d5db;"></i>
        <h5 class="text-muted">No withdrawal requests</h5>
    </div>
</div>
<?php endif; ?>

<div class="mt-3">
    <?= paginate($total, $page, $perPage, SITE_URL . '/admin/withdrawals?' . http_build_query(['status' => $status, 'page' => ''])) ?>
</div>

<!-- Payout Result Modal -->
<div class="modal fade" id="payoutModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="fas fa-paper-plane me-1"></i> Payout Result</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="payoutModalBody">
                <!-- Filled dynamically -->
            </div>
        </div>
    </div>
</div>

<script>
async function sendPayout(btn) {
    const id = btn.dataset.id;
    const name = btn.dataset.name;
    const amount = parseFloat(btn.dataset.amount);

    if (!confirm('Send payout TZS ' + amount.toLocaleString() + ' to ' + name + '?')) return;

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Sending...';

    const csrfToken = document.getElementById('csrf-' + id).value;

    try {
        const formData = new FormData();
        formData.append('action', 'send_payout');
        formData.append('withdrawal_id', id);
        formData.append('<?= CSRF_TOKEN_NAME ?>', csrfToken);

        const resp = await fetch('<?= SITE_URL ?>/api/create_payout.php', {
            method: 'POST',
            body: formData,
        });
        const data = await resp.json();

        if (data.success) {
            document.getElementById('payoutModalBody').innerHTML =
                '<div class="text-center">' +
                '<i class="fas fa-check-circle fa-3x text-success mb-3"></i>' +
                '<h6>Payout Sent!</h6>' +
                '<p>Kiasi: <strong>TZS ' + amount.toLocaleString() + '</strong></p>' +
                '<p class="text-muted" style="font-size:0.85rem;">Ref: ' + (data.reference || '-') + '</p>' +
                '<p class="text-muted" style="font-size:0.85rem;">Status: ' + data.status + '</p>' +
                '</div>';
        } else {
            document.getElementById('payoutModalBody').innerHTML =
                '<div class="text-center">' +
                '<i class="fas fa-exclamation-triangle fa-3x text-danger mb-3"></i>' +
                '<h6>Payout Failed</h6>' +
                '<p class="text-danger">' + data.error + '</p>' +
                '</div>';
        }
        new bootstrap.Modal(document.getElementById('payoutModal')).show();
    } catch (e) {
        alert('Network error: ' + e.message);
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Send Payout';
}

async function checkPayout(payoutId, reference, btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>';

    try {
        const resp = await fetch('<?= SITE_URL ?>/api/check_payout.php?payout_id=' + payoutId + '&reference=' + encodeURIComponent(reference));
        const data = await resp.json();

        if (data.success) {
            document.getElementById('payoutModalBody').innerHTML =
                '<div class="text-center">' +
                '<i class="fas fa-info-circle fa-3x text-info mb-3"></i>' +
                '<h6>Payout Status</h6>' +
                '<p>Status: <strong>' + data.status + '</strong></p>' +
                '<p class="text-muted" style="font-size:0.8rem;">Chanzo: ' + data.source + '</p>' +
                '</div>';
            new bootstrap.Modal(document.getElementById('payoutModal')).show();
        }
    } catch (e) {
        alert('Network error');
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-sync me-1"></i> Check Status';
}
</script>

<?php include __DIR__ . '/admin_footer.php'; ?>
