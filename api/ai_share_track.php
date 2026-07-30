<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/AIAssistant.php';
require_once __DIR__ . '/../includes/helpers.php';

Auth::initSession();
Auth::requireAuth();

$userId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
}

$contentType = trim($_POST['content_type'] ?? '');
$platform = trim($_POST['platform'] ?? '');

if (empty($contentType) || empty($platform)) {
    jsonResponse(['success' => false, 'error' => 'Content type and platform are required'], 400);
}

$trackId = AIAssistant::trackShare($userId, $contentType, $platform);

jsonResponse([
    'success' => true,
    'track_id' => $trackId,
    'message' => 'Share tracked successfully',
]);
