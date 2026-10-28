<?php
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once dirname(__DIR__).'/config/database.php';
Database::query(file_get_contents(__DIR__.'/snippe_admin_transfers.sql'));
echo "Snippe company withdrawals ready. No transfers sent and no user balances changed.\n";
