<?php
/**
 * EarnSphere - Transaction History
 * Complete wallet transaction history with pagination
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Wallet.php';
require_once __DIR__ . '/includes/helpers.php';

Auth::initSession();
Auth::requireAuth();

$page = getCurrentPage();
$filter = $_GET['filter'] ?? 'all';

$whereClause = 'user_id = ?';
$params = [$_SESSION['user_id']];

if ($filter === 'credit') {
    $whereClause .= ' AND amount > 0';
} elseif ($filter === 'debit') {
    $whereClause .= ' AND amount < 0';
} elseif ($filter !== 'all') {
    $whereClause .= ' AND type = ?';
    $params[] = $filter;
}

$total = Database::count('wallet_transactions', $whereClause, $params);

$transactions = Database::fetchAll(
    "SELECT * FROM wallet_transactions WHERE {$whereClause} ORDER BY created_at DESC LIMIT 20 OFFSET " . (($page - 1) * 20),
    $params
);

$pageTitle = 'Transaction History';
$baseUrl = SITE_URL . '/transactions?filter=' . $filter . '&';
include __DIR__ . '/includes/public_head.php';
?>

<div class="dash-header">
    <div class="top-bar">
        <a href="dashboard" style="color:white;text-decoration:none;">
            <i class="fas fa-arrow-left"></i>
        </a>
        <h5 class="mb-0" style="font-weight:800;">Transactions</h5>
        <div></div>
    </div>
</div>

<div class="dash-content mb-safe">
    
    <!-- Filter Tabs -->
    <div class="d-flex gap-2 mb-3" style="overflow-x:auto;padding-bottom:4px;">
        <a href="?filter=all" class="btn <?= $filter === 'all' ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm">All</a>
        <a href="?filter=credit" class="btn <?= $filter === 'credit' ? 'btn-success' : 'btn-outline-success' ?> btn-sm">Income</a>
        <a href="?filter=debit" class="btn <?= $filter === 'debit' ? 'btn-danger' : 'btn-outline-danger' ?> btn-sm">Expenses</a>
        <a href="?filter=commission" class="btn <?= $filter === 'commission' ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm">Commission</a>
        <a href="?filter=withdrawal" class="btn <?= $filter === 'withdrawal' ? 'btn-primary' : 'btn-outline-primary' ?> btn-sm">Withdrawals</a>
    </div>
    
    <!-- Transactions List -->
    <?php if (empty($transactions)): ?>
        <div class="empty-state">
            <i class="fas fa-receipt"></i>
            <h5>No Transactions</h5>
            <p>Financial activity will appear here.</p>
        </div>
    <?php else: ?>
        <?php foreach ($transactions as $tx): ?>
            <div class="list-item">
                <div class="item-icon" style="background:<?= $tx['amount'] > 0 ? '#ecfdf5' : '#fef2f2' ?>; color:<?= $tx['amount'] > 0 ? 'var(--secondary)' : 'var(--danger)' ?>;">
                    <i class="fas fa-<?= match($tx['type']) {
                        'commission' => 'percentage',
                        'referral_bonus' => 'users',
                        'withdrawal' => 'money-bill-wave',
                        'admin_adjustment' => 'tools',
                        'registration_bonus' => 'gift',
                        default => 'exchange-alt'
                    } ?>"></i>
                </div>
                <div class="item-info">
                    <p class="item-title"><?= sanitize($tx['description'] ?: ucfirst(str_replace('_', ' ', $tx['type']))) ?></p>
                    <p class="item-subtitle">
                        <?= date('d M Y, H:i', strtotime($tx['created_at'])) ?>
                        <?php if ($tx['status'] !== 'completed'): ?>
                            <span class="badge bg-warning text-dark" style="font-size:0.6rem;"><?= $tx['status'] ?></span>
                        <?php endif; ?>
                    </p>
                </div>
                <div style="text-align:right;">
                    <div class="item-amount <?= $tx['amount'] > 0 ? 'credit' : 'debit' ?>">
                        <?= $tx['amount'] > 0 ? '+' : '' ?><?= formatCurrency(abs($tx['amount'])) ?>
                    </div>
                    <div style="font-size:0.7rem;color:var(--gray-400);">
                        Balance: <?= formatCurrency($tx['balance_after']) ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        
        <!-- Pagination -->
        <div class="mt-3">
            <?= paginate($total, $page, 20, $baseUrl) ?>
        </div>
    <?php endif; ?>
</div>

<!-- Bottom Navigation -->
<nav class="mobile-nav">
    <a href="dashboard" class="nav-item">
        <i class="fas fa-home"></i>
        <span>Home</span>
    </a>
    <a href="referrals" class="nav-item">
        <i class="fas fa-users"></i>
        <span>Referrals</span>
    </a>
    <a href="wallet" class="nav-item center-action">
        <i class="fas fa-wallet"></i>
        <span>Wallet</span>
    </a>
    <a href="transactions" class="nav-item active">
        <i class="fas fa-receipt"></i>
        <span>History</span>
    </a>
    <a href="profile" class="nav-item">
        <i class="fas fa-user"></i>
        <span>Profile</span>
    </a>
</nav>

<?php include __DIR__ . '/includes/public_foot.php'; ?>
