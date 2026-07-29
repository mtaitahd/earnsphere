<?php
/**
 * EarnSphere - Admin Header
 * Matching Mtaita Tech design system: white sidebar, purple topbar
 */

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ' . SITE_URL . '/admin/login');
    exit;
}

require_once dirname(__DIR__) . '/includes/security_headers.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
Auth::initSession();

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$adminUser = Database::fetchOne("SELECT full_name, avatar FROM users WHERE id = ?", [$_SESSION['user_id']]);

$pendingWd = Database::count('withdrawals', 'status = ?', ['pending']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Admin' ?> | EarnSphere Admin</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= SITE_URL ?>/assets/img/logoo.png">
    
    <!-- CSRF Token for AJAX -->
    <meta name="csrf-token" content="<?= Auth::generateCSRF() ?>">
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Admin CSS -->
    <link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/admin.css">
</head>
<body id="page-top">
<div id="wrapper">

<!-- Sidebar -->
<ul class="navbar-nav sidebar sidebar-light accordion" id="accordionSidebar">
    <!-- Brand -->
    <a class="sidebar-brand d-flex align-items-center justify-content-center" href="<?= SITE_URL ?>/admin/index">
        <div class="sidebar-brand-icon">
            <i class="fas fa-gem"></i>
        </div>
        <div class="sidebar-brand-text mx-3">EarnSphere</div>
    </a>
    <hr class="sidebar-divider my-0">
    
    <!-- Nav Item - Dashboard -->
    <li class="nav-item <?= $currentPage === 'index' ? 'active' : '' ?>">
        <a class="nav-link" href="<?= SITE_URL ?>/admin/index">
            <i class="fas fa-fw fa-tachometer-alt"></i>
            <span>Dashboard</span>
        </a>
    </li>
    
    <hr class="sidebar-divider">
    <div class="sidebar-heading">Manage</div>
    
    <!-- Users -->
    <li class="nav-item <?= $currentPage === 'users' ? 'active' : '' ?>">
        <a class="nav-link" href="<?= SITE_URL ?>/admin/users">
            <i class="fas fa-fw fa-users"></i>
            <span>Users</span>
        </a>
    </li>
    
    <!-- Payments -->
    <li class="nav-item <?= $currentPage === 'payments' ? 'active' : '' ?>">
        <a class="nav-link" href="<?= SITE_URL ?>/admin/payments">
            <i class="fas fa-fw fa-credit-card"></i>
            <span>Payments</span>
        </a>
    </li>
    
    <!-- Commissions -->
    <li class="nav-item <?= $currentPage === 'commissions' ? 'active' : '' ?>">
        <a class="nav-link" href="<?= SITE_URL ?>/admin/commissions">
            <i class="fas fa-fw fa-coins"></i>
            <span>Commissions</span>
        </a>
    </li>
    
    <!-- Withdrawals -->
    <li class="nav-item <?= $currentPage === 'withdrawals' ? 'active' : '' ?>">
        <a class="nav-link" href="<?= SITE_URL ?>/admin/withdrawals">
            <i class="fas fa-fw fa-money-bill-wave"></i>
            <span>Withdrawals</span>
            <?php if ($pendingWd > 0): ?>
                <span class="badge badge-danger badge-pill" style="font-size:0.65rem;"><?= $pendingWd ?></span>
            <?php endif; ?>
        </a>
    </li>
    
    <!-- Referral Tree -->
    <li class="nav-item <?= $currentPage === 'referral-tree' ? 'active' : '' ?>">
        <a class="nav-link" href="<?= SITE_URL ?>/admin/referral-tree">
            <i class="fas fa-fw fa-sitemap"></i>
            <span>Referral Network</span>
        </a>
    </li>
    
    <hr class="sidebar-divider">
    <div class="sidebar-heading">Settings</div>
    
    <!-- Settings -->
    <li class="nav-item <?= $currentPage === 'settings' ? 'active' : '' ?>">
        <a class="nav-link" href="<?= SITE_URL ?>/admin/settings">
            <i class="fas fa-fw fa-cog"></i>
            <span>Settings</span>
        </a>
    </li>
    
    <!-- Profile -->
    <li class="nav-item <?= $currentPage === 'profile' ? 'active' : '' ?>">
        <a class="nav-link" href="<?= SITE_URL ?>/admin/profile">
            <i class="fas fa-fw fa-user-cog"></i>
            <span>Profile</span>
        </a>
    </li>
    
    <!-- Activity Logs -->
    <li class="nav-item <?= $currentPage === 'logs' ? 'active' : '' ?>">
        <a class="nav-link" href="<?= SITE_URL ?>/admin/logs">
            <i class="fas fa-fw fa-list-alt"></i>
            <span>Activity Logs</span>
        </a>
    </li>
    
    <!-- Error Logs -->
    <li class="nav-item <?= $currentPage === 'error-logs' ? 'active' : '' ?>">
        <a class="nav-link" href="<?= SITE_URL ?>/admin/error-logs">
            <i class="fas fa-fw fa-bug"></i>
            <span>Error Logs</span>
        </a>
    </li>
    
    <hr class="sidebar-divider">
    
    <!-- Back to Site -->
    <li class="nav-item">
        <a class="nav-link" href="<?= SITE_URL ?>/dashboard" target="_blank">
            <i class="fas fa-fw fa-external-link-alt"></i>
            <span>View Site</span>
        </a>
    </li>
    
    <!-- Sidebar Footer -->
    <div class="sidebar-footer" style="padding: 0.75rem 1.25rem; margin-top: auto;">
        <hr class="sidebar-divider mb-2">
        <a class="nav-link" href="<?= SITE_URL ?>/logout" style="padding:0.5rem 0;color:var(--danger);font-size:0.8rem;">
            <i class="fas fa-fw fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>
    
    <hr class="sidebar-divider d-none d-md-block">
    
    <!-- Sidebar Toggler -->
    <div class="text-center d-none d-md-inline">
        <button class="rounded-circle border-0" id="sidebarToggle" style="background: var(--accent-dim); color: var(--accent); width: 32px; height: 32px;">
            <i class="fas fa-angle-left"></i>
        </button>
    </div>
</ul>
<!-- End Sidebar -->

<!-- Content Wrapper -->
<div id="content-wrapper" class="d-flex flex-column">
    <!-- Main Content -->
    <div id="content">
        
        <!-- Topbar -->
        <nav class="topbar d-flex align-items-center justify-content-between">
            <!-- Sidebar Toggle (Mobile) -->
            <button id="sidebarToggleTop" class="btn d-md-none" style="color: rgba(255,255,255,0.8); background: none; border: none; font-size: 1.2rem;">
                <i class="fas fa-bars"></i>
            </button>
            
            <!-- Left: Search -->
            <form class="d-none d-sm-inline-block navbar-search" style="max-width:320px;">
                <div class="input-group">
                    <input type="text" class="form-control bg-light border-0 small" placeholder="Search...">
                    <div class="input-group-append">
                        <button class="btn btn-search" type="button">
                            <i class="fas fa-search fa-sm"></i>
                        </button>
                    </div>
                </div>
            </form>
            
            <!-- Right: Notifications + User -->
            <ul class="navbar-nav ml-auto d-flex align-items-center">
                <!-- Pending Withdrawals Alert -->
                <?php if ($pendingWd > 0): ?>
                <li class="nav-item dropdown no-arrow mx-1">
                    <a class="nav-link position-relative" href="<?= SITE_URL ?>/admin/withdrawals" title="<?= $pendingWd ?> pending requests" style="color: rgba(255,255,255,0.8);">
                        <span class="badge badge-danger badge-pill" style="font-size:0.6rem;position:absolute;top:2px;right:2px;animation:notifPulse 2s infinite;">
                            <?= $pendingWd ?>
                        </span>
                        <i class="fas fa-bell fa-fw" style="font-size: 1.1rem;"></i>
                    </a>
                </li>
                <?php endif; ?>
                
                <div class="topbar-divider d-none d-sm-block"></div>
                
                <!-- User Info -->
                <li class="nav-item dropdown no-arrow">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" style="color: rgba(255,255,255,0.85);">
                        <span class="mr-2 d-none d-lg-inline small" style="font-weight:700;">
                            <?= $adminUser['full_name'] ?? 'Admin' ?>
                        </span>
                        <div style="width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,0.2);color:white;display:inline-flex;align-items:center;justify-content:center;font-weight:800;font-size:0.8rem;vertical-align:middle;margin-left:0.5rem;border:2px solid rgba(255,255,255,0.3);">
                            <?= strtoupper(substr($adminUser['full_name'] ?? 'A', 0, 1)) ?>
                        </div>
                    </a>
                    <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in">
                        <a class="dropdown-item" href="<?= SITE_URL ?>/admin/profile">
                            <i class="fas fa-user-cog fa-sm fa-fw mr-2 text-gray-400"></i> Profile
                        </a>
                        <a class="dropdown-item" href="<?= SITE_URL ?>/admin/settings">
                            <i class="fas fa-cog fa-sm fa-fw mr-2 text-gray-400"></i> Settings
                        </a>
                        <a class="dropdown-item" href="<?= SITE_URL ?>/dashboard" target="_blank">
                            <i class="fas fa-external-link-alt fa-sm fa-fw mr-2 text-gray-400"></i> View Site
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="<?= SITE_URL ?>/logout" data-toggle="modal" data-target="#logoutModal">
                            <i class="fas fa-sign-out-alt fa-sm fa-fw mr-2 text-gray-400"></i> Logout
                        </a>
                    </div>
                </li>
            </ul>
        </nav>
        <!-- End Topbar -->
        
        <!-- Page Content -->
        <div class="container-fluid" id="container-wrapper">
