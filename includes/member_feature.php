<?php
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/../classes/SocialHub.php';
Auth::initSession();
$featureJsonRequest=($_SERVER['REQUEST_METHOD']??'GET')==='POST' && str_contains(strtolower($_SERVER['HTTP_ACCEPT']??''),'application/json');
if ($featureJsonRequest) {
    $requestUser=Auth::getUser();
    if (!$requestUser) jsonResponse(['success'=>false,'message'=>'Your session has expired. Sign in again, then reopen this task.'],401);
    if ($requestUser['role']!=='user' || $requestUser['status']!=='active') jsonResponse(['success'=>false,'message'=>'An active member account is required.'],403);
}
Auth::requireAuth();
$user=Auth::getUser();
if(!$user || $user['role']!=='user' || $user['status']!=='active'){ header('Location: '.SITE_URL.'/dashboard'); exit; }
header('Cache-Control: no-store, private');
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST' && !$_POST && (int)($_SERVER['CONTENT_LENGTH']??0)>0){if($featureJsonRequest)jsonResponse(['success'=>false,'message'=>'The submitted form exceeded the server limit. Refresh and try again.'],413);http_response_code(413);exit('The submitted form exceeded the server upload limit. Go back and choose smaller files.');}
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST' && !Auth::validateCSRF()){if($featureJsonRequest)jsonResponse(['success'=>false,'message'=>'Your security token has expired. Refresh this page and try again.'],403);http_response_code(403);exit('Invalid security token.');}
require_once __DIR__ . '/service_view.php';
