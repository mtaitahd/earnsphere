<?php
/**
 * EarnSphere - Logout
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';

Auth::initSession();
Auth::logout();

header('Location: ' . SITE_URL . '/login');
exit;
