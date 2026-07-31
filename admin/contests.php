<?php
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/classes/Contest.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

Auth::initSession();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: ' . SITE_URL . '/admin/login');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Auth::validateCSRF($_POST[CSRF_TOKEN_NAME] ?? '')) {
        setFlash('error', 'Security token invalid');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($_POST['action'] === 'create') {
        $title      = trim($_POST['title'] ?? '');
        $description= trim($_POST['description'] ?? '');
        $startDate  = trim($_POST['start_date'] ?? '');
        $endDate    = trim($_POST['end_date'] ?? '');
        $prize1     = (float) ($_POST['prize1'] ?? 0);
        $prize2     = (float) ($_POST['prize2'] ?? 0);
        $prize3     = (float) ($_POST['prize3'] ?? 0);
        $minRefs    = max(0, (int) ($_POST['min_referrals'] ?? 1));

        if ($title && $startDate && $endDate && $prize1 > 0 && $endDate >= $startDate) {
            Database::insert('contests', [
                'title'          => $title,
                'description'    => $description,
                'start_date'     => $startDate,
                'end_date'       => $endDate,
                'prize1'         => $prize1,
                'prize2'         => $prize2,
                'prize3'         => $prize3,
                'min_referrals'  => $minRefs,
                'status'         => 'upcoming',
                'created_by'     => $_SESSION['user_id'],
            ]);
            setFlash('success', 'Contest created');
        } else {
            setFlash('error', 'Title, dates and prize 1 required (end date must be after start date)');
        }
        header('Location: ' . SITE_URL . '/admin/contests');
        exit;
    }

    if ($_POST['action'] === 'activate') {
        $id = (int) ($_POST['id'] ?? 0);
        $contest = Database::fetchOne("SELECT status, end_date FROM contests WHERE id = ?", [$id]);
        if ($contest) {
            if ($contest['status'] === 'finished' || $contest['status'] === 'cancelled') {
                setFlash('error', 'Cannot activate a contest that is finished or cancelled');
            } else {
                Database::update('contests', ['status' => 'active'], 'id = ?', [$id]);
                Auth::logActivity($_SESSION['user_id'], 'contest_activated',
                    'Contest #' . $id . ' activated', 0);
                setFlash('success', 'Contest activated');
            }
        }
        header('Location: ' . SITE_URL . '/admin/contests');
        exit;
    }

    if ($_POST['action'] === 'cancel') {
        $id = (int) ($_POST['id'] ?? 0);
        Database::update('contests', ['status' => 'cancelled'], 'id = ?', [$id]);
        setFlash('success', 'Contest cancelled');
        header('Location: ' . SITE_URL . '/admin/contests');
        exit;
    }

    if ($_POST['action'] === 'award') {
        $id = (int) ($_POST['id'] ?? 0);
        $result = Contest::awardWinners($id, (int) $_SESSION['user_id']);
        if ($result['success']) {
            setFlash('success', $result['message']);
        } else {
            setFlash('error', $result['message']);
        }
        header('Location: ' . SITE_URL . '/admin/contests?contest_id=' . $id);
        exit;
    }
}

$contests = Database::fetchAll(
    "SELECT c.*, u.full_name AS created_by_name,
            (SELECT COUNT(*) FROM contest_winners cw WHERE cw.contest_id = c.id) AS winners_count
     FROM contests c
     LEFT JOIN users u ON c.created_by = u.id
     ORDER BY c.id DESC
     LIMIT 50"
);

$selectedId = (int) ($_GET['contest_id'] ?? 0);
if (!$selectedId && !empty($contests)) {
    $selectedId = (int) $contests[0]['id'];
}
$selected = $selectedId ? Contest::getContestById($selectedId) : null;
$standings = $selected ? Contest::getStandings($selectedId, 20) : [];
$winners = $selected ? Contest::getWinners($selectedId) : [];

$csrf = Auth::generateCSRF();
$pageTitle = 'Referral Contests';
include __DIR__ . '/admin_header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1><i class="fas fa-trophy me-2" style="color:var(--primary);"></i>Referral Contests</h1>
        <p>Manage weekly referral contests and award winners</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
        <i class="fas fa-plus me-1"></i> New Contest
    </button>
</div>

<?php displayFlash(); ?>

<?php if (!empty($contests)): ?>
<div class="card mb-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Title</th>
                        <th>Period</th>
                        <th>Prizes</th>
                        <th>Min Refs</th>
                        <th>Winners</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contests as $i => $c): ?>
                    <?php $isSelected = $selectedId === (int) $c['id']; ?>
                    <tr <?= $isSelected ? 'class="table-active"' : '' ?>>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <a href="?contest_id=<?= $c['id'] ?>" style="color:inherit;text-decoration:none;">
                                <strong><?= sanitize($c['title']) ?></strong>
                            </a>
                            <?php if ($isSelected): ?><span class="badge bg-primary ms-1">Viewing</span><?php endif; ?>
                        </td>
                        <td><small><?= date('d M Y', strtotime($c['start_date'])) ?><br>→ <?= date('d M Y', strtotime($c['end_date'])) ?></small></td>
                        <td><small>1st <?= number_format($c['prize1']) ?><br>2nd <?= number_format($c['prize2']) ?><br>3rd <?= number_format($c['prize3']) ?></small></td>
                        <td><strong><?= (int) $c['min_referrals'] ?></strong></td>
                        <td><strong><?= (int) $c['winners_count'] ?></strong></td>
                        <td>
                            <?php
                            $badge = match ($c['status']) {
                                'active'   => 'bg-success',
                                'upcoming' => 'bg-warning text-dark',
                                'finished' => 'bg-primary',
                                'cancelled'=> 'bg-secondary',
                                default    => 'bg-secondary',
                            };
                            ?>
                            <span class="badge <?= $badge ?>"><?= sanitize($c['status']) ?></span>
                        </td>
                        <td>
                            <div class="d-flex gap-1" style="white-space:nowrap;">
                                <?php if ($c['status'] === 'upcoming' || $c['status'] === 'active'): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Activate this contest? It will appear to all users.')">
                                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                                    <input type="hidden" name="action" value="activate">
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Activate">
                                        <i class="fas fa-play"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <?php if ($c['status'] === 'upcoming'): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this contest?')">
                                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Cancel">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <?php if (($c['status'] === 'active' || $c['status'] === 'finished') && (int) $c['winners_count'] === 0): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Award winners now? Top 3 get credited in their wallets. This cannot be undone.')">
                                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                                    <input type="hidden" name="action" value="award">
                                    <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-primary" title="Award winners">
                                        <i class="fas fa-award"></i> Award
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($selected): ?>
<div class="row">
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-ranking-star me-2" style="color:var(--primary);"></i>Standings
                <span class="text-muted ms-2" style="font-size:0.8rem;"><?= sanitize($selected['title']) ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Name</th>
                                <th>Paid Referrals</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($standings as $s): ?>
                            <tr>
                                <td><strong><?= $s['position'] ?></strong></td>
                                <td><?= sanitize($s['full_name'] ?: $s['name']) ?></td>
                                <td><strong><?= $s['count'] ?></strong></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($standings)): ?>
                            <tr><td colspan="3" class="text-center py-4 text-muted">No paid referrals in this period yet</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-medal me-2" style="color:var(--primary);"></i>Winners
                <span class="text-muted ms-2" style="font-size:0.8rem;"><?= count($winners) ?>/3 awarded</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Pos</th>
                                <th>Name</th>
                                <th>Refs</th>
                                <th>Amount</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($winners as $w): ?>
                            <tr>
                                <td><strong><?= $w['position'] ?></strong></td>
                                <td><?= sanitize($w['full_name'] ?: $w['name']) ?></td>
                                <td><?= $w['referrals_count'] ?></td>
                                <td><strong style="color:var(--primary);"><?= number_format($w['amount']) ?></strong></td>
                                <td><small><?= date('d M Y', strtotime($w['awarded_at'])) ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($winners)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">No winners awarded yet. Use the Award button above.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Create Modal -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="fas fa-trophy me-1" style="color:var(--primary);"></i> New Referral Contest</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" style="font-weight:700;">Title</label>
                        <input type="text" class="form-control" name="title" required maxlength="150" placeholder="e.g. Weekly Referral Contest #1">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight:700;">Description</label>
                        <textarea class="form-control" name="description" rows="2" placeholder="Rules and details shown to users" style="resize:vertical;"></textarea>
                    </div>
                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label" style="font-weight:700;">Start Date</label>
                            <input type="date" class="form-control" name="start_date" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label" style="font-weight:700;">End Date</label>
                            <input type="date" class="form-control" name="end_date" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-4">
                            <label class="form-label" style="font-weight:700;">1st Prize</label>
                            <input type="number" class="form-control" name="prize1" required min="1" placeholder="100000">
                        </div>
                        <div class="col-4">
                            <label class="form-label" style="font-weight:700;">2nd Prize</label>
                            <input type="number" class="form-control" name="prize2" min="0" placeholder="50000">
                        </div>
                        <div class="col-4">
                            <label class="form-label" style="font-weight:700;">3rd Prize</label>
                            <input type="number" class="form-control" name="prize3" min="0" placeholder="25000">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight:700;">Minimum Referrals</label>
                        <input type="number" class="form-control" name="min_referrals" min="1" value="1">
                        <div class="form-text">Minimum paid referrals a user needs to qualify as a winner.</div>
                    </div>
                    <div class="text-muted" style="font-size:0.8rem;">
                        <i class="fas fa-info-circle me-1"></i> Referrals count only when a referred user completes a <strong>paid</strong> registration within the contest period. Winners are credited via wallet (contest bonus) and paid out as normal withdrawals.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-check me-1"></i> Create Contest</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/admin_footer.php'; ?>
