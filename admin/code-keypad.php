<?php
if (!isset($isPrivateEntry)) {http_response_code(404);exit;}
?>
<div class="code-keypad" data-code-keypad hidden role="group" aria-label="Number keypad">
    <div class="keypad-grid">
        <?php foreach (['1','2','3','4','5','6','7','8','9','clear','0','backspace'] as $key): ?>
        <button type="button" class="keypad-key <?= ctype_digit($key)?'':'keypad-action' ?>" data-code-key="<?= $key ?>" aria-label="<?= $key==='backspace'?'Delete last digit':($key==='clear'?'Clear code':$key) ?>"><?= $key==='backspace'?'⌫':($key==='clear'?'Clear':$key) ?></button>
        <?php endforeach; ?>
    </div>
    <button type="button" class="keypad-paste" data-code-key="paste">Paste code</button>
    <p class="code-hint" data-keypad-status role="status" aria-live="polite"></p>
</div>
