<?php
if (PHP_SAPI!=='cli') exit;
require __DIR__.'/abliner_payment_test.php';
require __DIR__.'/../classes/CommissionReconciliation.php';
$db->exec('DELETE FROM commissions');$db->exec('DELETE FROM wallet_transactions');$db->exec('DELETE FROM wallets');
$db->exec("INSERT INTO wallets(user_id,balance,withdrawable_balance,total_earned,total_withdrawn,pending_amount) VALUES(2,100,50,10000,9900,50)");
$w=Wallet::getWallet(2);
$tx=Database::insert('wallet_transactions',['wallet_id'=>$w['id'],'user_id'=>2,'type'=>'commission','amount'=>10000,'status'=>'completed']);
Database::insert('commissions',['earner_id'=>2,'source_user_id'=>1,'level'=>1,'amount'=>10000,'status'=>'approved','wallet_transaction_id'=>$tx]);
Database::insert('commissions',['earner_id'=>2,'source_user_id'=>4,'level'=>2,'amount'=>999,'status'=>'cancelled']);
function reconcilePlan(int $l1): int {
 Database::beginTransaction();
 try {$n=CommissionReconciliation::apply(3,['commission_l1'=>$l1,'commission_l2'=>1000]);Database::commit();return $n;}
 catch(Throwable $e){Database::rollback();throw $e;}
}
expect(reconcilePlan(8000)===1,'One eligible commission');
$w=Wallet::getWallet(2);
expect((float)$w['balance']===-1900.0 && (float)$w['withdrawable_balance']===-1950.0,'Withdrawn money becomes debt');
expect((float)$w['total_earned']===8000.0 && (float)$w['pending_amount']===50.0 && (float)$w['total_withdrawn']===9900.0,'Net earnings and reservations');
expect(reconcilePlan(8000)===0,'Idempotent');
Database::beginTransaction();
$wd=Database::insert('withdrawals',['user_id'=>2,'amount'=>50,'withdrawal_charge'=>0,'status'=>'pending','gateway'=>'abliner','phone'=>'255712345678']);
$method=new ReflectionMethod(AblinerPayment::class,'settleWithdrawal');
$method->invoke(new AblinerPayment(new MockAbliner()),['withdrawal_id'=>$wd,'user_id'=>2,'amount'=>50],'completed');
expect(Database::fetchOne('SELECT status FROM withdrawals WHERE id = ?',[$wd])['status']==='completed','Confirmed payout completes despite reconciliation debt');
expect((float)Wallet::getWallet(2)['pending_amount']===0.0,'Held payout released once');
Database::rollback();

Wallet::credit(2,1000,'social_reward','Future reward');
expect((float)Wallet::getWallet(2)['withdrawable_balance']===-950.0,'Future earnings repay debt');
expect(reconcilePlan(11000)===1,'Increase');
expect((float)Wallet::getWallet(2)['withdrawable_balance']===2050.0 && Wallet::earnedTotal(2)===12000.0,'Net increase');
Database::beginTransaction();CommissionReconciliation::apply(3,['commission_l1'=>5000,'commission_l2'=>1000]);Database::rollback();
expect((float)Wallet::getWallet(2)['withdrawable_balance']===2050.0,'Atomic rollback');
Database::beginTransaction();rejects(fn()=>CommissionReconciliation::apply(2,['commission_l1'=>1,'commission_l2'=>1]),'Admin required');Database::rollback();
echo "Commission reconciliation tests passed.\n";
