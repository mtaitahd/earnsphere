<?php if (!isset($_SESSION['user_id'])) { http_response_code(404); exit; } ?>
<button type="button" class="account-help-fab" data-account-help aria-haspopup="dialog" aria-controls="supportModal"><i class="fas fa-life-ring" aria-hidden="true"></i><span>Need Help?</span><span data-help-unread hidden></span></button>
<dialog id="supportModal" class="account-support-dialog" aria-labelledby="account-support-title" data-endpoint="<?= sanitize(SITE_URL) ?>/api/support.php" data-csrf="<?= sanitize(Auth::generateCSRF()) ?>">
    <div class="account-support-heading"><div><p>EARNSPHERE SUPPORT</p><h2 id="account-support-title">Need Help?</h2></div><button type="button" data-support-close aria-label="Close support">&times;</button></div>
    <p class="account-support-intro">Tueleze changamoto yako. Majibu ya timu yetu yataonekana hapa.</p>
    <form data-support-form><label for="account-support-subject">Subject</label><input id="account-support-subject" name="subject" maxlength="200" placeholder="Mfano: malipo au kutumia account"><label for="account-support-message">Your message</label><textarea id="account-support-message" name="message" rows="4" minlength="5" maxlength="4000" placeholder="Eleza unachohitaji msaada..." required></textarea><button class="btn btn-primary" type="submit">Send Message</button><p data-support-status role="status"></p></form>
    <h3>Your requests &amp; replies</h3><div id="supportModalBody" data-support-tickets aria-live="polite"></div>
</dialog>
<script src="<?= sanitize(SITE_URL) ?>/assets/js/account-support.js?v=<?= filemtime(dirname(__DIR__).'/assets/js/account-support.js') ?>" defer></script>
