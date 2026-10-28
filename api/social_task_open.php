<?php
require_once dirname(__DIR__).'/classes/SocialHub.php';
Auth::initSession();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
function socialOpenResponse(array $data,int $status): void {
    http_response_code($status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
    header('Allow: POST');socialOpenResponse(['success'=>false,'message'=>'Use POST to open a task.'],405);
}
try {
    $user=Auth::getUser();
    if (!$user) socialOpenResponse(['success'=>false,'message'=>'Your session has expired. Sign in again and retry.'],401);
    if ($user['role']!=='user' || $user['status']!=='active') socialOpenResponse(['success'=>false,'message'=>'An active member account is required.'],403);
    if (!Auth::validateCSRF()) socialOpenResponse(['success'=>false,'message'=>'Your security token has expired. Refresh this page and try again.'],403);
    $id=SocialHub::number($_POST['campaign_id']??'',1,PHP_INT_MAX,'Task');
    $url=SocialHub::openTask((int)$user['id'],$id);
    socialOpenResponse(['success'=>true,'url'=>$url],200);
} catch (InvalidArgumentException $e) {
    socialOpenResponse(['success'=>false,'message'=>$e->getMessage()],409);
} catch (Throwable $e) {
    error_log('Social task open API: '.$e->getMessage());
    socialOpenResponse(['success'=>false,'message'=>'Could not open this task. Please contact support if retrying does not help.'],503);
}
