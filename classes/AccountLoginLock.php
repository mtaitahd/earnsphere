<?php
/** Persistent per-member password locks. Keep logs/security-limits across deployments. */
final class AccountLoginLock {
    private static function mutate(int $userId, callable $change,bool $repair=false): array {
        if ($userId<=0) throw new InvalidArgumentException('Invalid account.');
        $directory=APP_ROOT.'/logs/security-limits';
        if (!is_dir($directory) && !@mkdir($directory,0700,true) && !is_dir($directory)) throw new RuntimeException('Login protection storage unavailable.');
        $handle=@fopen($directory.'/'.hash('sha256','account-password-lock:'.$userId).'.json','c+');
        if (!$handle) throw new RuntimeException('Login protection storage unavailable.');
        try {
            if (!flock($handle,LOCK_EX)) throw new RuntimeException('Cannot secure account login.');
            $raw=stream_get_contents($handle);
            $state=$raw===''?['failures'=>0,'strikes'=>0,'until'=>0,'permanent'=>false]:json_decode($raw,true);
            if (!is_array($state) || !isset($state['failures'],$state['strikes'],$state['until'],$state['permanent'])) {
                if (!$repair) throw new RuntimeException('Login protection state requires administrator review.');
                $state=['failures'=>0,'strikes'=>0,'until'=>0,'permanent'=>false];
            }
            $state=$change($state);
            $encoded=json_encode($state,JSON_THROW_ON_ERROR);
            rewind($handle);
            if (!ftruncate($handle,0) || fwrite($handle,$encoded)!==strlen($encoded) || !fflush($handle)) throw new RuntimeException('Cannot save login protection.');
            return $state;
        } finally {flock($handle,LOCK_UN);fclose($handle);}
    }
    public static function status(int $userId): array {
        return self::mutate($userId,static fn($state)=>$state);
    }
    public static function blocked(array $state): bool {return !empty($state['permanent']) || (int)$state['until']>time();}
    public static function attempt(int $userId,bool $correct): array {
        return self::mutate($userId,static function($state) use($correct) {
            if (self::blocked($state)) return $state;
            if ($state['until']>0) {$state['until']=0;$state['failures']=0;}
            if ($correct) {$state['failures']=0;return $state;}
            $state['failures']++;
            if ($state['failures']>=5) {
                $state['failures']=0;$state['strikes']++;
                if ($state['strikes']>=2) $state['permanent']=true;
                else $state['until']=time()+7200;
            }
            return $state;
        });
    }
    public static function rejection(array $state): array {
        $message=!empty($state['permanent'])
            ? 'Akaunti imefungwa baada ya password kukosewa tena. Wasiliana na administrator kupitia Help ili ifunguliwe.'
            : 'Password imekosewa mara 5. Login imefungwa kwa saa 2. Jaribu baada ya '.date('d M H:i',(int)$state['until']).' au tumia Help.';
        return ['success'=>false,'help_required'=>true,'errors'=>[$message]];
    }
    public static function unlock(int $actor,int $userId): void {
        $admin=Database::fetchOne('SELECT role, status FROM users WHERE id = ?',[$actor]);
        $user=Database::fetchOne('SELECT role FROM users WHERE id = ?',[$userId]);
        if (!$admin || $admin['role']!=='admin' || $admin['status']!=='active' || !$user || $user['role']!=='user') throw new RuntimeException('Administrator access required.');
        // An administrator may also repair an unreadable lock file after reviewing the account.
        self::mutate($userId,static fn($state)=>['failures'=>0,'strikes'=>0,'until'=>0,'permanent'=>false],true);
        Auth::logActivity($actor,'account_login_unlocked','Administrator removed password lock for user #'.$userId);
    }
}
