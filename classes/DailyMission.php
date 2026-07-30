<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

class DailyMission {

    public static function getActiveMission(): ?array {
        $mission = Database::fetchOne(
            "SELECT * FROM missions WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT 1"
        );
        return $mission ?: null;
    }

    public static function getOrCreateUserMission(int $userId): array {
        $today = date('Y-m-d');

        $existing = Database::fetchOne(
            "SELECT udm.*, m.title, m.description, m.type as mission_type, m.requirement_type, m.requirement_count as base_requirement
             FROM user_daily_missions udm
             JOIN missions m ON udm.mission_id = m.id
             WHERE udm.user_id = ? AND udm.mission_date = ?",
            [$userId, $today]
        );

        if ($existing) {
            return $existing;
        }

        $mission = self::getActiveMission();
        if (!$mission) {
            return [
                'id' => 0,
                'status' => 'no_mission',
                'title' => '',
                'description' => '',
            ];
        }

        $udmId = Database::insert('user_daily_missions', [
            'user_id'           => $userId,
            'mission_id'        => $mission['id'],
            'mission_date'      => $today,
            'requirement_type'  => $mission['requirement_type'],
            'requirement_count' => $mission['requirement_count'],
            'completed_count'   => 0,
            'reward_amount'     => $mission['reward_amount'],
            'status'            => 'in_progress',
        ]);

        return Database::fetchOne(
            "SELECT udm.*, m.title, m.description, m.type as mission_type, m.requirement_type, m.requirement_count as base_requirement
             FROM user_daily_missions udm
             JOIN missions m ON udm.mission_id = m.id
             WHERE udm.id = ?",
            [$udmId]
        );
    }

    public static function countTodayPaidReferrals(int $userId): int {
        $today = date('Y-m-d');

        $result = Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM users u
             INNER JOIN payments p ON u.id = p.user_id
             WHERE u.referred_by = ?
               AND DATE(p.completed_at) = ?
               AND p.status = 'completed'
               AND u.status = 'active'
               AND p.payment_type = 'registration'",
            [$userId, $today]
        );

        return (int) ($result['cnt'] ?? 0);
    }

    public static function checkAndUpdateProgress(int $userId): array {
        $mission = self::getOrCreateUserMission($userId);

        if (empty($mission) || $mission['status'] === 'no_mission') {
            return ['success' => false, 'error' => 'No active mission'];
        }

        if ($mission['status'] === 'completed') {
            return [
                'success' => true,
                'completed' => true,
                'already_completed' => true,
                'mission' => $mission,
            ];
        }

        if ($mission['mission_date'] !== date('Y-m-d')) {
            return ['success' => false, 'error' => 'Mission expired'];
        }

        $completedCount = self::countTodayPaidReferrals($userId);
        $requirement = (int) $mission['requirement_count'];

        Database::update('user_daily_missions', [
            'completed_count' => $completedCount,
        ], 'id = ?', [$mission['id']]);

        if ($completedCount >= $requirement) {
            return self::awardReward($userId, $mission['id']);
        }

        return [
            'success' => true,
            'completed' => false,
            'mission' => array_merge($mission, ['completed_count' => $completedCount]),
            'progress' => "$completedCount/$requirement",
        ];
    }

    private static function awardReward(int $userId, int $udmId): array {
        try {
            Database::beginTransaction();

            $mission = Database::fetchOne(
                "SELECT udm.*, m.title, m.description
                 FROM user_daily_missions udm
                 JOIN missions m ON udm.mission_id = m.id
                 WHERE udm.id = ? FOR UPDATE",
                [$udmId]
            );

            if (!$mission || $mission['status'] === 'completed') {
                Database::rollback();
                return ['success' => false, 'error' => 'Already completed'];
            }

            $amount = (float) $mission['reward_amount'];

            Database::update('user_daily_missions', [
                'status'       => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
                'completed_count' => $mission['requirement_count'],
            ], 'id = ?', [$udmId]);

            $description = "Daily Mission Bonus: {$mission['title']}";
            $txId = Wallet::credit(
                $userId,
                $amount,
                'daily_mission_bonus',
                $description,
                $udmId,
                'daily_mission'
            );

            Database::insert('mission_rewards', [
                'user_id'                => $userId,
                'user_daily_mission_id'  => $udmId,
                'amount'                 => $amount,
                'wallet_transaction_id'  => $txId,
                'status'                 => 'completed',
            ]);

            Auth::logActivity($userId, 'daily_mission_completed', $description . ' TZS ' . number_format($amount));

            Database::commit();

            return [
                'success'  => true,
                'completed' => true,
                'amount'   => $amount,
                'description' => $description,
                'message'  => "Hongera! Umekamilisha mission ya leo na kupata TZS " . number_format($amount),
            ];

        } catch (Exception $e) {
            Database::rollback();
            error_log("Daily Mission award error: " . $e->getMessage());
            ErrorLogger::logException($e, 'wallet', $userId, 'DailyMission::awardReward');
            return ['success' => false, 'error' => 'System error awarding reward'];
        }
    }

    public static function hasCompletedToday(int $userId): bool {
        $today = date('Y-m-d');
        $result = Database::fetchOne(
            "SELECT id FROM user_daily_missions WHERE user_id = ? AND mission_date = ? AND status = 'completed'",
            [$userId, $today]
        );
        return (bool) $result;
    }

    public static function getMissionStatus(int $userId): array {
        $mission = self::getOrCreateUserMission($userId);

        if (empty($mission) || !isset($mission['id']) || $mission['id'] === 0) {
            return ['has_mission' => false];
        }

        $completedCount = self::countTodayPaidReferrals($userId);
        $requirement = (int) $mission['requirement_count'];
        $progress = min($completedCount, $requirement);
        $percentage = $requirement > 0 ? min(100, round(($completedCount / $requirement) * 100)) : 0;

        if ($mission['status'] === 'in_progress' && $mission['mission_date'] === date('Y-m-d')) {
            if ($completedCount < $requirement) {
                Database::update('user_daily_missions', [
                    'completed_count' => $completedCount,
                ], 'id = ?', [$mission['id']]);
            } elseif ($completedCount >= $requirement) {
                $result = self::awardReward($userId, $mission['id']);
                if ($result['success']) {
                    return [
                        'has_mission'   => true,
                        'completed'     => true,
                        'just_completed' => true,
                        'amount'        => $result['amount'],
                        'message'       => $result['message'],
                        'title'         => $mission['title'],
                        'description'   => $mission['description'],
                    ];
                }
            }
        }

        $isCompleted = $mission['status'] === 'completed';

        return [
            'has_mission'    => true,
            'id'             => $mission['id'],
            'title'          => $mission['title'] ?? 'Daily Mission',
            'description'    => $mission['description'] ?? '',
            'requirement_count' => $requirement,
            'completed_count'   => $completedCount,
            'progress'       => $progress,
            'percentage'     => $percentage,
            'reward_amount'  => $mission['reward_amount'],
            'status'         => $isCompleted ? 'completed' : 'in_progress',
            'completed'      => $isCompleted,
            'just_completed' => false,
            'mission_date'   => $mission['mission_date'],
        ];
    }
}
