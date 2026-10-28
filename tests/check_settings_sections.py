"""Independent settings forms save only their own fields; no live services."""
import json,os,subprocess,sys
from pathlib import Path
from html.parser import HTMLParser
root=Path(__file__).resolve().parent.parent
php=sys.argv[1] if len(sys.argv)>1 else 'php'
def run(post=None):
    code='ob_start();require '+json.dumps(str(root/'tests/social_loans_fixture.php'))+';ob_end_clean();'
    code+='foreach(["site_name"=>"Original site","snippe_api_key"=>"stored-secret","snippe_enabled"=>"0","maintenance_mode"=>"0"] as $key=>$value)Database::insert("settings",["setting_key"=>$key,"setting_value"=>$value]);'
    code+='Auth::initSession();$_SESSION=["user_id"=>3,"user_role"=>"admin",CSRF_TOKEN_NAME=>"test-csrf"];$_SERVER["REQUEST_URI"]="/admin/settings";$_SERVER["PHP_SELF"]="/admin/settings.php";'
    code+='$_SERVER["REQUEST_METHOD"]='+json.dumps('GET' if post is None else 'POST')+';$_POST=json_decode('+json.dumps(json.dumps(post or {}))+',true);'
    code+='register_shutdown_function(function(){echo "SETTINGS_STATE:".json_encode(["http"=>http_response_code(),"settings"=>array_column(Database::fetchAll("SELECT setting_key,setting_value FROM settings"),"setting_value","setting_key")]);});require '+json.dumps(str(root/'admin/settings.php'))+';'
    result=subprocess.run([php,'-r',code],capture_output=True,text=True,timeout=30,env=os.environ|{'APP_ENV':'testing'})
    assert result.returncode==0,(result.stdout,result.stderr)
    body,state=result.stdout.rsplit('SETTINGS_STATE:',1)
    assert 'Fatal error' not in body and 'Warning:' not in body,(body,result.stderr)
    return body,json.loads(state)
def post(section,values,csrf='test-csrf'):
    return run({'action':'save_section','section':section,'settings':values,'csrf_token':csrf})
class Forms(HTMLParser):
    def __init__(self):super().__init__();self.current=None;self.forms=[]
    def handle_starttag(self,tag,attrs):
        attrs=dict(attrs)
        if tag=='form':
            assert self.current is None,'Nested forms';self.current=[]
        if tag in ('input','select','textarea') and self.current is not None:self.current.append(attrs)
    def handle_endtag(self,tag):
        if tag=='form':assert self.current is not None;self.forms.append(self.current);self.current=None
body,state=run();parser=Forms();parser.feed(body)
sections={next((item.get('value') for item in form if item.get('name')=='section'),None):form for form in parser.forms}
assert all(key in sections for key in ['general','finance','abliner','snippe','sms','system'])
assert body.count('class="settings-section mb-3"')==8
finance={field.get('name') for field in sections['finance']}
assert 'settings[registration_fee]' in finance and 'settings[commission_l1]' in finance and 'settings[withdrawal_charge]' in finance
_,state=post('general',{'site_name':'Updated site'})
assert state['http']==302 and state['settings']['site_name']=='Updated site' and state['settings']['registration_fee']=='12000' and 'commission_total' not in state['settings']
_,state=post('snippe',{'snippe_api_key':'','snippe_enabled':'1'})
assert state['http']==302 and state['settings']['snippe_api_key']=='stored-secret' and state['settings']['snippe_enabled']=='1' and state['settings']['site_name']=='Original site'
_,state=post('snippe',{'registration_fee':'9999'})
assert state['http']==422 and state['settings']['registration_fee']=='12000'
_,state=post('system',{'maintenance_mode':'1'},csrf='wrong')
assert state['http']==403 and state['settings']['maintenance_mode']=='0'
_,state=post('finance',{'registration_fee':'15000','commission_l1':'3000','commission_l2':'2000','company_earning':'1','commission_total':'1','withdrawal_charge':'500','min_withdrawal':'5000','max_withdrawal':'1000000'})
assert state['http']==302 and float(state['settings']['company_earning'])==10000 and float(state['settings']['commission_total'])==5000 and state['settings']['site_name']=='Original site'
_,state=post('finance',{'registration_fee':'500','commission_l1':'1000','commission_l2':'1000'})
assert state['settings']['registration_fee']=='12000'
print('Settings sections: separate forms, scoped saves, secret preservation, finance validation, derived totals and CSRF passed.')
