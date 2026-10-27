<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/staff_fixture.php';require_once __DIR__.'/../includes/helpers.php';
function ensure(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
$_SESSION[CSRF_TOKEN_NAME]=str_repeat('a',64);ensure(Auth::validateCSRF(str_repeat('a',64)),'Valid CSRF rejected');
foreach(['','bad',[],['token'],123] as $bad)ensure(!Auth::validateCSRF($bad),'Malformed CSRF accepted');
ensure(!str_contains(statusBadge('<img src=x onerror=alert(1)>'),'<img'),'Badge XSS');
$key='test:'.bin2hex(random_bytes(12));ensure(SecurityRateLimit::consume($key,2,900),'First blocked');ensure(SecurityRateLimit::consume($key,2,900),'Second blocked');ensure(!SecurityRateLimit::consume($key,2,900),'Limit bypass');
$file=APP_ROOT.'/logs/security-limits/'.hash('sha256',$key).'.json';file_put_contents($file,json_encode(['count'=>2,'until'=>time()-1]));ensure(SecurityRateLimit::consume($key,2,900),'Expired window blocked');ensure(!str_contains(file_get_contents($file),$key),'Raw identity persisted');unlink($file);
$_SERVER['REMOTE_ADDR']='192.0.2.'.random_int(1,200);
$result=Auth::login("' OR 1=1 --",'bad-password');ensure(!$result['success'],'SQL login bypass');
Database::update('users',['password'=>password_hash('valid-password',PASSWORD_BCRYPT,['cost'=>4])],'id = ?',[2]);
$wrong=Auth::login('user@example.test','wrong-password');ensure($wrong['errors']===$result['errors'],'Account enumeration');
$valid=Auth::login('user@example.test','valid-password');ensure($valid['success'],'Valid login broken');
$identifier='limited-'.bin2hex(random_bytes(8)).'@example.test';for($i=0;$i<20;$i++)ensure(SecurityRateLimit::consume('login-account:'.$identifier,20,900),'Unexpected limit');
$_SERVER['REMOTE_ADDR']='198.51.100.1';$_SESSION=[];$limited=Auth::login($identifier,'anything');ensure(!$limited['success']&&str_contains($limited['errors'][0],'Too many'),'New session/IP bypass');
require_once __DIR__.'/../classes/OTP.php';
$staffDb->sqliteCreateFunction('NOW',fn()=>date('Y-m-d H:i:s'));
$staffDb->exec('CREATE TABLE user_otps (id INTEGER PRIMARY KEY, user_id INTEGER, type TEXT, otp TEXT, expires_at TEXT, used INTEGER, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
for($i=0;$i<3;$i++) ensure(is_string(OTP::create(2,'reset')),'Allowed OTP issuance failed');
ensure(OTP::create(2,'reset')===null,'OTP send limit bypassed');
$otp=Database::fetchOne('SELECT otp FROM user_otps WHERE user_id = 2 AND used = 0')['otp'];
$wrongCode=$otp==='000000'?'111111':'000000';
for($i=0;$i<10;$i++)ensure(!OTP::verify(2,$wrongCode),'Wrong OTP accepted');
ensure(!OTP::verify(2,$otp),'OTP guess limit bypassed');
$validCode=OTP::create(3,'reset');ensure(OTP::verify(3,$validCode),'Valid OTP rejected');ensure(!OTP::verify(3,$validCode),'OTP reused');
$flash=['type'=>'info','message'=>'</script><script>alert(99)</script>'];$_SESSION['flash']=$flash;
ob_start();include __DIR__.'/../admin/admin_footer.php';$footer=ob_get_clean();
ensure(!str_contains($footer,$flash['message']),'Flash script breakout');
ensure(str_contains($footer,'\\u003C'),'Flash message not JS encoded');
echo "Security PHP: CSRF, badge XSS, SQL login payload, generic failures, valid login and persistent limits passed.\n";
