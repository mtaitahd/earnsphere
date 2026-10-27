"""Real Users route, with unauthorized financial tables absent: no hidden financial queries allowed."""
import json,os,subprocess,sys
from pathlib import Path
root=Path(__file__).resolve().parent.parent
php=sys.argv[1] if len(sys.argv)>1 else 'php'
def run(permissions=(),admin=False,revoked=False,user=False,route="users"):
 code='ob_start();require '+json.dumps(str(root/'tests/staff_fixture.php'))+';ob_end_clean();'
 code+='$staffDb->exec("ALTER TABLE users ADD COLUMN referred_by INTEGER");$staffDb->exec("ALTER TABLE users ADD COLUMN created_at TEXT");$staffDb->sqliteCreateFunction("YEAR",fn($date)=>(int)substr($date,0,4));Database::update("users",["created_at"=>"2026-10-27","password"=>"PRIVATE_HASH_MUST_NOT_BE_SELECTED"],"id = ?",[2]);'
 code+='Database::insert("users",["id"=>3,"full_name"=>"Agent Test","phone"=>"255733333333","role"=>"agent","status"=>"active","created_at"=>"2026-10-27"]);'
 for permission in permissions:code+='Database::insert("agent_permissions",["user_id"=>3,"permission"=>'+json.dumps(permission)+']);'
 if revoked:code+='Database::delete("agent_permissions","permission = ?",["dashboard"]);'
 effective=set(permissions)-({'dashboard'} if revoked else set())
 if admin or effective.intersection({'payments','dashboard'}):code+='$staffDb->exec("CREATE TABLE payments(id INTEGER PRIMARY KEY,user_id INTEGER,amount NUMERIC,purpose TEXT,status TEXT,completed_at TEXT)");Database::insert("payments",["user_id"=>2,"amount"=>97531,"purpose"=>"registration","status"=>"completed","completed_at"=>"2026-10-27"]);'
 if admin:code+='$staffDb->exec("CREATE TABLE commissions(id INTEGER PRIMARY KEY,earner_id INTEGER,amount NUMERIC,status TEXT)");Database::insert("commissions",["earner_id"=>2,"amount"=>86420,"status"=>"approved"]);'
 code+='$_SESSION=["user_id"=>'+str(1 if admin else (2 if user else 3))+',"user_role"=>"admin","permissions"=>["dashboard","payments","commissions","users_manage"]];StaffAccess::clearCache();'
 code+='$_GET='+('[]' if admin else '["status"=>"suspended"]')+';'
 code+='$_SERVER["REQUEST_METHOD"]="GET";$_SERVER["PHP_SELF"]='+json.dumps('/admin/'+route+'.php')+';$_SERVER["REQUEST_URI"]='+json.dumps('/admin/'+route)+';'
 code+='register_shutdown_function(function(){echo "\nPRIVACY_STATE:".json_encode(["http"=>http_response_code(),"fields"=>isset($GLOBALS["users"][0])?array_keys($GLOBALS["users"][0]):[]]);});include '+json.dumps(str(root/('admin/'+route+'.php')))+';'
 r=subprocess.run([php,'-r',code],capture_output=True,text=True,timeout=20,env=os.environ|{'APP_ENV':'testing'})
 assert r.returncode==0,r.stdout+r.stderr
 assert 'Warning:' not in r.stdout and 'Warning:' not in r.stderr,r.stdout+r.stderr
 body,state=r.stdout.rsplit('\nPRIVACY_STATE:',1)
 return body,json.loads(state)
def check(permissions,summary=False,payments=False,earnings=False,manage=False,admin=False,revoked=False):
 body,state=run(permissions,admin=admin,revoked=revoked)
 assert state['http'] in (False,200),state
 assert ('> Total Paid</div>' in body)==summary,permissions
 assert ('<th>Payment</th>' in body)==payments,permissions
 assert ('<th>Earnings</th>' in body)==earnings,permissions
 assert ('<th>Referrals</th>' in body)==admin,permissions
 assert ('<th>Status</th>' in body)==admin,permissions
 assert ('direct_referrals' in state['fields'])==admin,permissions
 assert ('status' in state['fields'])==(admin or manage),permissions
 assert ('<option value="">All Status</option>' in body)==admin,permissions
 assert ('paid_amount' in state['fields'])==payments,permissions
 assert ('total_earned' in state['fields'])==earnings,permissions
 assert 'password' not in state['fields'] and 'PRIVATE_HASH_MUST_NOT_BE_SELECTED' not in body
 assert ('id="bulkDeleteForm"' in body)==manage,permissions
 assert ('id="emailModal"' in body)==manage,permissions
 assert ('97531' in body or '97,531' in body)==(summary or payments),permissions
 assert ('86,420' in body)==earnings,permissions
 if not payments:assert 'payment-paid-total">Total paid:' not in body and 'class="user-paid"' not in body
 if not manage:assert 'value="delete_single"' not in body and 'value="update_status"' not in body
 if not admin and 'payments_manage' not in permissions:assert 'value="mark_as_paid"' not in body and 'value="mark_as_unpaid"' not in body
 return body
check(('users',))
check(('users','commissions'))
check(('users','payments'),payments=True)
check(('users','dashboard'),summary=True)
check(('users','users_manage'),manage=True)
check(('users','users_manage','payments','payments_manage'),payments=True,manage=True)
check((),summary=True,payments=True,earnings=True,manage=True,admin=True)
check(('users','dashboard'),revoked=True)
for permissions in [(),('dashboard',)]:assert run(permissions)[1]['http']==403,'Users route bypassed View users permission'
assert run(('users','commissions'),route='index')[1]['http']==403,'Users permission exposed dashboard route'
assert run(('users',),user=True)[1]['http']==403,'Normal user opened staff route'
print('Users financial privacy: no unauthorized financial SQL/HTML, independent grants, revoked grants, forged sessions, admin access and management controls passed.')
