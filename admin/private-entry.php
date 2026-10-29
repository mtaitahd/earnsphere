<?php
require_once dirname(__DIR__).'/classes/Auth.php';
require_once dirname(__DIR__).'/classes/AdminPIN.php';
$entryPath=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
$basePath=rtrim((string)parse_url(SITE_URL,PHP_URL_PATH),'/');
if (!is_string($entryPath) || !preg_match('~^'.preg_quote($basePath,'~').'/staff-entry/([a-f0-9]{64})/?$~D',$entryPath,$matches)) {http_response_code(404);exit;}
$privateEntry=AdminPIN::resolve($matches[1]);
if (!$privateEntry) {http_response_code(404);exit;}
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noarchive');
require __DIR__.'/login.php';
