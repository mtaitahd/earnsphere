<?php
require_once __DIR__ . '/../includes/payment_api.php';
paymentApiUser(true, 'wallet');
$result = (new AblinerClient())->balance();
paymentApiReply($result, $result['success'] ? 200 : 503);
