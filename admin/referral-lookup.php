<?php
require_once dirname(__DIR__) . '/classes/ReferralLookup.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
Auth::initSession();
StaffAccess::requirePage('referral-lookup');
$code = is_string($_GET['code'] ?? null) ? trim($_GET['code']) : '';
$relativeCode = is_string($_GET['relative'] ?? null) ? trim($_GET['relative']) : '';
$selectedLevel = in_array($_GET['level'] ?? '', ['1', '2'], true) ? (int) $_GET['level'] : 0;
$members = null;
$result = null; $error = '';
if ($code !== '') {
    try {
        $result = ReferralLookup::find($code, $relativeCode);
        if ($result && $selectedLevel) $members = ReferralLookup::members($code, $selectedLevel, max(1, (int) ($_GET['page'] ?? 1)));
    }
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
        <dt class="col-sm-3">Referrals zake</dt><dd class="col-sm-9">
            <div class="d-flex flex-wrap gap-2">
            <?php foreach ([1 => $result['direct'], 2 => $result['grand']] as $level => $count): ?>
                <a class="btn <?= $selectedLevel === $level ? 'btn-primary' : 'btn-outline-primary' ?>" href="?<?= sanitize(http_build_query(['code' => $member['referral_code'], 'relative' => $relativeCode, 'level' => $level])) ?>#referral-members" <?= $selectedLevel === $level ? 'aria-current="true"' : '' ?>>Level <?= $level ?>: <?= (int) $count ?> <span class="small">— Ona watu</span></a>
            <?php endforeach; ?>
            </div>
        </dd>
    </dl>
    <a class="btn btn-outline-primary mt-3" href="referral-tree?user_id=<?= (int) $member['id'] ?>">View their referral network</a>
</div></div>
<?php if ($members !== null): ?>
<section class="card mb-4" id="referral-members" aria-labelledby="referral-members-title">
    <div class="card-header"><h2 class="h6 mb-0" id="referral-members-title">Watu wa Level <?= (int) $members['level'] ?> wa <?= sanitize($member['full_name']) ?> (<?= (int) $members['total'] ?>)</h2></div>
    <div class="card-body">
        <?php if (!$members['items']): ?><p class="text-muted mb-0">Hakuna watu kwenye Level <?= (int) $members['level'] ?> kwa sasa.</p><?php endif; ?>
        <?php foreach ($members['items'] as $person): ?>
        <article class="border-bottom py-3" style="overflow-wrap:anywhere">
            <div class="d-flex justify-content-between gap-2 flex-wrap"><a class="fw-bold" href="?<?= sanitize(http_build_query(['code' => $person['referral_code'], 'relative' => $member['referral_code']])) ?>"><?= sanitize($person['full_name']) ?></a><?= statusBadge($person['status']) ?></div>
            <div class="small mt-1">Code: <code><?= sanitize($person['referral_code']) ?></code> · <?= sanitize(formatPhone($person['phone'])) ?></div>
            <div class="small mt-1">Aliunganishwa na: <strong><?= sanitize($person['inviter_name']) ?></strong> · <?= sanitize($person['inviter_code']) ?></div>
            <div class="small text-muted mt-1">Joined: <?= sanitize($person['created_at'] ?? '—') ?></div>
        </article>
        <?php endforeach; ?>
        <?php if ($members['pages'] > 1): ?><nav class="d-flex align-items-center flex-wrap gap-3 mt-3" aria-label="Referral members pages">
            <?php if ($members['page'] > 1): ?><a class="btn btn-outline-primary" href="?<?= sanitize(http_build_query(['code' => $member['referral_code'], 'relative' => $relativeCode, 'level' => $selectedLevel, 'page' => $members['page'] - 1])) ?>#referral-members">Previous</a><?php endif; ?>
            <span class="small">Page <?= (int) $members['page'] ?> / <?= (int) $members['pages'] ?></span>
            <?php if ($members['page'] < $members['pages']): ?><a class="btn btn-outline-primary" href="?<?= sanitize(http_build_query(['code' => $member['referral_code'], 'relative' => $relativeCode, 'level' => $selectedLevel, 'page' => $members['page'] + 1])) ?>#referral-members">Next</a><?php endif; ?>
        </nav><?php endif; ?>
    </div>
</section>
<?php endif; ?>
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
