"""Exercise real notification endpoint authorization and CSRF using offline PHP fixtures."""
import json, subprocess, sys
from pathlib import Path
root=Path(__file__).resolve().parent.parent
php=sys.argv[1] if len(sys.argv)>1 else 'php'
def run(method='GET', token=False, user=200, through=99999, status='active'):
    code='ob_start();require '+json.dumps(str(root/'tests/abliner_payment_test.php'))+';ob_end_clean();'
    code+="Database::query(\"UPDATE user_notifications SET sms_status='skipped'\");"
    code+="$db->exec(\"INSERT INTO users(id,status,role,phone) VALUES(200,'active','user','255712345678'),(201,'active','user','255616591639')\");"
    code+="PaymentNotifications::withdrawal(['id'=>900,'user_id'=>200,'amount'=>1000,'withdrawal_charge'=>0,'phone'=>'255712345678'],960,false);"
    code+="PaymentNotifications::withdrawal(['id'=>901,'user_id'=>201,'amount'=>1000,'withdrawal_charge'=>0,'phone'=>'255616591639'],960,false);"
    code+='Database::update("users",["status"=>'+json.dumps(status)+'],"id=?",[200]);'
    code+='$_SESSION=["user_id"=>'+str(user)+',CSRF_TOKEN_NAME=>"test-csrf"];'
    code+='$_SERVER["REQUEST_METHOD"]='+json.dumps(method)+';'
    code+='$_POST=["action"=>"mark_all_read","through_id"=>'+str(through)+'];'
    if token:code+='$_POST[CSRF_TOKEN_NAME]="test-csrf";'
    code+='register_shutdown_function(function(){echo "TEST_STATE:".json_encode(["http"=>http_response_code(),"own"=>PaymentNotifications::listForUser(200),"other"=>PaymentNotifications::listForUser(201)]);});'
    code+='include '+json.dumps(str(root/'api/notifications.php'))+';'
    p=subprocess.run([php,'-r',code],capture_output=True,text=True,timeout=15)
    assert p.returncode==0,(p.stdout,p.stderr)
    body,state=p.stdout.split('TEST_STATE:')
    return json.loads(body),json.loads(state)
body,state=run();assert state['http']==200 and len(body['items'])==1 and state['own']['unread']==1
body,state=run('POST');assert state['http']==403 and state['own']['unread']==1
body,state=run('POST',True);assert state['http']==200 and state['own']['unread']==0 and state['other']['unread']==1
body,state=run('POST',True,through=-1);assert state['http']==422 and state['own']['unread']==1
body,state=run(user=3);assert state['http']==403
body,state=run(status='suspended');assert state['http']==403
print('Notification endpoint ownership, authenticated reads, CSRF, read-all and suspended-account checks passed.')
