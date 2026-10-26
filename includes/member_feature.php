<?php
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/SocialHub.php';
Auth::initSession(); Auth::requireAuth();
$user=Auth::getUser();
if(!$user || $user['role']!=='user' || $user['status']!=='active'){ header('Location: '.SITE_URL.'/dashboard'); exit; }
header('Cache-Control: no-store, private');
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST' && !Auth::validateCSRF()){http_response_code(403);exit('Invalid security token.');}
require_once __DIR__ . '/service_view.php';
