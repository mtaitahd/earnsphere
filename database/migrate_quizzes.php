<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/migrate_social_loans.php';
foreach (explode(';', file_get_contents(__DIR__ . '/quizzes.sql')) as $statement) if (trim($statement)) Database::query($statement);
echo "Comprehension quizzes ready. No rewards paid or balances changed.\n";
