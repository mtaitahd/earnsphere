<?php
/**
 * EarnSphere - Admin: Settings
 * Supports payout channel configuration
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/classes/CommissionPlan.php';
require_once dirname(__DIR__) . '/classes/WithdrawalCharge.php';

Auth::initSession();
require_once dirname(__DIR__) . '/classes/StaffAccess.php';
StaffAccess::requirePage('settings');

if (($_SERVER['REQUEST_METHOD']??'GET')==='POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH']??0)>0) {
    setFlash('error','Upload exceeds the hosting request limit. Upload fewer or smaller files, or use YouTube links.');
    header('Location: '.SITE_URL.'/admin/settings#welcome-media'); exit;
}
$success = getFlash('success') ?? '';
$error = '';

require_once dirname(__DIR__) . '/classes/WelcomeMedia.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['welcome_media','welcome_library'], true)) {
    if (!Auth::validateCSRF()) { http_response_code(403); exit('Invalid security token.'); }
    try {
        if (($_POST['action'] ?? '') === 'welcome_library') WelcomeMedia::saveLibrary($_POST, $_FILES['media_files'] ?? null);
        else WelcomeMedia::save($_POST, $_FILES['welcome_audio'] ?? null);
        setFlash('success', 'Welcome audio/video updated.');
    } catch (InvalidArgumentException $e) { setFlash('error', $e->getMessage()); }
    catch (Throwable $e) { error_log('Welcome media save: ' . $e->getMessage()); setFlash('error', 'Welcome media could not be saved. Please try again.'); }
    header('Location: ' . SITE_URL . '/admin/settings#welcome-media');
    exit;
}
$error = getFlash('error') ?? '';

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCSRF()) { http_response_code(403); exit('Invalid security token.'); }
    $settings = $_POST['settings'] ?? [];
    if (!is_array($settings)) { http_response_code(422); exit('Invalid settings.'); }
    foreach (array_keys($settings) as $settingKey) if (str_starts_with($settingKey, 'welcome_media_')) unset($settings[$settingKey]);
    unset($settings['snippe_api_key'], $settings['snippe_webhook_secret'], $settings['snippe_api_url'], $settings['snippe_api_version']);
    foreach (['abliner_api_key', 'abliner_webhook_secret'] as $secret) {
        if (isset($settings[$secret]) && trim((string) $settings[$secret]) === '') unset($settings[$secret]);
    }

    // Derive totals on the server; posted total/company/L3 values cannot override the two-level plan.
    try {
        $settings = array_replace($settings, CommissionPlan::fromInput($settings));
        if (isset($settings['withdrawal_charge'])) {
            $settings['withdrawal_charge'] = WithdrawalCharge::validate($settings['withdrawal_charge']);
            $minimum = (float) ($settings['min_withdrawal'] ?? app_setting('min_withdrawal', MIN_WITHDRAWAL));
            if ($settings['withdrawal_charge'] >= $minimum) throw new InvalidArgumentException('Withdrawal charge must be less than the minimum withdrawal amount.');
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }

    if (empty($error)) {
        Database::beginTransaction();
        try {
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

            Database::commit();
            setFlash('success', 'Settings updated. The registration fee and Level 1/Level 2 commissions now apply to new payments.');
            header('Location: ' . SITE_URL . '/admin/settings');
            exit;
        } catch (Throwable $e) {
            if (Database::inTransaction()) Database::rollback();
            error_log('Settings update failed: ' . $e->getMessage());
            $error = 'Settings could not be saved. Please try again.';
        }
    }
}

// Get all settings
$allSettings = Database::fetchAll("SELECT * FROM settings ORDER BY id ASC");

// Build a quick lookup
$settingsMap = [];
foreach ($allSettings as $s) {
    $settingsMap[$s['setting_key']] = $s;
}

foreach (['registration_fee' => 'Registration fee (TZS)', 'commission_l1' => 'Level 1 commission (TZS)', 'commission_l2' => 'Level 2 commission (TZS)', 'commission_total' => 'Total referral commissions (Level 1 + Level 2)', 'company_earning' => 'Company share (automatic)'] as $key => $label) {
    $defaults = ['registration_fee' => REGISTRATION_FEE, 'commission_l1' => COMMISSION_L1, 'commission_l2' => COMMISSION_L2, 'commission_total' => 0, 'company_earning' => 0];
    $settingsMap[$key] = ['setting_value' => $settingsMap[$key]['setting_value'] ?? (string) $defaults[$key], 'description' => $label];
}

$settingsMap['withdrawal_charge'] = ['setting_value' => $settingsMap['withdrawal_charge']['setting_value'] ?? '0', 'description' => 'Fixed withdrawal charge (TZS; 0 = free)'];

// Group by category
$categories = [
    'general'  => ['site_name', 'site_tagline', 'currency', 'admin_email', 'support_phone', 'terms_url', 'privacy_url'],
    'payment'  => ['registration_fee', 'min_withdrawal', 'max_withdrawal', 'withdrawal_charge'],
    'commission' => ['commission_l1', 'commission_l2', 'company_earning', 'commission_total'],
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

    <p class="text-muted">The fixed withdrawal charge is deducted from the requested amount. For example, a TZS 5,000 withdrawal with a TZS 500 charge sends TZS 4,500 to the user.</p>
    <!-- Commission Settings -->
    <p class="text-muted">Set the registration fee and two commission amounts. Total commissions and the remaining company share update automatically. Existing payments keep their original amounts.</p>
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
                               data-key="<?= $key ?>" min="0" step="1" <?= in_array($key, ['commission_total', 'company_earning'], true) ? 'readonly' : '' ?>>
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

<?php include __DIR__ . '/welcome_media_form.php'; ?>

<script src="<?= sanitize(SITE_URL) ?>/assets/js/commission-settings.js"></script>

<?php include __DIR__ . '/admin_footer.php'; ?>
