<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/SecurityRateLimit.php';
require_once __DIR__ . '/ErrorLogger.php';

/** Security signals are suspicions, never proof that a user is an attacker. */
final class SecurityAlerts {
    private static bool $recording = false;
    private const EVENTS = [
        'login_failures' => 'Repeated incorrect login details',
        'login_blocked' => 'Login attempt limit reached',
        'otp_blocked' => 'OTP send or verification limit reached',
        'csrf_failures' => 'Repeated invalid security tokens',
        'access_denied' => 'Repeated attempts to access restricted features',
        'webhook_signature' => 'Payment callback rejected: invalid signature',
    ];

    public static function repeated(string $event, int $threshold = 5, string $scope = '', string $identifier = ''): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!SecurityRateLimit::consume('security-signal:' . $event . ':' . $ip . ':' . $scope, max(1, $threshold - 1), 900)) {
            self::record($event, null, $identifier);
        }
    }

    public static function loginFailure(string $identifier): void {
        self::repeated('login_failures', 5, '', $identifier);
        // Also notice a single account being guessed from different source addresses.
        if (!SecurityRateLimit::consume('security-account-fail:' . hash('sha256', strtolower($identifier)), 4, 900)) {
            self::record('login_failures', null, $identifier);
        }
    }

    private static function ready(): void {
        try { Database::fetchOne('SELECT id FROM error_logs LIMIT 1'); }
        catch (Throwable $e) { ErrorLogger::ensureTable(); }
    }

    public static function record(string $event, ?callable $sender = null, string $identifier = ''): void {
        if (self::$recording || !isset(self::EVENTS[$event])) return;
        self::$recording = true;
        try {
            $ip = substr($_SERVER['REMOTE_ADDR'] ?? 'unknown', 0, 45);
            // At most one record/email per event/source in 15 minutes, even across sessions.
            if (!SecurityRateLimit::consume('security-alert:' . $event . ':' . $ip, 1, 900)) return;
            self::ready();
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            $path = substr(preg_replace('/[\x00-\x1f\x7f]/', '', is_string($path) ? $path : '/'), 0, 180);
            $context = ['event' => $event, 'path' => $path, 'email_status' => 'pending'];
            if (in_array($event, ['login_failures','login_blocked'], true)) {
                $candidate=trim(substr($identifier,0,254));
                if (filter_var($candidate,FILTER_VALIDATE_EMAIL)) $context['attempted_identifier']=$candidate;
                elseif (preg_match('/^\+?[0-9]{9,15}$/D',$candidate)) $context['attempted_identifier']=$candidate;
            }
            $agent=substr(preg_replace('/[\x00-\x1f\x7f]/','', (string)($_SERVER['HTTP_USER_AGENT']??'')),0,512);
            $context['device']=self::deviceDescription($agent);
            $id = Database::insert('error_logs', [
                'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
                'category' => 'security', 'severity' => 'warning', 'source' => 'SecurityAlerts',
                'message' => self::EVENTS[$event], 'context' => json_encode($context),
                'request_method' => substr($_SERVER['REQUEST_METHOD'] ?? '', 0, 10),
                'request_uri' => $path, 'ip_address' => $ip, 'user_agent' => $agent ?: null,
            ]);
            // Only the bounded User-Agent is retained; never passwords, OTPs, cookies or query strings.
            if (!$sender && ENVIRONMENT !== 'production') $context['email_status'] = 'disabled_nonproduction';
            elseif (!SecurityRateLimit::consume('security-alert-email-global', 4, 3600)) $context['email_status'] = 'rate_limited';
            else {
                $setting = Database::fetchOne('SELECT setting_value FROM settings WHERE setting_key = ?', ['admin_email']);
                $to = trim((string) ($setting['setting_value'] ?? ''));
                if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    require_once __DIR__ . '/../includes/helpers.php';
                    $to = ADMIN_NOTIFY_EMAIL;
                }
                $subject = 'EarnSphere security alert #' . $id;
                $text = self::EVENTS[$event] . "\nTime: " . date('d M Y H:i:s T') . "\nSource IP: " . $ip
                    . "\nPath: " . $path . "\nAttempted email/phone: " . ($context['attempted_identifier']??'Not recorded') . "\nReported device: " . $context['device'] . "\nThe request was rejected or limited. This is suspicious activity, not proof of a hacked account."
                    . "\nReview: " . SITE_URL . '/admin/security-alerts';
                $html = '<h2>EarnSphere security alert</h2><p>' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
                try {
                    if ($sender) $sent = $sender($to, $subject, $html);
                    else { require_once __DIR__ . '/Mailer.php'; $sent = (new Mailer())->send($to, $subject, $html); }
                    $context['email_status'] = $sent ? 'accepted' : 'failed';
                } catch (Throwable $e) { $context['email_status'] = 'failed'; error_log('Security alert email failed'); }
            }
            Database::update('error_logs', ['context' => json_encode($context)], 'id = ? AND category = ?', [$id, 'security']);
        } catch (Throwable $e) { error_log('Security alert could not be saved or emailed'); }
        finally { self::$recording = false; }
    }

    public static function deviceDescription(string $agent): string {
        if ($agent==='') return 'Not recorded';
        $os='Unknown OS';
        foreach (['Android'=>'Android','iPhone'=>'iOS (iPhone)','iPad'=>'iPadOS (iPad)','Windows'=>'Windows','Macintosh'=>'macOS','CrOS'=>'ChromeOS','Linux'=>'Linux'] as $token=>$name) {
            if (stripos($agent,$token)!==false) {$os=$name;break;}
        }
        $browser='Unknown browser';
        foreach (['SamsungBrowser/'=>'Samsung Internet','Edg/'=>'Edge','EdgA/'=>'Edge','OPR/'=>'Opera','Firefox/'=>'Firefox','FxiOS/'=>'Firefox','CriOS/'=>'Chrome','Chrome/'=>'Chrome','Safari/'=>'Safari'] as $token=>$name) {
            if (stripos($agent,$token)!==false) {$browser=$name;break;}
        }
        return $os.' / '.$browser;
    }

    private static function readThrough(int $adminId): int {
        $row = Database::fetchOne('SELECT setting_value FROM settings WHERE setting_key = ?', ['security_alert_read_' . $adminId]);
        return max(0, (int) ($row['setting_value'] ?? 0));
    }

    private static function requireAdministrator(int $adminId): void {
        require_once __DIR__ . '/StaffAccess.php';
        StaffAccess::requirePermission('security-alerts');
        if ($adminId !== (int) ($_SESSION['user_id'] ?? 0)) StaffAccess::deny();
    }

    public static function unread(int $adminId): int {
        self::requireAdministrator($adminId);
        try { self::ready(); return Database::count('error_logs', 'category = ? AND id > ?', ['security', self::readThrough($adminId)]); }
        catch (Throwable $e) { return 0; }
    }

    public static function listing(int $adminId): array {
        self::requireAdministrator($adminId);
        self::ready();
        return ['through' => self::readThrough($adminId), 'items' => Database::fetchAll("SELECT id, message, context, ip_address, user_id, user_agent, created_at FROM error_logs WHERE category = ? ORDER BY id DESC LIMIT 50", ['security'])];
    }

    public static function markRead(int $adminId, int $through): void {
        self::requireAdministrator($adminId);
        if ($through <= 0) return;
        $latest = Database::fetchOne('SELECT MAX(id) AS id FROM error_logs WHERE category = ?', ['security']);
        $through = min($through, (int) ($latest['id'] ?? 0));
        $key = 'security_alert_read_' . $adminId;
        Database::beginTransaction();
        try {
            $row = Database::fetchOne('SELECT id, setting_value FROM settings WHERE setting_key = ? FOR UPDATE', [$key]);
            if ($row) Database::update('settings', ['setting_value' => (string) max($through, (int) $row['setting_value'])], 'id = ?', [$row['id']]);
            else Database::insert('settings', ['setting_key' => $key, 'setting_value' => (string) $through, 'description' => 'Administrator security alert read cursor']);
            Database::commit();
        } catch (Throwable $e) { if (Database::inTransaction()) Database::rollback(); throw $e; }
    }
}
