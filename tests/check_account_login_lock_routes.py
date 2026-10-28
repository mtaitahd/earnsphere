"""Exercise locked login help and the real administrator unlock endpoint without live services."""
import json,os,subprocess,sys
from pathlib import Path
root=Path(__file__).resolve().parent.parent
php=sys.argv[1] if len(sys.argv)>1 else 'php'
for scenario in ['login_help','unlock_csrf','unlock_admin']:
    code='ob_start();require '+json.dumps(str(root/'tests/staff_fixture.php'))+';ob_end_clean();'
    code+='Database::update("users",["password"=>password_hash("correct-password",PASSWORD_BCRYPT,["cost"=>4])],"id = ?",[2]);AccountLoginLock::unlock(1,2);for($i=0;$i<5;$i++)AccountLoginLock::attempt(2,false);'
    if scenario=='login_help':
        code+='$_SESSION=[CSRF_TOKEN_NAME=>"test-csrf"];$_POST=[CSRF_TOKEN_NAME=>"test-csrf","identifier"=>"user@example.test","password"=>"correct-password"];'
        page='login.php'
    else:
        code+='$_SESSION=["user_id"=>1,"user_role"=>"admin",CSRF_TOKEN_NAME=>"test-csrf"];$_POST=["action"=>"unlock_login","user_id"=>2,CSRF_TOKEN_NAME=>'+json.dumps('wrong' if scenario=='unlock_csrf' else 'test-csrf')+'];'
        page='admin/users.php'
    code+='$_SERVER["REQUEST_METHOD"]="POST";$_SERVER["REQUEST_URI"]="/'+page+'";$_SERVER["PHP_SELF"]="/'+page+'";'
    code+='register_shutdown_function(function(){echo "LOCK_STATE:".json_encode(["http"=>http_response_code(),"locked"=>AccountLoginLock::blocked(AccountLoginLock::status(2)),"status"=>Database::fetchOne("SELECT status FROM users WHERE id=2")["status"]]);});require '+json.dumps(str(root/page))+';'
    result=subprocess.run([php,'-r',code],capture_output=True,text=True,timeout=30,env=os.environ|{'APP_ENV':'testing'})
    assert result.returncode==0,(scenario,result.stdout,result.stderr)
    body,state=result.stdout.rsplit('LOCK_STATE:',1);state=json.loads(state)
    assert 'Fatal error' not in body and 'Warning:' not in body,(scenario,body,result.stderr)
    assert state['status']=='active'
    if scenario=='login_help':assert state['locked'] and 'Help — Contact administrator' in body and '/#help' in body
    elif scenario=='unlock_csrf':assert state['http']==403 and state['locked']
    else:assert state['http']==302 and not state['locked']
assert "window.location.hash === '#help'" in (root/'index.php').read_text()
print('Locked login shows Help; public Help opens; administrator unlock works and rejects invalid CSRF.')
