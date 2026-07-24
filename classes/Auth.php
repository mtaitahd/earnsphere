<?php
/**
 * EarnSphere - Authentication Handler
 * Manages user registration, login, sessions, CSRF protection
 */

require_once __DIR__ . '/../config/database.php';

class Auth {
    
    /**
     * Initialize secure session
     */
    public static function initSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            // Harden session cookie settings
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_secure', 1);
            ini_set('session.cookie_samesite', 'Lax');
            ini_set('session.use_strict_mode', 1);
            ini_set('session.use_only_cookies', 1);
            ini_set('session.use_trans_sid', 0);
            ini_set('session.sid_length', 48);
            ini_set('session.sid_bits_per_character', 6);
            ini_set('session.gc_maxlifetime', 86400);
            
            session_start();
        }
        
        // Regenerate session ID periodically
        if (!isset($_SESSION['last_regeneration'])) {
            $_SESSION['last_regeneration'] = time();
        } elseif (time() - $_SESSION['last_regeneration'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = time();
        }
    }
    
    /**
     * Generate CSRF token
     */
    public static function generateCSRF(): string {
        if (empty($_SESSION[CSRF_TOKEN_NAME])) {
            $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        }
        return $_SESSION[CSRF_TOKEN_NAME];
    }
    
    /**
     * Validate CSRF token
     */
    public static function validateCSRF(?string $token = null): bool {
        $token = $token ?? $_POST[CSRF_TOKEN_NAME] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        return isset($_SESSION[CSRF_TOKEN_NAME]) && hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
    }
    
    /**
     * Register new user
     */
    public static function register(array $data): array {
        $errors = [];
        
        // Validate inputs
        $fullName = trim($data['full_name'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $confirmPassword = $data['confirm_password'] ?? '';
        $referralCode = trim($data['referral_code'] ?? '');
        
        if (strlen($fullName) < 3) {
            $errors[] = "Full name is too short";
        }
        
        if (!preg_match('/^(?:\+255|0)[67]\d{8}$/', $phone)) {
            $errors[] = "Phone number is invalid";
        }
        
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Email address is invalid";
        }
        
        if (strlen($password) < 6) {
            $errors[] = "Password is too short (at least 6 characters)";
        }
        
        if ($password !== $confirmPassword) {
            $errors[] = "Passwords do not match";
        }
        
        // Normalize phone FIRST
        if (substr($phone, 0, 1) === '0') {
            $phone = '255' . substr($phone, 1);
        }
        
        // Check if phone already exists
        $existingPhone = Database::fetchOne("SELECT id FROM users WHERE phone = ?", [$phone]);
        if ($existingPhone) {
            $errors[] = "Phone number already registered";
        }
        
        // Check if email already exists
        if (!empty($email)) {
            $existingEmail = Database::fetchOne("SELECT id FROM users WHERE email = ?", [$email]);
            if ($existingEmail) {
                $errors[] = "Email already registered";
            }
        }
        
        // Validate referral code if provided
        $referrerId = null;
        if (!empty($referralCode)) {
            $referrer = Database::fetchOne(
                "SELECT id, status FROM users WHERE referral_code = ? AND status != 'suspended'",
                [$referralCode]
            );
            if (!$referrer) {
                $errors[] = "Referral code is invalid or suspended";
            } else {
                $referrerId = $referrer['id'];
            }
        }
        
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }
        
        // Generate unique referral code
        $userReferralCode = self::generateReferralCode($fullName);
        
        try {
            Database::beginTransaction();
            
            // Create user
            $userId = Database::insert('users', [
                'full_name'    => $fullName,
                'phone'        => $phone,
                'email'        => $email ?: null,
                'password'     => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                'referral_code' => $userReferralCode,
                'referred_by'  => $referrerId,
                'status'       => 'pending',
                'role'         => 'user',
            ]);
            
            // Create wallet
            Database::insert('wallets', [
                'user_id' => $userId,
                'balance' => 0.00,
            ]);
            
            // If referred, create referral records for full chain
            if ($referrerId) {
                self::createReferralChain($userId, $referrerId);
            }
            
            Database::commit();
            
            // Log activity
            self::logActivity($userId, 'registration', "User registered successfully");
            
            // Notify admin
            $msg = "New Registration\n\nName: {$fullName}\nPhone: {$phone}\nEmail: " . ($email ?: 'N/A') . "\nReferral Code: {$userReferralCode}";
            @notifyAdmin("New User Registration - {$fullName}", $msg);
            
            return [
                'success' => true,
                'user_id' => $userId,
                'phone'   => $phone,
                'referrer_id' => $referrerId,
            ];
            
        } catch (Exception $e) {
            Database::rollback();
            error_log("Registration error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['System error. Please try again.']];
        }
    }
    
    /**
     * Create referral chain records (up to 3 levels)
     */
    private static function createReferralChain(int $newUserId, int $directReferrerId): void {
        // Level 1: Direct referrer
        Database::insert('referrals', [
            'referrer_id' => $directReferrerId,
            'referred_id' => $newUserId,
            'level'       => 1,
        ]);
        
        // Level 2: Who referred the direct referrer
        $level2Referrer = Database::fetchOne(
            "SELECT referred_by FROM users WHERE id = ? AND referred_by IS NOT NULL",
            [$directReferrerId]
        );
        
        if ($level2Referrer && $level2Referrer['referred_by'] != $newUserId) {
            Database::insert('referrals', [
                'referrer_id' => $level2Referrer['referred_by'],
                'referred_id' => $newUserId,
                'level'       => 2,
            ]);
            
            // Level 3: Who referred the level 2 referrer
            $level3Referrer = Database::fetchOne(
                "SELECT referred_by FROM users WHERE id = ? AND referred_by IS NOT NULL",
                [$level2Referrer['referred_by']]
            );
            
            if ($level3Referrer && $level3Referrer['referred_by'] != $newUserId) {
                Database::insert('referrals', [
                    'referrer_id' => $level3Referrer['referred_by'],
                    'referred_id' => $newUserId,
                    'level'       => 3,
                ]);
            }
        }
    }
    
    /**
     * Login user
     */
    public static function login(string $identifier, string $password): array {
        // Normalize phone: 0622... -> 255622..., +255622... -> 255622...
        $normalized = preg_replace('/^(\+?255|0)(\d{9})$/', '255$2', trim($identifier));
        
        // Find user by phone or email
        $user = Database::fetchOne(
            "SELECT * FROM users WHERE phone = ? OR phone = ? OR email = ?",
            [$normalized, $identifier, $identifier]
        );
        
        if (!$user) {
            return ['success' => false, 'errors' => ['User not found']];
        }
        
        if (!password_verify($password, $user['password'])) {
            return ['success' => false, 'errors' => ['Incorrect password']];
        }
        
        if ($user['status'] === 'suspended') {
            return ['success' => false, 'errors' => ['Your account has been suspended']];
        }
        
        // Set session data
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_name'] = $user['full_name'];
        $_SESSION['user_status'] = $user['status'];
        $_SESSION['last_regeneration'] = time();
        $_SESSION['login_ip'] = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        
        // Update last login
        Database::update('users', [
            'last_login' => date('Y-m-d H:i:s'),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'is_online'  => 1,
        ], 'id = ?', [$user['id']]);
        
        self::logActivity($user['id'], 'login', 'Logged in from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        
        return [
            'success' => true,
            'user'    => $user,
        ];
    }
    
    /**
     * Logout user
     */
    public static function logout(): void {
        if (isset($_SESSION['user_id'])) {
            Database::update('users', [
                'is_online' => 0,
            ], 'id = ?', [$_SESSION['user_id']]);
            
            self::logActivity($_SESSION['user_id'], 'logout', 'User logged out');
        }
        
        $_SESSION = [];
        
        // Destroy session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params["path"],
                'domain'   => $params["domain"],
                'secure'   => true,
                'httponly'  => true,
                'samesite' => 'Lax',
            ]);
        }
        
        session_destroy();
        
        // Clear any leftover session data
        if (isset($_COOKIE[session_name()])) {
            unset($_COOKIE[session_name()]);
        }
    }
    
    /**
     * Check if user is logged in
     */
    public static function isLoggedIn(): bool {
        return isset($_SESSION['user_id']);
    }
    
    /**
     * Check if current user is admin
     */
    public static function isAdmin(): bool {
        return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
    }
    
    /**
     * Require authentication
     */
    public static function requireAuth(): void {
        self::initSession();
        if (!self::isLoggedIn()) {
            header('Location: ' . SITE_URL . '/login');
            exit;
        }
    }
    
    /**
     * Require admin role
     */
    public static function requireAdmin(): void {
        self::requireAuth();
        if (!self::isAdmin()) {
            header('Location: ' . SITE_URL . '/dashboard');
            exit;
        }
    }
    
    /**
     * Get current user data
     */
    public static function getUser(?int $userId = null): ?array {
        $id = $userId ?? $_SESSION['user_id'] ?? null;
        if (!$id) return null;
        
        return Database::fetchOne(
            "SELECT id, full_name, phone, email, referral_code, referred_by, avatar, status, role, created_at 
             FROM users WHERE id = ?",
            [$id]
        );
    }
    
    /**
     * Generate unique referral code from name
     */
    private static function generateReferralCode(string $name): string {
        $clean = preg_replace('/[^A-Za-z]/', '', strtoupper($name));
        $prefix = substr($clean, 0, 4);
        if (strlen($prefix) < 4) {
            $prefix = str_pad($prefix, 4, 'X');
        }
        
        do {
            $suffix = strtoupper(substr(uniqid(), -4));
            $code = $prefix . $suffix;
            $exists = Database::fetchOne("SELECT id FROM users WHERE referral_code = ?", [$code]);
        } while ($exists);
        
        return $code;
    }
    
    /**
     * Log user activity
     */
    public static function logActivity(int $userId, string $action, string $description = ''): void {
        try {
            Database::insert('activity_logs', [
                'user_id'     => $userId,
                'action'      => $action,
                'description' => $description,
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (Exception $e) {
            error_log("Activity log error: " . $e->getMessage());
        }
    }
    
    /**
     * Get user by referral code
     */
    public static function getUserByReferralCode(string $code): ?array {
        return Database::fetchOne(
            "SELECT id, full_name, referral_code FROM users WHERE referral_code = ? AND status != 'suspended'",
            [$code]
        );
    }
}
