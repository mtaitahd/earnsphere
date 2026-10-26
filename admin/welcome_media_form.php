<?php if (!isset($settingsMap)) { http_response_code(404); exit; }
$welcomeType = $settingsMap['welcome_media_type']['setting_value'] ?? 'off'; ?>
<div class="card mb-4" id="welcome-media">
    <div class="card-header"><h5 class="mb-0">Welcome audio / YouTube video</h5></div>
    <div class="card-body">
        <p>Welcome users and explain how EarnSphere works. The player appears on the user dashboard.</p>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Auth::generateCSRF() ?>">
            <input type="hidden" name="action" value="welcome_media">
            <label for="welcome-title" class="form-label">Title</label>
            <input id="welcome-title" class="form-control mb-3" name="title" maxlength="150" value="<?= sanitize($settingsMap['welcome_media_title']['setting_value'] ?? 'Karibu EarnSphere') ?>">
            <label for="welcome-type" class="form-label">Show on user dashboard</label>
            <select id="welcome-type" name="type" class="form-select mb-3">
                <?php foreach (['off'=>'Hidden', 'audio'=>'Uploaded audio', 'youtube'=>'YouTube video'] as $value=>$label): ?>
                <option value="<?= $value ?>" <?= $welcomeType === $value ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <label for="welcome-audio" class="form-label">Audio file</label>
            <input id="welcome-audio" class="form-control" type="file" name="welcome_audio" accept=".mp3,.wav,.ogg,.m4a">
            <p class="text-muted mt-1">MP3, WAV, OGG or M4A; up to 20 MB, subject to your hosting upload limit. Leave empty to keep the saved audio.</p>
            <?php $savedAudio = $settingsMap['welcome_media_audio']['setting_value'] ?? ''; if (WelcomeMedia::audioPath($savedAudio)): ?>
            <audio controls preload="none" style="width:100%;margin-bottom:1rem" src="<?= sanitize(SITE_URL . '/' . $savedAudio) ?>"></audio>
            <?php endif; ?>
            <label for="welcome-youtube" class="form-label">YouTube video link</label>
            <input id="welcome-youtube" class="form-control mb-3" type="url" name="youtube" placeholder="https://www.youtube.com/watch?v=..." value="<?= sanitize($settingsMap['welcome_media_youtube']['setting_value'] ?? '') ?>">
            <button class="btn btn-primary" type="submit">Save welcome media</button>
        </form>
    </div>
</div>
