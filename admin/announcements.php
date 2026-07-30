<?php
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
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
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        if ($title && $content) {
            Database::insert('announcements', [
                'title'      => $title,
                'content'    => $content,
                'is_active'  => 1,
                'created_by' => $_SESSION['user_id'],
            ]);
            setFlash('success', 'Announcement created');
        } else {
            setFlash('error', 'Title and content required');
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($_POST['action'] === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $ann = Database::fetchOne("SELECT is_active FROM announcements WHERE id = ?", [$id]);
        if ($ann) {
            Database::update('announcements', ['is_active' => $ann['is_active'] ? 0 : 1], 'id = ?', [$id]);
            setFlash('success', 'Announcement ' . ($ann['is_active'] ? 'deactivated' : 'activated'));
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }

    if ($_POST['action'] === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        Database::delete('announcements', 'id = ?', [$id]);
        Database::delete('user_announcement_views', 'announcement_id = ?', [$id]);
        setFlash('success', 'Announcement deleted');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

$page = getCurrentPage();
$perPage = 20;
$offset = ($page - 1) * $perPage;

$total = Database::count('announcements');
$announcements = Database::fetchAll(
    "SELECT a.*, u.full_name as author_name,
            (SELECT COUNT(*) FROM user_announcement_views WHERE announcement_id = a.id) as views
     FROM announcements a
     JOIN users u ON a.created_by = u.id
     ORDER BY a.created_at DESC
     LIMIT {$perPage} OFFSET {$offset}"
);

$csrf = Auth::generateCSRF();
$pageTitle = 'Announcements';
include __DIR__ . '/admin_header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1><i class="fas fa-bullhorn me-2" style="color:var(--primary);"></i>Announcements</h1>
        <p>Manage system announcements shown to all users</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
        <i class="fas fa-plus me-1"></i> New Announcement
    </button>
</div>

<?php displayFlash(); ?>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Title</th>
                        <th>Content</th>
                        <th>Author</th>
                        <th>Views</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($announcements as $i => $a): ?>
                    <tr>
                        <td><?= $offset + $i + 1 ?></td>
                        <td><strong><?= sanitize($a['title']) ?></strong></td>
                        <td><small style="color:#9ca3af;"><?= truncate(sanitize($a['content']), 80) ?></small></td>
                        <td><small><?= sanitize($a['author_name']) ?></small></td>
                        <td><strong><?= number_format($a['views']) ?></strong></td>
                        <td><?= $a['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                        <td><small><?= date('d M Y', strtotime($a['created_at'])) ?></small></td>
                        <td>
                            <div class="d-flex gap-1">
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                    <button type="submit" class="btn btn-sm <?= $a['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= $a['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                        <i class="fas fa-<?= $a['is_active'] ? 'pause' : 'play' ?>"></i>
                                    </button>
                                </form>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this announcement?')">
                                    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($announcements)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4">
                            <i class="fas fa-bullhorn fa-2x mb-2" style="color:#d1d5db;"></i>
                            <p class="text-muted mb-0">No announcements yet</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mt-3">
    <?= paginate($total, $page, $perPage, SITE_URL . '/admin/announcements?' . http_build_query(['page' => ''])) ?>
</div>

<!-- Create Modal -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="fas fa-bullhorn me-1" style="color:var(--primary);"></i> New Announcement</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" style="font-weight:700;">Title</label>
                        <input type="text" class="form-control" name="title" required maxlength="200" placeholder="e.g. System Maintenance Tonight">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-weight:700;">Content</label>
                        <textarea class="form-control" name="content" rows="5" required placeholder="Write your announcement message here..." style="resize:vertical;"></textarea>
                    </div>
                    <div class="text-muted" style="font-size:0.8rem;">
                        <i class="fas fa-info-circle me-1"></i> Announcements are visible to all users in their dashboard notification bell.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-paper-plane me-1"></i> Publish</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/admin_footer.php'; ?>
