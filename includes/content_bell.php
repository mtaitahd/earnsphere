<?php
if (!isset($contentBellCount)) {http_response_code(404);exit;}
if ($contentBellCount>0): ?>
<span class="content-bell" role="status" aria-label="<?= (int)$contentBellCount ?> new tasks today"><svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a6 6 0 0 0-6 6v4l-2 4v2h16v-2l-2-4V8a6 6 0 0 0-6-6Zm-3 18a3 3 0 0 0 6 0H9Z"/></svg><b>NEW</b><em><?= $contentBellCount>99?'99+':(int)$contentBellCount ?></em></span>
<?php endif; ?>
