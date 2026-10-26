<?php
require_once __DIR__.'/includes/member_feature.php';
require_once __DIR__.'/classes/DailyMission.php';
require_once __DIR__.'/classes/Contest.php';
$missionStatus=DailyMission::getMissionStatus((int)$user['id']);
$showMissionAnimation=!empty($missionStatus['just_completed']);
$pageTitle='Daily Mission';include __DIR__.'/includes/public_head.php'; ?>
<link rel="stylesheet" href="<?= sanitize(SITE_URL) ?>/assets/css/member-services.css">
<link rel="stylesheet" href="<?= sanitize(SITE_URL) ?>/assets/css/member-rewards.css?v=<?= filemtime(__DIR__.'/assets/css/member-rewards.css') ?>">
<main class="services-page"><h1>Daily Mission</h1><nav class="feature-nav" aria-label="Rewards navigation"><a class="btn btn-outline-primary" href="dashboard">Dashboard</a><a class="btn btn-outline-primary" href="weekly-contest">Weekly Contest</a></nav>
<?php include __DIR__.'/includes/daily_mission_view.php'; ?>
<?php if (!$missionStatus || empty($missionStatus['has_mission'])): ?><p class="service-empty">Hakuna mission kwa sasa. Tafadhali rudi baadaye.</p><?php endif; ?>
</main>
<?php include __DIR__.'/includes/daily_mission_script.php';include __DIR__.'/includes/public_foot.php'; ?>
