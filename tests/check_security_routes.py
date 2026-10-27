"""Real PHP route guards and concurrent limit checks; SQLite/temp files only, no sends."""
import concurrent.futures,json,subprocess,sys,uuid
from pathlib import Path
root=Path(__file__).resolve().parent.parent
php=sys.argv[1] if len(sys.argv)>1 else 'php'
def run(route,method='GET',session='',post='[]',query='[]'):
 code='ob_start();require '+json.dumps(str(root/'tests/staff_fixture.php'))+';ob_end_clean();'
 code+='$_SESSION=["user_id"=>2,"user_role"=>"user",CSRF_TOKEN_NAME=>"security-test"'+session+'];'
 code+='$_SERVER["REQUEST_METHOD"]='+json.dumps(method)+';$_POST='+post+';$_GET='+query+';'
 code+='register_shutdown_function(function(){echo "\nSECURITY_STATE:".json_encode(["http"=>http_response_code(),"session"=>$_SESSION]);});'
 code+='include '+json.dumps(str(root/route))+';'
 r=subprocess.run([php,'-r',code],capture_output=True,text=True,timeout=20)
 assert r.returncode==0,r.stderr
 body,state=r.stdout.rsplit('\nSECURITY_STATE:',1)
 return body,json.loads(state)
for route,query in [('api/announcements.php','["action"=>"mark_read","id"=>1]'),('api/resend_otp.php','[]')]:
 assert run(route,query=query)[1]['http']==405,route+' accepts GET mutation'
 assert run(route,'POST',post='[]',query=query)[1]['http']==403,route+' missing CSRF accepted'
 assert run(route,'POST',post='["csrf_token"=>["bad"]]',query=query)[1]['http']==403,route+' malformed CSRF not rejected safely'
 assert run(route,'POST',post='["csrf_token"=>"wrong"]',query=query)[1]['http']==403,route+' incorrect CSRF accepted'
# Valid token proceeds to ID validation without changing anything.
assert 'Invalid ID' in run('api/announcements.php','POST',post='["csrf_token"=>"security-test"]',query='["action"=>"mark_read"]')[0]
for session in [',"reset_user_id"=>2,"reset_verified"=>true',',"reset_user_id"=>2,"reset_verified"=>true,"reset_verified_user"=>1,"reset_verified_at"=>time()',',"reset_user_id"=>2,"reset_verified"=>true,"reset_verified_user"=>2,"reset_verified_at"=>time()-901']:
 assert run('reset_password.php',session=session)[1]['http']==302,'Invalid reset grant accepted'
valid=',"reset_user_id"=>2,"reset_verified"=>true,"reset_verified_user"=>2,"reset_verified_at"=>time()'
assert 'New Password' in run('reset_password.php',session=valid)[0],'Valid reset form broken'
# New recovery clears any earlier proof, even if the new email is invalid.
# Use guest session by replacing user_id with null to avoid the logged-in redirect.
_,state=run('forgot_password.php','POST',session=',"user_id"=>null'+valid,post='["csrf_token"=>"security-test","email"=>"invalid"]')
assert not any(k in state['session'] for k in ['reset_verified','reset_verified_user','reset_verified_at','reset_user_id']),'Stale reset proof retained'
key='parallel-test:'+uuid.uuid4().hex
code='define("APP_ROOT",'+json.dumps(str(root))+');require '+json.dumps(str(root/'classes/SecurityRateLimit.php'))+';echo SecurityRateLimit::consume('+json.dumps(key)+',3,900)?"1":"0";'
def attempt(_):return subprocess.check_output([php,'-r',code],text=True)
with concurrent.futures.ThreadPoolExecutor(max_workers=12) as pool:
 results=list(pool.map(attempt,range(12)))
assert results.count('1')==3,results
print('Security routes: POST/CSRF, malformed tokens, account-bound expiring reset proof, recovery restart and concurrent limits passed.')
