<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/staff_fixture.php';
function checkLock(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
Database::update('users',['password'=>password_hash('correct-password',PASSWORD_BCRYPT,['cost'=>4])],'id = ?',[2]);
$file=APP_ROOT.'/logs/security-limits/'.hash('sha256','account-password-lock:2').'.json';
try {
    AccountLoginLock::unlock(1,2);
    for($i=1;$i<=4;$i++) {$result=Auth::login($i%2?'0722222222':'user@example.test','wrong');checkLock(!$result['success'] && empty($result['help_required']),'Locked before fifth failure');}
    $result=Auth::login('user@example.test','wrong');checkLock(!empty($result['help_required']),'Fifth failure did not lock');
    $state=AccountLoginLock::status(2);checkLock($state['until']>=time()+7190 && !$state['permanent'],'First lock not two hours');
    $_SERVER['REMOTE_ADDR']='198.51.100.44';$_SESSION=[];
    checkLock(!Auth::login('+255722222222','correct-password')['success'],'Correct password bypassed active lock');
    $before=AccountLoginLock::status(2);Auth::login('user@example.test','wrong');checkLock(AccountLoginLock::status(2)===$before,'Blocked request increased strike count');
    $state['until']=time()-1;file_put_contents($file,json_encode($state));
    checkLock(Auth::login('user@example.test','correct-password')['success'],'Expired lock rejected correct password');
    for($i=0;$i<5;$i++)Auth::login($i%2?'0722222222':'user@example.test','wrong');
    checkLock(AccountLoginLock::status(2)['permanent'],'Second set of failures did not require administrator');
    checkLock(!Auth::login('user@example.test','correct-password')['success'],'Correct password bypassed permanent lock');
    $state=AccountLoginLock::status(2);$state['until']=time()-10000;file_put_contents($file,json_encode($state));
    checkLock(AccountLoginLock::blocked(AccountLoginLock::status(2)),'Permanent lock expired automatically');
    Database::insert('users',['id'=>3,'role'=>'agent','status'=>'active']);
    foreach([2,3] as $actor) {try {AccountLoginLock::unlock($actor,2);throw new LogicException('Unauthorized unlock allowed');}catch(RuntimeException $e){}}
    Database::update('users',['status'=>'suspended'],'id = ?',[2]);AccountLoginLock::unlock(1,2);
    checkLock(!AccountLoginLock::blocked(AccountLoginLock::status(2)),'Administrator did not unlock');
    checkLock(Database::fetchOne('SELECT status FROM users WHERE id=2')['status']==='suspended','Unlock activated suspended member');
    Database::update('users',['status'=>'active'],'id = ?',[2]);checkLock(Auth::login('user@example.test','correct-password')['success'],'Login after unlock failed');
    checkLock(AccountLoginLock::status(2)['strikes']===0,'Administrator did not reset history');
    Auth::login('user@example.test','wrong');Auth::login('user@example.test','correct-password');checkLock(AccountLoginLock::status(2)['failures']===0,'Successful login retained consecutive failures');
    file_put_contents($file,'broken');checkLock(!Auth::login('user@example.test','correct-password')['success'],'Corrupted state allowed login');AccountLoginLock::unlock(1,2);
    checkLock(!AccountLoginLock::blocked(AccountLoginLock::status(2)),'Admin could not repair state');
    echo "Password locks: five attempts, two hours, alias/IP/session isolation, second lock, correct-password enforcement and admin-only unlock passed.\n";
} finally {if(is_file($file))unlink($file);}
