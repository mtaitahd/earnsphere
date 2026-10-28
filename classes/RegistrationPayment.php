<?php
require_once __DIR__.'/CommissionEngine.php';
require_once __DIR__.'/CommissionPlan.php';
require_once __DIR__.'/PaymentNotifications.php';
class RegistrationPayment {
    // Caller holds this payment lock and owns the surrounding transaction.
    public static function activate(array $row): void {
        $user = Database::fetchOne('SELECT status FROM users WHERE id = ? FOR UPDATE', [$row['user_id']]);
        if (!$user) throw new RuntimeException('Payment user not found.');
        if ($user['status'] === 'pending') {
            Database::update('users', ['status' => 'active'], 'id = ?', [$row['user_id']]);
            $stored = json_decode($row['metadata'] ?? '{}', true) ?: [];
            $plan = $stored['commission_plan'] ?? CommissionPlan::values();
            if ((int) $plan['commission_l1'] + (int) $plan['commission_l2'] > (float) $row['amount']) throw new RuntimeException('Commission amounts exceed this older payment. Manual reconciliation required.');
            CommissionEngine::processRegistrationCommissions((int) $row['user_id'], $plan);
            PaymentNotifications::payment($row);
            Database::insert('abliner_notifications', ['payment_id' => $row['id'], 'user_id' => $row['user_id'], 'amount' => $row['amount']]);
        } elseif ($user['status'] !== 'active') throw new RuntimeException('Account cannot be activated automatically.');
        // Close only this account's other registration attempts after verified payment.
        // This is local supersession, not cancellation at the mobile network.
        $others = Database::fetchAll("SELECT * FROM payments WHERE user_id = ? AND purpose = 'registration' AND gateway IN ('abliner','snippe') AND status = 'pending' AND id <> ? FOR UPDATE", [$row['user_id'], $row['id']]);
        foreach ($others as $other) {
            $otherMetadata = json_decode($other['metadata'] ?? '{}', true) ?: [];
            $otherMetadata['superseded_by_payment'] = $row['id'];
            $otherMetadata['local_failure_reason'] = 'Registration paid using another request; provider cancellation is not implied.';
            Database::update('payments', ['status' => 'failed', 'metadata' => json_encode($otherMetadata, JSON_THROW_ON_ERROR)], 'id = ? AND status = ?', [$other['id'], 'pending']);
        }
    }
}
