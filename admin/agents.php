<?php
require_once __DIR__ . '/../classes/AgentManager.php';
require_once __DIR__ . '/../includes/helpers.php';
StaffAccess::requirePage('agents');
$error = '';
$success = getFlash('success') ?? '';
$editId = max(0, (int) ($_GET['edit'] ?? 0));
$catalog = StaffAccess::catalog();
$agents = [];
$edit = null;
$selected = [];
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $id = AgentManager::save(max(0, (int) ($_POST['agent_id'] ?? 0)), $_POST);
        setFlash('success', 'Agent saved. Sidebar links and access now follow the selected permissions.');
        header('Location: ' . SITE_URL . '/admin/agents?edit=' . $id);
        exit;
    }
    $agents = Database::fetchAll("SELECT id, full_name, phone, email, status FROM users WHERE role = 'agent' ORDER BY id DESC");
    if ($editId) {
        $edit = Database::fetchOne("SELECT id, full_name, phone, email, status FROM users WHERE id = ? AND role = 'agent'", [$editId]);
        if (!$edit) { http_response_code(404); $error = 'Agent not found.'; $editId = 0; }
        else $selected = array_column(Database::fetchAll('SELECT permission FROM agent_permissions WHERE user_id = ?', [$editId]), 'permission');
    }
} catch (InvalidArgumentException | RuntimeException $e) {
    $error = $e instanceof PDOException ? 'Could not save or load agents. Run the agent migration and check that the phone/email are unique.' : $e->getMessage();
    $editId = max(0, (int) ($_POST['agent_id'] ?? $editId));
    $edit = $_POST;
    $selected = is_array($_POST['permissions'] ?? null) ? $_POST['permissions'] : [];
}
$pageTitle = 'Agents & Permissions';
include __DIR__ . '/admin_header.php';
?>
<div class="page-header"><h1>Agents &amp; Permissions</h1><p>Create staff accounts and choose exactly which work sections they can access.</p></div>
<?php if ($success): ?><div class="alert alert-success"><?= sanitize($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= sanitize($error) ?></div><?php endif; ?>
<div class="row g-4">
<div class="col-xl-8"><div class="card"><div class="card-header"><h6><?= $editId ? 'Edit agent' : 'Create agent' ?></h6></div><div class="card-body">
<form method="POST" id="agent-permissions-form">
    <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Auth::generateCSRF() ?>">
    <input type="hidden" name="agent_id" value="<?= $editId ?>">
    <div class="row g-3 mb-4">
        <div class="col-md-6"><label class="form-label" for="agent-name">Full name</label><input id="agent-name" name="full_name" class="form-control" value="<?= sanitize($edit['full_name'] ?? '') ?>" minlength="2" maxlength="150" required></div>
        <div class="col-md-6"><label class="form-label" for="agent-phone">Phone number</label><input id="agent-phone" name="phone" type="tel" class="form-control" value="<?= sanitize($edit['phone'] ?? '') ?>" required></div>
        <div class="col-md-6"><label class="form-label" for="agent-email">Email (optional)</label><input id="agent-email" name="email" type="email" class="form-control" value="<?= sanitize($edit['email'] ?? '') ?>" maxlength="150"></div>
        <div class="col-md-6"><label class="form-label" for="agent-password"><?= $editId ? 'Reset password (leave blank to keep current)' : 'Password' ?></label><input id="agent-password" name="password" type="password" class="form-control" minlength="10" maxlength="72" autocomplete="new-password" <?= $editId ? '' : 'required' ?>></div>
        <div class="col-md-6"><label class="form-label" for="agent-status">Status</label><select id="agent-status" name="status" class="form-select"><option value="active" <?= ($edit['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option><option value="suspended" <?= ($edit['status'] ?? '') === 'suspended' ? 'selected' : '' ?>>Suspended</option></select></div>
    </div>
    <h6>Permissions</h6>
    <p class="text-muted small">Management permissions also enable the matching view permission. Only administrators can manage agents or system settings.</p>
    <div class="alert alert-info" id="agent-sidebar-preview" aria-live="polite">Choose permissions below. My Profile and Logout are always available.</div>
    <div class="row g-3">
    <?php foreach ($catalog as $key => $permission): ?>
        <div class="col-md-6"><label class="border rounded p-3 d-block h-100" for="permission-<?= $key ?>">
            <input id="permission-<?= $key ?>" type="checkbox" name="permissions[]" value="<?= $key ?>" data-agent-permission data-parent="<?= $permission['requires'] ?? '' ?>" data-page="<?= $permission['page'] ?? '' ?>" data-label="<?= sanitize($permission['label']) ?>" <?= in_array($key, $selected, true) ? 'checked' : '' ?>>
            <strong class="ms-1"><?= sanitize($permission['label']) ?></strong>
            <small class="d-block text-muted mt-1"><?= sanitize($permission['description']) ?></small>
        </label></div>
    <?php endforeach; ?>
    </div>
    <div class="d-flex gap-2 mt-4"><button class="btn btn-primary" type="submit"><?= $editId ? 'Save agent & permissions' : 'Create agent' ?></button><a class="btn btn-outline-secondary" href="agents">New agent</a></div>
</form>
</div></div></div>
<div class="col-xl-4"><div class="card"><div class="card-header"><h6>Agent accounts</h6></div><div class="card-body">
    <p class="small text-muted">Agents sign in through the staff login using their phone or email and password. Suspending an account blocks access on its next request.</p>
    <?php foreach ($agents as $agent): ?><div class="border-bottom py-3"><strong><?= sanitize($agent['full_name']) ?></strong> <span class="badge <?= $agent['status'] === 'active' ? 'bg-success' : 'bg-danger' ?>"><?= sanitize($agent['status']) ?></span><div class="text-muted small"><?= sanitize($agent['phone']) ?></div><a class="btn btn-sm btn-outline-primary mt-2" href="?edit=<?= (int) $agent['id'] ?>">Edit permissions / account</a></div><?php endforeach; ?>
    <?php if (!$agents): ?><p>No agents created yet.</p><?php endif; ?>
</div></div></div>
</div>
<script src="<?= sanitize(SITE_URL) ?>/assets/js/agent-permissions.js"></script>
<?php include __DIR__ . '/admin_footer.php'; ?>
