<?php
require_once __DIR__.'/OTP.php';
final class AdminLoginOTP {
    public const EXPIRY_SECONDS = 60;
    private static function binding(array $user): string {
        return hash('sha256', $user['password'].'|'.$user['email'].'|'.($user['staff_version']??0));
    }
    public static function pending(): ?array {
        $pending=$_SESSION['admin_login_otp']??null;
        if (!is_array($pending) || ($pending['expires']??0)<=time()) {unset($_SESSION['admin_login_otp']);return null;}
        return $pending;
    }
    public static function begin(array $user, ?callable $sender=null): array {
        unset($_SESSION['admin_login_otp']);
        if ($user['role']!=='admin' || $user['status']!=='active' || !filter_var($user['email']??'',FILTER_VALIDATE_EMAIL)) return ['success'=>false,'errors'=>['Admin login requires an active account with a valid email.']];
        if (!SecurityRateLimit::consume('admin-login-otp-send:'.$user['id'],3,900)) {
            SecurityAlerts::record('otp_blocked');
            return ['success'=>false,'errors'=>['OTP request limit reached. Try again in 15 minutes.']];
        }
        $code=OTP::generate(6);
        try {$sent=$sender ? $sender($user['email'],$user['full_name'],$code) : OTP::sendEmail($user['email'],$user['full_name'],$code,'login',self::EXPIRY_SECONDS);}
        catch(Throwable $e){$sent=false;}
        if (!$sent) return ['success'=>false,'errors'=>['Could not send the login code. Check the administrator email and mail settings, then try again.']];
        session_regenerate_id(true);
        foreach (['user_id','user_role','staff_version','user_name','user_status','login_ip'] as $key) unset($_SESSION[$key]);
        $_SESSION['admin_login_otp']=['user_id'=>(int)$user['id'],'hash'=>password_hash($code,PASSWORD_DEFAULT),'binding'=>self::binding($user),'expires'=>time()+self::EXPIRY_SECONDS,'sent_at'=>time(),'attempts'=>0];
        return ['success'=>false,'otp_required'=>true];
    }
    public static function resend(?callable $sender=null): array {
        $p=self::pending();
        if (!$p) return ['success'=>false,'errors'=>['Login code expired. Enter your password again.']];
        if (time()-$p['sent_at']<60) return ['success'=>false,'errors'=>['Wait 60 seconds before requesting another code.']];
        $user=Database::fetchOne('SELECT * FROM users WHERE id = ?',[$p['user_id']]);
        if (!$user || !hash_equals($p['binding'],self::binding($user))) {unset($_SESSION['admin_login_otp']);return ['success'=>false,'errors'=>['Account changed. Enter your password again.']];}
        return self::begin($user,$sender);
    }
    public static function verify(string $code): ?array {
        $p=self::pending();
        if (!$p) return null;
        if (!SecurityRateLimit::consume('admin-login-otp-verify:'.$p['user_id'],10,900)) {unset($_SESSION['admin_login_otp']);SecurityAlerts::record('otp_blocked');return null;}
        $_SESSION['admin_login_otp']['attempts']++;
        if (!preg_match('/^[0-9]{6}$/D',$code) || !password_verify($code,$p['hash'])) {
            if ($_SESSION['admin_login_otp']['attempts']>=5) {unset($_SESSION['admin_login_otp']);SecurityAlerts::record('otp_blocked');}
            return null;
        }
        $user=Database::fetchOne('SELECT * FROM users WHERE id = ?',[$p['user_id']]);
        unset($_SESSION['admin_login_otp']);
        if (!$user || $user['role']!=='admin' || $user['status']!=='active' || !hash_equals($p['binding'],self::binding($user))) return null;
        return $user;
    }
}
