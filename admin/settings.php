<?php
/**
 * EarnSphere - Admin: Settings
 * Supports payout channel configuration
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

Auth::initSession();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ' . SITE_URL . '/admin/login');
    exit;
}

$success = '';
$error = '';

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCSRF()) { http_response_code(403); exit('Invalid security token.'); }
    $settings = $_POST['settings'] ?? [];
    if (!is_array($settings)) { http_response_code(422); exit('Invalid settings.'); }
    unset($settings['snippe_api_key'], $settings['snippe_webhook_secret'], $settings['snippe_api_url'], $settings['snippe_api_version']);
    foreach (['abliner_api_key', 'abliner_webhook_secret'] as $secret) {
        if (isset($settings[$secret]) && trim((string) $settings[$secret]) === '') unset($settings[$secret]);
    }

    // Commission validation
    $regFee    = (int)($settings['registration_fee'] ?? 0);
    $company   = (int)($settings['company_earning'] ?? 0);
    $commTotal = (int)($settings['commission_total'] ?? 0);
    $l1        = (int)($settings['commission_l1'] ?? 0);
    $l2        = (int)($settings['commission_l2'] ?? 0);
    $l3        = (int)($settings['commission_l3'] ?? 0);

    $commissionChanged = false;
    foreach (['registration_fee', 'company_earning', 'commission_total', 'commission_l1', 'commission_l2', 'commission_l3'] as $key) {
        if (isset($settings[$key])) {
            $old = Database::fetchOne('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);
            if (!$old || (string) $old['setting_value'] !== (string) $settings[$key]) $commissionChanged = true;
        }
    }
    if ($commissionChanged && $regFee > 0 && $company > 0 && $commTotal > 0) {
        if ($company + $commTotal !== $regFee) {
            $error = "Company earning ({$company}) + Commission total ({$commTotal}) must equal Registration fee ({$regFee}).";
        } elseif ($l1 + $l2 + $l3 !== $commTotal) {
            $error = "L1 ({$l1}) + L2 ({$l2}) + L3 ({$l3}) must equal Commission total ({$commTotal}).";
        }
    }

    if (empty($error)) {
        foreach ($settings as $key => $value) {
            $existing = Database::fetchOne("SELECT id FROM settings WHERE setting_key = ?", [$key]);
            if ($existing) {
                Database::update('settings', [
                    'setting_value' => $value,
                ], 'setting_key = ?', [$key]);
            } else {
                Database::insert('settings', [
                    'setting_key'   => $key,
                    'setting_value' => $value,
                    'description'   => $key,
                ]);
            }
        }

        // Clear app_setting cache so new values take effect immediately
        if (function_exists('opcache_reset')) { @opcache_reset(); }

        $success = 'Settings updated!';
    }
}

// Get all settings
$allSettings = Database::fetchAll("SELECT * FROM settings ORDER BY id ASC");

// Build a quick lookup
$settingsMap = [];
foreach ($allSettings as $s) {
    $settingsMap[$s['setting_key']] = $s;
}

// Group by category
$categories = [
    'general'  => ['site_name', 'site_tagline', 'currency', 'admin_email', 'support_phone', 'terms_url', 'privacy_url'],
    'payment'  => ['registration_fee', 'min_withdrawal', 'max_withdrawal'],
    'commission' => ['commission_l1', 'commission_l2', 'commission_l3', 'company_earning', 'commission_total'],
    'abliner' => ['abliner_api_key', 'abliner_webhook_secret'],
    'payout'   => ['payout_channel', 'payout_webhook_url'],
    'sms'      => ['meseji_api_key', 'meseji_api_url', 'meseji_sender_id', 'meseji_enabled', 'sms_welcome_enabled', 'sms_payment_enabled'],
    'system'   => ['maintenance_mode'],
];

$pageTitle = 'Settings';
include __DIR__ . '/admin_header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-cog me-2" style="color:var(--primary);"></i>Settings</h1>
    <p>Manage system settings</p>
</div>

<?php if ($success): ?>
    <div class="alert alert-success d-flex align-items-center" role="alert">
        <i class="fas fa-check-circle me-2"></i> <?= $success ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i> <?= $error ?>
    </div>
<?php endif; ?>

<form method="POST">
    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Auth::generateCSRF() ?>">

    <!-- General Settings -->
    <div class="card mb-4">
        <div class="card-header">
            <h6><i class="fas fa-globe me-1"></i> General Settings</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <?php foreach ($categories['general'] as $key): ?>
                    <?php $s = $settingsMap[$key] ?? null; if (!$s) continue; ?>
                    <div class="col-md-6">
                        <label class="form-label" style="font-weight:700;font-size:0.85rem;"><?= $s['description'] ?: $key ?></label>
                        <input type="text" class="form-control" name="settings[<?= $key ?>]" value="<?= sanitize($s['setting_value']) ?>">
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Payment Settings -->
    <div class="card mb-4">
        <div class="card-header">
            <h6><i class="fas fa-credit-card me-1"></i> Payment Settings</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <?php foreach ($categories['payment'] as $key): ?>
                    <?php $s = $settingsMap[$key] ?? null; if (!$s) continue; ?>
                    <div class="col-md-6">
                        <label class="form-label" style="font-weight:700;font-size:0.85rem;"><?= $s['description'] ?: $key ?></label>
                        <input type="number" class="form-control" name="settings[<?= $key ?>]" value="<?= sanitize($s['setting_value']) ?>">
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Commission Settings -->
    <div class="card mb-4">
        <div class="card-header">
            <h6><i class="fas fa-coins me-1"></i> Commission Settings</h6>
        </div>
        <div class="card-body">
            <!-- Live Breakdown Preview -->
            <div id="commissionBreakdown" style="background:#f8f9fa;border:1px solid #e3e6f0;border-radius:8px;padding:1rem;margin-bottom:1.25rem;">
                <div style="font-weight:700;font-size:0.85rem;color:#555;margin-bottom:0.5rem;">
                    <i class="fas fa-calculator me-1"></i> Commission Breakdown (per registration)
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:0.4rem 1.5rem;font-size:0.85rem;">
                    <span>L1 (Direct): <strong id="previewL1">0</strong></span>
                    <span>L2 (Grand): <strong id="previewL2">0</strong></span>
                    <span>L3 (Great-grand): <strong id="previewL3">0</strong></span>
                    <span>Company: <strong id="previewCompany">0</strong></span>
                    <span style="font-weight:700;border-top:1px solid #ccc;padding-top:4px;">Total: <strong id="previewTotal">0</strong></span>
                </div>
                <div id="validationMsg" style="display:none;margin-top:0.5rem;font-size:0.8rem;font-weight:600;"></div>
            </div>

            <div class="row g-3">
                <?php foreach ($categories['commission'] as $key): ?>
                    <?php $s = $settingsMap[$key] ?? null; if (!$s) continue; ?>
                    <div class="col-md-4">
                        <label class="form-label" style="font-weight:700;font-size:0.85rem;">
                            <?= $s['description'] ?: $key ?>
                        </label>
                        <input type="number" class="form-control commission-input" name="settings[<?= $key ?>]"
                               value="<?= sanitize($s['setting_value']) ?>"
                               data-key="<?= $key ?>" min="0" step="100">
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h6><i class="fas fa-plug me-1"></i> Abliner Payments</h6></div>
        <div class="card-body">
            <p>Collect registration payments, verify mobile money recipients, and send withdrawals with Abliner.</p>
            <div class="row g-3">
                <?php foreach (['abliner_api_key' => 'API key', 'abliner_webhook_secret' => 'Webhook signing secret'] as $key => $label): ?>
                <div class="col-md-6">
                    <label class="form-label" for="<?= $key ?>"><?= $label ?></label>
                    <input id="<?= $key ?>" type="password" autocomplete="new-password" class="form-control" name="settings[<?= $key ?>]" value="" placeholder="Leave blank to keep the saved secret">
                    <small class="text-muted"><?= !empty($settingsMap[$key]['setting_value']) || getenv(strtoupper($key)) ? 'Configured' : 'Not configured' ?></small>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="mt-3 mb-1"><strong>Callback URL:</strong></p>
            <code><?= htmlspecialchars(rtrim(SITE_URL, '/') . '/webhooks/abliner.php', ENT_QUOTES) ?></code>
            <p class="text-muted mt-2 mb-0">Save this HTTPS URL in Abliner Dashboard → API Keys → Add Your URL callback. Generate your personal signing secret there. Both secrets stay on the server.</p>
        </div>
    </div>

    <!-- SMS Settings (Meseji) -->
    <div class="card mb-4">
        <div class="card-header">
            <h6><i class="fas fa-sms me-1"></i> SMS (Meseji Gateway)</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" style="font-weight:700;font-size:0.85rem;">API Key</label>
                    <input type="text" class="form-control" name="settings[meseji_api_key]" value="<?= sanitize($settingsMap['meseji_api_key']['setting_value'] ?? '') ?>" placeholder="zs_xxxxxxxxxxxxxxxxxxxx">
                    <small class="text-muted">From Meseji dashboard &rarr; Developer Settings (starts with zs_)</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label" style="font-weight:700;font-size:0.85rem;">Sender ID</label>
                    <input type="text" class="form-control" name="settings[meseji_sender_id]" value="<?= sanitize($settingsMap['meseji_sender_id']['setting_value'] ?? 'MESEJI') ?>" maxlength="11">
                    <small class="text-muted">1-11 characters, must be approved (default: MESEJI)</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label" style="font-weight:700;font-size:0.85rem;">API URL</label>
                    <input type="text" class="form-control" name="settings[meseji_api_url]" value="<?= sanitize($settingsMap['meseji_api_url']['setting_value'] ?? 'https://meseji.co.tz/api/v1') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" style="font-weight:700;font-size:0.85rem;">Enable SMS</label>
                    <select class="form-select" name="settings[meseji_enabled]">
                        <option value="0" <?= ($settingsMap['meseji_enabled']['setting_value'] ?? '0') === '0' ? 'selected' : '' ?>>Disabled</option>
                        <option value="1" <?= ($settingsMap['meseji_enabled']['setting_value'] ?? '0') === '1' ? 'selected' : '' ?>>Enabled</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" style="font-weight:700;font-size:0.85rem;">Welcome SMS on registration</label>
                    <select class="form-select" name="settings[sms_welcome_enabled]">
                        <option value="1" <?= ($settingsMap['sms_welcome_enabled']['setting_value'] ?? '1') !== '0' ? 'selected' : '' ?>>On</option>
                        <option value="0" <?= ($settingsMap['sms_welcome_enabled']['setting_value'] ?? '1') === '0' ? 'selected' : '' ?>>Off</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" style="font-weight:700;font-size:0.85rem;">SMS on payment success</label>
                    <select class="form-select" name="settings[sms_payment_enabled]">
                        <option value="1" <?= ($settingsMap['sms_payment_enabled']['setting_value'] ?? '1') !== '0' ? 'selected' : '' ?>>On</option>
                        <option value="0" <?= ($settingsMap['sms_payment_enabled']['setting_value'] ?? '1') === '0' ? 'selected' : '' ?>>Off</option>
                    </select>
                </div>
            </div>
            <div class="alert alert-info mt-3 mb-0" style="font-size:0.8rem;">
                <i class="fas fa-info-circle me-1"></i>
                Manage broadcasts, templates, and test SMS from the
                <a href="<?= SITE_URL ?>/admin/sms" style="font-weight:700;">SMS Broadcast</a> page.
            </div>
        </div>
    </div>

    <!-- System Settings -->
    <div class="card mb-4">
        <div class="card-header">
            <h6><i class="fas fa-tools me-1"></i> System</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <?php foreach ($categories['system'] as $key): ?>
                    <?php $s = $settingsMap[$key] ?? null; if (!$s) continue; ?>
                    <div class="col-md-6">
                        <label class="form-label" style="font-weight:700;font-size:0.85rem;"><?= $s['description'] ?: $key ?></label>
                        <select class="form-select" name="settings[<?= $key ?>]">
                            <option value="0" <?= $s['setting_value'] === '0' ? 'selected' : '' ?>>Off</option>
                            <option value="1" <?= $s['setting_value'] === '1' ? 'selected' : '' ?>>On</option>
                        </select>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="text-end mb-4">
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="fas fa-save me-1"></i> Save Settings
        </button>
    </div>
</form>

<script>
(function() {
    var inputs = document.querySelectorAll('.commission-input');
    var regFeeInput = document.querySelector('input[name="settings[registration_fee]"]');

    function getVal(key) {
        var el = document.querySelector('input[name="settings[' + key + ']"]');
        return el ? parseInt(el.value) || 0 : 0;
    }

    function updatePreview() {
        var l1 = getVal('commission_l1');
        var l2 = getVal('commission_l2');
        var l3 = getVal('commission_l3');
        var company = getVal('company_earning');
        var commTotal = getVal('commission_total');
        var regFee = regFeeInput ? parseInt(regFeeInput.value) || 0 : 0;

        var commSum = l1 + l2 + l3;

        document.getElementById('previewL1').textContent = 'TZS ' + l1.toLocaleString();
        document.getElementById('previewL2').textContent = 'TZS ' + l2.toLocaleString();
        document.getElementById('previewL3').textContent = 'TZS ' + l3.toLocaleString();
        document.getElementById('previewCompany').textContent = 'TZS ' + company.toLocaleString();
        document.getElementById('previewTotal').textContent = 'TZS ' + (commSum + company).toLocaleString();

        var msg = document.getElementById('validationMsg');
        if (regFee > 0 && company > 0 && commTotal > 0) {
            if (company + commTotal !== regFee) {
                msg.style.display = 'block';
                msg.style.color = '#e74a3b';
                msg.textContent = 'Company (' + company.toLocaleString() + ') + Commission (' + commTotal.toLocaleString() + ') = ' + (company + commTotal).toLocaleString() + ' but Registration fee is ' + regFee.toLocaleString();
            } else if (commSum !== commTotal) {
                msg.style.display = 'block';
                msg.style.color = '#e74a3b';
                msg.textContent = 'L1+L2+L3 = ' + commSum.toLocaleString() + ' but Commission total is ' + commTotal.toLocaleString();
            } else {
                msg.style.display = 'block';
                msg.style.color = '#1cc88a';
                msg.textContent = 'All amounts match correctly.';
            }
        } else {
            msg.style.display = 'none';
        }
    }

    inputs.forEach(function(input) {
        input.addEventListener('input', updatePreview);
    });
    if (regFeeInput) regFeeInput.addEventListener('input', updatePreview);
    updatePreview();
})();
</script>

<?php include __DIR__ . '/admin_footer.php'; ?>
