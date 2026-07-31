<?php
/**
 * EarnSphere - Admin: Support Tickets
 * Lists help requests, allows inline reply, tracks the submitting user.
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

Auth::initSession();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ' . SITE_URL . '/admin/login');
    exit;
}

// ------------------------------------------------------------
// POST actions
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Auth::validateCSRF($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'Security token invalid');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    $action = $_POST['action'];
    $ticketId = (int) ($_POST['ticket_id'] ?? 0);

    if ($action === 'reply' && $ticketId > 0) {
        $reply = trim($_POST['admin_reply'] ?? '');
        if ($reply !== '') {
            $ticket = Database::fetchOne("SELECT id, user_id FROM support_tickets WHERE id = ?", [$ticketId]);
            if ($ticket) {
                Database::update('support_tickets', [
                    'admin_reply' => $reply,
                    'replied_by'  => (int) $_SESSION['user_id'],
                    'replied_at'  => date('Y-m-d H:i:s'),
                    'status'      => 'answered',
                    'user_read'   => 0, // triggers the user notification bell
                ], 'id = ?', [$ticketId]);

                if (!empty($ticket['user_id'])) {
                    Auth::logActivity((int) $ticket['user_id'], 'support_replied', 'Admin replied to your help request');
                }
                setFlash('success', 'Reply sent to user');
            }
        } else {
            setFlash('error', 'Reply cannot be empty');
        }
        header('Location: ' . SITE_URL . '/admin/support?view=' . $ticketId);
        exit;
    }

    if ($action === 'close' && $ticketId > 0) {
        Database::update('support_tickets', ['status' => 'closed'], 'id = ?', [$ticketId]);
        setFlash('success', 'Ticket closed');
        header('Location: ' . SITE_URL . '/admin/support?view=' . $ticketId);
        exit;
    }

    if ($action === 'reopen' && $ticketId > 0) {
        Database::update('support_tickets', ['status' => 'open'], 'id = ?', [$ticketId]);
        setFlash('success', 'Ticket reopened');
        header('Location: ' . SITE_URL . '/admin/support?view=' . $ticketId);
        exit;
    }

    if ($action === 'delete' && $ticketId > 0) {
        Database::delete('support_tickets', 'id = ?', [$ticketId]);
        setFlash('success', 'Ticket deleted');
        header('Location: ' . SITE_URL . '/admin/support');
        exit;
    }
}

// ------------------------------------------------------------
// Detail view
// ------------------------------------------------------------
$viewTicket = null;
$viewId = (int) ($_GET['view'] ?? 0);
if ($viewId > 0) {
    $viewTicket = Database::fetchOne(
        "SELECT t.*, u.full_name AS account_name, u.phone AS account_phone, u.status AS account_status,
                r.full_name AS replier_name
         FROM support_tickets t
         LEFT JOIN users u ON u.id = t.user_id
         LEFT JOIN users r ON r.id = t.replied_by
         WHERE t.id = ?",
        [$viewId]
    );
}

// ------------------------------------------------------------
// List (with filters)
// ------------------------------------------------------------
$statusFilter = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');
$page = getCurrentPage();
$perPage = 20;

$where = "1=1";
$params = [];
if (in_array($statusFilter, ['open', 'answered', 'closed'])) {
    $where .= " AND status = ?";
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where .= " AND (t.name LIKE ? OR t.subject LIKE ? OR t.message LIKE ? OR t.phone LIKE ?)";
    $sp = "%{$search}%";
    array_push($params, $sp, $sp, $sp, $sp);
}

$total = Database::count('support_tickets', $where, $params);
$tickets = Database::fetchAll(
    "SELECT t.*, u.full_name AS account_name
     FROM support_tickets t
     LEFT JOIN users u ON u.id = t.user_id
     WHERE {$where}
     ORDER BY
        CASE WHEN t.status = 'open' THEN 0 WHEN t.status = 'answered' THEN 1 ELSE 2 END,
        t.created_at DESC
     LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
    $params
);

$countOpen = Database::count('support_tickets', "status = 'open'");
$countAnswered = Database::count('support_tickets', "status = 'answered'");
$countClosed = Database::count('support_tickets', "status = 'closed'");

$csrf = Auth::generateCSRF();
$pageTitle = 'Support';
include __DIR__ . '/admin_header.php';
?>

<?php if ($viewTicket): ?>
    <!-- ============ DETAIL VIEW ============ -->
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h1><i class="fas fa-life-ring me-2" style="color:var(--primary);"></i>Ticket #<?= $viewTicket['id'] ?></h1>
            <p><a href="<?= SITE_URL ?>/admin/support">&larr; Back to all tickets</a></p>
        </div>
        <div>
            <?php if ($viewTicket['status'] === 'closed'): ?>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="reopen">
                    <input type="hidden" name="ticket_id" value="<?= $viewTicket['id'] ?>">
                    <button class="btn btn-outline-success btn-sm"><i class="fas fa-undo me-1"></i> Reopen</button>
                </form>
            <?php else: ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Close this ticket?')">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="close">
                    <input type="hidden" name="ticket_id" value="<?= $viewTicket['id'] ?>">
                    <button class="btn btn-outline-secondary btn-sm"><i class="fas fa-check me-1"></i> Close</button>
                </form>
            <?php endif; ?>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete this ticket?')">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="ticket_id" value="<?= $viewTicket['id'] ?>">
                <button class="btn btn-outline-danger btn-sm"><i class="fas fa-trash me-1"></i> Delete</button>
            </form>
        </div>
    </div>

    <?php displayFlash(); ?>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="fas fa-quote-left me-1"></i> Message</h6>
                    <?= $viewTicket['status'] === 'open' ? '<span class="badge bg-warning text-dark">Open</span>' : ($viewTicket['status'] === 'answered' ? '<span class="badge bg-success">Answered</span>' : '<span class="badge bg-secondary">Closed</span>') ?>
                </div>
                <div class="card-body">
                    <h5><?= sanitize($viewTicket['subject']) ?></h5>
                    <p style="color:var(--text-muted);font-size:0.8rem;"><?= date('d M Y, H:i', strtotime($viewTicket['created_at'])) ?></p>
                    <hr>
                    <p style="white-space:pre-wrap;margin-bottom:0;"><?= sanitize($viewTicket['message']) ?></p>
                </div>
            </div>

            <?php if (!empty($viewTicket['admin_reply'])): ?>
            <div class="card mb-3" style="border-left:4px solid var(--success);">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="fas fa-reply me-1"></i> Your Reply</h6>
                    <small style="color:var(--text-muted);">
                        <?= $viewTicket['replier_name'] ? sanitize($viewTicket['replier_name']) : 'Admin' ?>
                        &middot; <?= $viewTicket['replied_at'] ? date('d M Y, H:i', strtotime($viewTicket['replied_at'])) : '' ?>
                    </small>
                </div>
                <div class="card-body">
                    <p style="white-space:pre-wrap;margin-bottom:0;"><?= sanitize($viewTicket['admin_reply']) ?></p>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($viewTicket['status'] !== 'closed'): ?>
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-paper-plane me-1"></i> Reply to User</h6>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="reply">
                        <input type="hidden" name="ticket_id" value="<?= $viewTicket['id'] ?>">
                        <textarea name="admin_reply" class="form-control mb-2" rows="5" required placeholder="Type your reply here. The user will see a notification on their dashboard." style="resize:vertical;"></textarea>
                        <button class="btn btn-primary btn-sm"><i class="fas fa-paper-plane me-1"></i> Send Reply</button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header">
                    <h6 class="mb-0"><i class="fas fa-user me-1"></i> User Details</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><th style="width:40%;">Name</th><td><?= sanitize($viewTicket['name']) ?></td></tr>
                        <tr><th>Phone</th><td><?= $viewTicket['phone'] ? formatPhone($viewTicket['phone']) : '<span class="text-muted">Not provided</span>' ?></td></tr>
                        <tr><th>Email</th><td><?= $viewTicket['email'] ? sanitize($viewTicket['email']) : '<span class="text-muted">Not provided</span>' ?></td></tr>
                        <tr><th>Submitted</th><td><?= timeAgo($viewTicket['created_at']) ?></td></tr>
                        <tr>
                            <th>Account</th>
                            <td>
                                <?php if ($viewTicket['user_id']): ?>
                                    <a href="<?= SITE_URL ?>/admin/users?search=<?= urlencode($viewTicket['account_phone'] ?? '') ?>">
                                        <i class="fas fa-user-check me-1"></i><?= sanitize($viewTicket['account_name'] ?? 'View user') ?>
                                    </a>
                                    <br>
                                    <small class="text-muted"><?= formatPhone($viewTicket['account_phone']) ?> &middot; <?= statusBadge($viewTicket['account_status']) ?></small>
                                <?php else: ?>
                                    <span class="text-muted"><i class="fas fa-user-slash me-1"></i>No linked account</span>
                                    <?php if (!empty($viewTicket['phone'])): ?>
                                        <br><a href="<?= SITE_URL ?>/admin/users?search=<?= urlencode($viewTicket['phone']) ?>" class="small">Search users by this phone</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ============ LIST VIEW ============ -->
    <div class="page-header d-flex justify-content-between align-items-center">
        <div>
            <h1><i class="fas fa-life-ring me-2" style="color:var(--primary);"></i>Support Tickets</h1>
            <p>Help requests from the landing page and user dashboard</p>
        </div>
    </div>

    <?php displayFlash(); ?>

    <!-- Filter tabs -->
    <ul class="nav nav-pills mb-3">
        <li class="nav-item"><a class="nav-link <?= $statusFilter === '' ? 'active' : '' ?>" href="<?= SITE_URL ?>/admin/support">All (<?= $countOpen + $countAnswered + $countClosed ?>)</a></li>
        <li class="nav-item"><a class="nav-link <?= $statusFilter === 'open' ? 'active' : '' ?>" href="?status=open">Open (<?= $countOpen ?>)</a></li>
        <li class="nav-item"><a class="nav-link <?= $statusFilter === 'answered' ? 'active' : '' ?>" href="?status=answered">Answered (<?= $countAnswered ?>)</a></li>
        <li class="nav-item"><a class="nav-link <?= $statusFilter === 'closed' ? 'active' : '' ?>" href="?status=closed">Closed (<?= $countClosed ?>)</a></li>
    </ul>

    <form method="GET" class="mb-3" style="max-width:420px;">
        <div class="input-group">
            <input type="text" name="search" class="form-control" placeholder="Search name, subject, phone, or message..." value="<?= sanitize($search) ?>">
            <?php if ($statusFilter !== ''): ?><input type="hidden" name="status" value="<?= sanitize($statusFilter) ?>"><?php endif; ?>
            <button class="btn btn-primary"><i class="fas fa-search"></i></button>
        </div>
    </form>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>User</th>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>Account</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tickets as $t): ?>
                        <tr>
                            <td><?= $t['id'] ?></td>
                            <td>
                                <strong><?= sanitize($t['name']) ?></strong>
                                <?php if (!empty($t['phone'])): ?>
                                    <br><small style="color:var(--text-muted);"><?= formatPhone($t['phone']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= sanitize(truncate($t['subject'], 50)) ?></strong>
                                <br><small style="color:var(--text-muted);"><?= sanitize(truncate($t['message'], 60)) ?></small>
                            </td>
                            <td>
                                <?= $t['status'] === 'open' ? '<span class="badge bg-warning text-dark">Open</span>' : ($t['status'] === 'answered' ? '<span class="badge bg-success">Answered</span>' : '<span class="badge bg-secondary">Closed</span>') ?>
                            </td>
                            <td>
                                <?php if (!empty($t['account_name'])): ?>
                                    <a href="<?= SITE_URL ?>/admin/users?search=<?= urlencode($t['account_phone'] ?? '') ?>" class="small"><i class="fas fa-user-check me-1"></i><?= sanitize($t['account_name']) ?></a>
                                <?php else: ?>
                                    <small class="text-muted">Guest</small>
                                <?php endif; ?>
                            </td>
                            <td><small style="color:var(--text-muted);"><?= timeAgo($t['created_at']) ?></small></td>
                            <td>
                                <a href="<?= SITE_URL ?>/admin/support?view=<?= $t['id'] ?>" class="btn btn-sm btn-outline-cyan">
                                    <i class="fas fa-eye me-1"></i>View
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($tickets)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4">
                                <i class="fas fa-inbox fa-2x mb-2" style="color:#d1d5db;"></i>
                                <p class="text-muted mb-0">No support tickets found</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3">
        <?= paginate($total, $page, $perPage, SITE_URL . '/admin/support?' . http_build_query(['status' => $statusFilter, 'search' => $search, 'page' => ''])) ?>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/admin_footer.php'; ?>
