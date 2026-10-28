<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ob_start();
require __DIR__.'/social_loans_fixture.php';
Database::insert('users',['id'=>205,'status'=>'active','role'=>'user']);
Wallet::credit(205,16000,'registration_bonus','Legacy registration fee');
Wallet::credit(205,150,'social_reward','Quiz reward');
Database::update('wallets',['total_earned'=>16150],'user_id = ?',[205]);
$before=Wallet::getWallet(205);
expect(Wallet::earnedTotal(205)===150.0,'Registration fee counted as earnings');
expect(Wallet::getWallet(205)===$before,'Reading earnings changed wallet balances');
Wallet::credit(205,100,'commission','Referral commission');
expect(Wallet::earnedTotal(205)===250.0,'Real earnings were lost');
expect((float)Wallet::getWallet(205)['total_earned']===250.0,'Cached earnings still include registration');
Wallet::reverseCredit(205,16000,'admin_adjustment','Reverse legacy registration fee');
$wallet=Wallet::getWallet(205);
expect((float)$wallet['total_earned']===250.0 && Wallet::earnedTotal(205)===250.0,'Reversing registration reduced real earnings');
expect((float)$wallet['withdrawable_balance']===250.0 && (float)$wallet['balance']===250.0,'Registration reversal changed earned funds');
Wallet::credit(205,500,'admin_adjustment','Non-earning adjustment');
expect((float)Wallet::getWallet(205)['total_earned']===250.0,'Adjustment counted as earnings');
Database::insert('wallet_transactions',['user_id'=>205,'wallet_id'=>$wallet['id'],'type'=>'social_reward','amount'=>999,'status'=>'failed']);
expect(Wallet::earnedTotal(205)===250.0,'Failed reward counted as earnings');
echo "Wallet earnings: legacy registration excluded, legitimate rewards retained, no read-side balance changes, adjustments and reversals passed.\n";

// Real admin activation must record payment without adding the fee to the wallet.
Database::insert('users',['id'=>206,'status'=>'pending','role'=>'user','full_name'=>'Manual payer']);
Wallet::credit(206,150,'social_reward','Existing real reward');
Auth::initSession();$_SESSION=['user_id'=>3,'user_role'=>'admin',CSRF_TOKEN_NAME=>'earnings-csrf'];
$_SERVER['REQUEST_METHOD']='POST';$_SERVER['PHP_SELF']='/admin/users.php';$_SERVER['REQUEST_URI']='/admin/users';
$_POST=['action'=>'mark_as_paid','user_id'=>206,CSRF_TOKEN_NAME=>'earnings-csrf'];
register_shutdown_function(function(){
 expect(http_response_code()===302,'Admin action did not redirect');
 expect(Database::fetchOne('SELECT status FROM users WHERE id=206')['status']==='active','Manual activation failed');
 expect(Database::count('payments',"user_id=? AND status='completed'",[206])===1,'Manual payment not recorded');
 $wallet=Wallet::getWallet(206);
 expect((float)$wallet['balance']===150.0 && (float)$wallet['withdrawable_balance']===150.0 && (float)$wallet['total_earned']===150.0,'Manual fee credited to wallet');
 expect(Database::count('wallet_transactions',"user_id=? AND type='registration_bonus'",[206])===0,'Registration fee credit created');
 echo "Admin Mark as Paid records registration without creating wallet earnings.\n";
});
require dirname(__DIR__).'/admin/users.php';
