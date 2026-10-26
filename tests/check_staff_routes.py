"""Exercise actual protected PHP routes with isolated staff fixtures; never contacts a payment provider."""
import json
import shutil
import subprocess
import sys
from pathlib import Path
php = sys.argv[1] if len(sys.argv) > 1 else shutil.which("php")
if not php:
    raise SystemExit("Pass your PHP executable.")
root = Path(__file__).resolve().parent.parent

def run(endpoint, permissions=("support",), method="GET", post=None, status="active", version=0):
    code = 'require ' + json.dumps(str(root / "tests/staff_fixture.php")) + ';'
    code += 'Database::insert("users",["id"=>3,"full_name"=>"Agent","phone"=>"255733333333","role"=>"agent","status"=>' + json.dumps(status) + ',"staff_version"=>' + str(version) + ']);'
    for permission in permissions:
        code += 'Database::insert("agent_permissions",["user_id"=>3,"permission"=>' + json.dumps(permission) + ']);'
    code += '$_SESSION=["user_id"=>3,"user_role"=>"admin","staff_version"=>0,CSRF_TOKEN_NAME=>"test-csrf"];StaffAccess::clearCache();'
    code += '$_SERVER["REQUEST_METHOD"]=' + json.dumps(method) + ';$_SERVER["PHP_SELF"]=' + json.dumps('/' + endpoint) + ';'
    code += '$_POST=json_decode(' + json.dumps(json.dumps(post or {})) + ',true);'
    code += 'register_shutdown_function(function(){echo "\nTEST_STATE:".json_encode(["http"=>http_response_code(),"admin"=>Database::fetchOne("SELECT id,status,email FROM users WHERE id = 1"),"agent"=>Database::fetchOne("SELECT id,status FROM users WHERE id = 3"),"ticket"=>Database::fetchOne("SELECT admin_reply FROM support_tickets WHERE id = 1")]);});'
    code += 'require ' + json.dumps(str(root / endpoint)) + ';'
    result = subprocess.run([php, "-r", code], capture_output=True, text=True, timeout=15)
    if result.returncode:
        raise AssertionError(f"{endpoint}: {result.stderr} {result.stdout}")
    return json.loads(result.stdout.split("TEST_STATE:")[-1])

for page in ["index", "users", "abliner-wallet", "payments", "commissions", "withdrawals", "referral-tree", "settings", "agents", "announcements", "contests", "sms", "logs", "error-logs", "social", "loans", "quizzes", "marketplace"]:
    assert run(f"admin/{page}.php")["http"] == 403, f"Support-only agent opened {page}"
for endpoint in ["api/abliner_balance.php", "api/create_payout.php", "api/check_payout.php", "api/payout_preview.php", "api/lookup_phone.php", "api/check_payment.php"]:
    state = run(endpoint, method="POST", post={"csrf_token":"test-csrf"})
    assert state["http"] == 403, f"Support-only agent opened {endpoint}"
for page, permission in [("users","users"),("payments","payments"),("commissions","commissions"),("withdrawals","withdrawals"),("abliner-wallet","wallet"),("logs","logs"),("error-logs","error_logs")]:
    assert run(f"admin/{page}.php", (permission,), "POST", {"csrf_token":"test-csrf"})["http"] == 403, f"Read-only permission allowed mutation on {page}"
assert run("api/create_payout.php", ("withdrawals",), "POST", {"csrf_token":"test-csrf"})["http"] == 403
for action in ["mark_as_paid", "mark_as_unpaid"]:
    assert run("admin/users.php", ("users","users_manage"), "POST", {"csrf_token":"test-csrf","action":action,"user_id":2})["http"] == 403, "User management bypassed financial permission"
assert run("admin/users.php", ("users","users_manage"), "POST", {"csrf_token":"test-csrf","action":"update_status","user_id":2,"new_status":"active"})["http"] == 403, "User management bypassed activation permission"
for target in [1,3]:
    state = run("admin/users.php", ("users","users_manage"), "POST", {"csrf_token":"test-csrf","action":"update_status","user_id":target,"new_status":"suspended"})
    assert state["http"] == 302 and state["admin"]["status"] == "active" and state["agent"]["status"] == "active", "Users module modified staff account"
state = run("admin/users.php", ("users","users_manage"), "POST", {"csrf_token":"test-csrf","action":"update_email","user_id":1,"new_email":"changed@example.test"})
assert state["admin"]["email"] == "admin@example.test", "Agent modified admin email"
state = run("admin/users.php", ("users","users_manage"), "POST", {"csrf_token":"test-csrf","action":"delete_selected","user_ids":[1,3]})
assert state["admin"] and state["agent"], "Bulk deletion removed a staff account"
assert run("admin/support.php", status="suspended")["http"] == 403
assert run("admin/support.php", version=1)["http"] == 403, "Old session bypassed password reset"
assert run("admin/profile.php", method="POST", post={"form_type":"update_email","new_email":"test@example.test"})["http"] == 403, "Profile email update bypassed CSRF"
state = run("admin/support.php", method="POST", post={"csrf_token":"test-csrf","action":"reply","ticket_id":1,"admin_reply":"We are checking your request."})
assert state["http"] == 302 and state["ticket"]["admin_reply"] == "We are checking your request.", "Authorized agent could not reply to a ticket"
assert run("admin/login.php")["http"] == 302, "Logged-in staff was not redirected to an authorized page"
print("Staff direct-route, API, CSRF, privilege escalation and support reply checks passed.")
