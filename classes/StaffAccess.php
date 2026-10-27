<?php
require_once __DIR__ . '/Auth.php';

class StaffAccess {
    private static array $cache = [];
    private static array $audited = [];

    public static function catalog(): array {
        return [
            'dashboard' => ['label' => 'Dashboard reports', 'description' => 'View system-wide user and financial summaries, including Total Paid on the Users page.', 'page' => 'index', 'icon' => 'tachometer-alt'],
            'users' => ['label' => 'View users', 'description' => 'View member names, contacts, codes and join dates. Referrals, earnings and status columns on Users are administrator-only.', 'page' => 'users', 'icon' => 'users'],
            'users_manage' => ['label' => 'Manage users', 'description' => 'Edit, suspend and delete regular user accounts. Activation additionally requires Manage payments.', 'requires' => 'users'],
            'payments' => ['label' => 'View payments', 'description' => 'View payment records and each user\'s payment amounts on the Users page.', 'page' => 'payments', 'icon' => 'credit-card'],
            'payments_manage' => ['label' => 'Manage payments', 'description' => 'Delete legacy records; also required to manually mark users paid or unpaid.', 'requires' => 'payments'],
            'commissions' => ['label' => 'View commissions', 'description' => 'View commission history. This does not enable the administrator-only earnings column on Users.', 'page' => 'commissions', 'icon' => 'coins'],
            'commissions_manage' => ['label' => 'Manage commissions', 'description' => 'Perform commission record actions.', 'requires' => 'commissions'],
            'withdrawals' => ['label' => 'View withdrawals', 'description' => 'View requests and verify existing payout status.', 'page' => 'withdrawals', 'icon' => 'money-bill-wave'],
            'withdrawals_manage' => ['label' => 'Manage withdrawals / send payouts', 'description' => 'Dispatch payouts and perform withdrawal actions.', 'requires' => 'withdrawals'],
            'wallet' => ['label' => 'View Abliner wallet', 'description' => 'View company provider balances and own funding history.', 'page' => 'abliner-wallet', 'icon' => 'wallet'],
            'wallet_manage' => ['label' => 'Fund Abliner wallet', 'description' => 'Request mobile money deposits into the company wallet.', 'requires' => 'wallet'],
            'referrals' => ['label' => 'View referral network', 'description' => 'Search and inspect user referral trees.', 'page' => 'referral-tree', 'icon' => 'sitemap'],
            'announcements' => ['label' => 'Manage announcements', 'description' => 'Create and update announcements.', 'page' => 'announcements', 'icon' => 'bullhorn'],
            'contests' => ['label' => 'Manage contests', 'description' => 'Manage competitions and prize awards.', 'page' => 'contests', 'icon' => 'trophy'],
            'sms' => ['label' => 'Manage SMS', 'description' => 'Send broadcasts and manage SMS templates.', 'page' => 'sms', 'icon' => 'sms'],
            'marketplace' => ['label' => 'Marketplace ads', 'description' => 'Approve paid ads, reject and refund unpublished ads, and handle product reports.', 'page' => 'marketplace', 'icon' => 'store'],
            'marketplace_settings' => ['label' => 'Marketplace fee settings', 'description' => 'Set the advertising fee, duration and seller rules.', 'requires' => 'marketplace'],
            'quizzes' => ['label' => 'Comprehension quizzes', 'description' => 'Publish and pause quizzes, set correct answers, rewards and funded participant limits. Perfect scores automatically earn rewards.', 'page' => 'quizzes', 'icon' => 'question-circle'],
            'social' => ['label' => 'Social campaigns', 'description' => 'Publish campaigns, review rewards and quote promotion requests.', 'page' => 'social', 'icon' => 'bullhorn'],
            'loans' => ['label' => 'Loan applications', 'description' => 'Publish eligibility terms and review loan applications. Does not send money.', 'page' => 'loans', 'icon' => 'hand-holding-usd'],
            'support' => ['label' => 'Support tickets', 'description' => 'Read, reply to and close support tickets.', 'page' => 'support', 'icon' => 'life-ring'],
            'logs' => ['label' => 'View activity logs', 'description' => 'Inspect recorded account and staff activity.', 'page' => 'logs', 'icon' => 'list-alt'],
            'logs_manage' => ['label' => 'Manage activity logs', 'description' => 'Delete activity log records.', 'requires' => 'logs'],
            'error_logs' => ['label' => 'View error logs', 'description' => 'View diagnostic and payment error details.', 'page' => 'error-logs', 'icon' => 'bug'],
            'error_logs_manage' => ['label' => 'Manage error logs', 'description' => 'Delete error log records.', 'requires' => 'error_logs'],
        ];
    }

    public static function normalizePermissions(array $input): array {
        $catalog = self::catalog();
        $selected = [];
        foreach ($input as $key) {
            if (!is_string($key) || !isset($catalog[$key])) throw new InvalidArgumentException('Unknown agent permission.');
            $selected[$key] = true;
            if (isset($catalog[$key]['requires'])) $selected[$catalog[$key]['requires']] = true;
        }
        return array_keys($selected);
    }

    public static function clearCache(): void { self::$cache = []; }

    private static function account(int $id): ?array {
        if (!array_key_exists($id, self::$cache)) {
            $user = Database::fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
            if (!$user || $user['status'] !== 'active' || !in_array($user['role'], ['admin', 'agent'], true)) {
                self::$cache[$id] = null;
            } else {
                $user['permissions'] = $user['role'] === 'admin' ? array_keys(self::catalog()) : array_column(
                    Database::fetchAll('SELECT permission FROM agent_permissions WHERE user_id = ?', [$id]), 'permission');
                self::$cache[$id] = $user;
            }
        }
        return self::$cache[$id];
    }

    public static function current(): ?array {
        $id = (int) ($_SESSION['user_id'] ?? 0);
        if (!$id) return null;
        $user = self::account($id);
        if ($user && $user['role'] === 'agent' && (int) ($user['staff_version'] ?? 0) !== (int) ($_SESSION['staff_version'] ?? 0)) return null;
        return $user;
    }
    public static function isStaff(): bool { return self::current() !== null; }
    public static function isAdministrator(): bool { return (self::current()['role'] ?? '') === 'admin'; }
    public static function can(string $permission): bool {
        $user = self::current();
        if (!$user) return false;
        if (in_array($permission, ['profile', 'home'], true)) return true;
        if (in_array($permission, ['settings', 'agents', 'security-alerts', 'referral-lookup'], true)) return $user['role'] === 'admin';
        return isset(self::catalog()[$permission]) && in_array($permission, $user['permissions'], true);
    }
    public static function canForUser(int $id, string $permission): bool {
        $user = self::account($id);
        return $user && isset(self::catalog()[$permission]) && in_array($permission, $user['permissions'], true);
    }
    public static function menu(): array {
        $items = [];
        foreach (self::catalog() as $key => $item) {
            if (isset($item['page']) && self::can($key)) $items[] = ['permission' => $key] + $item;
        }
        if (self::isAdministrator()) {
            $items[] = ['permission' => 'security-alerts', 'page' => 'security-alerts', 'label' => 'Security Alerts', 'icon' => 'shield-alt'];
            $items[] = ['permission' => 'referral-lookup', 'page' => 'referral-lookup', 'label' => 'Referral Code Lookup', 'icon' => 'search'];
            $items[] = ['permission' => 'agents', 'page' => 'agents', 'label' => 'Agents & Permissions', 'icon' => 'user-shield'];
            $items[] = ['permission' => 'settings', 'page' => 'settings', 'label' => 'System Settings', 'icon' => 'cog'];
        }
        if (self::isStaff()) $items[] = ['permission' => 'profile', 'page' => 'profile', 'label' => 'My Profile', 'icon' => 'user-cog'];
        return $items;
    }
    public static function landingUrl(): string {
        if (self::isAdministrator()) return SITE_URL . '/admin/index';
        foreach (self::menu() as $item) if ($item['page'] !== 'profile') return SITE_URL . '/admin/' . $item['page'];
        return SITE_URL . '/admin/home';
    }
    public static function pagePermission(string $page): ?string {
        foreach (self::catalog() as $key => $item) if (($item['page'] ?? '') === $page) return $key;
        return in_array($page, ['settings', 'agents', 'security-alerts', 'referral-lookup', 'profile', 'home'], true) ? $page : null;
    }
    public static function writePermission(string $permission): string {
        return in_array($permission, ['users', 'payments', 'commissions', 'withdrawals', 'wallet', 'logs', 'error_logs'], true) ? $permission . '_manage' : $permission;
    }
    public static function deny(bool $json = false, int $status = 403): never {
        require_once __DIR__ . '/SecurityAlerts.php';
        SecurityAlerts::repeated('access_denied', 3);
        http_response_code($status);
        header('Cache-Control: no-store');
        if ($json) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'error' => 'You do not have permission to access this section.']); }
        else { echo 'Access denied. Contact your administrator to request permission.'; }
        exit;
    }
    public static function requirePermission(string $permission, bool $json = false): void {
        if (!self::can($permission)) self::deny($json, isset($_SESSION['user_id']) ? 403 : 401);
    }
    public static function requirePage(string $page): void {
        Auth::initSession();
        if (!isset($_SESSION['user_id'])) { header('Location: ' . SITE_URL . '/admin/login'); exit; }
        $permission = self::pagePermission($page);
        if (!$permission) self::deny();
        self::requirePermission($permission);
        header('Cache-Control: no-store, private');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            self::requirePermission(self::writePermission($permission));
            if (!Auth::validateCSRF()) self::deny();
            if ($page === 'users' && (in_array($_POST['action'] ?? '', ['mark_as_paid', 'mark_as_unpaid'], true)
                || (($_POST['action'] ?? '') === 'update_status' && ($_POST['new_status'] ?? '') === 'active'))) self::requirePermission('payments_manage');
            if (!isset(self::$audited[$page])) {
                self::$audited[$page] = true;
                Auth::logActivity((int) $_SESSION['user_id'], 'staff_action_attempt', 'POST admin/' . $page . ' action=' . substr((string) ($_POST['action'] ?? $_POST['form_type'] ?? 'save'), 0, 60));
            }
        }
    }
}
