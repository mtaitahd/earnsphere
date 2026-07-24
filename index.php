<?php
/**
 * EarnSphere - Landing Page
 * Mobile-first landing page for new visitors
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/includes/helpers.php';

Auth::initSession();

// Redirect logged-in users to dashboard
if (Auth::isLoggedIn()) {
    header('Location: ' . SITE_URL . '/dashboard');
    exit;
}

// Get system stats
$totalUsers = Database::count('users', 'status = ?', ['active']);
$totalPaid = Database::fetchOne("SELECT COALESCE(SUM(amount),0) as total FROM payments WHERE status = 'completed'")['total'] ?? 0;

$pageTitle = 'Join EarnSphere';
$pageDesc = 'EarnSphere - Earn money online through referrals. Build your network, earn commissions, and start your financial journey. Sign up now!';
$pageKeywords = 'EarnSphere, earn money online, referral, commission, passive income, Tanzania, TZS, network marketing, side hustle, make money online, referral program';
include __DIR__ . '/includes/public_head.php';
?>
<style>body{padding-bottom:0 !important;}</style>

<!-- Hero Section -->
<section class="landing-hero">
    <div class="brand-icon">
        <i class="fas fa-gem"></i>
    </div>
    <h1>EarnSphere</h1>
    <p class="tagline">Your gateway to opportunity and income</p>
    <p class="tagline" style="font-size: 0.9rem; opacity: 0.8; margin-bottom: 2rem;">
        Build your network, earn through referrals, and start your financial journey.
    </p>
    
    <div class="cta-buttons">
        <a href="register" class="btn btn-light btn-lg w-100 mb-3" style="font-weight: 800;">
            <i class="fas fa-rocket me-2"></i> Join Now
        </a>
        <a href="login" class="btn btn-outline-light btn-lg w-100">
            <i class="fas fa-sign-in-alt me-2"></i> Login
        </a>
    </div>
    
    
</section>



<!-- CTA Bottom -->
<section class="cta-bottom">
    <h2>Ready to Get Started?</h2>
    <p>Join thousands of members who are already earning money through EarnSphere.</p>
    <a href="register" class="btn btn-light btn-lg" style="font-weight: 800;">
        <i class="fas fa-rocket me-2"></i>Join Free
    </a>
</section>

<!-- Footer -->
<footer style="padding: 2rem 1.5rem; text-align: center; background: var(--gray-900); color: var(--gray-400); font-size: 0.8rem;">
    <p style="margin-bottom: 0.5rem;">
        <i class="fas fa-gem me-1" style="color: var(--primary-light);"></i>
        <strong style="color: var(--white);">EarnSphere</strong>
    </p>
    <p style="margin: 0;">Earn money online through referrals &copy; <?= date('Y') ?></p>
</footer>

<?php include __DIR__ . '/includes/public_foot.php'; ?>
