<?php if (!isset($user)) { http_response_code(404); exit; } ?>
<dialog id="accountMenu" class="account-drawer" aria-labelledby="account-menu-title">
    <div class="account-drawer-heading"><div><strong id="account-menu-title">EarnSphere</strong><span><?= sanitize($user['full_name']) ?></span></div><button type="button" data-account-close aria-label="Close account menu">&times;</button></div>
    <nav aria-label="Account menu">
        <p>ACCOUNT</p>
        <a href="dashboard" aria-current="page"><i class="fas fa-home" aria-hidden="true"></i> Dashboard</a>
        <a href="wallet"><i class="fas fa-wallet" aria-hidden="true"></i> Wallet</a>
        <a href="withdrawal"><i class="fas fa-money-bill-transfer" aria-hidden="true"></i> Withdraw</a>
        <a href="transactions"><i class="fas fa-receipt" aria-hidden="true"></i> History</a>
        <a href="referrals#referral-share"><i class="fas fa-link" aria-hidden="true"></i> Referral Link</a>
        <a href="referrals"><i class="fas fa-users" aria-hidden="true"></i> My Referrals</a>
        <?php if ($user['status'] === 'active'): ?>
        <p>EXPLORE</p>
        <a href="quizzes"><i class="fas fa-question-circle" aria-hidden="true"></i> Quiz</a>
        <a href="social-tasks"><i class="fas fa-tasks" aria-hidden="true"></i> Social Media Tasks</a>
        <a href="promote"><i class="fas fa-bullhorn" aria-hidden="true"></i> Promote Account</a>
        <a href="daily-mission"><i class="fas fa-calendar-day" aria-hidden="true"></i> Daily Mission</a>
        <a href="weekly-contest"><i class="fas fa-trophy" aria-hidden="true"></i> Weekly Contest</a>
        <a href="ai-assistant"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> AI Assistant</a>
        <?php endif; ?>
        <p>SOKO</p>
        <a href="marketplace"><i class="fas fa-store" aria-hidden="true"></i> Buy Goods</a>
        <?php if ($user['status'] === 'active'): ?><a href="my-products"><i class="fas fa-tag" aria-hidden="true"></i> Sell Products</a><?php endif; ?>
        <p>HELP &amp; SETTINGS</p>
        <button type="button" data-menu-guide><i class="fas fa-circle-play" aria-hidden="true"></i> Know Your Account</button>
        <button type="button" data-menu-support><i class="fas fa-life-ring" aria-hidden="true"></i> Support</button>
        <a href="profile"><i class="fas fa-user" aria-hidden="true"></i> Profile</a>
        <a href="logout" onclick="return confirm('Leave your account?')"><i class="fas fa-right-from-bracket" aria-hidden="true"></i> Sign Out</a>
    </nav>
</dialog>
