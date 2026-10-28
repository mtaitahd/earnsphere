<?php
/**
 * EarnSphere - Admin: User Management
 * Supports single delete, bulk delete with checkboxes
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/classes/CommissionEngine.php';
require_once dirname(__DIR__) . '/classes/Wallet.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

Auth::initSession();
require_once dirname(__DIR__) . '/classes/StaffAccess.php';
StaffAccess::requirePage('users');
$canViewDashboard = StaffAccess::can('dashboard');
$canViewPayments = StaffAccess::can('payments');
$canViewPrivateUserColumns = StaffAccess::isAdministrator();
$canViewEarnings = $canViewPrivateUserColumns;
$canManageUsers = StaffAccess::can('users_manage');
$canManagePayments = $canManageUsers && StaffAccess::can('payments_manage');
$canViewErrorLogs = StaffAccess::can('error_logs');

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Auth::validateCSRF($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'Security token invalid');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    $action = $_POST['action'];

    if ($action === 'unlock_login') {
        if (!StaffAccess::isAdministrator()) StaffAccess::deny();
        try { AccountLoginLock::unlock((int)$_SESSION['user_id'],(int)($_POST['user_id']??0));setFlash('success','Password lock removed. The user can log in again.'); }
        catch (Throwable $e) {error_log('Account unlock failed: '.$e->getMessage());setFlash('error','Could not unlock this account.');}
        header('Location: '.SITE_URL.'/admin/users');exit;
    }

    if ($action === 'update_status') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newStatus = in_array($_POST['new_status'] ?? '', ['active', 'pending', 'suspended'], true) ? $_POST['new_status'] : 'active';
        Database::update('users', ['status' => $newStatus], "id = ? AND role = 'user'", [$userId]);
        Auth::logActivity($userId, 'status_updated', 'Account status set to ' . ucfirst($newStatus) . ' by admin');
        setFlash('success', 'User status updated to ' . ucfirst($newStatus));
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($action === 'update_email') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newEmail = strtolower(trim($_POST['new_email'] ?? ''));
        if ($userId > 0 && filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $existing = Database::fetchOne("SELECT id FROM users WHERE email = ? AND id != ?", [$newEmail, $userId]);
            if ($existing) {
                setFlash('error', 'Email already in use by another user');
            } else {
                Database::update('users', ['email' => $newEmail], "id = ? AND role = 'user'", [$userId]);
                setFlash('success', 'Email updated successfully');
            }
        } else {
            setFlash('error', 'Invalid email address');
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($action === 'delete_single') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $user = Database::fetchOne("SELECT id, full_name FROM users WHERE id = ? AND role = 'user'", [$userId]);
        if ($user) {
            if (deleteUsersAndData([$userId]) > 0) {
                setFlash('success', 'User "' . sanitize($user['full_name']) . '" deleted permanently');
            } else {
                setFlash('error', 'User could not be deleted. Check the error logs for details.');
            }
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($action === 'mark_as_paid') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $user = Database::fetchOne("SELECT id, full_name, phone, status FROM users WHERE id = ? AND role = 'user'", [$userId]);
        if ($user) {
            $existingPayment = Database::fetchOne("SELECT id FROM payments WHERE user_id = ? AND status = 'completed' LIMIT 1", [$userId]);
            if (!$existingPayment) {
                $fee = (int) app_setting('registration_fee', REGISTRATION_FEE);
                Database::beginTransaction();
                try {
                    $paymentId = Database::insert('payments', [
                        'user_id'         => $userId,
                        'order_id'        => 'ADMIN-' . time() . '-' . $userId,
                        'amount'          => $fee,
                        'currency'        => 'TZS',
                        'payment_method'  => 'manual_admin',
                        'phone'           => $user['phone'] ?? '',
                        'status'          => 'completed',
                        'completed_at'    => date('Y-m-d H:i:s'),
                        'metadata'        => json_encode(['marked_by' => $_SESSION['user_id'], 'method' => 'admin']),
                    ]);
                    if ($user['status'] !== 'active') {
                        Database::update('users', ['status' => 'active'], "id = ? AND role = 'user'", [$userId]);
                        CommissionEngine::processRegistrationCommissions($userId);
                        Auth::logActivity($userId, 'account_activated', 'Account activated by admin (Mark as Paid)');
                    }
                    Database::commit();
                    setFlash('success', 'User "' . sanitize($user['full_name']) . '" marked as paid — registration payment recorded; no wallet earnings added');

                    // Notify the user that payment was completed and account is activated
                    try {
                        notifyAccountActivated($userId, (float) $fee);
                    } catch (Throwable $e) {
                        error_log("Admin mark-as-paid notification error: " . $e->getMessage());
                    }
                } catch (Exception $e) {
                    Database::rollback();
                    setFlash('error', 'Error: ' . $e->getMessage());
                    ErrorLogger::logException($e, 'system', $userId, 'admin/users.php');
                }
            } else {
                setFlash('warning', 'User already has a completed payment record');
            }
        } else {
            setFlash('error', 'User not found');
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($action === 'mark_as_unpaid') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $user = Database::fetchOne("SELECT id, full_name FROM users WHERE id = ? AND role = 'user'", [$userId]);
        if ($user) {
            $payments = Database::fetchAll(
                "SELECT id, amount, metadata FROM payments WHERE user_id = ? AND status = 'completed' ORDER BY id DESC",
                [$userId]
            );
            if ($payments) {
                $totalRefunded = 0;
                Database::beginTransaction();
                try {
                    foreach ($payments as $payment) {
                        $meta = json_decode($payment['metadata'] ?? '', true) ?: [];
                        $meta['refunded']     = true;
                        $meta['refunded_by']  = (int) $_SESSION['user_id'];
                        $meta['refunded_at']  = date('Y-m-d H:i:s');
                        Database::update('payments', [
                            'status'   => 'refunded',
                            'metadata' => json_encode($meta),
                        ], 'id = ?', [$payment['id']]);
                        $totalRefunded += (float) $payment['amount'];
                    }
                    // Only remove money from the wallet if it was actually credited
                    // Older manual activations credited registration_bonus; new activations do not.
                    $credited = Database::fetchOne(
                        "SELECT id, amount FROM wallet_transactions
                         WHERE user_id = ? AND type = 'registration_bonus' AND status = 'completed'
                         ORDER BY id DESC LIMIT 1",
                        [$userId]
                    );
                    if ($credited) {
                        Wallet::reverseCredit(
                            $userId,
                            (float) $credited['amount'],
                            'admin_adjustment',
                            'Registration fee removed — marked as unpaid by admin',
                            $payments[0]['id'],
                            'payment'
                        );
                    }
                    Auth::logActivity($userId, 'payment_reversed', 'Marked as unpaid by admin — TZS ' . number_format($totalRefunded) . ' removed from wallet');
                    Database::commit();
                    $removedMsg = $credited ? ' removed from their account' : ' (no wallet credit to remove)';
                    setFlash('success', 'User "' . sanitize($user['full_name']) . '" marked as unpaid — TZS ' . number_format($totalRefunded) . $removedMsg);
                } catch (Exception $e) {
                    Database::rollback();
                    setFlash('error', 'Error: ' . $e->getMessage());
                    ErrorLogger::logException($e, 'system', $userId, 'admin/users.php');
                }
            } else {
                setFlash('warning', 'No completed payment to reverse for this user');
            }
        } else {
            setFlash('error', 'User not found');
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($action === 'delete_selected') {
        @set_time_limit(0);
        if (!empty($_POST['select_all_mode'])) {
            // Delete ALL users matching the current filter, not just this page
            [$w, $p] = buildUserFilterWhere(
                trim($_POST['filter_search'] ?? ''),
                trim($_POST['filter_status'] ?? ''),
                preg_match('/^\d{4}$/', trim($_POST['filter_year'] ?? '')) ? trim($_POST['filter_year']) : ''
            );
            $ids = array_column(Database::fetchAll("SELECT id FROM users WHERE {$w}", $p), 'id');
        } else {
            $ids = $_POST['user_ids'] ?? [];
        }

        $deleted = deleteUsersAndData($ids);

        if ($deleted > 0) {
            setFlash('success', $deleted . ' user(s) deleted permanently');
        } else {
            setFlash('error', 'No users were deleted. Check the error logs for details.');
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

/**
 * Build the WHERE clause + params used by both the user list query and the
 * bulk "delete all matching" action, so they always filter identically.
 */
function buildUserFilterWhere(string $search, string $status, string $year): array {
    $where  = "role = 'user'";
    $params = [];

    if ($search !== '') {
        $where .= " AND (full_name LIKE ? OR phone LIKE ? OR email LIKE ? OR referral_code LIKE ?)";
        $sp = "%{$search}%";
        $params = array_merge($params, [$sp, $sp, $sp, $sp]);
    }

    if ($status !== '') {
        $where .= " AND status = ?";
        $params[] = $status;
    }

    if ($year !== '') {
        $where .= " AND created_at >= ? AND created_at < ?";
        $params[] = $year . '-01-01 00:00:00';
        $params[] = ((int) $year + 1) . '-01-01 00:00:00';
    }

    return [$where, $params];
}

/**
 * Delete many users and all their related data in ONE transaction.
 * Uses batched IN() deletes so it stays fast even for hundreds of users.
 * Deletes the user row last so any remaining FK ON DELETE CASCADE cleans up.
 *
 * @param array $ids
 * @return int number of users actually deleted
 */
function deleteUsersAndData(array $ids): int {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $ids = array_values(array_filter($ids, fn($id) => $id > 0));
    if (empty($ids)) {
        return 0;
    }

    $allowed = implode(',', array_fill(0, count($ids), '?'));
    $ids = array_column(Database::fetchAll("SELECT id FROM users WHERE role = 'user' AND id IN ($allowed)", $ids), 'id');
    if (!$ids) return 0;
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids;

    $deleted = 0;
    try {
        Database::beginTransaction();

        // Retain the provider references and wallet holds needed to prevent duplicate transfers.
        Database::fetchAll("SELECT id FROM users WHERE id IN ({$placeholders}) FOR UPDATE", $params);
        $financial = Database::fetchOne("SELECT id FROM payments WHERE user_id IN ({$placeholders}) AND gateway IN ('abliner','snippe') LIMIT 1", $params)
            ?: Database::fetchOne("SELECT id FROM payouts WHERE user_id IN ({$placeholders}) AND gateway = 'abliner' LIMIT 1", $params);
        if ($financial) throw new RuntimeException('Accounts with Abliner or Snippe transactions must be suspended rather than deleted; financial records are retained.');

        // Wallet transactions for these users' wallets (subquery targets a different table)
        safeUserDelete('wallet_transactions', "wallet_id IN (SELECT id FROM wallets WHERE user_id IN ({$placeholders}))", $params);
        // Payouts for these users' withdrawals
        safeUserDelete('payouts', "withdrawal_id IN (SELECT id FROM withdrawals WHERE user_id IN ({$placeholders}))", $params);
        safeUserDelete('user_notifications', "user_id IN ({$placeholders})", $params);
        // Direct references
        safeUserDelete('commissions', "earner_id IN ({$placeholders}) OR source_user_id IN ({$placeholders})", array_merge($params, $params));
        safeUserDelete('payments', "user_id IN ({$placeholders})", $params);
        safeUserDelete('withdrawals', "user_id IN ({$placeholders})", $params);
        safeUserDelete('wallets', "user_id IN ({$placeholders})", $params);
        safeUserDelete('referrals', "referrer_id IN ({$placeholders}) OR referred_id IN ({$placeholders})", array_merge($params, $params));
        safeUserDelete('user_otps', "user_id IN ({$placeholders})", $params);
        safeUserDelete('activity_logs', "user_id IN ({$placeholders})", $params);
        safeUserDelete('user_sessions', "user_id IN ({$placeholders})", $params);
        safeUserDelete('error_logs', "user_id IN ({$placeholders})", $params);
        safeUserDelete('sms_logs', "user_id IN ({$placeholders})", $params);
        // Users last — remaining FK ON DELETE CASCADE tables get cleaned here
        safeUserDelete('users', "id IN ({$placeholders})", $params);

        Database::commit();
        $deleted = count($ids);
    } catch (Throwable $e) {
        Database::rollback();
        error_log("Batch delete users error: " . $e->getMessage());
        ErrorLogger::logException($e, 'system', null, 'admin/users.php');
    }

    return $deleted;
}

/**
 * DELETE from a table, ignoring the case where the table doesn't exist on an
 * older deployment so one missing table can't block the whole batch.
 */
function safeUserDelete(string $table, string $where, array $params): void {
    try {
        Database::delete($table, $where, $params);
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02' || stripos($e->getMessage(), "doesn't exist") !== false || stripos($e->getMessage(), 'unknown table') !== false) {
            return; // optional table missing — skip
        }
        throw $e;
    }
}

$search = trim($_GET['search'] ?? '');
$status = $canViewPrivateUserColumns ? ($_GET['status'] ?? '') : '';
$year   = preg_match('/^\d{4}$/', trim($_GET['year'] ?? '')) ? trim($_GET['year']) : '';
$page = getCurrentPage();
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Available registration years for the filter dropdown
$availableYears = Database::fetchAll(
    "SELECT DISTINCT YEAR(created_at) AS yr FROM users WHERE role = 'user' AND created_at IS NOT NULL ORDER BY yr DESC"
);

// Build query
[$where, $params] = buildUserFilterWhere($search, $status, $year);

$total = Database::count('users', $where, $params);

$userFields = 'u.id, u.full_name, u.phone, u.email, u.referral_code, u.created_at';
if ($canViewPrivateUserColumns || $canManageUsers) $userFields .= ', u.status';
if ($canViewPrivateUserColumns) $userFields .= ', (SELECT COUNT(*) FROM users WHERE referred_by = u.id) as direct_referrals';
if ($canViewEarnings) $userFields .= ', '.Wallet::earningsProjection('u.id').' as total_earned';
if ($canViewPayments) $userFields .= ", (SELECT COALESCE(SUM(amount),0) FROM payments WHERE user_id = u.id AND purpose = 'registration' AND status = 'completed') as paid_amount,
            (SELECT MAX(completed_at) FROM payments WHERE user_id = u.id AND purpose = 'registration' AND status = 'completed') as paid_date";
$users = Database::fetchAll(
    "SELECT {$userFields}
     FROM users u
     WHERE {$where}
     ORDER BY u.created_at DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$paidSummary = $canViewDashboard ? Database::fetchOne(
    "SELECT COALESCE(SUM(p.amount),0) AS total_paid, COUNT(DISTINCT p.user_id) AS paid_users
     FROM payments p WHERE p.status = 'completed' AND p.purpose = 'registration'
     AND EXISTS (SELECT 1 FROM users WHERE id = p.user_id AND {$where})",
    $params
) : null;

$csrf = Auth::generateCSRF();
$pageTitle = 'Users';
include __DIR__ . '/admin_header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1><i class="fas fa-users me-2" style="color:var(--primary);"></i>Users</h1>
        <p>Manage all system users (<?= number_format($total) ?>)</p>
    </div>
</div>

<style>
.users-paid-summary { background:#15803d; color:#fff; padding:1.25rem; margin-bottom:1.5rem; }
.users-paid-summary strong { display:block; font-size:1.75rem; }
.table > tbody > tr.user-paid > td { background-color:#dcfce7; --bs-table-bg:#dcfce7; --bs-table-accent-bg:transparent; }
.payment-paid-label { display:inline-block; background:#15803d; color:#fff; padding:0.25rem 0.65rem; font-weight:800; }
.payment-paid-total { display:block; color:#14532d; font-weight:800; margin-top:0.35rem; white-space:nowrap; }
.payment-paid-date { color:#166534; font-size:0.75rem; }
</style>
<?php if ($canViewDashboard): ?>
<div class="users-paid-summary" role="status">
    <div><i class="fas fa-check-circle me-1"></i> Total Paid</div>
    <strong><?= formatCurrency((float) $paidSummary['total_paid']) ?></strong>
    <div><?= number_format((int) $paidSummary['paid_users']) ?> paid users · completed registration payments matching your filters</div>
</div>
<?php endif; ?>

<?php displayFlash(); ?>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="<?= $canViewPrivateUserColumns ? 'col-md-4' : 'col-md-7' ?>">
                <input type="text" class="form-control" name="search" placeholder="Search name, phone, email, or code..." value="<?= sanitize($search) ?>">
            </div>
            <?php if ($canViewPrivateUserColumns): ?><div class="col-md-3">
                <select class="form-select" name="status">
                    <option value="">All Status</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="suspended" <?= $status === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-3">
                <input type="text" class="form-control" name="year" list="yearOptions" inputmode="numeric" maxlength="4" placeholder="All Years" value="<?= $year !== '' ? sanitize($year) : '' ?>">
                <datalist id="yearOptions">
                    <?php foreach ($availableYears as $yr): ?>
                        <option value="<?= (int)$yr['yr'] ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search me-1"></i> Search</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Delete Form -->
<?php if ($canManageUsers): ?>
<form method="POST" id="bulkDeleteForm">
    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="delete_selected">
    <input type="hidden" name="select_all_mode" id="selectAllMode" value="0">
    <input type="hidden" name="filter_search" value="<?= sanitize($search) ?>">
    <input type="hidden" name="filter_status" value="<?= sanitize($status) ?>">
    <input type="hidden" name="filter_year" value="<?= sanitize($year) ?>">

    <?php if (!empty($users)): ?>
    <div class="card mb-3" style="display:none;" id="bulkActions">
        <div class="card-body py-2 d-flex align-items-center flex-wrap" style="gap:0.5rem;">
            <input type="checkbox" id="selectAll" class="form-check-input me-2">
            <label for="selectAll" class="me-2" style="font-size:0.85rem;font-weight:700;">
                Select All (<span id="selectedCount">0</span> selected)
            </label>
            <span class="text-muted me-2" style="font-size:0.8rem;">
                <i class="fas fa-info-circle me-1"></i>Selects all <?= number_format($total) ?> matching user(s), not just this page
            </span>
            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirmDelete();">
                <i class="fas fa-trash me-1"></i> Delete Selected
            </button>
        </div>
    </div>
    <?php endif; ?>
</form>
<?php endif; ?>

    <!-- Users Table -->
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <?php if ($canManageUsers): ?><th style="width:40px;"><input type="checkbox" class="form-check-input" id="selectAllHeader"></th><?php endif; ?>
                            <th>#</th>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Code</th>
                            <?php if ($canViewPrivateUserColumns): ?><th>Referrals</th><?php endif; ?>
                            <?php if ($canViewEarnings): ?><th>Earnings</th><?php endif; ?>
                            <?php if ($canViewPayments): ?><th>Payment</th><?php endif; ?>
                            <?php if ($canViewPrivateUserColumns): ?><th>Status</th><?php endif; ?>
                            <th>Date</th>
                            <?php if ($canManageUsers || $canViewErrorLogs): ?><th>Action</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $i => $u): ?>
                        <tr class="<?= $canViewPayments && (float) $u['paid_amount'] > 0 ? 'user-paid' : '' ?>">
                            <?php if ($canManageUsers): ?><td><input type="checkbox" name="user_ids[]" value="<?= $u['id'] ?>" class="form-check-input item-checkbox" form="bulkDeleteForm"></td><?php endif; ?>
                            <td><?= $offset + $i + 1 ?></td>
                            <td>
                                <strong><?= sanitize($u['full_name']) ?></strong>
                                <?php if ($u['email']): ?>
                                    <br><small style="color:#9ca3af;"><?= sanitize($u['email']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= formatPhone($u['phone']) ?></td>
                            <td><code style="font-size:0.75rem;"><?= sanitize($u['referral_code']) ?></code></td>
                            <?php if ($canViewPrivateUserColumns): ?><td><strong><?= number_format($u['direct_referrals']) ?></strong></td><?php endif; ?>
                            <?php if ($canViewEarnings): ?><td><strong style="color:#10b981;"><?= formatCurrency($u['total_earned']) ?></strong></td><?php endif; ?>
                            <?php if ($canViewPayments): ?>
                            <td>
                                <?php if ((float) $u['paid_amount'] > 0): ?>
                                    <span class="payment-paid-label"><i class="fas fa-check-circle me-1"></i> Paid</span>
                                    <span class="payment-paid-total">Total paid: <?= formatCurrency((float) $u['paid_amount']) ?></span>
                                    <?php if ($u['paid_date']): ?><small class="payment-paid-date"><?= date('d M Y', strtotime($u['paid_date'])) ?></small><?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:0.85rem;">Unpaid</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <?php if ($canViewPrivateUserColumns): ?><td><?= statusBadge($u['status']) ?>
                                <?php try {$loginLock=AccountLoginLock::status((int)$u['id']);}catch(Throwable $e){$loginLock=['permanent'=>true,'until'=>0];} ?>
                                <?php if (AccountLoginLock::blocked($loginLock)): ?><small class="d-block text-danger">Login locked<?= !empty($loginLock['permanent'])?' — admin unlock required':' until '.date('d M H:i',(int)$loginLock['until']) ?></small><?php endif; ?>
                            </td><?php endif; ?>
                            <td><small><?= date('d M Y', strtotime($u['created_at'])) ?></small></td>
                            <?php if ($canManageUsers || $canViewErrorLogs): ?><td>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                        <i class="fas fa-ellipsis-v"></i>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <?php if (StaffAccess::isAdministrator()): ?>
                                        <li><form method="POST" onsubmit="return confirm('Remove the password lock for this user?')">
                                            <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                                            <input type="hidden" name="action" value="unlock_login">
                                            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                            <button class="dropdown-item" type="submit"><i class="fas fa-unlock me-1"></i> Unlock login</button>
                                        </form></li>
                                        <?php endif; ?>
                                        <?php if ($canManageUsers): ?>
                                        <li><h6 class="dropdown-header">Change Status</h6></li>
                                        <?php foreach (['active' => 'Activate', 'pending' => 'Pending', 'suspended' => 'Suspend'] as $val => $label): ?>
                                        <?php if ($u['status'] !== $val && ($val !== 'active' || $canManagePayments)): ?>
                                        <li>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <input type="hidden" name="new_status" value="<?= $val ?>">
                                                <button type="submit" class="dropdown-item <?= $val === 'suspended' ? 'text-danger' : ($val === 'active' ? 'text-success' : '') ?>">
                                                    <i class="fas fa-<?= $val === 'active' ? 'check' : ($val === 'suspended' ? 'ban' : 'clock') ?> me-1"></i> <?= $label ?>
                                                </button>
                                            </form>
                                        </li>
                                        <?php endif; ?>
                                        <?php endforeach; ?>
                                        <?php if ($canManagePayments): ?>
                                        <?php if ((float) $u['paid_amount'] > 0): ?>
                                        <li>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Mark <?= sanitize($u['full_name']) ?> as UNPAID? TZS <?= number_format((float)$u['paid_amount']) ?> will be removed from their account wallet.')">
                                                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                                                <input type="hidden" name="action" value="mark_as_unpaid">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <button type="submit" class="dropdown-item text-danger">
                                                    <i class="fas fa-undo me-1"></i> Mark as Unpaid
                                                </button>
                                            </form>
                                        </li>
                                        <?php else: ?>
                                        <li>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Mark <?= sanitize($u['full_name']) ?> as PAID — TZS <?= number_format((int)app_setting('registration_fee', REGISTRATION_FEE)) ?>?')">
                                                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                                                <input type="hidden" name="action" value="mark_as_paid">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <button type="submit" class="dropdown-item text-success">
                                                    <i class="fas fa-check-circle me-1"></i> Mark as Paid
                                                </button>
                                            </form>
                                        </li>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <?php if ($canViewErrorLogs): ?>
                                    <li>
                                        <a class="dropdown-item" href="<?= SITE_URL ?>/admin/error-logs?<?= http_build_query(['user_id' => $u['id']]) ?>">
                                            <i class="fas fa-bug me-1"></i> Error Logs
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                    <?php if ($canManageUsers): ?>
                                    <li>
                                        <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#emailModal" data-user-id="<?= $u['id'] ?>" data-user-name="<?= sanitize($u['full_name']) ?>" data-user-email="<?= sanitize($u['email'] ?? '') ?>">
                                            <i class="fas fa-envelope me-1"></i> Edit Email
                                        </button>
                                    </li>
                                    <li>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this user permanently? All related data will be removed.')">
                                                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                                                <input type="hidden" name="action" value="delete_single">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <button type="submit" class="dropdown-item text-danger">
                                                    <i class="fas fa-trash me-1"></i> Delete
                                                </button>
                                            </form>
                                        </li>
                                    <?php endif; ?>
                                    </ul>
                                </div>
                            </td><?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="<?= 5 + 2 * (int) $canViewPrivateUserColumns + (int) $canManageUsers + (int) $canViewEarnings + (int) $canViewPayments + (int) ($canManageUsers || $canViewErrorLogs) ?>" class="text-center py-4">
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
    <?= paginate($total, $page, $perPage, SITE_URL . '/admin/users?' . http_build_query(['search' => $search, 'status' => $status, 'year' => $year, 'page' => ''])) ?>
</div>

<!-- Email Edit Modal -->
<?php if ($canManageUsers): ?>
<div class="modal fade" id="emailModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="update_email">
                <input type="hidden" name="user_id" id="emailUserId">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="fas fa-envelope me-1"></i> Edit Email — <span id="emailUserName"></span></h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label" style="font-weight:700;">New Email Address</label>
                    <input type="email" class="form-control" name="new_email" id="emailInput" required placeholder="user@example.com">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const selectAll = document.getElementById('selectAll');
const selectAllHeader = document.getElementById('selectAllHeader');
const checkboxes = document.querySelectorAll('.item-checkbox');
const bulkActions = document.getElementById('bulkActions');
const selectedCount = document.getElementById('selectedCount');
const selectAllMode = document.getElementById('selectAllMode');
const totalMatching = <?= (int) $total ?>;

function updateBulkUI() {
    if (!selectAllMode || !selectedCount || !bulkActions) return;
    const checked = document.querySelectorAll('.item-checkbox:checked').length;
    const modeOn = selectAllMode.value === '1';
    selectedCount.textContent = modeOn ? totalMatching.toLocaleString() : checked;
    bulkActions.style.display = (checked > 0 || modeOn) ? 'block' : 'none';
}

function confirmDelete() {
    const countText = selectAllMode.value === '1'
        ? totalMatching.toLocaleString() + ' matching user(s)'
        : document.querySelectorAll('.item-checkbox:checked').length + ' user(s)';
    return confirm('Delete ' + countText + ' permanently? All their data will be removed.');
}

function setSelectAll(on) {
    selectAllMode.value = on ? '1' : '0';
    checkboxes.forEach(cb => cb.checked = on);
    if (selectAll) selectAll.checked = on;
    if (selectAllHeader) selectAllHeader.checked = on;
    updateBulkUI();
}

selectAll?.addEventListener('change', () => setSelectAll(selectAll.checked));
selectAllHeader?.addEventListener('change', () => setSelectAll(selectAllHeader.checked));

checkboxes.forEach(cb => cb.addEventListener('change', () => {
    if (!cb.checked) {
        selectAllMode.value = '0';
        if (selectAll) selectAll.checked = false;
        if (selectAllHeader) selectAllHeader.checked = false;
    }
    updateBulkUI();
}));

// Email modal populate
document.getElementById('emailModal')?.addEventListener('show.bs.modal', function(event) {
    const btn = event.relatedTarget;
    document.getElementById('emailUserId').value = btn.dataset.userId;
    document.getElementById('emailUserName').textContent = btn.dataset.userName;
    document.getElementById('emailInput').value = btn.dataset.userEmail || '';
});
</script>

<?php include __DIR__ . '/admin_footer.php'; ?>
