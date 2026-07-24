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
    header('Location: ' . SITE_URL . '/admin/login.php');
    exit;
}

$success = '';
$error = '';

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = $_POST['settings'] ?? [];

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

    $success = 'Settings updated!';
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
    'payment'  => ['registration_fee', 'company_earning', 'commission_total', 'min_withdrawal', 'max_withdrawal'],
    'commission' => ['commission_l1', 'commission_l2', 'commission_l3'],
    'snippe'   => ['snippe_api_key', 'snippe_webhook_secret', 'snippe_api_url', 'snippe_api_version'],
    'payout'   => ['payout_channel', 'payout_webhook_url'],
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
            <div class="row g-3">
                <?php foreach ($categories['commission'] as $key): ?>
                    <?php $s = $settingsMap[$key] ?? null; if (!$s) continue; ?>
                    <div class="col-md-4">
                        <label class="form-label" style="font-weight:700;font-size:0.85rem;"><?= $s['description'] ?: $key ?></label>
                        <input type="number" class="form-control" name="settings[<?= $key ?>]" value="<?= sanitize($s['setting_value']) ?>">
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Snippe API Settings -->
    <div class="card mb-4">
        <div class="card-header">
            <h6><i class="fas fa-plug me-1"></i> Snippe Payment API (Collection)</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <?php foreach ($categories['snippe'] as $key): ?>
                    <?php $s = $settingsMap[$key] ?? null; if (!$s) continue; ?>
                    <div class="col-md-6">
                        <label class="form-label" style="font-weight:700;font-size:0.85rem;"><?= $s['description'] ?: $key ?></label>
                        <input type="text" class="form-control" name="settings[<?= $key ?>]" value="<?= sanitize($s['setting_value']) ?>">
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Payout / Disbursement Settings -->
    <div class="card mb-4">
        <div class="card-header">
            <h6><i class="fas fa-paper-plane me-1"></i> Snippe Payout (Disbursement)</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" style="font-weight:700;font-size:0.85rem;">Payout Channel</label>
                    <select class="form-select" name="settings[payout_channel]">
                        <?php
                        $currentChannel = $settingsMap['payout_channel']['setting_value'] ?? 'mobile';
                        $channels = [
                            'mobile'       => 'Mobile Money (All)',
                            'mpesa'        => 'Vodacom M-Pesa',
                            'airtel_money' => 'Airtel Money',
                            'mixx_yas'     => 'Mixx by Yas (Tigo Pesa)',
                            'halopesa'     => 'HaloPesa',
                        ];
                        foreach ($channels as $val => $label):
                        ?>
                            <option value="<?= $val ?>" <?= $currentChannel === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Payment channel for customers</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label" style="font-weight:700;font-size:0.85rem;">Payout Webhook URL</label>
                    <input type="url" class="form-control" name="settings[payout_webhook_url]"
                           value="<?= sanitize($settingsMap['payout_webhook_url']['setting_value'] ?? 'https://earnsphere.site.je/webhooks/snippe.php') ?>">
                    <small class="text-muted">Snippe will send status updates to this URL</small>
                    <div class="alert alert-warning mt-2 mb-0" style="font-size:0.8rem;padding:0.5rem 0.75rem;">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        <strong>HTTPS required!</strong> Snippe does not allow HTTP webhooks. On localhost, webhooks will not work. Deploy with HTTPS URL in production.
                    </div>
                </div>
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

<?php include __DIR__ . '/admin_footer.php'; ?>
