<?php
require_once __DIR__.'/AdminLoginOTP.php';
final class AdminPIN {
    public static function provision(int $adminId): array {
        $user=Database::fetchOne("SELECT * FROM users WHERE id = ? AND role = 'admin' AND status = 'active'",[$adminId]);
        if (!$user || !filter_var($user['email']??'',FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Choose an active administrator with a valid email address.');
        $token=bin2hex(random_bytes(32));
        $pin='';for($i=0;$i<4;$i++)$pin.=random_int(0,9);
        $key='admin_access_'.$adminId;
        $value=json_encode(['pin_hash'=>password_hash($pin,PASSWORD_DEFAULT),'token_hash'=>hash('sha256',$token)],JSON_THROW_ON_ERROR);
        $row=Database::fetchOne('SELECT id FROM settings WHERE setting_key = ?',[$key]);
        if ($row) Database::update('settings',['setting_value'=>$value],'id = ?',[$row['id']]);
        else Database::insert('settings',['setting_key'=>$key,'setting_value'=>$value,'description'=>'Private administrator PIN and entry token hashes']);
        Auth::logActivity($adminId,'admin_pin_provisioned','Administrator private entry and PIN created or rotated.');
        return ['pin'=>$pin,'url'=>rtrim(SITE_URL,'/').'/staff-entry/'.$token];
    }
    public static function resolve(string $token): ?array {
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) return null;
        $rows=Database::fetchAll("SELECT setting_key,setting_value FROM settings WHERE setting_key LIKE 'admin_access_%'");
        foreach ($rows as $row) {
            $config=json_decode($row['setting_value'],true);
            if (!is_array($config) || !hash_equals((string)($config['token_hash']??''),hash('sha256',$token))) continue;
            $id=(int)substr($row['setting_key'],strlen('admin_access_'));
            $user=Database::fetchOne("SELECT * FROM users WHERE id = ? AND role = 'admin' AND status = 'active'",[$id]);
            return $user ? ['user'=>$user,'config'=>$config] : null;
        }
        return null;
    }
    public static function login(array $entry,string $pin,?callable $sender=null): array {
        $id=(int)$entry['user']['id'];$ip=$_SERVER['REMOTE_ADDR']??'unknown';
        $ipAllowed=SecurityRateLimit::consume('admin-pin-ip:'.$ip,20,900);
        $accountAllowed=SecurityRateLimit::consume('admin-pin-account:'.$id,5,900);
        if (!$ipAllowed || !$accountAllowed) {
            SecurityAlerts::record('login_blocked',null,$entry['user']['email']);
            return ['success'=>false,'errors'=>['Too many PIN attempts. Try again in 15 minutes.']];
        }
        if (!preg_match('/^[0-9]{4}$/D',$pin) || !password_verify($pin,$entry['config']['pin_hash'])) {
            SecurityAlerts::loginFailure($entry['user']['email']);
            return ['success'=>false,'errors'=>['Incorrect PIN.']];
        }
        $result=AdminLoginOTP::begin($entry['user'],$sender);
        if (!empty($result['otp_required'])) $_SESSION['admin_login_otp']['entry_hash']=$entry['config']['token_hash'];
        return $result;
    }
}
