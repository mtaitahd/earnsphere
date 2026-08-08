<?php
/**
 * EarnSphere - Admin: SMS Management
 * Send SMS to selected users or broadcast to all, manage templates,
 * review send logs, configure the Meseji gateway, and send test SMS.
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/classes/MesejiSms.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

Auth::initSession();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ' . SITE_URL . '/admin/login');
    exit;
}

$sms = new MesejiSms();
$adminId = (int) $_SESSION['user_id'];

// ================================================================
// AJAX: search users (for the selected-user picker)
// ================================================================
if (isset($_GET['action']) && $_GET['action'] === 'search_users') {
    $q = trim($_GET['q'] ?? '');
    $results = [];
    if (strlen($q) >= 2) {
        $rows = Database::fetchAll(
            "SELECT id, full_name, phone, status FROM users
             WHERE role = 'user' AND (full_name LIKE ? OR phone LIKE ? OR email LIKE ?)
             ORDER BY created_at DESC LIMIT 10",
            ["%{$q}%", "%{$q}%", "%{$q}%"]
        );
        foreach ($rows as $r) {
            $results[] = ['id' => (int) $r['id'], 'name' => $r['full_name'], 'phone' => $r['phone'], 'status' => $r['status']];
        }
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'results' => $results]);
    exit;
}

// ================================================================
// POST actions
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Auth::validateCSRF($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'Security token invalid');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    $action = $_POST['action'];

    switch ($action) {

        case 'send':
            $audience    = $_POST['audience'] ?? 'selected';
            $templateKey = trim($_POST['template_key'] ?? '');
            $message     = trim($_POST['message'] ?? '');
            $rawPhones   = trim($_POST['phones'] ?? '');
            $userIds     = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['user_ids'] ?? [])))));

            if ($message === '') {
                setFlash('error', 'Message body is required.');
                break;
            }

            // Resolve target users based on audience
            $targetUserIds = [];
            $manualPhones  = [];

            switch ($audience) {
                case 'all':
                    $targetUserIds = array_column(Database::fetchAll("SELECT id FROM users WHERE role = 'user'"), 'id');
                    break;
                case 'active':
                    $targetUserIds = array_column(Database::fetchAll("SELECT id FROM users WHERE role = 'user' AND status = 'active'"), 'id');
                    break;
                case 'pending':
                    $targetUserIds = array_column(Database::fetchAll("SELECT id FROM users WHERE role = 'user' AND status = 'pending'"), 'id');
                    break;
                case 'selected':
                default:
                    $targetUserIds = $userIds;
                    foreach (preg_split('/[\s,;]+/', $rawPhones) as $p) {
                        if (trim($p) !== '') $manualPhones[] = $sms->normalizePhone(trim($p));
                    }
                    break;
            }

            $targetUserIds = array_values(array_unique(array_map('intval', $targetUserIds)));
            $manualPhones  = array_values(array_unique(array_filter($manualPhones)));

            if (empty($targetUserIds) && empty($manualPhones)) {
                setFlash('error', 'No recipients selected.');
                break;
            }

            $type = $templateKey !== '' ? $templateKey : ($audience === 'selected' ? 'custom' : 'broadcast');

            // If the message contains user-specific placeholders, send personalized
            // (one call per user). Otherwise batch all phones into a single request.
            $hasPersonal = preg_match('/\{(name|code|amount|fee|phone|email)\}/', $message);

            if (!empty($targetUserIds) && $hasPersonal) {
                $res = $sms->sendToUsers($targetUserIds, $message, $type, $templateKey !== '' ? $templateKey : null, $adminId);
                $sent = $res['sent'];
                if ($sent > 0) {
                    setFlash('success', 'SMS sent to ' . $sent . ' user(s)' . ($res['failed'] ? ', ' . $res['failed'] . ' failed' : '.'));
                } else {
                    $firstErr = $res['results'][0]['error'] ?? 'Unknown error';
                    setFlash('error', 'SMS not sent: ' . $firstErr);
                }
            } else {
                $phones = $manualPhones;
                if (!empty($targetUserIds)) {
                    $in     = implode(',', array_fill(0, count($targetUserIds), '?'));
                    $rows   = Database::fetchAll("SELECT phone FROM users WHERE id IN ({$in})", $targetUserIds);
                    foreach ($rows as $r) {
                        $phones[] = $sms->normalizePhone($r['phone']);
                    }
                }
                $res = $sms->sendBulk($phones, $message, $type, $templateKey !== '' ? $templateKey : null, $adminId);
                if ($res['sent'] > 0) {
                    setFlash('success', 'SMS sent to ' . $res['sent'] . ' recipient(s)' . ($res['failed'] ? ', ' . $res['failed'] . ' failed' : '.'));
                } else {
                    $firstErr = $res['results'][0]['error'] ?? 'No valid recipients';
                    setFlash('error', 'SMS not sent: ' . $firstErr);
                }
            }
            break;

        case 'save_settings':
            $map = [
                'meseji_api_key'        => trim($_POST['meseji_api_key'] ?? ''),
                'meseji_api_url'        => rtrim(trim($_POST['meseji_api_url'] ?? ''), '/'),
                'meseji_sender_id'      => strtoupper(trim($_POST['meseji_sender_id'] ?? '')),
                'meseji_enabled'        => isset($_POST['meseji_enabled']) ? '1' : '0',
                'sms_welcome_enabled'   => isset($_POST['sms_welcome_enabled']) ? '1' : '0',
                'sms_payment_enabled'   => isset($_POST['sms_payment_enabled']) ? '1' : '0',
            ];
            foreach ($map as $key => $value) {
                $existing = Database::fetchOne("SELECT id FROM settings WHERE setting_key = ?", [$key]);
                if ($existing) {
                    Database::update('settings', ['setting_value' => $value], 'setting_key = ?', [$key]);
                } else {
                    Database::insert('settings', ['setting_key' => $key, 'setting_value' => $value, 'description' => $key]);
                }
            }
            setFlash('success', 'SMS settings saved.');
            break;

        case 'test':
            $phone   = $sms->normalizePhone(trim($_POST['test_phone'] ?? ''));
            $message = trim($_POST['test_message'] ?? '');
            if (!preg_match('/^255[67]\d{8}$/', $phone)) {
                setFlash('error', 'Enter a valid Tanzanian mobile number (e.g. 0712 345 678).');
                break;
            }
            if ($message === '') {
                $message = 'Test SMS kutoka ' . SITE_NAME . ' — SMS configuration inafanya kazi vizuri.';
            }
            $res = $sms->send($message, $phone, null, 'custom', null, $adminId);
            if ($res['success']) {
                setFlash('success', 'Test SMS queued to ' . $phone . ' (batch: ' . $res['batch_id'] . ')');
            } else {
                setFlash('error', 'Test SMS failed: ' . $res['error']);
            }
            break;

        case 'create_template':
            $name      = trim($_POST['name'] ?? '');
            $message   = trim($_POST['message'] ?? '');
            $variables = trim($_POST['variables'] ?? '');
            $key       = trim($_POST['template_key'] ?? '');
            if ($name === '' || $message === '') {
                setFlash('error', 'Template name and message are required.');
                break;
            }
            $key = $key !== '' ? preg_replace('/[^a-z0-9_]/', '', strtolower($key)) : ('tpl_' . substr(uniqid(), -8));
            if (Database::fetchOne("SELECT id FROM sms_templates WHERE template_key = ?", [$key])) {
                setFlash('error', 'A template with this key already exists.');
                break;
            }
            Database::insert('sms_templates', [
                'name'         => $name,
                'template_key' => $key,
                'message'      => $message,
                'variables'    => $variables !== '' ? $variables : null,
                'is_system'    => 0,
                'is_active'    => 1,
                'created_by'   => $adminId,
            ]);
            setFlash('success', 'SMS template created.');
            break;

        case 'update_template':
            $id        = (int) ($_POST['id'] ?? 0);
            $name      = trim($_POST['name'] ?? '');
            $message   = trim($_POST['message'] ?? '');
            $variables = trim($_POST['variables'] ?? '');
            $tpl = Database::fetchOne("SELECT id FROM sms_templates WHERE id = ?", [$id]);
            if ($tpl && $name !== '' && $message !== '') {
                Database::update('sms_templates', [
                    'name'      => $name,
                    'message'   => $message,
                    'variables' => $variables !== '' ? $variables : null,
                ], 'id = ?', [$id]);
                setFlash('success', 'SMS template updated.');
            } else {
                setFlash('error', 'Invalid template data.');
            }
            break;

        case 'delete_template':
            $id  = (int) ($_POST['id'] ?? 0);
            $tpl = Database::fetchOne("SELECT id FROM sms_templates WHERE id = ? AND is_system = 0", [$id]);
            if ($tpl) {
                Database::delete('sms_templates', 'id = ?', [$id]);
                setFlash('success', 'SMS template deleted.');
            } else {
                setFlash('error', 'System templates cannot be deleted.');
            }
            break;

        case 'toggle_template':
            $id  = (int) ($_POST['id'] ?? 0);
            $tpl = Database::fetchOne("SELECT is_active FROM sms_templates WHERE id = ?", [$id]);
            if ($tpl) {
                Database::update('sms_templates', ['is_active' => $tpl['is_active'] ? 0 : 1], 'id = ?', [$id]);
                setFlash('success', 'Template ' . ($tpl['is_active'] ? 'deactivated' : 'activated') . '.');
            }
            break;
    }

    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// ================================================================
// Data for the page
// ================================================================
$templates = MesejiSms::listTemplates();

$settingsMap = [];
foreach (Database::fetchAll("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'meseji%' OR setting_key LIKE 'sms_%'") as $s) {
    $settingsMap[$s['setting_key']] = $s['setting_value'];
}

// Account stats (best effort)
$accountStats = null;
if ($sms->isConfigured()) {
    $statsRes = $sms->getAccountStats();
    if ($statsRes['success']) {
        $accountStats = $statsRes['data'];
    }
}

// Logs list with filters
$logType   = trim($_GET['log_type'] ?? '');
$logStatus = trim($_GET['log_status'] ?? '');
$logSearch = trim($_GET['log_search'] ?? '');
$page      = getCurrentPage();
$perPage   = 20;
$offset    = ($page - 1) * $perPage;

$logWhere = '1=1';
$logParams = [];
if ($logType !== '') {
    $logWhere .= ' AND l.type = ?';
    $logParams[] = $logType;
}
if ($logStatus !== '') {
    $logWhere .= ' AND l.status = ?';
    $logParams[] = $logStatus;
}
if ($logSearch !== '') {
    $logWhere .= ' AND (l.phone LIKE ? OR l.batch_id LIKE ? OR l.message LIKE ? OR u.full_name LIKE ?)';
    $sp = "%{$logSearch}%";
    $logParams = array_merge($logParams, [$sp, $sp, $sp, $sp]);
}

$logTotal = Database::count('sms_logs l', $logWhere, $logParams);

$logs = Database::fetchAll(
    "SELECT l.*, u.full_name as user_name, a.full_name as sender_name
     FROM sms_logs l
     LEFT JOIN users u ON l.user_id = u.id
     LEFT JOIN users a ON l.sent_by = a.id
     WHERE {$logWhere}
     ORDER BY l.created_at DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $logParams
);

$logTypes = ['welcome', 'payment', 'reminder', 'broadcast', 'custom'];

$tab = $_GET['tab'] ?? 'send';

$csrf = Auth::generateCSRF();
$pageTitle = 'SMS';
include __DIR__ . '/admin_header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1><i class="fas fa-sms me-2" style="color:var(--primary);"></i>SMS System</h1>
        <p>Send SMS to users, manage templates, and monitor delivery (Meseji)</p>
    </div>
    <?php if ($sms->isConfigured()): ?>
        <div class="text-end">
            <?php if ($accountStats): ?>
                <div class="small text-muted">
                    <strong style="color:#10b981;"><?= number_format($accountStats['balance'] ?? 0) ?> SMS credits</strong>
                    &middot; <?= number_format($accountStats['total_messages_sent'] ?? 0) ?> sent
                    &middot; <?= number_format($accountStats['success_rate'] ?? 0, 1) ?>% success
                </div>
            <?php endif; ?>
            <span class="badge <?= $sms->isEnabled() ? 'bg-success' : 'bg-warning text-dark' ?>" style="font-size:0.75rem;">
                <i class="fas fa-<?= $sms->isEnabled() ? 'check-circle' : 'exclamation-triangle' ?> me-1"></i>
                <?= $sms->isEnabled() ? 'Enabled' : 'Disabled' ?>
            </span>
        </div>
    <?php else: ?>
        <span class="badge bg-danger" style="font-size:0.75rem;">
            <i class="fas fa-plug me-1"></i> API key not configured
        </span>
    <?php endif; ?>
</div>

<?php if (!$sms->isEnabled()): ?>
    <div class="alert alert-warning d-flex align-items-center" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <div>
            <strong>SMS service is disabled.</strong>
            Add your Meseji API key (starts with <code>zs_</code>) and enable SMS below, or in
            <a href="<?= SITE_URL ?>/admin/settings" style="font-weight:700;">Admin Settings</a>.
        </div>
    </div>
<?php endif; ?>

<?php displayFlash(); ?>

<!-- Tabs -->
<ul class="nav nav-pills mb-4" style="gap:0.5rem;">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'send' ? 'active' : '' ?>" href="?tab=send" style="<?= $tab === 'send' ? 'background:var(--primary);color:#fff;' : 'color:var(--primary);' ?>">
            <i class="fas fa-paper-plane me-1"></i> Send SMS
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'templates' ? 'active' : '' ?>" href="?tab=templates" style="<?= $tab === 'templates' ? 'background:var(--primary);color:#fff;' : 'color:var(--primary);' ?>">
            <i class="fas fa-list-alt me-1"></i> Templates
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'logs' ? 'active' : '' ?>" href="?tab=logs" style="<?= $tab === 'logs' ? 'background:var(--primary);color:#fff;' : 'color:var(--primary);' ?>">
            <i class="fas fa-history me-1"></i> SMS Logs
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'settings' ? 'active' : '' ?>" href="?tab=settings" style="<?= $tab === 'settings' ? 'background:var(--primary);color:#fff;' : 'color:var(--primary);' ?>">
            <i class="fas fa-cog me-1"></i> Gateway Settings
        </a>
    </li>
</ul>

<?php if ($tab === 'send'): ?>

<!-- ============ SEND TAB ============ -->
<div class="row g-4">
    <div class="col-lg-7">
        <form method="POST" id="sendForm">
            <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="send">

            <div class="card mb-4">
                <div class="card-header">
                    <h6><i class="fas fa-users me-1"></i> 1. Choose Recipients</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" style="font-weight:700;">Audience</label>
                            <select class="form-select" name="audience" id="audienceSelect">
                                <option value="selected">Selected users / phones</option>
                                <option value="all">All users</option>
                                <option value="active">All active users</option>
                                <option value="pending">All pending (unpaid) users — reminder</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" style="font-weight:700;">Template <span class="text-muted" style="font-weight:400;font-size:0.8rem;">(optional, loads into message)</span></label>
                            <select class="form-select" name="template_key" id="templateSelect">
                                <option value="">— Custom message —</option>
                                <?php foreach ($templates as $t): ?>
                                    <option value="<?= $t['template_key'] ?>" data-message="<?= htmlspecialchars($t['message'], ENT_QUOTES) ?>">
                                        <?= sanitize($t['name']) ?> (<?= $t['template_key'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Selected users picker (only for audience=selected) -->
                    <div id="selectedUsersBox" class="mt-3">
                        <label class="form-label" style="font-weight:700;">
                            <i class="fas fa-user-plus me-1"></i> Search users to add
                        </label>
                        <input type="text" class="form-control" id="userSearch" placeholder="Type name, phone, or email (min 2 chars)..." autocomplete="off">
                        <div id="searchResults" class="mt-2"></div>

                        <div id="selectedUsers" class="mt-2 d-flex flex-wrap" style="gap:0.4rem;"></div>

                        <div class="mt-3">
                            <label class="form-label" style="font-weight:700;">Or paste phone numbers <span class="text-muted" style="font-weight:400;font-size:0.8rem;">(comma / newline separated)</span></label>
                            <textarea class="form-control" name="phones" id="phonesInput" rows="3" placeholder="0712345678, 255712345678&#10;0622123456"></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="fas fa-envelope me-1"></i> 2. Message</h6>
                    <span class="text-muted" id="charCount" style="font-size:0.8rem;">0 characters</span>
                </div>
                <div class="card-body">
                    <div class="mb-2 text-muted" style="font-size:0.8rem;">
                        <strong>Placeholders:</strong>
                        <code>{name}</code> <code>{phone}</code> <code>{code}</code>
                        <code>{fee}</code> <code>{amount}</code> <code>{site}</code>
                        &mdash; replaced per recipient.
                    </div>
                    <textarea class="form-control" name="message" id="messageInput" rows="6" placeholder="Type your message here... e.g. Kumbukumbu {name}: bado haujalipa TZS {fee}. Maliza leo!" style="resize:vertical;"></textarea>
                    <div class="mt-2 text-muted" style="font-size:0.8rem;">
                        <i class="fas fa-info-circle me-1"></i> If a personal placeholder (<code>{name}</code>, <code>{code}</code>, etc.) is present, each user receives a personalized SMS. Otherwise the exact same text is sent to everyone in one batch.
                    </div>

                    <div class="mt-3 p-3 rounded" style="background:#f8f9fa;border:1px dashed #d1d5db;font-size:0.85rem;">
                        <div style="font-weight:700;color:#555;margin-bottom:0.3rem;"><i class="fas fa-eye me-1"></i> Preview (sample)</div>
                        <div id="livePreview" class="text-muted">Select a template or type a message to preview...</div>
                    </div>
                </div>
            </div>

            <div class="text-end mb-4">
                <button type="submit" class="btn btn-primary btn-lg" id="sendBtn">
                    <i class="fas fa-paper-plane me-1"></i> Send SMS
                </button>
            </div>
        </form>
    </div>

    <div class="col-lg-5">
        <!-- Account stats -->
        <div class="card mb-4">
            <div class="card-header">
                <h6><i class="fas fa-chart-bar me-1"></i> Meseji Account</h6>
            </div>
            <div class="card-body">
                <?php if ($accountStats): ?>
                    <div class="row text-center g-3">
                        <div class="col-6">
                            <div style="font-size:1.6rem;font-weight:800;color:var(--primary);"><?= number_format($accountStats['total_messages_sent'] ?? 0) ?></div>
                            <div class="text-muted small">Messages sent</div>
                        </div>
                        <div class="col-6">
                            <div style="font-size:1.6rem;font-weight:800;color:#10b981;"><?= number_format($accountStats['success_rate'] ?? 0, 1) ?>%</div>
                            <div class="text-muted small">Success rate</div>
                        </div>
                        <div class="col-6">
                            <div style="font-size:1.6rem;font-weight:800;color:#f59e0b;"><?= number_format($accountStats['balance'] ?? 0) ?></div>
                            <div class="text-muted small">SMS credits</div>
                        </div>
                        <div class="col-6">
                            <div style="font-size:1.6rem;font-weight:800;color:#ef4444;"><?= number_format($accountStats['failed_deliveries'] ?? 0) ?></div>
                            <div class="text-muted small">Failed deliveries</div>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0" style="font-size:0.85rem;">
                        <i class="fas fa-info-circle me-1"></i>
                        Configure your API key in the <a href="?tab=settings">Gateway Settings</a> tab to see live account stats.
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent sends -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-history me-1"></i> Recent Sends</h6>
                <a href="?tab=logs" class="small" style="color:var(--primary);">View all</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr><th>When</th><th>Type</th><th>Recipients</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php
                            $recent = Database::fetchAll("SELECT * FROM sms_logs ORDER BY created_at DESC LIMIT 6");
                            foreach ($recent as $r):
                            ?>
                            <tr>
                                <td><small><?= date('d M H:i', strtotime($r['created_at'])) ?></small></td>
                                <td><code style="font-size:0.7rem;"><?= $r['type'] ?></code></td>
                                <td><small><?= (int) $r['total_recipients'] ?></small></td>
                                <td>
                                    <?php if ($r['status'] === 'failed'): ?>
                                        <span class="badge bg-danger" style="font-size:0.65rem;">failed</span>
                                    <?php else: ?>
                                        <span class="badge bg-success" style="font-size:0.65rem;"><?= $r['status'] ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($recent)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-3 small">No SMS sent yet</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php elseif ($tab === 'templates'): ?>

<!-- ============ TEMPLATES TAB ============ -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <p class="text-muted mb-0" style="font-size:0.85rem;">
            Templates are message skeletons. <code>{name}</code>, <code>{fee}</code>, <code>{code}</code> etc. are replaced per recipient.
        </p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTemplateModal">
        <i class="fas fa-plus me-1"></i> New Template
    </button>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Key</th>
                        <th>Message</th>
                        <th>Variables</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($templates as $i => $t): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><strong><?= sanitize($t['name']) ?></strong></td>
                        <td><code style="font-size:0.7rem;"><?= $t['template_key'] ?></code></td>
                        <td><small style="color:#9ca3af;"><?= truncate($t['message'], 70) ?></small></td>
                        <td><small style="color:#9ca3af;"><?= $t['variables'] ?: '—' ?></small></td>
                        <td>
                            <?= $t['is_system'] ? '<span class="badge bg-info">System</span>' : '<span class="badge bg-secondary">Custom</span>' ?>
                        </td>
                        <td>
                            <?= $t['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                                    <input type="hidden" name="action" value="toggle_template">
                                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="<?= $t['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                        <i class="fas fa-<?= $t['is_active'] ? 'pause' : 'play' ?>"></i>
                                    </button>
                                </form>
                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editTemplateModal"
                                        data-id="<?= $t['id'] ?>" data-name="<?= sanitize($t['name']) ?>" data-message="<?= htmlspecialchars($t['message'], ENT_QUOTES) ?>" data-variables="<?= sanitize($t['variables'] ?? '') ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if (!$t['is_system']): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this template?')">
                                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                                    <input type="hidden" name="action" value="delete_template">
                                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($templates)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4">
                            <i class="fas fa-list-alt fa-2x mb-2" style="color:#d1d5db;"></i>
                            <p class="text-muted mb-0">No templates found</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create Template Modal -->
<div class="modal fade" id="createTemplateModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="create_template">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="fas fa-list-alt me-1" style="color:var(--primary);"></i> New SMS Template</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" style="font-weight:700;">Template Name</label>
                        <input type="text" class="form-control" name="name" required maxlength="100" placeholder="e.g. Birthday Greeting">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight:700;">Template Key <span class="text-muted" style="font-weight:400;font-size:0.8rem;">(auto-generated if empty)</span></label>
                        <input type="text" class="form-control" name="template_key" maxlength="50" placeholder="birthday_greeting">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight:700;">Message</label>
                        <textarea class="form-control" name="message" rows="5" required placeholder="Siku njema {name}! ..." style="resize:vertical;"></textarea>
                        <div class="form-text">Available placeholders: {name} {phone} {code} {fee} {amount} {site}</div>
                    </div>
                    <div>
                        <label class="form-label" style="font-weight:700;">Variables <span class="text-muted" style="font-weight:400;font-size:0.8rem;">(comma separated)</span></label>
                        <input type="text" class="form-control" name="variables" placeholder="name,code,fee">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Template Modal -->
<div class="modal fade" id="editTemplateModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="update_template">
                <input type="hidden" name="id" id="editId">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="fas fa-edit me-1" style="color:var(--primary);"></i> Edit Template</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" style="font-weight:700;">Template Name</label>
                        <input type="text" class="form-control" name="name" id="editName" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight:700;">Message</label>
                        <textarea class="form-control" name="message" id="editMessage" rows="5" required style="resize:vertical;"></textarea>
                        <div class="form-text">Available placeholders: {name} {phone} {code} {fee} {amount} {site}</div>
                    </div>
                    <div>
                        <label class="form-label" style="font-weight:700;">Variables <span class="text-muted" style="font-weight:400;font-size:0.8rem;">(comma separated)</span></label>
                        <input type="text" class="form-control" name="variables" id="editVariables" placeholder="name,code,fee">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php elseif ($tab === 'logs'): ?>

<!-- ============ LOGS TAB ============ -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="tab" value="logs">
            <div class="col-md-4">
                <input type="text" class="form-control" name="log_search" placeholder="Search phone, batch, message, user..." value="<?= sanitize($logSearch) ?>">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="log_type">
                    <option value="">All types</option>
                    <?php foreach ($logTypes as $lt): ?>
                        <option value="<?= $lt ?>" <?= $logType === $lt ? 'selected' : '' ?>><?= ucfirst($lt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select class="form-select" name="log_status">
                    <option value="">All statuses</option>
                    <?php foreach (['queued', 'sent', 'failed'] as $st): ?>
                        <option value="<?= $st ?>" <?= $logStatus === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search me-1"></i> Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Recipient</th>
                        <th>Message</th>
                        <th>Type</th>
                        <th>Batch</th>
                        <th>Cost</th>
                        <th>Status</th>
                        <th>Sent By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $i => $log): ?>
                    <tr>
                        <td><?= $offset + $i + 1 ?></td>
                        <td><small><?= date('d M Y H:i', strtotime($log['created_at'])) ?></small></td>
                        <td>
                            <?php if ($log['user_name']): ?>
                                <strong><?= sanitize($log['user_name']) ?></strong><br>
                                <small style="color:#9ca3af;"><?= $log['phone'] ?: 'broadcast' ?></small>
                            <?php elseif ($log['phone']): ?>
                                <small><?= $log['phone'] ?></small>
                            <?php else: ?>
                                <span class="text-muted" style="font-size:0.8rem;"><?= (int) $log['total_recipients'] ?> recipients</span>
                            <?php endif; ?>
                        </td>
                        <td><small style="color:#9ca3af;" title="<?= htmlspecialchars($log['message'], ENT_QUOTES) ?>"><?= truncate($log['message'], 55) ?></small></td>
                        <td><code style="font-size:0.7rem;"><?= $log['type'] ?></code></td>
                        <td><small><code style="font-size:0.7rem;"><?= $log['batch_id'] ?: '—' ?></code></small></td>
                        <td><small><?= $log['estimated_cost'] > 0 ? number_format((float) $log['estimated_cost']) : '—' ?></small></td>
                        <td>
                            <?php if ($log['status'] === 'failed'): ?>
                                <span class="badge bg-danger" title="<?= htmlspecialchars($log['error'] ?? '', ENT_QUOTES) ?>" style="font-size:0.65rem;">failed</span>
                            <?php elseif ($log['status'] === 'queued'): ?>
                                <span class="badge bg-warning text-dark" style="font-size:0.65rem;">queued</span>
                            <?php else: ?>
                                <span class="badge bg-success" style="font-size:0.65rem;"><?= $log['status'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td><small><?= sanitize($log['sender_name'] ?? 'System') ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4">
                            <i class="fas fa-history fa-2x mb-2" style="color:#d1d5db;"></i>
                            <p class="text-muted mb-0">No SMS logs found</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mt-3">
    <?= paginate($logTotal, $page, $perPage, SITE_URL . '/admin/sms?' . http_build_query(['tab' => 'logs', 'log_type' => $logType, 'log_status' => $logStatus, 'log_search' => $logSearch, 'page' => ''])) ?>
</div>

<?php elseif ($tab === 'settings'): ?>

<!-- ============ SETTINGS TAB ============ -->
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <h6><i class="fas fa-plug me-1"></i> Meseji Gateway</h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="save_settings">

                    <div class="mb-3">
                        <label class="form-label" style="font-weight:700;">API Key</label>
                        <input type="text" class="form-control" name="meseji_api_key" value="<?= sanitize($settingsMap['meseji_api_key'] ?? '') ?>" placeholder="zs_xxxxxxxxxxxxxxxxxxxx">
                        <div class="form-text">Get it from Meseji dashboard &rarr; Developer Settings &rarr; Generate API key (starts with <code>zs_</code>).</div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label" style="font-weight:700;">Sender ID</label>
                            <input type="text" class="form-control" name="meseji_sender_id" value="<?= sanitize($settingsMap['meseji_sender_id'] ?? 'MESEJI') ?>" maxlength="11">
                            <div class="form-text">1-11 characters, must be approved in Meseji. Default: <code>MESEJI</code></div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" style="font-weight:700;">API URL</label>
                            <input type="text" class="form-control" name="meseji_api_url" value="<?= sanitize($settingsMap['meseji_api_url'] ?? 'https://meseji.co.tz/api/v1') ?>">
                        </div>
                    </div>

                    <hr>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="meseji_enabled" id="mesejiEnabled" <?= !empty($settingsMap['meseji_enabled']) && filter_var($settingsMap['meseji_enabled'], FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="mesejiEnabled" style="font-weight:700;">Enable SMS sending</label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="sms_welcome_enabled" id="smsWelcomeEnabled" <?= filter_var($settingsMap['sms_welcome_enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="smsWelcomeEnabled" style="font-weight:700;">Welcome SMS on registration</label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="sms_payment_enabled" id="smsPaymentEnabled" <?= filter_var($settingsMap['sms_payment_enabled'] ?? '1', FILTER_VALIDATE_BOOLEAN) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="smsPaymentEnabled" style="font-weight:700;">SMS on payment success</label>
                    </div>

                    <div class="text-end">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Settings</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <h6><i class="fas fa-paper-plane me-1"></i> Test SMS</h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="test">
                    <div class="mb-3">
                        <label class="form-label" style="font-weight:700;">Phone Number</label>
                        <input type="text" class="form-control" name="test_phone" required placeholder="0712 345 678">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight:700;">Message <span class="text-muted" style="font-weight:400;font-size:0.8rem;">(optional)</span></label>
                        <textarea class="form-control" name="test_message" rows="3" placeholder="Leave empty for a default test message"></textarea>
                    </div>
                    <button type="submit" class="btn btn-outline-primary w-100">
                        <i class="fas fa-paper-plane me-1"></i> Send Test SMS
                    </button>
                </form>
                <div class="alert alert-info mt-3 mb-0" style="font-size:0.8rem;">
                    <i class="fas fa-info-circle me-1"></i>
                    Send a test SMS to confirm your API key, sender ID, and phone formatting are working.
                </div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
(function() {
    // ===== Send tab helpers =====
    var audienceSelect = document.getElementById('audienceSelect');
    var selectedBox = document.getElementById('selectedUsersBox');
    var templateSelect = document.getElementById('templateSelect');
    var messageInput = document.getElementById('messageInput');
    var charCount = document.getElementById('charCount');
    var livePreview = document.getElementById('livePreview');
    var selectedUsers = document.getElementById('selectedUsers');
    var searchInput = document.getElementById('userSearch');
    var searchResults = document.getElementById('searchResults');
    var selectedMap = {};

    function updateAudience() {
        if (selectedBox) {
            selectedBox.style.display = audienceSelect.value === 'selected' ? 'block' : 'none';
        }
    }
    if (audienceSelect) audienceSelect.addEventListener('change', updateAudience);
    updateAudience();

    // Template → load message
    if (templateSelect) {
        templateSelect.addEventListener('change', function() {
            var opt = templateSelect.options[templateSelect.selectedIndex];
            if (opt && opt.dataset.message) {
                messageInput.value = opt.dataset.message;
                updateCharCount();
            }
        });
    }

    function updateCharCount() {
        if (!charCount || !messageInput) return;
        var len = messageInput.value.length;
        charCount.textContent = len + ' characters' + (len > 160 ? ' (' + Math.ceil(len / 160) + ' SMS parts)' : '');
    }
    if (messageInput) {
        messageInput.addEventListener('input', function() {
            updateCharCount();
            var sample = messageInput.value
                .replace(/\{name\}/g, 'Juma Hassan')
                .replace(/\{code\}/g, 'JUMHA1B2')
                .replace(/\{fee\}/g, '11,500')
                .replace(/\{amount\}/g, '11,500')
                .replace(/\{phone\}/g, '0712 345 678')
                .replace(/\{site\}/g, '<?= SITE_NAME ?>')
                .replace(/\{message\}/g, '');
            livePreview.textContent = sample || 'Select a template or type a message to preview...';
        });
    }
    updateCharCount();

    // ===== User search & selection =====
    var csrf = document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').content : '';
    var searchTimer;

    function addUser(id, name, phone) {
        if (selectedMap[id]) return;
        selectedMap[id] = true;
        var chip = document.createElement('div');
        chip.className = 'd-inline-flex align-items-center gap-2 px-2 py-1 rounded';
        chip.style.cssText = 'background:var(--accent-dim,#f0eef6);border:1px solid #ddd3ee;font-size:0.8rem;';
        chip.innerHTML = '<input type="hidden" name="user_ids[]" value="' + id + '">' +
            '<span style="font-weight:700;">' + name + '</span>' +
            '<span class="text-muted">' + phone + '</span>' +
            '<button type="button" class="btn-close" style="font-size:0.6rem;" data-remove="' + id + '"></button>';
        chip.querySelector('[data-remove]').addEventListener('click', function() {
            chip.remove();
            delete selectedMap[id];
        });
        selectedUsers.appendChild(chip);
        searchResults.innerHTML = '';
        searchInput.value = '';
    }

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimer);
            var q = searchInput.value.trim();
            if (q.length < 2) {
                searchResults.innerHTML = '';
                return;
            }
            searchTimer = setTimeout(function() {
                fetch('sms.php?action=search_users&q=' + encodeURIComponent(q))
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        searchResults.innerHTML = '';
                        if (!d.success || !d.results.length) {
                            searchResults.innerHTML = '<div class="text-muted small p-2" style="border:1px solid #eee;border-radius:6px;">No users found</div>';
                            return;
                        }
                        d.results.forEach(function(u) {
                            var item = document.createElement('div');
                            item.className = 'd-flex justify-content-between align-items-center p-2 mb-1 rounded';
                            item.style.cssText = 'border:1px solid #eee;cursor:pointer;font-size:0.85rem;';
                            if (selectedMap[u.id]) item.style.opacity = '0.5';
                            item.innerHTML = '<div><strong>' + u.name + '</strong> <span class="text-muted small">' + u.phone + '</span></div>' +
                                (selectedMap[u.id] ? '<span class="badge bg-secondary">added</span>' : '<span class="badge" style="background:var(--primary);color:#fff;">Add</span>');
                            item.addEventListener('click', function() {
                                if (!selectedMap[u.id]) addUser(u.id, u.name, u.phone);
                            });
                            searchResults.appendChild(item);
                        });
                    });
            }, 300);
        });
    }

    // ===== Edit template modal =====
    var editModal = document.getElementById('editTemplateModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function(event) {
            var btn = event.relatedTarget;
            document.getElementById('editId').value = btn.dataset.id;
            document.getElementById('editName').value = btn.dataset.name || '';
            document.getElementById('editMessage').value = btn.dataset.message || '';
            document.getElementById('editVariables').value = btn.dataset.variables || '';
        });
    }
})();
</script>

<?php include __DIR__ . '/admin_footer.php'; ?>
