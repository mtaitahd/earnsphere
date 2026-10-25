"""Run isolated PHP processes to check endpoint access without a database or API key."""
import json
import shutil
import subprocess
import sys
from pathlib import Path

php = sys.argv[1] if len(sys.argv) > 1 else shutil.which("php")
if not php:
    raise SystemExit("Pass your PHP executable as the first argument.")
root = Path(__file__).resolve().parent.parent

def run(endpoint, method="POST", session=None):
    setup = 'ini_set("session.save_path",sys_get_temp_dir());$_SERVER["REQUEST_METHOD"]=' + json.dumps(method) + ';'
    if session is not None:
        setup += 'require ' + json.dumps(str(root / "tests/staff_fixture.php")) + ';'
        setup += 'Database::update("users",["role"=>' + json.dumps(session) + '],"id = ?",[1]);'
        setup += '$_SESSION=["user_id"=>1,"user_role"=>' + json.dumps(session) + '];'
        setup += '$_SERVER["REQUEST_METHOD"]=' + json.dumps(method) + ';StaffAccess::clearCache();'
    setup += 'register_shutdown_function(function(){echo "HTTP:".http_response_code();});'
    setup += 'include ' + json.dumps(str(root / endpoint)) + ';'
    result = subprocess.run([php, "-r", setup], capture_output=True, text=True, timeout=15)
    if result.returncode:
        raise AssertionError(f"{endpoint}: {result.stderr}")
    return result.stdout

for name in ["test-payout.php", "temp_setup_otp.php", "diagnostic.php", "mail_test.php", "install.php", "api/payment-debug.php"]:
    for method in ["GET", "POST"]:
        assert run(name, method) == "HTTP:404", f"Retired endpoint exposed: {name}"
for name in ["create_payment.php", "check_payment.php", "check_withdrawal.php", "notifications.php", "lookup_phone.php", "create_payout.php", "check_payout.php", "abliner_balance.php", "payout_preview.php"]:
    assert run("api/" + name).endswith("HTTP:401"), f"Anonymous access accepted: {name}"
for name in ["create_payment.php", "create_payout.php", "lookup_phone.php"]:
    assert run("api/" + name, session="admin").endswith("HTTP:403"), f"Missing CSRF accepted: {name}"
for name in ["create_payout.php", "check_payout.php", "abliner_balance.php"]:
    assert run("api/" + name, session="user").endswith("HTTP:403"), f"Non-admin access accepted: {name}"
assert run("webhooks/snippe.php") == "HTTP:410", "Legacy webhook remains active"
print("Payment endpoint access checks passed.")
