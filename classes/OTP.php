<?php
/**
 * EarnSphere - OTP Class
 * Generates, sends, and verifies OTP codes via email
 * Adapted from mtaita-tech OTP implementation
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Mailer.php';

class OTP {

    /**
     * Generate a 6-digit OTP code
     */
    public static function generate(int $length = OTP_LENGTH): string {
        $otp = '';
        for ($i = 0; $i < $length; $i++) {
            $otp .= random_int(0, 9);
        }
        return $otp;
    }

    /**
     * Create OTP record in database
     * Invalidates previous unused OTPs of the same type for this user
     */
    public static function create(int $userId, string $type = 'reset'): ?string {
        // Invalidate previous unused OTPs
        Database::query(
            "UPDATE user_otps SET used = 1 WHERE user_id = ? AND type = ? AND used = 0",
            [$userId, $type]
        );

        $otp = self::generate();
        $expiry = date('Y-m-d H:i:s', strtotime('+' . OTP_EXPIRY_MINUTES . ' minutes'));

        $id = Database::insert('user_otps', [
            'user_id'    => $userId,
            'type'       => $type,
            'otp'        => $otp,
            'expires_at' => $expiry,
            'used'       => 0,
        ]);

        return $id ? $otp : null;
    }

    /**
     * Send OTP via email
     */
    public static function sendEmail(string $email, string $name, string $otp, string $type = 'reset'): bool {
        $labels = [
            'reset'  => 'Reset Password',
            'verify' => 'Verify Account',
            'login'  => 'Login',
        ];
        $label = $labels[$type] ?? 'Verification';

        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

        $body  = '<div style="font-family:Arial,sans-serif;color:#333;max-width:500px;margin:auto;">';
        $body .= '<p>Hello <strong>' . $safeName . '</strong>,</p>';
        $body .= '<p>Use the code below to ' . htmlspecialchars($label) . ':</p>';
        $body .= '<div style="background:#f4f4f5;border-radius:8px;padding:20px;text-align:center;font-size:36px;font-weight:700;letter-spacing:10px;color:#72578B;font-family:monospace;margin:20px 0;">' . $otp . '</div>';
        $body .= '<p style="font-size:13px;color:#666;">This code expires in <strong>' . OTP_EXPIRY_MINUTES . ' minutes</strong>. Do not share this code with anyone.</p>';
        $body .= '<p style="font-size:13px;color:#666;">If you did not request this code, please ignore this message.</p>';
        $body .= '<hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0;">';
        $body .= '<p style="font-size:12px;color:#94a3b8;">Regards,<br><strong>EarnSphere</strong></p>';
        $body .= '</div>';

        $mailer = new Mailer();
        return $mailer->send($email, $label . ' - EarnSphere', $body);
    }

    /**
     * Send OTP to user (fetches email from DB)
     */
    public static function sendUserOTP(int $userId, string $type = 'reset'): bool {
        $user = Database::fetchOne(
            "SELECT id, full_name, email FROM users WHERE id = ? AND email IS NOT NULL AND email != ''",
            [$userId]
        );

        if (!$user || empty($user['email'])) return false;

        $otp = self::create($userId, $type);
        if (!$otp) return false;

        return self::sendEmail($user['email'], $user['full_name'], $otp, $type);
    }

    /**
     * Verify OTP for user
     */
    public static function verify(int $userId, string $inputOtp, string $type = 'reset'): bool {
        $row = Database::fetchOne(
            "SELECT id, otp FROM user_otps 
             WHERE user_id = ? AND type = ? AND used = 0 AND expires_at > NOW()
             ORDER BY created_at DESC LIMIT 1",
            [$userId, $type]
        );

        if (!$row) return false;

        if (hash_equals($row['otp'], $inputOtp)) {
            Database::update('user_otps', ['used' => 1], 'id = ?', [$row['id']]);
            self::cleanup();
            return true;
        }

        return false;
    }

    /**
     * Clean up expired/used OTPs
     */
    public static function cleanup(): void {
        Database::query("DELETE FROM user_otps WHERE expires_at < NOW() OR used = 1");
    }
}
