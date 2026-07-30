<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/AIAssistant.php';
require_once __DIR__ . '/../includes/helpers.php';

Auth::initSession();
Auth::requireAuth();

$userId = (int) $_SESSION['user_id'];
$action = $_GET['action'] ?? 'generate';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $action !== 'history' && $action !== 'stats') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

switch ($action) {
    case 'generate':
        $contentType = trim($_POST['content_type'] ?? '');
        $tone = trim($_POST['tone'] ?? 'professional');
        $language = trim($_POST['language'] ?? 'english');
        $customPrompt = trim($_POST['custom_prompt'] ?? '');

        if (empty($contentType)) {
            jsonResponse(['success' => false, 'error' => 'Content type is required'], 400);
        }

        $result = AIAssistant::generateContent($userId, $contentType, $tone, $language, $customPrompt);
        jsonResponse($result);
        break;

    case 'history':
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $history = AIAssistant::getHistory($userId, $page);
        jsonResponse(['success' => true, 'data' => $history]);
        break;

    case 'stats':
        $stats = AIAssistant::getShareStats($userId);
        jsonResponse(['success' => true, 'data' => $stats]);
        break;

    default:
        jsonResponse(['success' => false, 'error' => 'Unknown action'], 400);
}
