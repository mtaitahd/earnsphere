<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../classes/AgentManager.php';
(new ReflectionProperty(ErrorLogger::class, 'isLogging'))->setValue(null, true);
class StaffTestPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options); }
}
$staffDb = new StaffTestPDO('sqlite::memory:');
$staffDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$staffDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
(new ReflectionProperty(Database::class, 'instance'))->setValue(null, $staffDb);
$staffDb->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, full_name TEXT, phone TEXT UNIQUE, email TEXT UNIQUE, password TEXT, role TEXT DEFAULT 'user', status TEXT DEFAULT 'active', referral_code TEXT UNIQUE, avatar TEXT, staff_version INTEGER DEFAULT 0, is_online INTEGER DEFAULT 0, last_login TEXT, ip_address TEXT)");
$staffDb->exec('CREATE TABLE agent_permissions (user_id INTEGER, permission TEXT, PRIMARY KEY(user_id,permission))');
$staffDb->exec('CREATE TABLE activity_logs (id INTEGER PRIMARY KEY, user_id INTEGER, action TEXT, description TEXT, ip_address TEXT, user_agent TEXT)');
$staffDb->exec('CREATE TABLE support_tickets (id INTEGER PRIMARY KEY, status TEXT, user_id INTEGER, admin_reply TEXT, replied_by INTEGER, replied_at TEXT, user_read INTEGER)');
$staffDb->exec("INSERT INTO support_tickets(id,status,user_id) VALUES (1,'open',2)");
$staffDb->exec('CREATE TABLE withdrawals (id INTEGER PRIMARY KEY, status TEXT)');
$staffDb->exec("INSERT INTO users(id,full_name,phone,email,role,status,referral_code) VALUES (1,'Administrator','255711111111','admin@example.test','admin','active','ADMIN'),(2,'Regular User','255722222222','user@example.test','user','active','USER')");
ini_set('session.save_path', sys_get_temp_dir());
Auth::initSession();
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/admin/support';
$_SERVER['PHP_SELF'] = '/admin/support.php';
$_SESSION = ['user_id' => 1, 'user_role' => 'admin', 'staff_version' => 0];
StaffAccess::clearCache();

$staffDb->exec('CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY AUTOINCREMENT, setting_key TEXT UNIQUE, setting_value TEXT, description TEXT)');
$staffDb->exec("CREATE TABLE IF NOT EXISTS error_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, category TEXT, severity TEXT, source TEXT, message TEXT, context TEXT, request_method TEXT, request_uri TEXT, ip_address TEXT, user_agent TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
