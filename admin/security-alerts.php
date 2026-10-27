<?php
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/classes/StaffAccess.php';
require_once dirname(__DIR__) . '/classes/SecurityAlerts.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
Auth::initSession();
StaffAccess::requirePage('security-alerts');
$adminId = (int) $_SESSION['user_id'];
$error = '';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'mark_read') {
        SecurityAlerts::markRead($adminId, (int) ($_POST['through'] ?? 0));
        header('Location: ' . SITE_URL . '/admin/security-alerts');
        exit;
    }
    $alerts = SecurityAlerts::listing($adminId);
} catch (Throwable $e) {
    $alerts = ['items' => [], 'through' => 0];
    $error = 'Security alerts could not be loaded. Please try again.';
}
$latest = (int) ($alerts['items'][0]['id'] ?? 0);
$pageTitle = 'Security Alerts';
include __DIR__ . '/admin_header.php';
?>
<div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-4">
    <div><h1 class="h3">Security Alerts</h1><p class="text-muted mb-0">Rejected and suspicious activity. An alert does not prove that an account was hacked.</p></div>
    <?php if ($latest > 0): ?><form method="post">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= sanitize(Auth::generateCSRF()) ?>">
        <input type="hidden" name="action" value="mark_read"><input type="hidden" name="through" value="<?= $latest ?>">
        <button class="btn btn-primary" type="submit">Mark all as read</button>
    </form><?php endif; ?>
</div>
<div class="alert alert-info">Five incorrect logins in 15 minutes trigger a warning. Login and OTP limits reject further attempts temporarily. Repeated invalid tokens, restricted access and invalid payment callback signatures also generate alerts. Similar alerts are grouped for 15 minutes; email is limited to four alerts per hour.</div>
<?php if (ENVIRONMENT !== 'production'): ?><div class="alert alert-warning">Security email is disabled outside production. Alerts still appear here.</div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>
<?php if (!$alerts['items'] && !$error): ?><div class="card"><div class="card-body">No security alerts recorded yet.</div></div><?php endif; ?>
<?php foreach ($alerts['items'] as $alert):
    $context = json_decode($alert['context'] ?? '{}', true);
    if (!is_array($context)) $context = [];
    $status = $context['email_status'] ?? 'unknown';
    $emailLabels = ['accepted' => 'Accepted by mail service', 'failed' => 'Failed — check email settings', 'rate_limited' => 'Grouped — hourly email limit reached', 'disabled_nonproduction' => 'Not sent — non-production environment', 'pending' => 'Sending / outcome not confirmed'];
    $unread = (int) $alert['id'] > $alerts['through'];
?>
<article class="card mb-3"><div class="card-body">
    <div class="d-flex justify-content-between gap-2 flex-wrap"><h2 class="h6 mb-2"><?= sanitize($alert['message']) ?></h2><span class="badge <?= $unread ? 'bg-warning text-dark' : 'bg-secondary' ?>"><?= $unread ? 'New' : 'Read' ?></span></div>
    <p class="small text-muted mb-2">#<?= (int) $alert['id'] ?> · <?= sanitize(formatLedgerTime($alert['created_at'])) ?> EAT</p>
    <dl class="row small mb-0" style="overflow-wrap:anywhere">
        <dt class="col-sm-3">Source IP</dt><dd class="col-sm-9"><?= sanitize($alert['ip_address'] ?? 'Unknown') ?></dd>
        <dt class="col-sm-3">Page</dt><dd class="col-sm-9"><?= sanitize($context['path'] ?? '/') ?></dd>
        <dt class="col-sm-3">Signed-in account</dt><dd class="col-sm-9"><?= !empty($alert['user_id']) ? '#' . (int) $alert['user_id'] : 'Not signed in' ?></dd>
        <dt class="col-sm-3">Email</dt><dd class="col-sm-9"><?= sanitize($emailLabels[$status] ?? 'Delivery status unknown') ?></dd>
    </dl>
    <p class="small mb-0 text-muted">Review the activity before taking action. Do not suspend an account based on this alert alone.</p>
</div></article>
<?php endforeach; ?>
<?php include __DIR__ . '/admin_footer.php'; ?>
