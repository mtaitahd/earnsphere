"""Private admin entry route checks; fake fixtures, no network/mail."""
import json,os,subprocess,sys
from pathlib import Path
root=Path(__file__).resolve().parent.parent
php=sys.argv[1] if len(sys.argv)>1 else 'php'
def run(mode):
 code='require '+json.dumps(str(root/'tests/staff_fixture.php'))+';require_once '+json.dumps(str(root/'classes/AdminPIN.php'))+';'
 code+='$credentials=AdminPIN::provision(1);$_SESSION=[];StaffAccess::clearCache();$_SERVER["REQUEST_METHOD"]="GET";'
 code+='$_SERVER["REQUEST_URI"]='+{'valid':'parse_url($credentials["url"],PHP_URL_PATH)','invalid':'"/staff-entry/".str_repeat("0",64)','direct':'"/admin/private-entry.php"'}[mode]+';'
 code+='register_shutdown_function(function(){echo "STATUS:".json_encode(http_response_code());});require '+json.dumps(str(root/'admin/private-entry.php'))+';'
 result=subprocess.run([php,'-r',code],capture_output=True,text=True,env=os.environ|{'APP_ENV':'testing'},timeout=30)
 assert result.returncode==0,result.stderr
 return result.stdout.rsplit('STATUS:',1)
body,status=run('valid')
assert 'name="pin"' in body and 'name="identifier"' not in body and 'name="password"' not in body
assert body.count('<span class="code-box"></span>')==4 and 'data-code-length="4"' in body
assert 'data-code-keypad' in body and all('data-code-key="'+key+'"' in body for key in ['0','1','2','3','4','5','6','7','8','9','backspace','clear','paste'])
assert json.loads(status) in (False,200)
for mode in ['invalid','direct']:
 body,status=run(mode);assert json.loads(status)==404 and not body
print('Private admin route: valid PIN form, invalid token and direct-file rejection passed.')

assert not (root/'admin/login.php').exists(), 'Old public admin login still exists'
assert 'admin/(?:login|loign)' in (root/'.htaccess').read_text(), 'Removed login aliases are not blocked'
for endpoint in ['login.php','admin/agent-login.php']:
 code='require '+json.dumps(str(root/'tests/staff_fixture.php'))+';'
 code+='Database::update("users",["password"=>password_hash("known-password",PASSWORD_DEFAULT)],"id = ?",[1]);$_SESSION=[CSRF_TOKEN_NAME=>"csrf-test"];StaffAccess::clearCache();'
 code+='$_SERVER["REQUEST_METHOD"]="POST";$_POST=[CSRF_TOKEN_NAME=>"csrf-test","identifier"=>"admin@example.test","password"=>"known-password"];'
 code+='register_shutdown_function(function(){echo "AUTH_STATE:".json_encode(isset($_SESSION["user_id"]));});require '+json.dumps(str(root/endpoint))+';'
 result=subprocess.run([php,'-r',code],capture_output=True,text=True,env=os.environ|{'APP_ENV':'testing'},timeout=30)
 assert result.returncode==0 and result.stdout.endswith('AUTH_STATE:false'), (endpoint,result.stderr)
print('Administrator blocked from member and agent password login; old admin login removed.')
