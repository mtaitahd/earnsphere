<?php
/**
 * EarnSphere - Commission Engine
 * Handles all referral commission calculations and distributions
 * 
 * Commission Structure:
 * - Level 1 (Direct referrer): 2,000 TZS
 * - Level 2 (Grand referrer):  1,200 TZS
 * - Level 3 (Great-grand):       800 TZS
 * - Company:                    6,000 TZS
 */

require_once __DIR__ . '/../config/database.php';

class CommissionEngine {
    
    /**
     * Get a setting from DB, falling back to env/config constant
     */
    public static function getSetting(string $key, $default = null) {
        static $cache = [];
        if (isset($cache[$key])) return $cache[$key];
        
        $row = Database::fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
        $value = $row ? $row['setting_value'] : $default;
        $cache[$key] = $value;
        return $value;
    }
    
    /**
     * Process all commissions when a new user registers and pays
     * 
     * @param int $newUserId The ID of the newly activated user
     */
    public static function processRegistrationCommissions(int $newUserId): void {
        // Find the referral chain for this user
        $referralChain = self::getReferralChain($newUserId);
        
        $commissions = [
            1 => (int) app_setting('commission_l1', COMMISSION_L1),
            2 => (int) app_setting('commission_l2', COMMISSION_L2),
            3 => (int) app_setting('commission_l3', COMMISSION_L3),
        ];
        
        foreach ($commissions as $level => $amount) {
            if (!isset($referralChain[$level])) continue;
            
            $earnerId = $referralChain[$level];
            
            // Skip if earner is the same as new user (safety check)
            if ($earnerId == $newUserId) continue;
            
            // Check earner is active
            $earner = Database::fetchOne(
                "SELECT id, status FROM users WHERE id = ? AND status = 'active'",
                [$earnerId]
            );
            
            if (!$earner) continue;
            
            // Create commission record
            $commissionId = Database::insert('commissions', [
                'earner_id'      => $earnerId,
                'source_user_id' => $newUserId,
                'level'          => $level,
                'amount'         => $amount,
                'status'         => 'approved',
            ]);
            
            // Credit earner's wallet
            $walletTransactionId = Wallet::credit(
                $earnerId,
                $amount,
                'commission',
                "Commission Level {$level} from new member #" . $newUserId,
                $commissionId,
                'commission'
            );
            
            // Link commission to wallet transaction
            Database::update('commissions', [
                'wallet_transaction_id' => $walletTransactionId,
            ], 'id = ?', [$commissionId]);
            
            // Log activity
            Auth::logActivity(
                $earnerId,
                'commission_earned',
                "Earned TZS " . number_format($amount) . " (Level {$level}) from user #{$newUserId}"
            );
        }
    }
    
    /**
     * Get the referral chain for a user
     * Returns [1 => userId, 2 => userId, 3 => userId] for each level
     */
    public static function getReferralChain(int $userId): array {
        $chain = [];
        
        // Level 1: Who directly referred this user
        $user = Database::fetchOne(
            "SELECT referred_by FROM users WHERE id = ?",
            [$userId]
        );
        
        if (!$user || !$user['referred_by']) return $chain;
        
        $chain[1] = $user['referred_by'];
        
        // Level 2: Who referred the level 1 referrer
        $level2 = Database::fetchOne(
            "SELECT referred_by FROM users WHERE id = ?",
            [$user['referred_by']]
        );
        
        if (!$level2 || !$level2['referred_by']) return $chain;
        
        $chain[2] = $level2['referred_by'];
        
        // Level 3: Who referred the level 2 referrer
        $level3 = Database::fetchOne(
            "SELECT referred_by FROM users WHERE id = ?",
            [$level2['referred_by']]
        );
        
        if (!$level3 || !$level3['referred_by']) return $chain;
        
        $chain[3] = $level3['referred_by'];
        
        return $chain;
    }
    
    /**
     * Get referral statistics for a user
     */
    public static function getReferralStats(int $userId): array {
        $stats = [
            'total_referrals' => 0,
            'level_1'         => 0,
            'level_2'         => 0,
            'level_3'         => 0,
            'total_earned'    => 0,
            'recent_referrals' => [],
        ];
        
        // Count direct referrals (Level 1)
        $stats['level_1'] = Database::count(
            'users',
            'referred_by = ? AND status = ?',
            [$userId, 'active']
        );
        
        // Count Level 2 referrals
        $stats['level_2'] = Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM users u 
             INNER JOIN users ref ON u.referred_by = ref.id 
             WHERE ref.referred_by = ? AND u.status = 'active'",
            [$userId]
        )['cnt'] ?? 0;
        
        // Count Level 3 referrals
        $stats['level_3'] = Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM users u 
             INNER JOIN users ref1 ON u.referred_by = ref1.id 
             INNER JOIN users ref2 ON ref1.referred_by = ref2.id 
             WHERE ref2.referred_by = ? AND u.status = 'active'",
            [$userId]
        )['cnt'] ?? 0;
        
        $stats['total_referrals'] = $stats['level_1'] + $stats['level_2'] + $stats['level_3'];
        
        // Total earned
        $stats['total_earned'] = Database::fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total FROM commissions 
             WHERE earner_id = ? AND status IN ('approved', 'paid')",
            [$userId]
        )['total'] ?? 0;
        
        // Recent referrals (direct)
        $stats['recent_referrals'] = Database::fetchAll(
            "SELECT id, full_name, phone, created_at FROM users 
             WHERE referred_by = ? ORDER BY created_at DESC LIMIT 10",
            [$userId]
        );
        
        return $stats;
    }
    
    /**
     * Get commission summary for admin
     */
    public static function getCommissionSummary(): array {
        return [
            'total_commissions' => Database::fetchOne(
                "SELECT COALESCE(SUM(amount), 0) as total FROM commissions WHERE status != 'cancelled'"
            )['total'] ?? 0,
            
            'total_l1' => Database::fetchOne(
                "SELECT COALESCE(SUM(amount), 0) as total FROM commissions WHERE level = 1 AND status != 'cancelled'"
            )['total'] ?? 0,
            
            'total_l2' => Database::fetchOne(
                "SELECT COALESCE(SUM(amount), 0) as total FROM commissions WHERE level = 2 AND status != 'cancelled'"
            )['total'] ?? 0,
            
            'total_l3' => Database::fetchOne(
                "SELECT COALESCE(SUM(amount), 0) as total FROM commissions WHERE level = 3 AND status != 'cancelled'"
            )['total'] ?? 0,
            
            'pending_payouts' => Database::fetchOne(
                "SELECT COALESCE(SUM(amount), 0) as total FROM withdrawals WHERE status IN ('pending', 'approved', 'processing')"
            )['total'] ?? 0,
        ];
    }
    
    /**
     * Get full referral tree for a user (visual tree)
     */
    public static function getReferralTree(int $userId, int $maxDepth = 3): array {
        $tree = self::buildTree($userId, $maxDepth, 0);
        return $tree;
    }
    
    /**
     * Recursively build referral tree
     */
    private static function buildTree(int $userId, int $maxDepth, int $currentDepth): array {
        if ($currentDepth >= $maxDepth) return [];
        
        $children = Database::fetchAll(
            "SELECT id, full_name, phone, status, created_at 
             FROM users WHERE referred_by = ? ORDER BY created_at DESC",
            [$userId]
        );
        
        $tree = [];
        foreach ($children as $child) {
            $node = [
                'id'         => $child['id'],
                'name'       => $child['full_name'],
                'phone'      => $child['phone'],
                'status'     => $child['status'],
                'joined'     => $child['created_at'],
                'children'   => self::buildTree($child['id'], $maxDepth, $currentDepth + 1),
            ];
            $tree[] = $node;
        }
        
        return $tree;
    }
    
    /**
     * Calculate total company earnings from registrations
     */
    public static function getCompanyEarnings(): float {
        return (float) Database::fetchOne(
            "SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE status = 'completed'"
        )['total'] ?? 0;
    }
    
    /**
     * Get daily commission data for charts (last 30 days)
     */
    public static function getDailyCommissionData(int $days = 30): array {
        return Database::fetchAll(
            "SELECT DATE(created_at) as date, 
                    SUM(CASE WHEN level = 1 THEN amount ELSE 0 END) as l1,
                    SUM(CASE WHEN level = 2 THEN amount ELSE 0 END) as l2,
                    SUM(CASE WHEN level = 3 THEN amount ELSE 0 END) as l3,
                    COUNT(*) as total
             FROM commissions 
             WHERE status != 'cancelled' 
             AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            [$days]
        );
    }
}
