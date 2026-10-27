<?php
require_once dirname(__DIR__) . '/classes/ReferralLookup.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
Auth::initSession();
StaffAccess::requirePage('referral-lookup');
$code = is_string($_GET['code'] ?? null) ? trim($_GET['code']) : '';
$relativeCode = is_string($_GET['relative'] ?? null) ? trim($_GET['relative']) : '';
$result = null; $error = '';
if ($code !== '') {
    try { $result = ReferralLookup::find($code, $relativeCode); }
    catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
    catch (Throwable $e) { $error = 'Could not load referral details. Please try again.'; }
}
$pageTitle = 'Referral Code Lookup';
include __DIR__ . '/admin_header.php';
?>
<div class="page-header"><h1>Referral Code Lookup</h1><p>Tafuta code, angalia aliyemuunga na mnyororo wa referrals. Admin pekee.</p></div>
<div class="card mb-4"><div class="card-body"><form method="get" class="row g-3">
    <div class="col-md-5"><label for="lookup-code" class="form-label">Referral code</label><input id="lookup-code" class="form-control" name="code" value="<?= sanitize($code) ?>" maxlength="32" required placeholder="Mfano: JOHN1234"></div>
    <div class="col-md-5"><label for="relative-code" class="form-label">Linganisha na code nyingine (hiari)</label><input id="relative-code" class="form-control" name="relative" value="<?= sanitize($relativeCode) ?>" maxlength="32" placeholder="Code ya mwenye mtandao"></div>
    <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit">Search</button></div>
</form></div></div>
<?php if ($error): ?><div class="alert alert-warning"><?= sanitize($error) ?></div><?php endif; ?>
<?php if ($code !== '' && !$result && !$error): ?><div class="alert alert-info">Hakuna member mwenye code hiyo.</div><?php endif; ?>
<?php if ($result): $member = $result['user']; ?>
<div class="card mb-4"><div class="card-body">
    <div class="d-flex justify-content-between gap-2 flex-wrap"><h2 class="h5"><?= sanitize($member['full_name']) ?></h2><?= statusBadge($member['status']) ?></div>
    <dl class="row mb-0" style="overflow-wrap:anywhere">
        <dt class="col-sm-3">Code</dt><dd class="col-sm-9"><?= sanitize($member['referral_code']) ?></dd>
        <dt class="col-sm-3">Phone</dt><dd class="col-sm-9"><?= sanitize(formatPhone($member['phone'])) ?></dd>
        <dt class="col-sm-3">Email</dt><dd class="col-sm-9"><?= sanitize($member['email'] ?? '—') ?></dd>
        <dt class="col-sm-3">Joined</dt><dd class="col-sm-9"><?= sanitize($member['created_at']) ?></dd>
        <dt class="col-sm-3">Aliyemuunga</dt><dd class="col-sm-9"><?= isset($result['chain'][1]) ? sanitize($result['chain'][1]['full_name'] . ' · ' . $result['chain'][1]['referral_code']) : (empty($member['referred_by']) ? 'Alijiunga bila referral code' : 'Inviter record unavailable') ?></dd>
        <dt class="col-sm-3">Referrals zake</dt><dd class="col-sm-9">Level 1: <?= (int) $result['direct'] ?> · Level 2: <?= (int) $result['grand'] ?></dd>
    </dl>
    <a class="btn btn-outline-primary mt-3" href="referral-tree?user_id=<?= (int) $member['id'] ?>">View their referral network</a>
</div></div>
<?php if ($result['relative']): ?><div class="alert alert-info">
    <?= sanitize($member['referral_code']) ?>:
    <?php if ($result['level'] === 0): ?>Code zote mbili ni account hiyo hiyo.
    <?php elseif ($result['level'] !== null): ?><strong>Level <?= (int) $result['level'] ?></strong> kwenye mtandao wa <?= sanitize($result['relative']['full_name'] . ' · ' . $result['relative']['referral_code']) ?>.
    <?php else: ?>Haijaonekana kwenye mnyororo wa code ya kulinganisha ndani ya rekodi zilizoonyeshwa.
    <?php endif; ?>
</div><?php endif; ?>
<?php if ($result['warning']): ?><div class="alert alert-warning"><?= sanitize($result['warning']) ?></div><?php endif; ?>
<div class="card"><div class="card-header"><h2 class="h6 mb-0">Nani amemuunga nani?</h2></div><div class="card-body">
    <p class="text-muted small">Level ni uhusiano kati ya watu wawili. Member huyu ni Level 1 kwa aliyemuunga moja kwa moja, na Level 2 kwa aliyemuunga huyo. Muonekano huu haubadilishi commissions au malipo.</p>
    <ol class="list-group list-group-numbered">
    <?php foreach ($result['chain'] as $depth => $node): ?>
        <li class="list-group-item" style="overflow-wrap:anywhere"><a href="?code=<?= urlencode($node['referral_code']) ?>"><?= sanitize($node['full_name']) ?></a> · <code><?= sanitize($node['referral_code']) ?></code>
        <div class="small text-muted"><?php if ($depth === 0): ?>Member uliyetafuta<?php else: ?>Member uliyetafuta yuko Level <?= (int) $depth ?> kwa huyu<?php endif; ?></div>
        </li>
    <?php endforeach; ?>
    </ol>
</div></div>
<?php endif; ?>
<?php include __DIR__ . '/admin_footer.php'; ?>
