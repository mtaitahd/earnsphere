<?php
require_once __DIR__ . '/../config/database.php';

class WithdrawalCharge {
    public static function validate(mixed $value): int {
        if (!is_scalar($value) || !preg_match('/^\d+$/D', (string) $value) || (float) $value > 3000000) {
            throw new InvalidArgumentException('Withdrawal charge must be a whole, non-negative TZS amount.');
        }
        return (int) $value;
    }

    public static function configured(): int {
        return self::validate(app_setting('withdrawal_charge', 0));
    }

    public static function quote(float $amount, ?int $charge = null): array {
        $charge = $charge ?? self::configured();
        if (!is_finite($amount) || floor($amount) !== $amount || $amount <= $charge || $charge < 0) {
            throw new InvalidArgumentException('Withdrawal amount must be greater than the withdrawal charge.');
        }
        return ['amount' => $amount, 'withdrawal_charge' => $charge, 'net_amount' => $amount - $charge];
    }
}
