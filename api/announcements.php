<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../includes/helpers.php';

Auth::initSession();
Auth::requireAuth();

$userId = (int) $_SESSION['user_id'];
$action = $_GET['action'] ?? 'fetch';

header('Content-Type: application/json; charset=utf-8');

switch ($action) {
    case 'fetch':
        $announcements = Database::fetchAll(
            "SELECT a.id, a.title, a.content, a.created_at,
                    (SELECT COUNT(*) FROM user_announcement_views WHERE user_id = ? AND announcement_id = a.id) as viewed
             FROM announcements a
             WHERE a.is_active = 1
             ORDER BY a.created_at DESC",
            [$userId]
        );
        foreach ($announcements as &$a) {
            $a['viewed'] = (int) $a['viewed'] > 0;
        }
        unset($a);
        jsonResponse(['success' => true, 'data' => $announcements]);
        break;

    case 'mark_read':
        $annId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($annId) {
            $existing = Database::fetchOne(
                "SELECT id FROM user_announcement_views WHERE user_id = ? AND announcement_id = ?",
                [$userId, $annId]
            );
            if (!$existing) {
                Database::insert('user_announcement_views', [
                    'user_id'         => $userId,
                    'announcement_id' => $annId,
                ]);
            }
            jsonResponse(['success' => true]);
        } else {
            jsonResponse(['success' => false, 'error' => 'Invalid ID']);
        }
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Unknown action']);
}
