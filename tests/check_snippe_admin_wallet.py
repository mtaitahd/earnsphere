"""Render and exercise the admin company wallet with a simulated Snippe provider."""
import json,os,subprocess,sys,tempfile
from pathlib import Path
root=Path(__file__).resolve().parent.parent
php=sys.argv[1] if len(sys.argv)>1 else 'php'
source=(root/'admin/snippe-wallet.php').read_text().replace('new SnippeAdminTransfer();','new SnippeAdminTransfer(new MockCompanySnippe());')
with tempfile.NamedTemporaryFile(mode='w',suffix='.php',prefix='company-wallet-test-',dir=root/'admin',encoding='utf-8',delete=False) as f:
    f.write(source);page=Path(f.name)
try:
    for scenario in ['render','preview','csrf','send']:
        code='ob_start();require '+json.dumps(str(root/'tests/snippe_admin_transfer_test.php'))+';ob_end_clean();'
        code+='Auth::initSession();$_SESSION=["user_id"=>3,"user_role"=>"admin",CSRF_TOKEN_NAME=>"test-csrf"];$_SERVER["REQUEST_URI"]="/admin/snippe-wallet";$_SERVER["PHP_SELF"]="/admin/snippe-wallet.php";'
        code+='$_SERVER["REQUEST_METHOD"]='+json.dumps('GET' if scenario=='render' else 'POST')+';'
        if scenario=='preview':code+='$_POST=["action"=>"preview","phone"=>"0712345678","amount"=>"5000",CSRF_TOKEN_NAME=>"test-csrf"];'
        elif scenario=='csrf':code+='$_POST=["action"=>"send",CSRF_TOKEN_NAME=>"wrong"];'
        elif scenario=='send':
            code+='$prepared=(new SnippeAdminTransfer(new MockCompanySnippe()))->preview(3,["phone"=>"0712345678","amount"=>"5000"]);$key="SN-CA-".bin2hex(random_bytes(12));$_SESSION["snippe_company_quote"]=$prepared+["key"=>$key,"expires"=>time()+300];$_POST=["action"=>"send","request_key"=>$key,"confirmed"=>"1",CSRF_TOKEN_NAME=>"test-csrf"];'
        code+='register_shutdown_function(function(){echo "COMPANY_STATE:".json_encode(["http"=>http_response_code(),"quote"=>$_SESSION["snippe_company_quote"]??null,"count"=>Database::count("snippe_admin_transfers")]);});require '+json.dumps(str(page))+';'
        result=subprocess.run([php,'-r',code],capture_output=True,text=True,timeout=30,env=os.environ|{'APP_ENV':'testing'})
        assert result.returncode==0,(scenario,result.stdout,result.stderr)
        body,state=result.stdout.rsplit('COMPANY_STATE:',1);state=json.loads(state)
        assert 'Fatal error' not in body and 'Warning:' not in body,(scenario,body,result.stderr)
        if scenario=='render':assert 'Verify recipient &amp; preview fee' in body and 'Recent company withdrawals' in body
        elif scenario=='preview':assert state['http']==302 and state['quote']['total']==5100 and state['count']==3
        elif scenario=='csrf':assert state['http']==403 and state['count']==3
        else:assert state['http']==302 and state['count']==4 and state['quote'] is None
    print('Admin company wallet renders, previews fees, sends only after confirmation and rejects invalid CSRF.')
finally:
    page.unlink(missing_ok=True)
