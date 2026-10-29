<?php
// Run only on the server console. Never expose credentials in a web response.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../classes/Auth.php';
require_once __DIR__.'/../classes/AdminPIN.php';
try {
    $options=getopt('',['admin-id:']);
    $id=(int)($options['admin-id']??0);
    if (!$id) {
        $admins=Database::fetchAll("SELECT id FROM users WHERE role = 'admin' AND status = 'active'");
        if(count($admins)!==1)throw new RuntimeException('Use --admin-id=ID to choose an administrator.');
        $id=(int)$admins[0]['id'];
    }
    Database::beginTransaction();
    $result=AdminPIN::provision($id);
    Database::commit();
    echo "Administrator #".$id." private login configured. Previous PIN/link revoked.\n";
    echo "Private link: ".$result['url']."\nPIN: ".$result['pin']."\nSave these privately. OTP will be sent to this administrator account email.\n";
} catch(Throwable $e) {
    if(Database::inTransaction())Database::rollback();
    fwrite(STDERR,$e->getMessage()."\n");exit(1);
}
