<?php if (!isset($settingsMap)) { http_response_code(404); exit; }
$guideItems=WelcomeMedia::items(true);
$guideEnabled=($settingsMap['welcome_media_type']['setting_value']??'off')!=='off'; ?>
<div class="card mb-4" id="welcome-media">
    <div class="card-header"><h5 class="mb-0">Know Your Account — audio, videos &amp; links</h5></div>
    <div class="card-body">
        <p>Add several instructions for users. They open from Know Your Account on the dashboard.</p>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Auth::generateCSRF() ?>">
            <input type="hidden" name="action" value="welcome_library">
            <label class="form-label" for="welcome-title">Popup title</label>
            <input class="form-control mb-3" id="welcome-title" name="title" maxlength="150" value="<?= sanitize($settingsMap['welcome_media_title']['setting_value']??'Know Your Account') ?>">
            <label class="d-block mb-3"><input type="checkbox" name="enabled" value="1" <?= $guideEnabled?'checked':'' ?>> Show instructions to users</label>
            <?php foreach ($guideItems as $item): ?>
            <div class="border rounded p-3 mb-3">
                <label class="form-label" for="guide-<?= $item['id'] ?>"><?= sanitize(ucfirst($item['type'])) ?> title</label>
                <input class="form-control mb-2" id="guide-<?= $item['id'] ?>" name="item_titles[<?= $item['id'] ?>]" maxlength="150" value="<?= sanitize($item['title']) ?>" required>
                <?php if (in_array($item['type'],['youtube','link'],true)): ?><a href="<?= sanitize($item['source']) ?>" target="_blank" rel="noopener noreferrer">Preview saved link</a><?php else: ?><span class="text-muted">Uploaded <?= sanitize($item['type']) ?></span><?php endif; ?>
                <label class="d-block mt-2"><input type="checkbox" name="remove_items[]" value="<?= $item['id'] ?>"> Remove from instructions</label>
            </div>
            <?php endforeach; ?>
            <label for="welcome-files" class="form-label">Add videos / audio (select multiple files)</label>
            <input class="form-control" id="welcome-files" name="media_files[]" type="file" multiple accept=".mp4,.webm,.mp3,.wav,.ogg,.m4a">
            <p class="text-muted mt-1">MP4/WebM video up to 50 MB each; audio up to 20 MB each. Up to 10 files per save and 30 items total. Hosting limits may be lower (upload: <?= sanitize(ini_get('upload_max_filesize')) ?>; request: <?= sanitize(ini_get('post_max_size')) ?>). Saved items stay when no new files are selected.</p>
            <label for="welcome-links" class="form-label">Add YouTube videos or other HTTPS links</label>
            <textarea class="form-control mb-2" id="welcome-links" name="links" rows="5" maxlength="40000" placeholder="Kutumia wallet | https://www.youtube.com/watch?v=...&#10;https://youtu.be/...&#10;Msaada | https://example.com/help"></textarea>
            <p class="text-muted">One link per line. Optional: title | link. YouTube videos play in the popup when embedding is allowed; other links open in a new tab. For YouTube videos, enable Allow embedding in YouTube Studio.</p>
            <button class="btn btn-primary" type="submit">Save instructions</button>
        </form>
    </div>
</div>
