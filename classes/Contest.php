<?php
/**
 * EarnSphere - Referral Contest Engine
 * Handles weekly referral competitions and prize awarding.
 *
 * A contest counts "paid referrals" = new members who activated
 * (completed their registration payment) within the contest window.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/Wallet.php';

class Contest {

    /**
     * Get the currently active contest (status active + date window).
     */
    public static function getActiveContest(): ?array {
        $contest = Database::fetchOne(
            "SELECT * FROM contests
             WHERE status = 'active' AND end_date >= CURDATE()
             ORDER BY id DESC LIMIT 1"
        );
        return $contest ?: null;
    }

    /**
     * Get the most recent contest (for display when none is active).
     */
    public static function getLatestContest(): ?array {
        return Database::fetchOne("SELECT * FROM contests ORDER BY id DESC LIMIT 1") ?: null;
    }

    /**
     * Get contest to feature on public pages.
     */
    public static function getFeaturedContest(): ?array {
        $active = self::getActiveContest();
        if ($active) return $active;
        return self::getLatestContest();
    }

    /**
     * Get contest by id.
     */
    public static function getContestById(int $contestId): ?array {
        return Database::fetchOne("SELECT * FROM contests WHERE id = ?", [$contestId]) ?: null;
    }

    /**
     * Compute standings (paid referrals per user) for a contest window.
     *
     * @return array list of ['position','user_id','name','count']
     */
    public static function getStandings(int $contestId, int $limit = 20): array {
        $contest = self::getContestById($contestId);
        if (!$contest) return [];

        $start = $contest['start_date'] . ' 00:00:00';
        $end   = $contest['end_date'] . ' 23:59:59';
        $limit = min(max($limit, 1), 100);

        $rows = Database::fetchAll(
            "SELECT u.referred_by AS user_id,
                    MAX(r.full_name) AS full_name,
                    COUNT(DISTINCT u.id) AS cnt,
                    MIN(p.completed_at) AS first_paid_at
             FROM users u
             INNER JOIN payments p
                     ON p.user_id = u.id
                    AND p.status = 'completed'
                    AND p.payment_type = 'registration'
                    AND p.completed_at BETWEEN ? AND ?
             INNER JOIN users r ON r.id = u.referred_by
             WHERE u.referred_by IS NOT NULL
               AND u.status = 'active'
             GROUP BY u.referred_by
             ORDER BY cnt DESC, first_paid_at ASC
             LIMIT ?",
            [$start, $end, $limit]
        );

        $standings = [];
        foreach ($rows as $i => $row) {
            $standings[] = [
                'position' => $i + 1,
                'user_id'  => (int) $row['user_id'],
                'name'     => self::maskName($row['full_name']),
                'full_name'=> (string) $row['full_name'],
                'count'    => (int) $row['cnt'],
            ];
        }
        return $standings;
    }

    /**
     * Compute a single user's rank + referral count for a contest.
     *
     * @return array|null ['count' => int, 'rank' => int|null]
     */
    public static function getUserRank(int $contestId, int $userId): ?array {
        $contest = self::getContestById($contestId);
        if (!$contest) return null;

        $start = $contest['start_date'] . ' 00:00:00';
        $end   = $contest['end_date'] . ' 23:59:59';

        $myCount = (int) (Database::fetchOne(
            "SELECT COUNT(DISTINCT u.id) AS cnt
             FROM users u
             INNER JOIN payments p
                     ON p.user_id = u.id
                    AND p.status = 'completed'
                    AND p.payment_type = 'registration'
                    AND p.completed_at BETWEEN ? AND ?
             WHERE u.referred_by = ? AND u.status = 'active'",
            [$start, $end, $userId]
        )['cnt'] ?? 0);

        if ($myCount <= 0) {
            return ['count' => 0, 'rank' => null];
        }

        $ahead = (int) (Database::fetchOne(
            "SELECT COUNT(*) AS ahead
             FROM (
                 SELECT u.referred_by
                 FROM users u
                 INNER JOIN payments p
                         ON p.user_id = u.id
                        AND p.status = 'completed'
                        AND p.payment_type = 'registration'
                        AND p.completed_at BETWEEN ? AND ?
                 WHERE u.referred_by IS NOT NULL AND u.status = 'active'
                 GROUP BY u.referred_by
                 HAVING COUNT(DISTINCT u.id) > ?
             ) t",
            [$start, $end, $myCount]
        )['ahead'] ?? 0);

        return ['count' => $myCount, 'rank' => $ahead + 1];
    }

    /**
     * Recorded winners for a contest.
     */
    public static function getWinners(int $contestId): array {
        return Database::fetchAll(
            "SELECT cw.position, cw.user_id, cw.amount, cw.referrals_count,
                    cw.created_at, u.full_name
             FROM contest_winners cw
             JOIN users u ON u.id = cw.user_id
             WHERE cw.contest_id = ?
             ORDER BY cw.position ASC",
            [$contestId]
        );
    }

    /**
     * Award prizes to the top qualifiers of a contest.
     * Credits each winner's withdrawable wallet via Wallet::credit().
     *
     * @return array ['success' => bool, 'winners' => array, 'errors' => array]
     */
    public static function awardWinners(int $contestId, int $adminId): array {
        $contest = self::getContestById($contestId);
        if (!$contest) {
            return ['success' => false, 'errors' => ['Contest not found']];
        }

        if ($contest['status'] === 'finished' && !empty($contest['awarded_at'])) {
            return ['success' => false, 'errors' => ['Prizes for this contest have already been awarded']];
        }

        $standings = self::getStandings($contestId, 20);
        $prizes    = [1 => (float) $contest['prize1'], 2 => (float) $contest['prize2'], 3 => (float) $contest['prize3']];
        $minRef    = (int) $contest['min_referrals'];

        $winners = [];
        foreach ($standings as $s) {
            if (count($winners) >= 3) break;
            if ($s['count'] < $minRef) break;
            $winners[] = $s;
        }

        if (empty($winners)) {
            return ['success' => false, 'errors' => ['No qualifying winners. Minimum ' . $minRef . ' paid referral required.']];
        }

        Database::beginTransaction();

        try {
            $awarded = [];

            foreach ($winners as $s) {
                $position = $s['position'];
                $amount   = $prizes[$position] ?? 0;

                $winnerId = Database::insert('contest_winners', [
                    'contest_id'       => $contestId,
                    'user_id'          => $s['user_id'],
                    'position'         => $position,
                    'amount'           => $amount,
                    'referrals_count'  => $s['count'],
                ]);

                $txId = Wallet::credit(
                    $s['user_id'],
                    $amount,
                    'contest_bonus',
                    "Referral Contest Prize - Position #{$position} ({$contest['title']})",
                    $winnerId,
                    'contest_prize'
                );

                Database::update('contest_winners', [
                    'wallet_transaction_id' => $txId,
                ], 'id = ?', [$winnerId]);

                Auth::logActivity($s['user_id'], 'contest_prize_awarded',
                    "Won TZS " . number_format($amount) . " in {$contest['title']} (Position #{$position})");

                $awarded[] = [
                    'position' => $position,
                    'user_id'  => $s['user_id'],
                    'amount'   => $amount,
                    'count'    => $s['count'],
                ];
            }

            Database::update('contests', [
                'status'     => 'finished',
                'awarded_by' => $adminId,
                'awarded_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$contestId]);

            Auth::logActivity($adminId, 'contest_awarded',
                "Awarded prizes for contest #{$contestId} (" . count($awarded) . " winners)");

            Database::commit();

            return ['success' => true, 'winners' => $awarded];

        } catch (Exception $e) {
            Database::rollback();
            error_log("EarnSphere: Contest award error: " . $e->getMessage());
            ErrorLogger::logException($e, 'contest', null, 'Contest::awardWinners');
            return ['success' => false, 'errors' => ['System error while awarding prizes']];
        }
    }

    /**
     * Mask a full name for public display, e.g. "Bahati M."
     */
    public static function maskName(?string $fullName): string {
        $fullName = trim((string) $fullName);
        if ($fullName === '') return 'Member';

        $parts = preg_split('/\s+/', $fullName);
        $first = $parts[0] ?? 'Member';
        $last  = $parts[1] ?? null;

        if ($last !== null && $last !== '') {
            return $first . ' ' . mb_substr($last, 0, 1) . '.';
        }
        return $first;
    }
}
