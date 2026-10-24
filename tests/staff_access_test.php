<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/staff_fixture.php';
function staffCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function staffRejects(callable $action, string $message): void { try { $action(); } catch (Throwable $e) { return; } throw new RuntimeException($message); }
$input = ['full_name' => 'Support Agent', 'phone' => '0712345678', 'email' => 'support@example.test', 'password' => 'Agent-pass-123', 'status' => 'active', 'permissions' => ['support']];
$id = AgentManager::save(0, $input);
$agent = Database::fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
staffCheck($agent['role'] === 'agent' && password_verify($input['password'], $agent['password']), 'Agent creation/password hashing failed');
staffCheck(StaffAccess::can('agents') && StaffAccess::can('settings') && StaffAccess::can('withdrawals_manage'), 'Administrator lost full access');
staffRejects(fn() => AgentManager::save(1, $input), 'Agent manager modified an administrator');
staffRejects(fn() => AgentManager::save(0, array_replace($input, ['permissions' => ['agents'], 'phone' => '0712345679'])), 'Agent received reserved permissions');
staffRejects(fn() => AgentManager::save(0, array_replace($input, ['password' => 'short'])), 'Weak agent password accepted');
staffRejects(fn() => AgentManager::save(0, $input), 'Duplicate identity accepted');

// Even a stale/forged admin role in the session cannot expand a database agent's access.
$_SESSION = ['user_id' => $id, 'user_role' => 'admin', 'staff_version' => 0];
StaffAccess::clearCache();
staffCheck(StaffAccess::can('support') && !StaffAccess::can('users') && !StaffAccess::can('wallet'), 'Support permission leaked access');
staffCheck(!StaffAccess::can('agents') && !StaffAccess::can('settings'), 'Agent escalated to full admin');
staffCheck(StaffAccess::landingUrl() === SITE_URL . '/admin/support', 'Agent landing page is unauthorized');
$menu = array_column(StaffAccess::menu(), 'page');
staffCheck($menu === ['support', 'profile'], 'Support-only sidebar has extra links');
staffRejects(fn() => AgentManager::save(0, $input), 'Agent created another staff account');

// Render the actual header, checking that restricted sidebar links and counters are absent.
$pageTitle = 'Support';
ob_start();
require __DIR__ . '/../admin/admin_header.php';
$header = ob_get_clean();
staffCheck(str_contains($header, 'data-permission="support"') && str_contains($header, 'data-permission="profile"'), 'Assigned links missing from actual sidebar');
foreach (['users', 'wallet', 'agents', 'settings', 'withdrawals'] as $permission) staffCheck(!str_contains($header, 'data-permission="' . $permission . '"'), 'Restricted link visible: ' . $permission);
staffCheck(!str_contains($header, '/admin/settings'), 'Settings dropdown bypassed sidebar filtering');

// Changes take effect on the next request, without trusting permissions in a session.
Database::delete('agent_permissions', 'user_id = ?', [$id]);
StaffAccess::clearCache();
staffCheck(!StaffAccess::can('support'), 'Revoked permission remained usable');
staffCheck(StaffAccess::landingUrl() === SITE_URL . '/admin/home', 'Zero-permission agent did not get a safe landing page');
Database::update('users', ['status' => 'suspended'], 'id = ?', [$id]);
StaffAccess::clearCache();
staffCheck(!StaffAccess::isStaff() && StaffAccess::menu() === [], 'Suspended agent retained access');

// Management grants bring the associated view permission, with no unrelated links.
$_SESSION = ['user_id' => 1, 'user_role' => 'admin']; StaffAccess::clearCache();
AgentManager::save($id, array_replace($input, ['password' => '', 'permissions' => ['withdrawals_manage', 'users']]));
$_SESSION = ['user_id' => $id, 'user_role' => 'agent', 'staff_version' => 0]; StaffAccess::clearCache();
staffCheck(StaffAccess::can('withdrawals') && StaffAccess::can('withdrawals_manage'), 'Write grant did not include view access');
staffCheck(StaffAccess::can('users') && !StaffAccess::can('users_manage') && !StaffAccess::can('payments_manage'), 'View grant allowed edits');
staffCheck(count(StaffAccess::menu()) === 3, 'Sidebar link count ignored assigned modules');
staffCheck(StaffAccess::writePermission('withdrawals') === 'withdrawals_manage', 'Page action permission mapping incorrect');

// Password resets invalidate existing agent sessions, then the new password can log in.
$_SESSION = ['user_id' => 1, 'user_role' => 'admin']; StaffAccess::clearCache();
AgentManager::save($id, array_replace($input, ['password' => 'Replacement-pass-123', 'permissions' => ['support']]));
$_SESSION = ['user_id' => $id, 'user_role' => 'agent', 'staff_version' => 0]; StaffAccess::clearCache();
staffCheck(!StaffAccess::isStaff(), 'Old agent session survived an admin password reset');
$_SESSION = []; StaffAccess::clearCache();
$login = Auth::login('0712345678', 'Replacement-pass-123');
staffCheck($login['success'] && StaffAccess::can('support'), 'Agent login with reset password failed');
staffCheck((int) $_SESSION['staff_version'] === 1, 'Login did not record the new session version');
staffCheck(Database::count('activity_logs', 'action = ?', ['agent_created']) === 1, 'Agent creation was not audited');
staffCheck(Database::count('activity_logs', 'action = ?', ['agent_updated']) === 2, 'Agent changes were not audited');
echo "Agent management, permission enforcement and sidebar checks passed.\n";
