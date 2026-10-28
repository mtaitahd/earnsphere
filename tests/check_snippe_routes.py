"""Render and submit Snippe USSD forms with a simulated provider; no real requests."""
import json,os,subprocess,sys,tempfile
from pathlib import Path
root=Path(__file__).resolve().parent.parent
php=sys.argv[1] if len(sys.argv)>1 else 'php'
source=(root/'payment.php').read_text().replace('new SnippePayment();','new SnippePayment(new UssdPageClient());')
with tempfile.NamedTemporaryFile(mode='w',suffix='.php',prefix='snippe-page-test-',dir=root,encoding='utf-8',delete=False) as f:
    f.write(source);page=Path(f.name)
try:
    for scenario in ['chooser','create','csrf','waiting']:
        code='ob_start();require '+json.dumps(str(root/'tests/social_loans_fixture.php'))+';ob_end_clean();require '+json.dumps(str(root/'classes/SnippePayment.php'))+';'
        code+="""
        class UssdPageClient extends SnippeClient {
          public function __construct(){parent::__construct(['enabled'=>true,'api_key'=>'FAKE-API-SECRET','webhook_secret'=>'FAKE-WEBHOOK-SECRET','base_url'=>'https://api.snippe.sh','callback_url'=>'https://example.test/webhooks/snippe.php']);}
          public function createPayment(array $payload,string $key): array {return ['success'=>true,'data'=>['reference'=>'pay_test','amount'=>['value'=>$payload['details']['amount'],'currency'=>'TZS'],'status'=>'pending']];}
        }
        Database::insert('users',['id'=>205,'status'=>'pending','role'=>'user','phone'=>'255624404217','full_name'=>'Snippe User','referral_code'=>'SN205','email'=>'payer@example.test']);
        Auth::initSession();$_SESSION=['user_id'=>205,'user_role'=>'user',CSRF_TOKEN_NAME=>'checkout-csrf'];
        $_GET=['action'=>'show'];$_POST=[];
        """
        code+='$_SERVER["REQUEST_METHOD"]='+json.dumps('POST' if scenario in ['create','csrf'] else 'GET')+';'
        if scenario in ['create','csrf']:
            code+='$_POST=["action"=>"snippe_pay","phone"=>"0624404217","csrf_token"=>'+json.dumps('wrong' if scenario=='csrf' else 'checkout-csrf')+'];'
        if scenario=='waiting':
            code+='$created=(new SnippePayment(new UssdPageClient()))->initiate(205,"0624404217");$_GET=["action"=>"waiting","order_id"=>$created["order_id"]];'
        code+='register_shutdown_function(function(){echo "SNIPPE_STATE:".json_encode(["http"=>http_response_code(),"payments"=>Database::count("payments","user_id=?",[205]),"status"=>Database::fetchOne("SELECT status FROM users WHERE id=205")["status"]]);});'
        code+='require '+json.dumps(str(page))+';'
        result=subprocess.run([php,'-r',code],capture_output=True,text=True,timeout=20,env=os.environ|{'APP_ENV':'testing'})
        assert result.returncode==0,(scenario,result.stderr)
        body,state=result.stdout.rsplit('SNIPPE_STATE:',1);state=json.loads(state)
        assert 'FAKE-' not in body and 'Fatal error' not in body and 'Warning:' not in body
        assert state['status']=='pending','Return or checkout creation activated user'
        if scenario=='chooser':assert 'Snippe USSD Push' in body and 'name="phone"' in body and 'name="customer_email"' in body and 'Pay via Abliner' in body
        if scenario=='create':assert state['http']==303 and state['payments']==1
        if scenario=='csrf':assert state['payments']==0 and 'Security token expired' in body
        if scenario=='waiting':assert 'Change Snippe number' in body and '255624404217' in body and 'id="changeNumSection"' not in body
        assert 'snippe.me' not in body and 'merchant JWT' not in body and 'checkout_token' not in body
    print('Snippe USSD form, payer number, POST/CSRF, local waiting page, secret privacy and gateway isolation passed.')
    code='ob_start();require '+json.dumps(str(root/'tests/social_loans_fixture.php'))+';ob_end_clean();'
    code+='Auth::initSession();$_SESSION=["user_id"=>3,"user_role"=>"admin"];$_SERVER["REQUEST_METHOD"]="GET";$_SERVER["PHP_SELF"]="/admin/settings.php";$_SERVER["REQUEST_URI"]="/admin/settings";'
    code+='require '+json.dumps(str(root/'admin/settings.php'))+';'
    result=subprocess.run([php,'-r',code],capture_output=True,text=True,timeout=20,env=os.environ|{'APP_ENV':'testing'})
    assert result.returncode==0,result.stderr
    assert 'Snippe USSD Push' in result.stdout and 'collection:create and collection:read' in result.stdout
    assert 'merchant JWT' not in result.stdout and 'checkout_token' not in result.stdout and 'Checkout sessions token' not in result.stdout
    assert 'Fatal error' not in result.stdout and 'Warning:' not in result.stdout
    assert 'id="snippe-test"' in result.stdout and 'value="snippe_test"' in result.stdout
    assert 'name="request_key"' in result.stdout and 'name="confirmed"' in result.stdout
    for action in ['snippe_test','snippe_test_status']:
        protected=code.replace('$_SERVER["REQUEST_METHOD"]="GET";', '$_SERVER["REQUEST_METHOD"]="POST";$_POST=["action"=>'+json.dumps(action)+'];')
        result=subprocess.run([php,'-r',protected],capture_output=True,text=True,timeout=20,env=os.environ|{'APP_ENV':'testing'})
        assert result.returncode==0 and 'Access denied.' in result.stdout,(action,result.stdout,result.stderr)
    print('Admin Snippe settings and test form render; test/status actions reject missing CSRF tokens.')
finally:
    page.unlink(missing_ok=True)
