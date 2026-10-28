"""Unpaid members cannot fetch referral links, including forged active session flags."""
import json,os,subprocess,sys
from pathlib import Path
root=Path(__file__).resolve().parent.parent
php=sys.argv[1] if len(sys.argv)>1 else 'php'
for status,page in [('pending','referrals'),('suspended','referrals'),('active','referrals'),('pending','dashboard')]:
    code='ob_start();require '+json.dumps(str(root/'tests/social_loans_fixture.php'))+';ob_end_clean();'
    code+='Database::update("users",["status"=>'+json.dumps(status)+',"referral_code"=>"PRIVATE-REFERRAL-200","full_name"=>"Referral Tester"],"id = ?",[200]);'
    if page=='dashboard':code+='$db->exec("ALTER TABLE payments ADD COLUMN payment_type TEXT");Database::insert("wallets",["user_id"=>200,"balance"=>0,"withdrawable_balance"=>0]);'
    code+='Auth::initSession();$_SESSION=["user_id"=>200,"user_role"=>"user","user_status"=>"active"];$_SERVER["REQUEST_METHOD"]="GET";$_SERVER["REQUEST_URI"]="/'+page+'";$_SERVER["PHP_SELF"]="/'+page+'.php";require '+json.dumps(str(root/(page+'.php')))+';'
    result=subprocess.run([php,'-r',code],capture_output=True,text=True,timeout=30,env=os.environ|{'APP_ENV':'testing'})
    assert result.returncode==0,(status,page,result.stdout[-1600:],result.stderr)
    assert 'Fatal error' not in result.stdout and 'Warning:' not in result.stdout,(status,page,result.stdout[-1600:],result.stderr)
    if status=='active':assert 'PRIVATE-REFERRAL-200' in result.stdout and 'referral-link-input' in result.stdout
    else:assert 'PRIVATE-REFERRAL-200' not in result.stdout and 'referral-link-input' not in result.stdout
    if status=='pending':assert 'Lipia akaunti yako kwanza' in result.stdout and '/payment' in result.stdout
    if page=='dashboard':
        assert result.stdout.count('data-referral-pay-required')>=2 and 'referral-payment-notice' in result.stdout
        assert all(item not in result.stdout for item in ['Know Your Account','welcomeMediaDialog','data-welcome-open','data-menu-guide'])
print('Referral paywall: unpaid/suspended direct access blocked, session flags ignored, paid links retained and dashboard payment prompt passed.')

for status in ['pending','active']:
    code='ob_start();require '+json.dumps(str(root/'tests/social_loans_fixture.php'))+';ob_end_clean();'
    code+='Database::update("users",["status"=>'+json.dumps(status)+'],"id = ?",[200]);$user=Auth::getUser(200);require '+json.dumps(str(root/'includes/welcome_media.php'))+';require '+json.dumps(str(root/'includes/dashboard_menu.php'))+';'
    result=subprocess.run([php,'-r',code],capture_output=True,text=True,timeout=30,env=os.environ|{'APP_ENV':'testing'})
    assert result.returncode==0,result.stderr
    assert ('welcomeMediaDialog' in result.stdout)==(status=='active')
    assert ('data-menu-guide' in result.stdout)==(status=='active')
print('Know Your Account: unpaid dashboard/menu/player hidden; active member access retained.')
