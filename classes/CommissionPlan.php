<?php
require_once __DIR__ . '/../config/database.php';

class CommissionPlan {
    public static function values(): array {
        return self::fromInput([]);
    }

    public static function fromInput(array $input): array {
        $values = [];
        foreach (['registration_fee' => REGISTRATION_FEE, 'commission_l1' => COMMISSION_L1, 'commission_l2' => COMMISSION_L2] as $key => $default) {
            $raw = $input[$key] ?? app_setting($key, $default);
            if (!is_scalar($raw) || !preg_match('/^\d+$/D', (string) $raw)) throw new InvalidArgumentException('Enter whole, non-negative TZS amounts for the registration fee and commissions.');
            $values[$key] = (int) $raw;
        }
        if ($values['registration_fee'] < 500 || $values['registration_fee'] > 3000000) throw new InvalidArgumentException('Registration fee must be between TZS 500 and TZS 3,000,000.');
        $total = $values['commission_l1'] + $values['commission_l2'];
        if ($total > $values['registration_fee']) throw new InvalidArgumentException('Level 1 plus Level 2 commissions cannot exceed the registration fee.');
        return $values + ['commission_total' => $total, 'company_earning' => $values['registration_fee'] - $total, 'commission_l3' => 0];
    }
}
