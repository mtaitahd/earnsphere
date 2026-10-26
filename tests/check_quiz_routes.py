"""Render and exercise the real quiz endpoints with an isolated database."""
import json, subprocess, sys
from pathlib import Path
root=Path(__file__).resolve().parent.parent
php=sys.argv[1] if len(sys.argv)>1 else 'php'
def run(endpoint,user=200,method='GET',post=None,permission=False):
    code='ob_start();require '+json.dumps(str(root/'tests/quizzes_fixture.php'))+';ob_end_clean();'
    code+='ComprehensionQuiz::create(3,sampleQuiz());'
    if permission: code+='Database::insert("agent_permissions",["user_id"=>204,"permission"=>"quizzes"]);StaffAccess::clearCache();'
    code+='Auth::initSession();$_SESSION=["user_id"=>'+str(user)+',"user_role"=>'+json.dumps('admin' if user==3 else ('agent' if user==204 else 'user'))+',CSRF_TOKEN_NAME=>"quiz-csrf"];'
    code+='$_SERVER["REQUEST_METHOD"]='+json.dumps(method)+';$_SERVER["PHP_SELF"]='+json.dumps('/'+endpoint)+';$_SERVER["REQUEST_URI"]='+json.dumps('/'+endpoint)+';'
    code+='$_POST=json_decode('+json.dumps(json.dumps(post or {}))+',true);'
    code+='register_shutdown_function(function(){echo "\nQUIZ_STATE:".json_encode(["http"=>http_response_code(),"count"=>Database::count("comprehension_quizzes"),"attempts"=>Database::count("comprehension_attempts"),"paid"=>Database::count("comprehension_attempts","status=?",["paid"])]);});'
    code+='require '+json.dumps(str(root/endpoint))+';'
    p=subprocess.run([php,'-r',code],capture_output=True,text=True,timeout=20)
    assert p.returncode==0,(endpoint,p.stderr,p.stdout[-1000:])
    body,state=p.stdout.rsplit('QUIZ_STATE:',1)
    assert 'Fatal error' not in body and 'Warning:' not in body,(endpoint,body[-2000:])
    return body,json.loads(state)
body,state=run('quizzes.php')
assert 'answers[0]' in body and 'answers[1]' in body and 'jaribio' in body
assert 'name="correct"' not in body and '"correct":' not in body and 'Correct answer' not in body,'Public page exposed answer key'
assert run('quizzes.php',method='POST',post={'quiz_id':1,'confirmed':'1','answers':['1','2']})[1]['http']==403
state=run('quizzes.php',method='POST',post={'csrf_token':'quiz-csrf','quiz_id':1,'confirmed':'1','answers':['1','2']})[1]
assert state['http']==302 and state['paid']==1,'Perfect score endpoint did not automatically credit reward'
assert run('quizzes.php',user=203)[1]['http']==302,'Suspended account opened quizzes'
for actor in [200,204]: assert run('admin/quizzes.php',user=actor)[1]['http']==403,'Quiz publishing permission bypassed'
body,state=run('admin/quizzes.php',user=204,permission=True)
assert 'Correct answer' in body and 'quiz-question-list' in body,'Authorized agent could not publish'
assert run('admin/quizzes.php',user=204,permission=True,method='POST',post={'action':'toggle','id':1})[1]['http']==403
state=run('admin/quizzes.php',user=204,permission=True,method='POST',post={'csrf_token':'quiz-csrf','action':'toggle','id':1})[1]
assert state['http']==302,'Authorized agent could not pause quiz'
print('Quiz routes: private answer keys, automatic scoring/payment, CSRF, active accounts and agent publishing permissions passed.')
