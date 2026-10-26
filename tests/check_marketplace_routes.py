"""Public/storefront and protected moderation route checks. No live provider calls."""
import json, subprocess, sys
from pathlib import Path
root=Path(__file__).resolve().parent.parent
php=sys.argv[1] if len(sys.argv)>1 else 'php'
def run(endpoint,user=0,method='GET',post=None,query=None,permission=False):
    code='ob_start();require '+json.dumps(str(root/'tests/marketplace_fixture.php'))+';ob_end_clean();'
    code+="""
    enableMarketplace();$images=new FixtureProductImages();Wallet::credit(200,10000,'social_reward','Test funds');
    $ad=sampleAd('public-product');$ad['title']='Public phone <script>alert(555)</script>';$ad['description']='Original phone, good condition.';
    $public=Marketplace::create(200,$ad,[],$images);Marketplace::pay(200,$public);Marketplace::review(3,$public,'approved','Checked');
    $ad=sampleAd('private-product');$ad['title']='PRIVATE_DRAFT_201';Marketplace::create(201,$ad,[],$images);
    $ad=sampleAd('pending-product');$ad['title']='PRIVATE_PENDING_200';$pending=Marketplace::create(200,$ad,[],$images);Marketplace::pay(200,$pending);
    $ad=sampleAd('expired-product');$ad['title']='PRIVATE_EXPIRED_PRODUCT';$expired=Marketplace::create(200,$ad,[],$images);Marketplace::pay(200,$expired);Marketplace::review(3,$expired,'approved','Checked');Database::update('marketplace_listings',['expires_at'=>'2020-01-01 00:00:00'],'id = ?',[$expired]);
    """
    if permission: code+='Database::insert("agent_permissions",["user_id"=>204,"permission"=>"marketplace"]);StaffAccess::clearCache();'
    code+='Auth::initSession();$_SESSION='+ ('[]' if user==0 else '["user_id"=>'+str(user)+',"user_role"=>'+json.dumps('admin' if user==3 else ('agent' if user==204 else 'user'))+',CSRF_TOKEN_NAME=>"market-csrf"]')+';'
    code+='$_SERVER["REQUEST_METHOD"]='+json.dumps(method)+';$_SERVER["PHP_SELF"]='+json.dumps('/'+endpoint)+';$_SERVER["REQUEST_URI"]='+json.dumps('/'+endpoint)+';'
    code+='$_POST=json_decode('+json.dumps(json.dumps(post or {}))+',true);$_GET=json_decode('+json.dumps(json.dumps(query or {}))+',true);'
    code+='register_shutdown_function(function(){echo "\nMARKET_STATE:".json_encode(["http"=>http_response_code(),"fees"=>Database::count("wallet_transactions","type=?",["marketplace_fee"]),"reports"=>Database::count("marketplace_reports"),"pending_public"=>Marketplace::publicListing(3)!==null,"unpaid_public"=>Marketplace::publicListing(2)!==null]);});'
    code+='require '+json.dumps(str(root/endpoint))+';'
    p=subprocess.run([php,'-r',code],capture_output=True,text=True,timeout=20)
    assert p.returncode==0,(endpoint,p.stderr,p.stdout[-1000:])
    body,state=p.stdout.rsplit('MARKET_STATE:',1)
    assert 'Fatal error' not in body and 'Warning:' not in body,(endpoint,body[-1500:])
    return body,json.loads(state)
body,state=run('marketplace.php')
assert 'Public phone' in body and 'PRIVATE_' not in body,'Storefront leaked unapproved/expired ads'
assert '<script>alert(555)</script>' not in body,'Product title injected HTML'
assert 'product?id=1' in body,'Public product navigation missing'
for product_id in [2,3,4]:
    body,state=run('product.php',query={'id':product_id})
    assert state['http']==404 and 'Chat on WhatsApp' not in body,'Private/unavailable product leaked contact'
body,state=run('product.php',query={'id':1})
assert 'https://wa.me/255712345678?text=' in body and '<script>alert(555)</script>' not in body
body,state=run('my-products.php',user=200)
assert 'PRIVATE_PENDING_200' in body and 'PRIVATE_DRAFT_201' not in body,'Seller view leaked another account'
assert '<details class="service-card" open><summary>Post bidhaa yako</summary>' in body,'Seller posting form was hidden'
assert run('my-products.php',user=200,method='POST',post={'action':'pay','id':3})[1]['http']==403
state=run('my-products.php',user=200,method='POST',post={'csrf_token':'market-csrf','action':'pay','accepted':'1','id':3})[1]
assert state['http']==302 and state['fees']==3,'Repeated ad payment charged twice'
for actor in [200,204]: assert run('admin/marketplace.php',user=actor)[1]['http']==403,'Marketplace permission bypassed'
assert run('admin/marketplace.php',user=204,permission=True)[1]['http'] in [False,200]
assert run('admin/marketplace.php',user=204,permission=True,query={'tab':'settings'})[1]['http']==403,'Moderator changed fee settings'
assert run('admin/marketplace.php',user=204,permission=True,method='POST',post={'csrf_token':'market-csrf','action':'policy','enabled':'1','fee':'100','days':'90','rules':'Changed rules'})[1]['http']==403
assert run('admin/marketplace.php',user=204,permission=True,method='POST',post={'action':'review','id':3,'decision':'approved','note':'Checked'})[1]['http']==403
state=run('admin/marketplace.php',user=204,permission=True,method='POST',post={'csrf_token':'market-csrf','action':'review','id':3,'decision':'approved','note':'Checked'})[1]
assert state['http']==302 and state['pending_public'],'Authorized agent could not approve paid ad'
state=run('admin/marketplace.php',user=3,method='POST',post={'csrf_token':'market-csrf','action':'review','id':2,'decision':'approved','note':'Checked'})[1]
assert not state['unpaid_public'],'Unpaid ad became public'
state=run('product.php',user=201,method='POST',query={'id':1},post={'csrf_token':'market-csrf','reason':'Suspicious description'})[1]
assert state['http']==302 and state['reports']==1,'Buyer report not recorded'
assert run('my-products.php',user=203)[1]['http']==302,'Suspended user opened seller forms'
print('Marketplace routes: public paid-and-approved ads only, privacy/XSS, seller ownership, CSRF, reports and agent moderation passed.')

# A read failure must not be misreported as a command the operator has not run.
code='ob_start();require '+json.dumps(str(root/'tests/marketplace_fixture.php'))+';ob_end_clean();'
code+='Auth::initSession();$_SESSION=["user_id"=>3,"user_role"=>"admin"];$_SERVER["REQUEST_METHOD"]="GET";$_SERVER["PHP_SELF"]="/admin/marketplace.php";$_SERVER["REQUEST_URI"]="/admin/marketplace";Database::query("DROP TABLE marketplace_listings");'
code+='require '+json.dumps(str(root/'admin/marketplace.php'))+';'
result=subprocess.run([php,'-r',code],capture_output=True,text=True,timeout=20)
assert result.returncode==0 and 'migrate_marketplace.php' not in result.stdout,'Admin still exposes migration command'
assert 'Hatukuweza kufungua matangazo' in result.stdout,'Failed reads have no honest service message'
assert 'SQLSTATE' not in result.stdout,'Database details exposed in admin product flow'
print('Seller form is open and admin read errors no longer imply a missing migration.')
