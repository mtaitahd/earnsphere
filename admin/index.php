<?php
/**
 * EarnSphere - Admin Dashboard
 * Matching Mtaita Tech design: stat cards with left accent stripe
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/classes/CommissionEngine.php';
require_once dirname(__DIR__) . '/classes/Wallet.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

Auth::initSession();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ' . SITE_URL . '/admin/login');
    exit;
}

// Get stats
$totalUsers = Database::count('users', "role = 'user'");
$activeUsers = Database::count('users', "role = 'user' AND status = 'active'");
$pendingUsers = Database::count('users', "role = 'user' AND status = 'pending'");
$todayRegistrations = Database::count('users', "role = 'user' AND DATE(created_at) = CURDATE()");

$totalPayments = Database::fetchOne("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE status = 'completed'")['total'] ?? 0;
$todayPayments = Database::fetchOne("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE status = 'completed' AND DATE(completed_at) = CURDATE()")['total'] ?? 0;

$totalCommissions = Database::fetchOne("SELECT COALESCE(SUM(amount),0) as total FROM commissions WHERE status != 'cancelled'")['total'] ?? 0;
$pendingWithdrawals = Database::count('withdrawals', "status = 'pending'");

$totalPayouts = Database::fetchOne("SELECT COALESCE(SUM(amount),0) as total FROM payouts WHERE status = 'completed'")['total'] ?? 0;
$totalPayoutFees = Database::fetchOne("SELECT COALESCE(SUM(fees),0) as total FROM payouts WHERE status = 'completed'")['total'] ?? 0;
$completedPayouts = Database::count('payouts', "status = 'completed'");
$processingPayouts = Database::count('payouts', "status = 'pending'");
$failedPayouts = Database::count('payouts', "status = 'failed'");

$commissions = CommissionEngine::getCommissionSummary();

$recentUsers = Database::fetchAll(
    "SELECT id, full_name, phone, status, referral_code, created_at FROM users WHERE role = 'user' ORDER BY created_at DESC LIMIT 10"
);

$recentPayments = Database::fetchAll(
    "SELECT p.*, u.full_name FROM payments p JOIN users u ON p.user_id = u.id ORDER BY p.created_at DESC LIMIT 10"
);

$pageTitle = 'Dashboard';
include __DIR__ . '/admin_header.php';
?>

<!-- Page Header -->
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0" style="font-weight:700;">
            <i class="fas fa-tachometer-alt me-2" style="color:var(--accent);"></i>Dashboard
        </h1>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0.25rem 0 0;">Modern referral system — EarnSphere</p>
    </div>
    <div>
        <span class="badge badge-success" style="font-size:0.75rem;padding:0.5rem 1rem;">
            <i class="fas fa-circle me-1" style="font-size:0.4rem;vertical-align:middle;"></i> System Online
        </span>
    </div>
</div>

<!-- Stat Cards - Row 1 -->
<div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 row-cols-xl-6 g-3 mb-4">
    <div class="col">
        <a href="users" class="stat-card" style="text-decoration:none;color:inherit;">
            <div class="stat-icon" style="background:var(--accent-dim);color:var(--accent);">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-info">
                <h3><?= number_format($totalUsers) ?></h3>
                <p>Total Users</p>
            </div>
        </a>
    </div>
    <div class="col">
        <a href="users" class="stat-card" style="text-decoration:none;color:inherit;">
            <div class="stat-icon" style="background:rgba(28,200,138,0.12);color:var(--success);">
                <i class="fas fa-user-check"></i>
            </div>
            <div class="stat-info">
                <h3><?= number_format($activeUsers) ?></h3>
                <p>Active Users</p>
            </div>
        </a>
    </div>
    <div class="col">
        <a href="payments" class="stat-card" style="text-decoration:none;color:inherit;">
            <div class="stat-icon" style="background:rgba(78,115,223,0.12);color:#4E73DF;">
                <i class="fas fa-money-bill-wave"></i>
            </div>
            <div class="stat-info">
                <h3><?= formatCurrency($totalPayments) ?></h3>
                <p>Total Payments</p>
            </div>
        </a>
    </div>
    <div class="col">
        <a href="withdrawals" class="stat-card" style="text-decoration:none;color:inherit;">
            <div class="stat-icon" style="background:rgba(231,74,59,0.12);color:var(--danger);">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stat-info">
                <h3><?= $pendingWithdrawals ?></h3>
                <p>Pending Withdrawals</p>
            </div>
        </a>
    </div>
    <div class="col">
        <a href="commissions" class="stat-card" style="text-decoration:none;color:inherit;">
            <div class="stat-icon" style="background:rgba(246,194,62,0.12);color:#F6C23E;">
                <i class="fas fa-coins"></i>
            </div>
            <div class="stat-info">
                <h3><?= formatCurrency($totalCommissions) ?></h3>
                <p>Total Commissions</p>
            </div>
        </a>
    </div>
    <div class="col">
        <a href="users" class="stat-card" style="text-decoration:none;color:inherit;">
            <div class="stat-icon" style="background:rgba(54,185,204,0.12);color:#36B9CC;">
                <i class="fas fa-user-plus"></i>
            </div>
            <div class="stat-info">
                <h3><?= $todayRegistrations ?></h3>
                <p>New Today</p>
            </div>
        </a>
    </div>
</div>

<!-- Quick Actions -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="fas fa-bolt me-1" style="color:var(--accent);"></i> Quick Actions</h6>
    </div>
    <div class="card-body" style="padding:1rem 1.25rem;">
        <div class="d-flex flex-wrap gap-2">
            <a href="users" class="btn btn-cyan btn-sm">
                <i class="fas fa-users me-1"></i> Manage Users
            </a>
            <a href="payments" class="btn btn-outline-cyan btn-sm">
                <i class="fas fa-credit-card me-1"></i> Payments
            </a>
            <a href="withdrawals" class="btn btn-outline-cyan btn-sm">
                <i class="fas fa-money-bill-wave me-1"></i> Withdrawals
                <?php if ($pendingWd > 0): ?>
                    <span class="badge badge-danger ms-1" style="font-size:0.6rem;"><?= $pendingWd ?></span>
                <?php endif; ?>
            </a>
            <a href="commissions" class="btn btn-outline-cyan btn-sm">
                <i class="fas fa-coins me-1"></i> Commissions
            </a>
            <a href="settings" class="btn btn-outline-cyan btn-sm">
                <i class="fas fa-cog me-1"></i> Settings
            </a>
        </div>
    </div>
</div>

<!-- Second Row: Commission Breakdown -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card" style="margin-bottom:0;">
            <div class="card-body text-center">
                <div style="font-size:1.75rem;font-weight:700;color:var(--accent);"><?= formatCurrency($commissions['total_l1'] ?? 0) ?></div>
                <div style="font-size:0.8rem;color:var(--text-muted);font-weight:500;">Commission L1</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card" style="margin-bottom:0;">
            <div class="card-body text-center">
                <div style="font-size:1.75rem;font-weight:700;color:var(--success);"><?= formatCurrency($commissions['total_l2'] ?? 0) ?></div>
                <div style="font-size:0.8rem;color:var(--text-muted);font-weight:500;">Commission L2</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card" style="margin-bottom:0;">
            <div class="card-body text-center">
                <div style="font-size:1.75rem;font-weight:700;color:#F6C23E;"><?= formatCurrency($commissions['total_l3'] ?? 0) ?></div>
                <div style="font-size:0.8rem;color:var(--text-muted);font-weight:500;">Commission L3</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card" style="margin-bottom:0;">
            <div class="card-body text-center">
                <div style="font-size:1.75rem;font-weight:700;color:var(--danger);"><?= formatCurrency($commissions['pending_payouts'] ?? 0) ?></div>
                <div style="font-size:0.8rem;color:var(--text-muted);font-weight:500;">Pending</div>
            </div>
        </div>
    </div>
</div>

<!-- Third Row: Payouts -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card" style="margin-bottom:0;">
            <div class="card-body text-center">
                <div style="font-size:1.5rem;font-weight:700;color:var(--accent);"><?= formatCurrency($totalPayouts) ?></div>
                <div style="font-size:0.8rem;color:var(--text-muted);font-weight:500;">Completed Payouts</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card" style="margin-bottom:0;">
            <div class="card-body text-center">
                <div style="font-size:1.5rem;font-weight:700;color:#F6C23E;"><?= formatCurrency($totalPayoutFees) ?></div>
                <div style="font-size:0.8rem;color:var(--text-muted);font-weight:500;">Payout Fees</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card" style="margin-bottom:0;">
            <div class="card-body text-center">
                <div style="font-size:1.5rem;font-weight:700;color:var(--success);"><?= $completedPayouts ?></div>
                <div style="font-size:0.8rem;color:var(--text-muted);font-weight:500;">Payouts Completed</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card" style="margin-bottom:0;">
            <div class="card-body text-center">
                <div style="font-size:1.5rem;font-weight:700;color:var(--danger);"><?= $failedPayouts ?></div>
                <div style="font-size:0.8rem;color:var(--text-muted);font-weight:500;">Payouts Failed</div>
            </div>
        </div>
    </div>
</div>

<!-- Today + Summary -->
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card" style="margin-bottom:0;">
            <div class="card-header"><h6 class="mb-0"><i class="fas fa-calendar-day me-1"></i> Today</h6></div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span style="color:var(--text-muted);">New Users</span>
                    <strong style="color:var(--accent);"><?= $todayRegistrations ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span style="color:var(--text-muted);">Payments</span>
                    <strong style="color:var(--success);"><?= formatCurrency($todayPayments) ?></strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span style="color:var(--text-muted);">Pending Users</span>
                    <strong style="color:#F6C23E;"><?= $pendingUsers ?></strong>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card" style="margin-bottom:0;">
            <div class="card-header"><h6 class="mb-0"><i class="fas fa-chart-bar me-1"></i> Summary</h6></div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span style="color:var(--text-muted);">Total Commission</span>
                    <strong><?= formatCurrency($totalCommissions) ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span style="color:var(--text-muted);">Company Revenue</span>
                    <strong style="color:var(--success);"><?= formatCurrency(($totalPayments ?? 0) - ($totalCommissions ?? 0)) ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span style="color:var(--text-muted);">Total Payout (Money Sent)</span>
                    <strong style="color:var(--danger);"><?= formatCurrency($totalPayouts) ?></strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span style="color:var(--text-muted);">Pending Requests</span>
                    <strong style="color:var(--danger);"><?= $pendingWithdrawals ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Users + Payments -->
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card" style="margin-bottom:0;">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-users me-1"></i> New Users</h6>
                <a href="users" style="font-size:0.8rem;font-weight:700;color:var(--accent);">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentUsers as $u): ?>
                            <tr>
                                <td>
                                    <strong><?= sanitize($u['full_name']) ?></strong>
                                    <br><small style="color:var(--text-muted);"><?= $u['referral_code'] ?></small>
                                </td>
                                <td><?= formatPhone($u['phone']) ?></td>
                                <td><?= statusBadge($u['status']) ?></td>
                                <td><small style="color:var(--text-muted);"><?= timeAgo($u['created_at']) ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card" style="margin-bottom:0;">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-credit-card me-1"></i> Recent Payments</h6>
                <a href="payments" style="font-size:0.8rem;font-weight:700;color:var(--accent);">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentPayments as $p): ?>
                            <tr>
                                <td><strong><?= sanitize($p['full_name']) ?></strong></td>
                                <td><strong><?= formatCurrency($p['amount']) ?></strong></td>
                                <td><?= statusBadge($p['status']) ?></td>
                                <td><small style="color:var(--text-muted);"><?= timeAgo($p['created_at']) ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/admin_footer.php'; ?>
