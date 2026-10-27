"""Exercise admin-only security/referral routes and SMTP rejection with isolated fixtures."""
import json,os,socket,subprocess,sys,threading
from pathlib import Path
root=Path(__file__).resolve().parent.parent
php=sys.argv[1] if len(sys.argv)>1 else 'php'
def run(route,user=0,method='GET',post='[]',permissions=()):
 code='ob_start();require '+json.dumps(str(root/'tests/staff_fixture.php'))+';ob_end_clean();'
 code+='Database::insert("users",["id"=>3,"full_name"=>"Agent","phone"=>"255733333333","role"=>"agent","status"=>"active"]);'
 for permission in permissions:code+='Database::insert("agent_permissions",["user_id"=>3,"permission"=>'+json.dumps(permission)+']);'
 code+='$_SESSION='+('[]' if not user else '["user_id"=>'+str(user)+',"user_role"=>"admin","staff_version"=>0,"csrf_token"=>"route-token"]')+';StaffAccess::clearCache();'
 code+='$_SERVER["REQUEST_METHOD"]='+json.dumps(method)+';$_SERVER["PHP_SELF"]='+json.dumps('/'+route)+';$_POST='+post+';'
 code+='register_shutdown_function(function(){echo "\nSTATUS:".http_response_code();});include '+json.dumps(str(root/route))+';'
 r=subprocess.run([php,'-r',code],capture_output=True,text=True,timeout=20,env=os.environ|{'APP_ENV':'testing'})
 assert r.returncode==0,r.stdout+r.stderr
 body,status=r.stdout.rsplit('\nSTATUS:',1)
 return body,int(status or '200')
for page in ['security-alerts','referral-lookup']:
 for user in [2,3]:
  body,status=run('admin/'+page+'.php',user,permissions=('referrals','error_logs','error_logs_manage'))
  assert status==403 and 'Source IP' not in body and 'Referral code</label>' not in body,(page,user,status)
 assert run('admin/'+page+'.php',0)[1]==302
 assert run('admin/'+page+'.php',1)[1]==200
 assert run('admin/'+page+'.php',1,'POST')[1]==403
# Service-level guard also forbids calling through a different route.
for service in ['SecurityAlerts::listing(2)','SecurityAlerts::markRead(2,999)','ReferralLookup::find("USER")']:
 code='require '+json.dumps(str(root/'tests/staff_fixture.php'))+';require_once '+json.dumps(str(root/'classes/ReferralLookup.php'))+';$_SESSION=["user_id"=>2,"user_role"=>"admin"];StaffAccess::clearCache();register_shutdown_function(function(){echo "STATUS:".http_response_code();});'+service+';'
 r=subprocess.run([php,'-r',code],capture_output=True,text=True,env=os.environ|{'APP_ENV':'testing'})
 assert r.returncode==0 and r.stdout.endswith('STATUS:403'),r.stdout+r.stderr
# The older error-log viewer cannot expose/delete security records for an agent.
for action in ['view','delete_single','clear_all']:
 code='require '+json.dumps(str(root/'tests/staff_fixture.php'))+';'
 code+='(new ReflectionProperty(ErrorLogger::class,"tableChecked"))->setValue(null,true);$staffDb->sqliteCreateFunction("CURDATE",fn()=>date("Y-m-d"));'
 code+='Database::insert("users",["id"=>3,"full_name"=>"Log Agent","phone"=>"255733333333","role"=>"agent","status"=>"active"]);'
 for permission in ['error_logs','error_logs_manage']:code+='Database::insert("agent_permissions",["user_id"=>3,"permission"=>'+json.dumps(permission)+']);'
 code+='Database::insert("error_logs",["category"=>"security","message"=>"PRIVATE_SECURITY_DETAIL_X","severity"=>"warning"]);Database::insert("error_logs",["category"=>"system","message"=>"VISIBLE_ERROR_X","severity"=>"error"]);'
 code+='$_SESSION=["user_id"=>3,"user_role"=>"admin","staff_version"=>0,"csrf_token"=>"route-token"];StaffAccess::clearCache();$_SERVER["PHP_SELF"]="/admin/error-logs.php";$_SERVER["REQUEST_URI"]="/admin/error-logs";'
 code+='$_SERVER["REQUEST_METHOD"]='+json.dumps('GET' if action=='view' else 'POST')+';'
 if action!='view':code+='$_POST=["action"=>'+json.dumps(action)+',"log_id"=>1,"csrf_token"=>"route-token"];'
 code+='register_shutdown_function(function(){echo "RETAINED:".Database::count("error_logs","category = ?",["security"]);});include '+json.dumps(str(root/'admin/error-logs.php'))+';'
 r=subprocess.run([php,'-r',code],capture_output=True,text=True,env=os.environ|{'APP_ENV':'testing'})
 assert r.returncode==0,r.stdout+r.stderr
 assert 'PRIVATE_SECURITY_DETAIL_X' not in r.stdout and r.stdout.endswith('RETAINED:1'),r.stdout+r.stderr
 if action=='view':assert 'VISIBLE_ERROR_X' in r.stdout

# A fake SMTP server rejects STARTTLS: the mailer must not send AUTH/credentials.
listener=socket.socket();listener.bind(('127.0.0.1',0));listener.listen();port=listener.getsockname()[1];commands=[]
def smtp():
 conn,_=listener.accept();conn.settimeout(5)
 with conn,conn.makefile('rwb',buffering=0) as stream:
  stream.write(b'220 test SMTP\r\n');commands.append(stream.readline());stream.write(b'250-localhost\r\n250 STARTTLS\r\n');commands.append(stream.readline());stream.write(b'454 TLS unavailable\r\n')
  try:
   line=stream.readline()
   if line:commands.append(line)
  except (TimeoutError,OSError):pass
 listener.close()
thread=threading.Thread(target=smtp,daemon=True);thread.start()
code='require '+json.dumps(str(root/'classes/Mailer.php'))+';$m=new Mailer();'
for key,value in {'host':'127.0.0.1','port':port,'encryption':'tls','username':'test-user','password':'test-password'}.items():
 code+='(new ReflectionProperty(Mailer::class,'+json.dumps(key)+'))->setValue($m,'+json.dumps(value)+');'
code+='echo (new ReflectionMethod(Mailer::class,"sendSMTP"))->invoke($m,"test@example.test","Test","Hello")?"SENT":"REJECTED";'
r=subprocess.run([php,'-r',code],capture_output=True,text=True,timeout=15,env=os.environ|{'APP_ENV':'testing'});thread.join(6)
assert r.returncode==0 and r.stdout=='REJECTED',r.stdout+r.stderr
assert len(commands)==2 and commands[1]==b'STARTTLS\r\n',commands
source=(root/'classes/Mailer.php').read_text()
assert "'verify_peer'       => true" in source and "'allow_self_signed' => false" in source
print('Admin-only pages/services, guest/user/agent denial, POST/CSRF and SMTP TLS downgrade rejection passed; no real email sent.')
