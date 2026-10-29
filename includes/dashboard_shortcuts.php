<?php if (!isset($user)) { http_response_code(404); exit; } ?>
<?php if ($user['status'] === 'active'): ?>
<section class="dashboard-services" aria-labelledby="dashboard-explore-title">
    <h2 id="dashboard-explore-title">Explore</h2>
    <div class="dashboard-shortcuts">
        <a href="quizzes" class="dashboard-shortcut"><?php $contentBellCount=$contentCounts['quizzes']??0;include __DIR__.'/content_bell.php'; ?><i class="fas fa-question-circle" aria-hidden="true"></i><span>Quiz</span></a>
        <a href="social-tasks" class="dashboard-shortcut"><?php $contentBellCount=$contentCounts['social']??0;include __DIR__.'/content_bell.php'; ?><i class="fas fa-tasks" aria-hidden="true"></i><span>Social Media Tasks</span></a>
        <a href="promote" class="dashboard-shortcut"><i class="fas fa-bullhorn" aria-hidden="true"></i><span>Promote Account</span></a>
    </div>
</section>
<?php endif; ?>
<section class="dashboard-services" aria-labelledby="dashboard-shop-title">
    <h2 id="dashboard-shop-title">Soko</h2>
    <div class="dashboard-shortcuts dashboard-shortcuts--shop">
        <a href="marketplace" class="dashboard-shortcut <?= $user['status'] === 'active' ? '' : 'dashboard-shortcut--wide' ?>"><i class="fas fa-store" aria-hidden="true"></i><span>Buy Goods</span></a>
        <?php if ($user['status'] === 'active'): ?><a href="my-products" class="dashboard-shortcut"><i class="fas fa-tag" aria-hidden="true"></i><span>Sell Products</span></a><?php endif; ?>
    </div>
</section>
