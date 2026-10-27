<?php
require_once __DIR__ . '/StaffAccess.php';

/** Read-only administrator lookup; levels are relative to an inviter, not global ranks. */
final class ReferralLookup {
    private static function byCode(string $code): ?array {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9]{4,32}$/D', $code)) throw new InvalidArgumentException('Enter a valid referral code (4–32 letters or numbers).');
        return Database::fetchOne("SELECT id, full_name, phone, email, referral_code, referred_by, status, created_at FROM users WHERE referral_code = ? AND role = 'user'", [$code]);
    }

    public static function find(string $code, string $relativeCode = ''): ?array {
        StaffAccess::requirePermission('referral-lookup');
        $user = self::byCode($code);
        if (!$user) return null;
        $relative = $relativeCode !== '' ? self::byCode($relativeCode) : null;
        if ($relativeCode !== '' && !$relative) throw new InvalidArgumentException('The comparison code was not found.');
        $chain = [$user]; $seen = [(int) $user['id'] => true]; $warning = '';
        $current = $user;
        while (!empty($current['referred_by'])) {
            $parentId = (int) $current['referred_by'];
            if (isset($seen[$parentId])) { $warning = 'A circular referral relationship was detected. Review the account links.'; break; }
            if (count($chain) >= 30) { $warning = 'Showing the first 30 accounts in this chain.'; break; }
            $parent = Database::fetchOne("SELECT id, full_name, phone, email, referral_code, referred_by, status, created_at FROM users WHERE id = ? AND role = 'user'", [$parentId]);
            if (!$parent) { $warning = 'An inviter record is missing or is not a member account.'; break; }
            $chain[] = $parent; $seen[$parentId] = true; $current = $parent;
        }
        $level = null;
        if ($relative) foreach ($chain as $depth => $node) if ((int) $node['id'] === (int) $relative['id']) { $level = $depth; break; }
        $direct = Database::count('users', "referred_by = ? AND role = 'user' AND id != ?", [$user['id'], $user['id']]);
        $grand = Database::fetchOne("SELECT COUNT(*) AS total FROM users child JOIN users parent ON child.referred_by = parent.id WHERE parent.referred_by = ? AND child.role = 'user' AND parent.role = 'user' AND child.id != ?", [$user['id'], $user['id']]);
        return ['user' => $user, 'chain' => $chain, 'warning' => $warning, 'relative' => $relative, 'level' => $level, 'direct' => $direct, 'grand' => (int) ($grand['total'] ?? 0)];
    }
}
