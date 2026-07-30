<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/Wallet.php';
require_once __DIR__ . '/../classes/DailyMission.php';
require_once __DIR__ . '/../includes/helpers.php';

Auth::initSession();
Auth::requireAuth();

$userId = (int) $_SESSION['user_id'];
$action = $_GET['action'] ?? 'status';

header('Content-Type: application/json; charset=utf-8');

switch ($action) {
    case 'status':
        $status = DailyMission::getMissionStatus($userId);
        jsonResponse($status);
        break;

    case 'claim':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
        }
        $result = DailyMission::checkAndUpdateProgress($userId);
        jsonResponse($result);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);
}
