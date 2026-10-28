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
require_once dirname(__DIR__) . '/classes/SnippePayment.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['snippe_test','snippe_test_status'], true)) {
    if (!Auth::validateCSRF()) { http_response_code(403); exit('Invalid security token.'); }
    try {
        $tester = new SnippePayment();
        if ($_POST['action'] === 'snippe_test') {
            $result = $tester->testPush((int)$_SESSION['user_id'], $_POST);
            if (empty($result['success'])) throw new InvalidArgumentException($result['error']);
            setFlash('success', $result['message'].' Order: '.$result['order_id'].' Status: '.$result['status']);
        } else {
            $order = is_string($_POST['order_id'] ?? null) ? $_POST['order_id'] : '';
            $test = Database::fetchOne("SELECT * FROM payments WHERE order_id = ? AND user_id = ? AND gateway = 'snippe' AND purpose = 'gateway_test'", [$order,(int)$_SESSION['user_id']]);
            if (!$test) throw new InvalidArgumentException('Test payment not found.');
            $tester->reconcile((int)$test['id']);
            $test = Database::fetchOne('SELECT status FROM payments WHERE id = ?', [$test['id']]);
            setFlash('success', 'Test status: '.$test['status'].'. No new USSD request was sent.');
        }
    } catch (InvalidArgumentException $e) { setFlash('error', $e->getMessage()); }
    catch (Throwable $e) { error_log('Snippe admin test: '.$e->getMessage()); setFlash('error', 'Could not confirm the test request. Check its status before trying again.'); }
    header('Location: '.SITE_URL.'/admin/settings#snippe-test'); exit;
}

// Each save accepts only the selected section's fields.
$settingSections = [
    'general'=>['label'=>'General settings','keys'=>['site_name','site_tagline','currency','admin_email','support_phone','terms_url','privacy_url']],
    'finance'=>['label'=>'Payments & commissions','keys'=>['registration_fee','min_withdrawal','max_withdrawal','withdrawal_charge','commission_l1','commission_l2','company_earning','commission_total']],
    'abliner'=>['label'=>'Abliner payments','keys'=>['abliner_api_key','abliner_webhook_secret']],
    'snippe'=>['label'=>'Snippe payments & withdrawals','keys'=>['snippe_enabled','snippe_payout_enabled','snippe_api_key','snippe_webhook_secret']],
    'sms'=>['label'=>'SMS settings','keys'=>['meseji_api_key','meseji_api_url','meseji_sender_id','meseji_enabled','sms_welcome_enabled','sms_payment_enabled']],
    'system'=>['label'=>'System controls','keys'=>['maintenance_mode']],
];
$selectedSection='';

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validateCSRF()) { http_response_code(403); exit('Invalid security token.'); }
    $selectedSection=is_string($_POST['section']??null)?$_POST['section']:'';
    if (($_POST['action']??'')!=='save_section' || !isset($settingSections[$selectedSection])) {http_response_code(422);exit('Choose a valid settings section.');}
    $settings = $_POST['settings'] ?? [];
    if (!is_array($settings)) { http_response_code(422); exit('Invalid settings.'); }
    if (array_diff(array_keys($settings),$settingSections[$selectedSection]['keys'])) {http_response_code(422);exit('This form can only update its own section.');}
    foreach ($settings as $value) if (!is_string($value)) {http_response_code(422);exit('Invalid setting value.');}
    if (isset($settings['snippe_payout_enabled']) && !in_array($settings['snippe_payout_enabled'],['0','1'],true)) {http_response_code(422);exit('Invalid Snippe withdrawal setting.');}
    if (isset($settings['snippe_enabled']) && !in_array($settings['snippe_enabled'],['0','1'],true)) {http_response_code(422);exit('Invalid Snippe setting.');}
    foreach (['abliner_api_key', 'abliner_webhook_secret', 'snippe_api_key', 'snippe_webhook_secret'] as $secret) {
        if (isset($settings[$secret]) && trim((string) $settings[$secret]) === '') unset($settings[$secret]);
    }

    // Derive totals on the server; posted total/company/L3 values cannot override the two-level plan.
    try {
        if ($selectedSection==='finance') $settings = array_replace($settings, CommissionPlan::fromInput($settings));
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
            setFlash('success', $settingSections[$selectedSection]['label'].' saved.');
            header('Location: ' . SITE_URL . '/admin/settings#'.($selectedSection==='snippe'?'snippe-settings':'settings-'.$selectedSection));
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
    'snippe' => ['snippe_enabled','snippe_api_key','snippe_webhook_secret'],
    'payout'   => ['payout_channel', 'payout_webhook_url'],
    'sms'      => ['meseji_api_key', 'meseji_api_url', 'meseji_sender_id', 'meseji_enabled', 'sms_welcome_enabled', 'sms_payment_enabled'],
    'system'   => ['maintenance_mode'],
];

$pageTitle = 'Settings';
include __DIR__ . '/admin_header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-cog me-2" style="color:var(--primary);"></i>Settings</h1>
    <p>Choose a section to manage its settings.</p>
</div>

<?php if ($success): ?>
    <div class="alert alert-success d-flex align-items-center" role="alert">
        <i class="fas fa-check-circle me-2"></i> <?= sanitize($success) ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i> <?= sanitize($error) ?>
    </div>
<?php endif; ?>

<p class="text-muted">Open the section you need, make your changes, then save that section.</p>
<div class="mb-3 d-flex flex-wrap gap-2"><a class="btn btn-outline-primary" href="#snippe-test">Test USSD push</a><a class="btn btn-outline-primary" href="<?= sanitize(SITE_URL) ?>/admin/snippe-wallet">Withdraw from Snippe</a></div>
<details class="settings-section mb-3" id="settings-general" <?= $selectedSection==='general'?'open':'' ?>>
    <summary>General settings<span aria-hidden="true" class="settings-chevron">⌄</span></summary>
    <div class="settings-section-body"><form method="POST">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Auth::generateCSRF() ?>">
        <input type="hidden" name="action" value="save_section"><input type="hidden" name="section" value="general">
    <!-- General Settings -->
    <div class="card mb-4">

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


        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save General settings</button>
    </form></div>
</details>
<details class="settings-section mb-3" id="settings-finance" <?= $selectedSection==='finance'?'open':'' ?>>
    <summary>Payments & commissions<span aria-hidden="true" class="settings-chevron">⌄</span></summary>
    <div class="settings-section-body"><form method="POST">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Auth::generateCSRF() ?>">
        <input type="hidden" name="action" value="save_section"><input type="hidden" name="section" value="finance">
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


        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Payments &amp; commissions</button>
    </form></div>
</details>
<details class="settings-section mb-3" id="settings-abliner" <?= $selectedSection==='abliner'?'open':'' ?>>
    <summary>Abliner payments<span aria-hidden="true" class="settings-chevron">⌄</span></summary>
    <div class="settings-section-body"><form method="POST">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Auth::generateCSRF() ?>">
        <input type="hidden" name="action" value="save_section"><input type="hidden" name="section" value="abliner">
    <div class="card mb-4">

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


        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Abliner payments</button>
    </form></div>
</details>
<details class="settings-section mb-3" id="snippe-settings" <?= $selectedSection==='snippe'?'open':'' ?>>
    <summary>Snippe USSD Push &amp; withdrawals<span aria-hidden="true" class="settings-chevron">⌄</span></summary>
    <div class="settings-section-body"><form method="POST">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Auth::generateCSRF() ?>">
        <input type="hidden" name="action" value="save_section"><input type="hidden" name="section" value="snippe">
    <div class="card mb-4" style="scroll-margin-top:90px">

        <div class="card-body">
            <div class="mb-3">
        <label class="form-label" for="snippe-payout-enabled">Snippe withdrawals</label>
        <select class="form-select" id="snippe-payout-enabled" name="settings[snippe_payout_enabled]">
            <option value="0" <?= (string)app_setting('snippe_payout_enabled','0')!=='1'?'selected':'' ?>>Disabled</option>
            <option value="1" <?= (string)app_setting('snippe_payout_enabled','0')==='1'?'selected':'' ?>>Enabled</option>
        </select>
        <small>Requires API key scopes disbursement:create and disbursement:read, plus available Snippe balance. Recipient verification uses the existing Abliner lookup.</small>
            </div>

            <p>Collect registration payments by sending a USSD PIN prompt to the customer’s mobile money number.</p>
            <label for="snippe-enabled">Enable Snippe USSD push</label>
            <select id="snippe-enabled" class="form-select mb-3" name="settings[snippe_enabled]"><option value="0">Off</option><option value="1" <?= ($settingsMap['snippe_enabled']['setting_value']??'0')==='1'?'selected':'' ?>>On</option></select>
            <div class="row g-3"><?php foreach(['snippe_api_key'=>'API key (collection:create and collection:read; withdrawals also need disbursement:create and disbursement:read)','snippe_webhook_secret'=>'Webhook signing secret'] as $key=>$label): ?>
                <div class="col-md-6"><label for="<?= $key ?>"><?= $label ?></label><input type="password" id="<?= $key ?>" name="settings[<?= $key ?>]" class="form-control" value="" autocomplete="new-password" placeholder="Leave blank to keep the saved secret"><small><?= !empty($settingsMap[$key]['setting_value']) || getenv(strtoupper($key)) ? 'Configured':'Not configured' ?></small></div>
            <?php endforeach; ?></div>
            <p class="mt-3">Webhook: <code><?= sanitize(rtrim(SITE_URL,'/').'/webhooks/snippe.php') ?></code></p>
            <p class="text-muted">Uses POST /v1/payments with your API key. Only the API key and webhook signing secret are needed. Account activation waits for verified payment.</p>
        </div>
    </div>


        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save Snippe payments &amp; withdrawals</button>
    </form></div>
</details>
<details class="settings-section mb-3" id="settings-sms" <?= $selectedSection==='sms'?'open':'' ?>>
    <summary>SMS settings<span aria-hidden="true" class="settings-chevron">⌄</span></summary>
    <div class="settings-section-body"><form method="POST">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Auth::generateCSRF() ?>">
        <input type="hidden" name="action" value="save_section"><input type="hidden" name="section" value="sms">
    <!-- SMS Settings (Meseji) -->
    <div class="card mb-4">

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


        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save SMS settings</button>
    </form></div>
</details>
<details class="settings-section mb-3" id="settings-system" <?= $selectedSection==='system'?'open':'' ?>>
    <summary>System controls<span aria-hidden="true" class="settings-chevron">⌄</span></summary>
    <div class="settings-section-body"><form method="POST">
        <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Auth::generateCSRF() ?>">
        <input type="hidden" name="action" value="save_section"><input type="hidden" name="section" value="system">
    <!-- System Settings -->
    <div class="card mb-4">

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


        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save System controls</button>
    </form></div>
</details>


<?php
$testAdmin = Auth::getUser();
$testReady = (new SnippePayment())->testReady();
$lastTest = Database::fetchOne("SELECT * FROM payments WHERE user_id = ? AND gateway = 'snippe' AND purpose = 'gateway_test' ORDER BY id DESC LIMIT 1", [(int)$_SESSION['user_id']]);
?>
<details class="settings-section mb-3" id="settings-test"><summary>Snippe USSD test<span aria-hidden="true" class="settings-chevron">⌄</span></summary><div class="settings-section-body">
<div class="card mb-4" id="snippe-test" style="scroll-margin-top:90px">
    <div class="card-header"><h6>Test Snippe USSD push</h6></div>
    <div class="card-body">
        <p>Send a real payment request to your own phone. Approving with your PIN deducts the amount shown. This test does not activate a member or add wallet earnings.</p>
        <?php if (!$testReady): ?><div class="alert alert-info">Save your Snippe API key and webhook secret first.</div><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Auth::generateCSRF() ?>">
            <input type="hidden" name="action" value="snippe_test">
            <input type="hidden" name="request_key" value="<?= 'SN-'.bin2hex(random_bytes(12)) ?>">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label" for="test-phone">Your mobile money number</label><input id="test-phone" name="phone" type="tel" class="form-control" required value="<?= sanitize($testAdmin['phone'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label" for="test-amount">Test amount (TZS)</label><input id="test-amount" name="amount" type="number" class="form-control" min="500" max="10000" step="1" value="500" required></div>
                <div class="col-md-6"><label class="form-label" for="test-name">First and last name</label><input id="test-name" name="name" class="form-control" maxlength="200" required value="<?= sanitize($testAdmin['full_name'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label" for="test-email">Email</label><input id="test-email" name="email" type="email" class="form-control" maxlength="254" required value="<?= sanitize($testAdmin['email'] ?? '') ?>"></div>
            </div>
            <label class="d-block my-3"><input type="checkbox" name="confirmed" value="1" required> I understand this sends a real payment request.</label>
            <button class="btn btn-primary" <?= $testReady ? '' : 'disabled' ?>>Send test USSD push</button>
        </form>
        <?php if ($lastTest): ?>
            <hr><p>Latest test: <?= sanitize($lastTest['order_id']) ?> · TZS <?= number_format((float)$lastTest['amount']) ?> · <?= sanitize($lastTest['phone']) ?> · <?= sanitize($lastTest['status']) ?></p>
            <form method="POST">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Auth::generateCSRF() ?>"><input type="hidden" name="action" value="snippe_test_status"><input type="hidden" name="order_id" value="<?= sanitize($lastTest['order_id']) ?>">
                <button class="btn btn-outline-primary">Check test status</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</div></details>
<details class="settings-section mb-3" id="settings-guides"><summary>Know Your Account — audio, videos &amp; links<span aria-hidden="true" class="settings-chevron">⌄</span></summary><div class="settings-section-body">
<?php include __DIR__ . '/welcome_media_form.php'; ?>
</div></details>

<style>
.settings-section{background:#fff;border:1px solid #e3e6f0;border-radius:8px;scroll-margin-top:90px}
.settings-section>summary{cursor:pointer;padding:1.1rem 1.25rem;font-weight:700;color:var(--primary);display:flex;justify-content:space-between;align-items:center;list-style:none}
.settings-section>summary::-webkit-details-marker{display:none}
.settings-section>summary:focus-visible{outline:2px solid var(--primary);outline-offset:2px}
.settings-section[open]>summary{border-bottom:1px solid #e3e6f0}.settings-section[open] .settings-chevron{transform:rotate(180deg)}
.settings-section-body{padding:1.25rem}.settings-section-body .card{box-shadow:none}
</style>
<script src="<?= sanitize(SITE_URL) ?>/assets/js/settings-sections.js"></script>
<script src="<?= sanitize(SITE_URL) ?>/assets/js/commission-settings.js"></script>

<?php include __DIR__ . '/admin_footer.php'; ?>
