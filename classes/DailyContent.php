<?php
// Content timestamps are stored in UTC; publishing days follow Tanzania time.
final class DailyContent {
    public static function bounds(?DateTimeImmutable $now=null): array {
        $zone=new DateTimeZone('Africa/Dar_es_Salaam');
        $start=($now??new DateTimeImmutable('now',$zone))->setTimezone($zone)->setTime(0,0);
        $utc=new DateTimeZone('UTC');
        return [$start->setTimezone($utc)->format('Y-m-d H:i:s'),$start->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')];
    }
    public static function isCurrent(array $row,?DateTimeImmutable $now=null): bool {
        [$start,$end]=self::bounds($now);
        $created=$row['created_at']??'';
        return is_string($created) && $created>=$start && $created<$end;
    }
    public static function status(array $row): string {
        return in_array($row['status'],['active','paused'],true) && !self::isCurrent($row) ? 'expired' : $row['status'];
    }
    public static function pendingCounts(int $userId): array {
        $counts=['quizzes'=>0,'social'=>0];
        $user=Database::fetchOne('SELECT role,status FROM users WHERE id = ?',[$userId]);
        if (!$user || $user['role']!=='user' || $user['status']!=='active') return $counts;
        [$start,$end]=self::bounds();
        try {
            $row=Database::fetchOne("SELECT COUNT(*) AS total FROM comprehension_quizzes q WHERE q.status='active' AND q.paid_count<q.slots AND q.created_at>=? AND q.created_at<? AND NOT EXISTS (SELECT 1 FROM comprehension_attempts a WHERE a.quiz_id=q.id AND a.user_id=?) AND EXISTS (SELECT 1 FROM payments p WHERE p.user_id=? AND p.purpose='registration' AND p.status='completed' AND p.amount>0)",[$start,$end,$userId,$userId]);
            $counts['quizzes']=(int)$row['total'];
        } catch(Throwable $e) {error_log('Quiz badge unavailable');}
        try {
            $row=Database::fetchOne("SELECT COUNT(*) AS total FROM social_campaigns c WHERE c.status='active' AND c.approved_count<c.slots AND c.created_at>=? AND c.created_at<? AND (c.owner_id IS NULL OR c.owner_id<>?) AND NOT EXISTS (SELECT 1 FROM social_task_opens o WHERE o.campaign_id=c.id AND o.user_id=?) AND NOT EXISTS (SELECT 1 FROM social_submissions s WHERE s.campaign_id=c.id AND s.user_id=?)",[$start,$end,$userId,$userId,$userId]);
            $counts['social']=(int)$row['total'];
        } catch(Throwable $e) {error_log('Social badge unavailable');}
        return $counts;
    }
    public static function requireCurrent(array $row): void {
        if (!self::isCurrent($row)) throw new InvalidArgumentException('This content expired at midnight Tanzania time. Choose today’s published tasks.');
    }
}
