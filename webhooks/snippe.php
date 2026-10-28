<?php
require_once dirname(__DIR__).'/classes/SnippePayment.php';
require_once dirname(__DIR__).'/classes/SnippePayout.php';
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);exit;}
$headers=array_change_key_case(function_exists('getallheaders')?getallheaders():['X-Webhook-Timestamp'=>$_SERVER['HTTP_X_WEBHOOK_TIMESTAMP']??'','X-Webhook-Signature'=>$_SERVER['HTTP_X_WEBHOOK_SIGNATURE']??''],CASE_LOWER);
if (!is_string($headers['x-webhook-signature']??null) || !preg_match('/^[a-f0-9]{64}$/D',$headers['x-webhook-signature'])
    || !is_string($headers['x-webhook-timestamp']??null) || !ctype_digit($headers['x-webhook-timestamp']) || abs(time()-(int)$headers['x-webhook-timestamp'])>300) {http_response_code(401);exit('{"error":"Invalid signature"}');}
$raw=file_get_contents('php://input');$client=new SnippeClient();
if (!$client->verifyWebhookSignature($headers,$raw)){http_response_code(401);exit('{"error":"Invalid signature"}');}
try {
    $body=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if (!is_array($body))throw new InvalidArgumentException('Invalid body.');
    $service=str_starts_with((string)($body['type']??''),'payout.')?new SnippePayout():new SnippePayment($client);$result=$service->processWebhook($body);
    echo json_encode(['success'=>true]+$result);
    if(function_exists('fastcgi_finish_request'))fastcgi_finish_request();
    elseif(function_exists('litespeed_finish_request'))litespeed_finish_request();
    try{PaymentNotifications::dispatch(1);}catch(Throwable $e){error_log('Snippe SMS dispatch deferred.');}
}catch(JsonException | InvalidArgumentException $e){http_response_code(400);echo '{"error":"Invalid event"}';}
catch(Throwable $e){error_log('Snippe webhook reconciliation failed: '.$e->getMessage());http_response_code(503);echo '{"error":"Verification required"}';}
