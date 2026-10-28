<?php
require_once __DIR__.'/CommissionEngine.php';
require_once __DIR__.'/Wallet.php';
class CommissionReconciliation {
    // Signed available balances preserve debt and existing payout reservations.
    public static function apply(int $adminId, array $plan): int {
        if (!Database::inTransaction()) throw new LogicException('Transaction required.');
        $admin=Database::fetchOne('SELECT role,status FROM users WHERE id = ?',[$adminId]);
        if (!$admin || $admin['role']!=='admin' || $admin['status']!=='active') throw new RuntimeException('Administrator required.');
        $rows=Database::fetchAll("SELECT * FROM commissions WHERE status IN ('approved','paid') AND level IN (1,2) ORDER BY earner_id,id FOR UPDATE");
        $changed=0;
        foreach ($rows as $row) {
            $target=(int)$plan['commission_l'.(int)$row['level']];
            $delta=round($target-(float)$row['amount'],2);
            if ($delta==0) continue;
            $wallet=Wallet::getWallet((int)$row['earner_id']);
            $before=(float)$wallet['balance'];
            Database::insert('wallet_transactions',[
                'wallet_id'=>$wallet['id'],'user_id'=>$row['earner_id'],
                'type'=>'commission','amount'=>$delta,'balance_before'=>$before,
                'balance_after'=>$before+$delta,'status'=>'completed',
                'reference_id'=>$row['id'],'reference_type'=>'commission_reconciliation',
                'description'=>'Commission Level '.$row['level'].' reconciliation: TZS '.$row['amount'].' to '.$target.'; admin #'.$adminId,
            ]);
            Database::update('wallets',[
                'balance'=>$before+$delta,
                'withdrawable_balance'=>(float)$wallet['withdrawable_balance']+$delta,
                'total_earned'=>Wallet::earnedTotal((int)$row['earner_id']),
            ],'id = ?',[$wallet['id']]);
            Database::update('commissions',['amount'=>$target],'id = ?',[$row['id']]);
            $changed++;
        }
        Auth::logActivity($adminId,'commission_reconciliation','Reconciled '.$changed.' commissions; Level 1 TZS '.$plan['commission_l1'].'; Level 2 TZS '.$plan['commission_l2'].'. Debt is recovered from future earnings.');
        return $changed;
    }
}
