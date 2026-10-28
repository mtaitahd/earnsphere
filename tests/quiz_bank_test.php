<?php
require __DIR__.'/quizzes_fixture.php';
require dirname(__DIR__).'/classes/QuizQuestionBank.php';
function bankAssert($ok){if(!$ok)throw new RuntimeException('Question bank assertion failed.');}
$bank=QuizQuestionBank::catalog(3);bankAssert(count($bank)===20);
$ids=[];$counts=['sw'=>0,'en'=>0];
foreach($bank as $batch){bankAssert(count($batch['questions'])===5);ComprehensionQuiz::validateQuestions($batch['questions']);foreach($batch['questions'] as $q){bankAssert(!isset($ids[$q['id']]));$ids[$q['id']]=true;$counts[$batch['language']]++;}}
bankAssert($counts===['sw'=>50,'en'=>50]);$before=Database::count('comprehension_quizzes');$keys=array_keys($ids);
$draft=QuizQuestionBank::draft(3,[$keys[0],$keys[99]]);bankAssert(count($draft['questions'])===2 && !isset($draft['budget_confirmed']));bankAssert(Database::count('comprehension_quizzes')===$before);
foreach([[],['unknown'],[$keys[0],$keys[0]],array_slice($keys,0,11),[[]]] as $bad){try{QuizQuestionBank::draft(3,$bad);throw new RuntimeException('Invalid selection accepted.');}catch(InvalidArgumentException $e){}}
foreach([200,204] as $actor){try{QuizQuestionBank::draft($actor,[$keys[0]]);throw new RuntimeException('Permission bypass.');}catch(InvalidArgumentException $e){}}
echo "Question bank validation, limits, draft-only selection and permissions passed.\n";
