<?php
if (!isset($user)) { http_response_code(404); exit; }
require_once __DIR__ . '/../classes/WelcomeMedia.php';
$welcomeItems=WelcomeMedia::items();
?>
<dialog id="welcomeMediaDialog" class="welcome-media-dialog" aria-labelledby="welcome-media-heading">
    <div class="welcome-media-header">
        <h2 id="welcome-media-heading"><?= sanitize(app_setting('welcome_media_title', 'Know Your Account')) ?></h2>
        <button type="button" class="welcome-media-close" data-welcome-close aria-label="Funga maelekezo">&times;</button>
    </div>
    <?php if (!$welcomeItems): ?><p>Maelekezo yatawekwa hapa hivi karibuni.</p><?php endif; ?>
    <?php foreach ($welcomeItems as $index=>$item): ?>
    <details class="welcome-guide" data-guide <?= $index===0?'open':'' ?>>
        <summary><span class="welcome-guide-number"><?= $index+1 ?></span><?= sanitize($item['title']) ?><small><?= sanitize(ucfirst($item['type'])) ?></small></summary>
        <div class="welcome-guide-content">
        <?php if ($item['type']==='youtube'): $videoId=WelcomeMedia::youtubeId($item['source']); ?>
            <iframe data-welcome-src="https://www.youtube.com/embed/<?= sanitize($videoId) ?>?playsinline=1&amp;rel=0" title="<?= sanitize($item['title']) ?>" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
            <p class="welcome-playback-help">Video haifunguki? <a href="https://www.youtube.com/watch?v=<?= sanitize($videoId) ?>" target="_blank" rel="noopener">Open on YouTube</a></p>
        <?php elseif ($item['type']==='video'): ?>
            <video controls playsinline preload="none" src="<?= sanitize(SITE_URL.'/'.$item['source']) ?>">Your browser does not support video playback.</video>
        <?php elseif ($item['type']==='audio'): ?>
            <audio controls preload="none" src="<?= sanitize(SITE_URL.'/'.$item['source']) ?>">Your browser does not support audio playback.</audio>
        <?php else: ?>
            <a class="btn btn-outline-primary" href="<?= sanitize($item['source']) ?>" target="_blank" rel="noopener noreferrer">Open link <i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i></a>
        <?php endif; ?>
        </div>
    </details>
    <?php endforeach; ?>
</dialog>
<script src="<?= sanitize(SITE_URL) ?>/assets/js/welcome-media.js?v=<?= filemtime(dirname(__DIR__) . '/assets/js/welcome-media.js') ?>" defer></script>
