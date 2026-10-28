"""Exercise real payment-page number selection with an offline gateway."""
import json, os, subprocess, sys, tempfile
from pathlib import Path
root=Path(__file__).resolve().parent.parent
php=sys.argv[1] if len(sys.argv)>1 else 'php'
source=(root/'payment.php').read_text().replace('new AblinerPayment();','new AblinerPayment($client);')
with tempfile.NamedTemporaryFile(mode='w',suffix='.php',prefix='payment-phone-test-',dir=root,encoding='utf-8',delete=False) as f:
    f.write(source);page=Path(f.name)
try:
    for scenario in ['new','pending','switch','csrf','invalid','default','waiting']:
        code='ob_start();require '+json.dumps(str(root/'tests/social_loans_fixture.php'))+';ob_end_clean();'
        code+="Database::insert('users',['id'=>205,'status'=>'pending','role'=>'user','phone'=>'255712345678','full_name'=>'Payment Tester','referral_code'=>'TEST205']);$client=new MockAbliner();$service=new AblinerPayment($client);"
        if scenario in ['pending','switch','default','waiting']:
            code+="$old=$service->initiatePayment(205,'0712345678');"
        if scenario in ['waiting','default']:
            code+="$new=$service->initiatePayment(205,'0624404217',0,true,$old['order_id']);"
        code+='Auth::initSession();$_SESSION=["user_id"=>205,"user_role"=>"user",CSRF_TOKEN_NAME=>"phone-csrf"];'
        code+='$_SERVER["REQUEST_METHOD"]='+json.dumps('GET' if scenario in ['default','waiting'] else 'POST')+';'
        code+='$_GET=["user_id"=>205,"action"=>"show"];$_POST=[];'
        if scenario not in ['default','waiting']:
            post={'action':'pay','phone':'0624404217','csrf_token':'phone-csrf'}
            if scenario=='csrf':post['csrf_token']='wrong'
            if scenario=='invalid':post['phone']='bad-number'
            code+='$_POST=json_decode('+json.dumps(json.dumps(post))+',true);'
        if scenario=='switch':code+='$_POST["confirm_number_change"]="1";$_POST["previous_order"]=$old["order_id"];'
        if scenario=='waiting':code+='$_GET["action"]="waiting";$_GET["order_id"]=$new["order_id"];'
        code+='register_shutdown_function(function(){echo "PHONE_STATE:".json_encode(["http"=>http_response_code(),"phones"=>array_column(Database::fetchAll("SELECT phone FROM payments WHERE user_id=205 ORDER BY id"),"phone"),"profile"=>Database::fetchOne("SELECT phone FROM users WHERE id=205")["phone"]]);});'
        code+='include '+json.dumps(str(page))+';'
        result=subprocess.run([php,'-r',code],capture_output=True,text=True,env=os.environ|{'APP_ENV':'testing'},timeout=20)
        assert result.returncode==0,(scenario,result.stderr,result.stdout[-1000:])
        body,state=result.stdout.rsplit('PHONE_STATE:',1);state=json.loads(state)
        assert state['profile']=='255712345678','Payer selection changed account phone'
        if scenario=='new':assert state['http']==302 and state['phones']==['255624404217'],state
        if scenario=='switch':assert state['http']==302 and state['phones']==['255712345678','255624404217'],state
        if scenario=='pending':assert state['phones']==['255712345678'] and 'value="0624404217"' in body
        if scenario=='csrf':assert state['phones']==[] and 'value="0624404217"' in body
        if scenario=='invalid':assert state['phones']==[] and 'value="bad-number"' in body
        if scenario=='default':assert 'value="255624404217"' in body and '/payment"' in body
        if scenario=='waiting':assert '<strong>255624404217</strong>' in body
    print('Payment payer number: submitted action, gateway phone, confirmed switch, preserved errors, pending default and waiting screen passed. No live requests.')
finally:
    page.unlink(missing_ok=True)
