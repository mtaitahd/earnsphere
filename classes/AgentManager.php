<?php
require_once __DIR__ . '/StaffAccess.php';
require_once __DIR__ . '/AblinerClient.php';

class AgentManager {
    public static function save(int $id, array $input): int {
        if (!StaffAccess::isAdministrator()) throw new RuntimeException('Only an administrator can manage agents.');
        $name = trim((string) ($input['full_name'] ?? ''));
        $phone = AblinerClient::normalizePhone((string) ($input['phone'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $status = (string) ($input['status'] ?? 'active');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) throw new InvalidArgumentException('Agent name must contain 2 to 150 characters.');
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150)) throw new InvalidArgumentException('Enter a valid email address.');
        if (!in_array($status, ['active', 'suspended'], true)) throw new InvalidArgumentException('Invalid agent status.');
        if (($id === 0 || $password !== '') && (strlen($password) < 10 || strlen($password) > 72)) throw new InvalidArgumentException('Use a password containing 10 to 72 characters.');
        $rawPermissions = $input['permissions'] ?? [];
        if (!is_array($rawPermissions)) throw new InvalidArgumentException('Invalid permissions.');
        $permissions = StaffAccess::normalizePermissions($rawPermissions);
        Database::beginTransaction();
        try {
            $previous = $id ? Database::fetchOne("SELECT * FROM users WHERE id = ? AND role = 'agent' FOR UPDATE", [$id]) : null;
            if ($id && !$previous) throw new InvalidArgumentException('Agent not found.');
            if (Database::fetchOne('SELECT id FROM users WHERE phone = ? AND id != ?', [$phone, $id])
                || ($email !== '' && Database::fetchOne('SELECT id FROM users WHERE email = ? AND id != ?', [$email, $id]))) {
                throw new InvalidArgumentException('This phone number or email is already in use.');
            }
            $fields = ['full_name' => $name, 'phone' => $phone, 'email' => $email !== '' ? $email : null, 'status' => $status];
            if ($password !== '') $fields['password'] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            if ($id === 0) {
                $id = Database::insert('users', $fields + ['role' => 'agent', 'referral_code' => 'AGT' . bin2hex(random_bytes(6)), 'staff_version' => 0]);
            } else {
                if ($password !== '') $fields['staff_version'] = (int) ($previous['staff_version'] ?? 0) + 1;
                Database::update('users', $fields, "id = ? AND role = 'agent'", [$id]);
                Database::delete('agent_permissions', 'user_id = ?', [$id]);
            }
            foreach ($permissions as $permission) Database::insert('agent_permissions', ['user_id' => $id, 'permission' => $permission]);
            Database::commit();
        } catch (Throwable $e) { if (Database::inTransaction()) Database::rollback(); throw $e; }
        Auth::logActivity((int) $_SESSION['user_id'], $previous ? 'agent_updated' : 'agent_created',
            'Agent #' . $id . '; status=' . $status . '; permissions=' . implode(',', $permissions) . ($previous && $password !== '' ? '; password reset' : ''));
        StaffAccess::clearCache();
        return $id;
    }
}
