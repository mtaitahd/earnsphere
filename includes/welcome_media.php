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
?>
<dialog id="welcomeMediaDialog" class="welcome-media-dialog" aria-labelledby="welcome-media-heading">
    <div class="welcome-media-header">
        <h2 id="welcome-media-heading"><?= sanitize(app_setting('welcome_media_title', 'Maelekezo ya akaunti')) ?></h2>
        <button type="button" class="welcome-media-close" data-welcome-close aria-label="Funga maelekezo">&times;</button>
    </div>
    <?php if (!$welcomeSource): ?>
    <p>Maelekezo yatawekwa hapa hivi karibuni.</p>
    <?php elseif ($welcomeType === 'audio'): ?>
    <p>Sikiliza maelekezo ya kutumia akaunti yako.</p>
    <audio controls preload="none" src="<?= sanitize($welcomeSource) ?>">Your browser does not support audio playback.</audio>
    <?php else: ?>
    <p>Tazama maelekezo ya kutumia akaunti yako.</p>
    <iframe data-welcome-src="<?= sanitize($welcomeSource) ?>" title="<?= sanitize(app_setting('welcome_media_title', 'Maelekezo ya akaunti')) ?>" allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
    <?php endif; ?>
</dialog>
<script src="<?= sanitize(SITE_URL) ?>/assets/js/welcome-media.js?v=<?= filemtime(dirname(__DIR__) . '/assets/js/welcome-media.js') ?>" defer></script>
