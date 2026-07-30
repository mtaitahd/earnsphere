<?php
require_once __DIR__ . '/includes/phpqrcode.php';
header('Content-Type: image/png');
$data = $_GET['data'] ?? '';
if ($data === '') {
    $data = SITE_URL;
}
$size = min(20, max(2, (int)($_GET['size'] ?? 8)));
$margin = (int)($_GET['margin'] ?? 2);
QRcode::png($data, false, QR_ECLEVEL_M, $size, $margin);