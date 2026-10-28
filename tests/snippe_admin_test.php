<?php
require __DIR__.'/snippe_payment_test.php';
$mock=new MockSnippe();$service=new SnippePayment($mock);
$input=['phone'=>'0624404217','amount'=>'500','name'=>'Admin Tester','email'=>'admin@example.test','confirmed'=>'1','request_key'=>'SN-'.bin2hex(random_bytes(12))];
foreach([200,204] as $actor)rejects(fn()=>$service->testPush($actor,$input),'Non-admin sent test');
foreach(['confirmed'=>'0','amount'=>'499','email'=>'invalid','name'=>'Single','request_key'=>'invalid'] as $key=>$value)rejects(fn()=>$service->testPush(3,array_replace($input,[$key=>$value])),'Invalid test accepted');
expect(count($mock->posts)===0,'Invalid request sent push');
$wallets=Database::fetchAll('SELECT * FROM wallets ORDER BY id');$commissions=Database::count('commissions');$notifications=Database::count('user_notifications');
$result=$service->testPush(3,$input);$row=Database::fetchOne('SELECT * FROM payments WHERE order_id=?',[$result['order_id']]);
expect($row['purpose']==='gateway_test' && (int)$row['user_id']===3,'Wrong test classification');
$service->testPush(3,$input);$service->testPush(3,array_replace($input,['request_key'=>'SN-'.bin2hex(random_bytes(12))]));
expect(count($mock->posts)===1,'Duplicate test push');
$ref=$row['transaction_ref'];$mock->payments[$ref]['status']='completed';$event=['type'=>'payment.completed','data'=>$mock->payments[$ref]];
$service->processWebhook($event);$service->processWebhook($event);$service->reconcile((int)$row['id']);
expect(Database::fetchOne('SELECT status FROM payments WHERE id=?',[$row['id']])['status']==='completed','Test not settled');
expect(Database::fetchAll('SELECT * FROM wallets ORDER BY id')===$wallets,'Test changed wallet');
expect(Database::count('commissions')===$commissions && Database::count('user_notifications')===$notifications,'Test triggered registration rewards');
expect(count($mock->posts)===1,'Status check sent push');
echo "Admin USSD test permissions, validation, duplicate prevention and isolated settlement passed. No live requests.\n";
