<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config/database.php';
foreach (explode(';', file_get_contents(__DIR__ . '/social_task_opens.sql')) as $statement) if (trim($statement)) Database::query($statement);
echo "Social task opened tracking ready. No rewards or balances changed.\n";
