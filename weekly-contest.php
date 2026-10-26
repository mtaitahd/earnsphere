<?php
require_once __DIR__.'/includes/member_feature.php';
require_once __DIR__.'/classes/DailyMission.php';
require_once __DIR__.'/classes/Contest.php';
$contest=Contest::getFeaturedContest();$contestStandings=[];$contestUserRank=null;
if($contest){$contestStandings=Contest::getStandings($contest['id'],5);$contestUserRank=Contest::getUserRank($contest['id'],(int)$user['id']);}
$pageTitle='Weekly Contest';include __DIR__.'/includes/public_head.php'; ?>
<link rel="stylesheet" href="<?= sanitize(SITE_URL) ?>/assets/css/member-services.css">
<link rel="stylesheet" href="<?= sanitize(SITE_URL) ?>/assets/css/member-rewards.css?v=<?= filemtime(__DIR__.'/assets/css/member-rewards.css') ?>">
<main class="services-page"><h1>Weekly Contest</h1><nav class="feature-nav" aria-label="Rewards navigation"><a class="btn btn-outline-primary" href="dashboard">Dashboard</a><a class="btn btn-outline-primary" href="daily-mission">Daily Mission</a></nav>
<?php include __DIR__.'/includes/weekly_contest_view.php'; ?>
<?php if (!$contest): ?><p class="service-empty">Hakuna shindano kwa sasa. Tafadhali rudi baadaye.</p><?php endif; ?>
</main><?php include __DIR__.'/includes/public_foot.php'; ?>
