<?php
/**
 * EarnSphere - Support API
 * AJAX endpoints for the help/support ticket system.
 *   action=create     (POST)  submit a help request (guest or logged-in)
 *   action=fetch      (GET)   fetch current user's tickets + unread count
 *   action=mark_read  (POST)  clear notification for a ticket's admin reply
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

Auth::initSession();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

header('Content-Type: application/json; charset=utf-8');

switch ($action) {

    // ------------------------------------------------------------
    // CREATE - submit a help request
    // ------------------------------------------------------------
    case 'create':
        // CSRF check (uses meta csrf-token on public pages)
        if (!Auth::validateCSRF($_POST[CSRF_TOKEN_NAME] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            jsonResponse(['success' => false, 'error' => 'Security: Invalid session token. Please refresh and try again.'], 403);
        }

        $loggedIn  = Auth::isLoggedIn();
        $user      = $loggedIn ? Auth::getUser() : null;

        $name    = trim($_POST['name'] ?? '');
        $phone   = trim($_POST['phone'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if ($loggedIn && $user) {
            $name  = $user['full_name'];
            $phone = $phone ?: $user['phone'];
            $email = $email ?: $user['email'];
        }

        if ($name === '') {
            jsonResponse(['success' => false, 'error' => 'Please enter your name'], 422);
        }
        if ($message === '' || mb_strlen($message) < 5) {
            jsonResponse(['success' => false, 'error' => 'Please describe your problem (at least 5 characters)'], 422);
        }

        $subject = $subject !== '' ? $subject : 'Help Request';
        $normalizedPhone = preg_replace('/[^0-9]/', '', $phone);
        if ($normalizedPhone !== '' && str_starts_with($normalizedPhone, '0') && strlen($normalizedPhone) === 10) {
            $normalizedPhone = '255' . substr($normalizedPhone, 1);
        }

        // Try to link to an existing account so the user gets the reply notification
        $linkedUserId = $loggedIn ? (int) $_SESSION['user_id'] : null;
        if (!$linkedUserId && $normalizedPhone !== '') {
            $existing = Database::fetchOne("SELECT id FROM users WHERE phone = ? LIMIT 1", [$normalizedPhone]);
            if ($existing) {
                $linkedUserId = (int) $existing['id'];
            }
        }

        $ticketId = Database::insert('support_tickets', [
            'user_id' => $linkedUserId,
            'name'    => mb_substr($name, 0, 150),
            'phone'   => $normalizedPhone !== '' ? $normalizedPhone : null,
            'email'   => $email !== '' ? mb_substr($email, 0, 150) : null,
            'subject' => mb_substr($subject, 0, 200),
            'message' => $message,
            'status'  => 'open',
            'user_read' => 1,
        ]);

        if ($linkedUserId) {
            Auth::logActivity($linkedUserId, 'support_ticket_created', "Help request sent: {$subject}");
        }

        // Notify admin by email
        $adminMsg = "New Help Request\n\nName: {$name}\nPhone: " . ($normalizedPhone ?: 'N/A') . "\nEmail: " . ($email ?: 'N/A') . "\nSubject: {$subject}\n\nMessage:\n{$message}";
        @notifyAdmin("New Help Request - {$subject}", $adminMsg);

        jsonResponse([
            'success'   => true,
            'message'   => 'Your message has been sent. We will reply soon!',
            'ticket_id' => $ticketId,
        ]);
        break;

    // ------------------------------------------------------------
    // FETCH - list current user's tickets + unread reply count
    // ------------------------------------------------------------
    case 'fetch':
        if (!Auth::isLoggedIn()) {
            jsonResponse(['success' => false, 'error' => 'Login required'], 401);
        }

        $userId = (int) $_SESSION['user_id'];
        $user   = Auth::getUser();
        $phone  = preg_replace('/[^0-9]/', '', $user['phone'] ?? '');

        if ($phone !== '' && str_starts_with($phone, '0') && strlen($phone) === 10) {
            $phone = '255' . substr($phone, 1);
        }

        if ($phone !== '') {
            $tickets = Database::fetchAll(
                "SELECT * FROM support_tickets
                 WHERE user_id = ? OR phone = ?
                 ORDER BY created_at DESC",
                [$userId, $phone]
            );
        } else {
            $tickets = Database::fetchAll(
                "SELECT * FROM support_tickets WHERE user_id = ? ORDER BY created_at DESC",
                [$userId]
            );
        }

        // Expose the reply once it exists; count unread notifications
        $unread = 0;
        foreach ($tickets as &$t) {
            $t['has_reply'] = !empty($t['admin_reply']);
            $t['unread']    = !empty($t['admin_reply']) && (int) $t['user_read'] === 0;
            if ($t['unread']) {
                $unread++;
            }
        }
        unset($t);

        jsonResponse(['success' => true, 'data' => $tickets, 'unread' => $unread]);
        break;

    // ------------------------------------------------------------
    // MARK_READ - clear the notification for a ticket reply
    // ------------------------------------------------------------
    case 'mark_read':
        if (!Auth::isLoggedIn()) {
            jsonResponse(['success' => false, 'error' => 'Login required'], 401);
        }
        if (!Auth::validateCSRF($_POST[CSRF_TOKEN_NAME] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
            jsonResponse(['success' => false, 'error' => 'Security: Invalid session token'], 403);
        }

        $ticketId = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
        $userId   = (int) $_SESSION['user_id'];
        $user     = Auth::getUser();
        $phone    = preg_replace('/[^0-9]/', '', $user['phone'] ?? '');
        if ($phone !== '' && str_starts_with($phone, '0') && strlen($phone) === 10) {
            $phone = '255' . substr($phone, 1);
        }

        if ($ticketId <= 0) {
            jsonResponse(['success' => false, 'error' => 'Invalid ticket'], 422);
        }

        $updated = 0;
        if ($phone !== '') {
            $updated = Database::update(
                'support_tickets',
                ['user_read' => 1],
                'id = ? AND (user_id = ? OR phone = ?)',
                [$ticketId, $userId, $phone]
            );
        } else {
            $updated = Database::update(
                'support_tickets',
                ['user_read' => 1],
                'id = ? AND user_id = ?',
                [$ticketId, $userId]
            );
        }

        jsonResponse([
            'success' => true,
            'updated' => $updated > 0,
            'message' => 'Notification cleared',
        ]);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Unknown action'], 404);
}
