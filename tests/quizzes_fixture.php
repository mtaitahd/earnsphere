<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/social_loans_fixture.php';require __DIR__.'/../classes/ComprehensionQuiz.php';
foreach(explode(';',file_get_contents(__DIR__.'/../database/quizzes.sql')) as $statement){
    if(!trim($statement))continue;
    $statement=str_replace('INT UNSIGNED AUTO_INCREMENT PRIMARY KEY','INTEGER PRIMARY KEY AUTOINCREMENT',$statement);
    $statement=preg_replace('/UNIQUE KEY [a-z_]+ \(/i','UNIQUE (',$statement);
    $statement=preg_replace('/, KEY [a-z_]+ \([^)]*\)/i','',$statement);
    $statement=preg_replace('/\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4/',' )',$statement);
    $db->exec($statement);
}
foreach([200,201,202] as $paidUser) Database::insert('payments',['user_id'=>$paidUser,'order_id'=>'QUIZ-PAID-'.$paidUser,'purpose'=>'registration','amount'=>16000,'status'=>'completed']);
function sampleQuiz(): array {return ['title'=>'Understand the lesson','instructions'=>'Read carefully and answer both questions.','platform'=>'none','reward'=>'100','slots'=>'1','budget_confirmed'=>'1','questions'=>[
    ['question'=>'What is two plus two?','options'=>['Three','Four','Five','Six'],'correct'=>'1'],
    ['question'=>'What is the capital of Tanzania?','options'=>['Arusha','Dar es Salaam','Dodoma','Mwanza'],'correct'=>'2']
]];}
