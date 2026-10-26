<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/abliner_payment_test.php';
require __DIR__.'/../classes/LoanApplications.php';
$db->exec('ALTER TABLE settings ADD COLUMN description TEXT');
$db->exec("ALTER TABLE users ADD COLUMN created_at TEXT DEFAULT '2020-01-01 00:00:00'");
$db->exec("ALTER TABLE users ADD COLUMN full_name TEXT DEFAULT 'Test User'");
foreach (['email','referral_code','avatar'] as $column) $db->exec('ALTER TABLE users ADD COLUMN '.$column.' TEXT');
$db->exec("CREATE TABLE support_tickets(id INTEGER PRIMARY KEY,status TEXT)");
foreach(explode(';',file_get_contents(__DIR__.'/../database/social_loans.sql')) as $statement){
    if(!trim($statement))continue;
    $statement=str_replace('INT UNSIGNED AUTO_INCREMENT PRIMARY KEY','INTEGER PRIMARY KEY AUTOINCREMENT',$statement);
    $statement=preg_replace('/UNIQUE KEY [a-z_]+ \(/i','UNIQUE (',$statement);
    $statement=preg_replace('/, KEY [a-z_]+ \([^)]*\)/i','',$statement);
    $statement=preg_replace('/\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4/',' )',$statement);
    $db->exec($statement);
}
$db->exec("INSERT INTO users(id,status,role)VALUES(200,'active','user'),(201,'active','user'),(202,'active','user'),(203,'suspended','user'),(204,'active','agent')");
$db->exec('CREATE TABLE agent_permissions(user_id INTEGER,permission TEXT)');
$db->exec("INSERT INTO agent_permissions VALUES(204,'support')");
StaffAccess::clearCache();
