<?php
/**
 * EarnSphere - Helper Functions
 * Common utility functions used throughout the application
 */

/**
 * Sanitize input
 */
function sanitize(mixed $input): mixed {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Format currency
 */
function formatCurrency(float $amount): string {
    return 'TZS ' . number_format($amount, 0, '.', ',');
}

/**
 * Get relative time
 */
function timeAgo(string $datetime): string {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    
    if ($diff->y > 0) return $diff->y . ' yr' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' mo' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hr' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' min' . ($diff->i > 1 ? 's' : '') . ' ago';
    return 'Just now';
}

/**
 * Format phone number for display
 */
function formatPhone(string $phone): string {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) === 12 && substr($phone, 0, 3) === '255') {
        return '+255 ' . substr($phone, 3, 3) . ' ' . substr($phone, 6, 3) . ' ' . substr($phone, 9);
    }
    return $phone;
}

/**
 * Get status badge HTML
 */
function statusBadge(string $status): string {
    $classes = match($status) {
        'active', 'completed', 'approved' => 'bg-success',
        'pending', 'processing'           => 'bg-warning text-dark',
        'failed', 'rejected', 'suspended' => 'bg-danger',
        'cancelled'                       => 'bg-secondary',
        default                           => 'bg-info',
    };
    
    $labels = [
        'active'     => 'Active',
        'pending'    => 'Pending',
        'completed'  => 'Completed',
        'failed'     => 'Failed',
        'approved'   => 'Approved',
        'rejected'   => 'Rejected',
        'processing' => 'Processing',
        'suspended'  => 'Suspended',
        'cancelled'  => 'Cancelled',
    ];
    
    $label = $labels[$status] ?? ucfirst($status);
    return "<span class=\"badge {$classes}\">{$label}</span>";
}

/**
 * Generate QR code URL (using external API)
 */
function generateQRCodeUrl(string $data, int $size = 200): string {
    $encoded = urlencode($data);
    return "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data={$encoded}&bgcolor=FFFFFF&color=72578B&margin=10";
}

/**
 * Get referral link
 */
function getReferralLink(string $referralCode): string {
    return SITE_URL . "/register.php?ref=" . urlencode($referralCode);
}

/**
 * Set flash message
 */
function setFlash(string $type, string $message): void {
    $_SESSION['flash'][$type] = $message;
}

/**
 * Get and clear flash message
 */
function getFlash(string $type): ?string {
    if (isset($_SESSION['flash'][$type])) {
        $message = $_SESSION['flash'][$type];
        unset($_SESSION['flash'][$type]);
        return $message;
    }
    return null;
}

/**
 * Display flash messages as HTML
 */
function displayFlash(): void {
    foreach (['success', 'error', 'warning', 'info'] as $type) {
        $message = getFlash($type);
        if ($message) {
            $icon = match($type) {
                'success' => 'check-circle',
                'error'   => 'exclamation-circle',
                'warning' => 'exclamation-triangle',
                'info'    => 'info-circle',
                default   => 'info-circle',
            };
            $alertClass = match($type) {
                'success' => 'alert-success',
                'error'   => 'alert-danger',
                'warning' => 'alert-warning',
                'info'    => 'alert-info',
                default   => 'alert-info',
            };
            echo "<div class=\"alert {$alertClass} alert-dismissible fade show\" role=\"alert\">";
            echo "<i class=\"fas fa-{$icon} me-2\"></i>" . sanitize($message);
            echo "<button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"alert\"></button>";
            echo "</div>";
        }
    }
}

/**
 * Paginate results
 */
function paginate(int $total, int $currentPage, int $perPage, string $baseUrl): string {
    $totalPages = ceil($total / $perPage);
    if ($totalPages <= 1) return '';
    
    $html = '<nav aria-label="Page navigation"><ul class="pagination justify-content-center">';
    
    // Previous
    if ($currentPage > 1) {
        $html .= "<li class=\"page-item\"><a class=\"page-link\" href=\"{$baseUrl}page=" . ($currentPage - 1) . "\">&laquo;</a></li>";
    }
    
    // Page numbers
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);
    
    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $currentPage ? ' active' : '';
        $html .= "<li class=\"page-item{$active}\"><a class=\"page-link\" href=\"{$baseUrl}page={$i}\">{$i}</a></li>";
    }
    
    // Next
    if ($currentPage < $totalPages) {
        $html .= "<li class=\"page-item\"><a class=\"page-link\" href=\"{$baseUrl}page=" . ($currentPage + 1) . "\">&raquo;</a></li>";
    }
    
    $html .= '</ul></nav>';
    return $html;
}

/**
 * Get current page number
 */
function getCurrentPage(): int {
    return max(1, (int)($_GET['page'] ?? 1));
}

/**
 * Redirect with message
 */
function redirect(string $url, string $flashType = '', string $flashMessage = ''): void {
    if ($flashType && $flashMessage) {
        setFlash($flashType, $flashMessage);
    }
    header("Location: {$url}");
    exit;
}

/**
 * Check if request is AJAX
 */
function isAjax(): bool {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * JSON response
 */
function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Get setting value from database
 */
function getSetting(string $key, ?string $default = null): ?string {
    try {
        $result = Database::fetchOne("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
        return $result ? $result['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Get user avatar URL
 */
function getAvatar(?string $avatar, string $gender = ''): string {
    if ($avatar && file_exists(AVATAR_DIR . '/' . $avatar)) {
        return SITE_URL . '/uploads/avatars/' . $avatar;
    }
    return SITE_URL . '/assets/img/default-avatar.png';
}

/**
 * Truncate text
 */
function truncate(string $text, int $length = 50): string {
    if (strlen($text) <= $length) return $text;
    return substr($text, 0, $length) . '...';
}

/**
 * Send notification email to admin
 */
const ADMIN_NOTIFY_EMAIL = 'mtaitahd@gmail.com';

function notifyAdmin(string $subject, string $message): void {
    require_once __DIR__ . '/../classes/Mailer.php';
    $mailer = new Mailer();
    
    $html  = '<div style="font-family:sans-serif;max-width:600px;margin:auto;padding:20px;">';
    $html .= '<div style="background:#72578B;color:white;padding:15px;border-radius:12px 12px 0 0;text-align:center;">';
    $html .= '<h2 style="margin:0;">EarnSphere</h2></div>';
    $html .= '<div style="background:#f9fafb;padding:20px;border:1px solid #e5e7eb;border-radius:0 0 12px 12px;">';
    $html .= '<p>' . nl2br($message) . '</p>';
    $html .= '<hr style="border:none;border-top:1px solid #e5e7eb;">';
    $html .= '<p style="color:#6b7280;font-size:0.8rem;">This is an automated notification from EarnSphere</p>';
    $html .= '</div></div>';
    
    $mailer->send(ADMIN_NOTIFY_EMAIL, $subject, $html);
}
