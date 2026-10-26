<?php
if (!isset($user)) { http_response_code(404); exit; }
require_once __DIR__ . '/../classes/WelcomeMedia.php';
$welcomeType = app_setting('welcome_media_type', 'off');
$welcomeSource = '';
if ($welcomeType === 'youtube') {
    try { $welcomeSource = 'https://www.youtube-nocookie.com/embed/' . WelcomeMedia::youtubeId((string) app_setting('welcome_media_youtube', '')); }
    catch (InvalidArgumentException $e) { $welcomeType = 'off'; }
} elseif ($welcomeType === 'audio') {
    $welcomeAudio = (string) app_setting('welcome_media_audio', '');
    if (WelcomeMedia::audioPath($welcomeAudio) && is_file(dirname(__DIR__) . '/' . $welcomeAudio)) $welcomeSource = SITE_URL . '/' . $welcomeAudio;
}
if ($welcomeSource): ?>
<section class="card" style="margin:1rem 0" aria-labelledby="welcome-media-heading">
    <div class="card-body">
        <h2 id="welcome-media-heading" style="font-size:1.1rem"><?= sanitize(app_setting('welcome_media_title', 'Karibu EarnSphere')) ?></h2>
        <p>Karibu! Sikiliza au tazama maelekezo ya kutumia EarnSphere.</p>
        <?php if ($welcomeType === 'audio'): ?>
        <audio controls preload="none" style="width:100%" src="<?= sanitize($welcomeSource) ?>">Your browser does not support audio playback.</audio>
        <?php else: ?>
        <iframe src="<?= sanitize($welcomeSource) ?>" title="<?= sanitize(app_setting('welcome_media_title', 'Karibu EarnSphere')) ?>" loading="lazy" style="width:100%;aspect-ratio:16/9;border:0" allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>
