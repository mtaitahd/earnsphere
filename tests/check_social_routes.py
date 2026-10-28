"""Exercise feature routes with an isolated DB. No provider requests."""
import json, subprocess, sys
from pathlib import Path
root=Path(__file__).resolve().parent.parent
php=sys.argv[1] if len(sys.argv)>1 else 'php'
def run(endpoint,user=200,method='GET',post=None,accept=''):
    code='ob_start();require '+json.dumps(str(root/'tests/social_loans_fixture.php'))+';ob_end_clean();'
    code+='LoanApplications::savePolicy(3,["enabled"=>"1","terms"=>"Test terms: zero fee, 30 days","min_amount"=>"1000","max_amount"=>"10000","min_days"=>"0","min_referrals"=>"0"]);'
    code+="""
    $seedTask=SocialHub::createCampaign(3,['platform'=>'youtube','url'=>'https://youtu.be/dQw4w9WgXcQ','title'=>'Review this lesson','instructions'=>'What did you learn?','reward'=>'100','slots'=>'5','budget_confirmed'=>'1']);
    SocialHub::submit(200,$seedTask,'<script>alert(999)</script>My feedback');
    SocialHub::submit(201,$seedTask,'PRIVATE_USER_201_SUBMISSION');
    SocialHub::requestPromotion(200,['platform'=>'facebook','url'=>'https://facebook.com/example','title'=>'My promotion','brief'=>'Feedback please','slots'=>'5','request_key'=>'user-200-promo']);
    SocialHub::requestPromotion(201,['platform'=>'facebook','url'=>'https://facebook.com/example','title'=>'PRIVATE_USER_201_PROMOTION','brief'=>'Private brief','slots'=>'5','request_key'=>'user-201-promo']);
    SocialHub::quote(3,1,['price'=>'1000','reward'=>'100','slots'=>'5','terms'=>'Feedback only, no guaranteed followers.']);
    SocialHub::quote(3,2,['price'=>'1000','reward'=>'100','slots'=>'5','terms'=>'Private quotation']);
    $terms=LoanApplications::hash(LoanApplications::policy());
    LoanApplications::apply(200,['purpose'=>'Equipment','request_key'=>'user-200-loan','accepted'=>'1','amount'=>'1000','terms_hash'=>$terms]);
    LoanApplications::apply(201,['purpose'=>'PRIVATE_USER_201_LOAN','request_key'=>'user-201-loan','accepted'=>'1','amount'=>'1000','terms_hash'=>$terms]);
    """
    code+='Auth::initSession();$_SESSION=["user_id"=>'+str(user)+',"user_role"=>'+json.dumps('admin' if user==3 else ('agent' if user==204 else 'user'))+',CSRF_TOKEN_NAME=>"route-csrf"];'
    code+='$_SERVER["REQUEST_METHOD"]='+json.dumps(method)+';$_SERVER["PHP_SELF"]='+json.dumps('/'+endpoint)+';$_SERVER["REQUEST_URI"]='+json.dumps('/'+endpoint)+';'
    code+='$_SERVER["HTTP_ACCEPT"]='+json.dumps(accept)+';'
    code+='$_POST=json_decode('+json.dumps(json.dumps(post or {}))+',true);'
    code+='register_shutdown_function(function(){echo "\nROUTE_STATE:".json_encode(["http"=>http_response_code(),"tasks"=>Database::count("social_campaigns"),"applications"=>Database::count("loan_applications")]);});'
    code+='require '+json.dumps(str(root/endpoint))+';'
    p=subprocess.run([php,'-r',code],capture_output=True,text=True,timeout=20)
    assert p.returncode==0,(endpoint,p.stderr,p.stdout[-1000:])
    body,state=p.stdout.rsplit('ROUTE_STATE:',1)
    assert 'Fatal error' not in body and 'Warning:' not in body,(endpoint,body[-1500:])
    return body,json.loads(state)
for endpoint in ['social-tasks.php','promote.php']:
    body,state=run(endpoint)
    assert state['http'] in (False,200) and '<form' in body and 'route-csrf' in body,(endpoint,state)
    assert 'PRIVATE_USER_201' not in body,'Another user records leaked'
    assert '<script>alert(999)</script>' not in body,'Task answer allowed HTML injection'
    assert run(endpoint,method='POST')[1]['http']==403,'Missing CSRF was accepted'
    assert run(endpoint,user=203)[1]['http']==302,'Suspended user opened feature'
assert run('loans.php')[1]['http']==302,'Removed loan page did not redirect'
assert run('loans.php',method='POST')[1]['http']==302,'Removed loan form still accepts submissions'
for endpoint in ['admin/social.php','admin/loans.php']:
    assert run(endpoint,user=200)[1]['http']==403,'User opened admin feature'
    assert run(endpoint,user=204)[1]['http']==403,'Support-only agent opened feature'
    assert run(endpoint,user=3,method='POST')[1]['http']==403,'Admin mutation bypassed CSRF'
    body,state=run(endpoint,user=3)
    assert state['http'] in (False,200) and 'route-csrf' in body,(endpoint,state)
print('Social/loan page rendering, active users, admin permissions and CSRF checks passed.')

for endpoint in ['admin/social.php','admin/loans.php']:
    body,state=run(endpoint,user=3,method='POST',post={'csrf_token':'route-csrf','action':'invalid'})
    assert state['http']==302,'Valid admin POST did not follow redirect flow'
print('Populated user pages hide other users records and escape submitted HTML.')

# AJAX task opening must return JSON for success and all access failures.
for actor,post,status in [(202,{'csrf_token':'route-csrf','action':'open','campaign_id':1},200),(202,{'action':'open','campaign_id':1},403),(203,{'csrf_token':'route-csrf','action':'open','campaign_id':1},403),(999,{'csrf_token':'route-csrf','action':'open','campaign_id':1},401),(200,{'csrf_token':'route-csrf','action':'open','campaign_id':1},409)]:
    body,state=run('social-tasks.php',user=actor,method='POST',post=post,accept='application/json')
    result=json.loads(body)
    assert state['http']==status,(state,body)
    assert result['success']==(status==200)
    if status==200: assert result['url']=='https://youtu.be/dQw4w9WgXcQ'
    else: assert result['message']
body,_=run('social-tasks.php',user=202)
assert '/social-tasks" target="_blank" rel="noopener" data-social-open' in body
print('Social open JSON: success, repeat protection, expired session, inactive account and CSRF passed.')
