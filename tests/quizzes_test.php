<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/quizzes_fixture.php';
function quizBlocked(callable $fn,string $label):void{try{$fn();}catch(InvalidArgumentException $e){return;}throw new RuntimeException($label);}
$input=sampleQuiz();
quizBlocked(fn()=>ComprehensionQuiz::create(200,$input),'Normal user published quiz');
quizBlocked(fn()=>ComprehensionQuiz::create(204,$input),'Agent without quiz permission published');
Database::insert('agent_permissions',['user_id'=>204,'permission'=>'quizzes']);StaffAccess::clearCache();
expect(StaffAccess::canForUser(204,'quizzes'),'Quiz checkbox permission not applied');
expect(!StaffAccess::canForUser(204,'social'),'Quiz permission granted promotion management');
$id=ComprehensionQuiz::create(204,$input);
$quiz=Database::fetchOne('SELECT * FROM comprehension_quizzes WHERE id=?',[$id]);
foreach(ComprehensionQuiz::publicQuestions($quiz['questions']) as $question)expect(array_keys($question)===['question','options'],'Answer key leaked into public projection');
$before=Wallet::getWallet(200);
foreach([[1],['1'=>'1','2'=>'2'],[1,9],['1',[]],['1','2','0']] as $invalid)quizBlocked(fn()=>ComprehensionQuiz::submit(200,$id,$invalid),'Invalid answer set accepted');
expect(Database::count('comprehension_attempts')===0 && Wallet::getWallet(200)===$before,'Invalid answers consumed attempt or changed wallet');
$failed=ComprehensionQuiz::submit(201,$id,['0','2']);
expect($failed['status']==='failed' && (int)$failed['score']===1 && (int)$failed['reward']===0,'Partial score incorrectly paid');
$retry=ComprehensionQuiz::submit(201,$id,['1','2']);expect($retry['id']===$failed['id'] && $retry['status']==='failed','Failed user guessed again');
expect((int)Database::fetchOne('SELECT paid_count FROM comprehension_quizzes WHERE id=?',[$id])['paid_count']===0,'Failed attempt consumed paid slot');
quizBlocked(fn()=>ComprehensionQuiz::submit(203,$id,['1','2']),'Suspended user received reward');
$result=ComprehensionQuiz::submit(200,$id,['1','2']);
expect($result['status']==='paid' && (int)$result['score']===2 && (int)$result['reward']===100,'Perfect score was not paid');
$again=ComprehensionQuiz::submit(200,$id,['1','2']);expect($again['id']===$result['id'],'Duplicate submission created attempt');
expect((float)Wallet::getWallet(200)['withdrawable_balance']-(float)$before['withdrawable_balance']===100.0,'Perfect score paid more or less than once');
expect(Database::count('wallet_transactions','reference_type=? AND reference_id=?',['quiz_attempt',$result['id']])===1,'Duplicate quiz ledger');
expect(Database::count('user_notifications','event_key=?',['quiz:'.$result['id']])===1,'Duplicate quiz notification');
expect(Database::fetchOne('SELECT status FROM comprehension_quizzes WHERE id=?',[$id])['status']==='completed','Reward cap did not close quiz');
quizBlocked(fn()=>ComprehensionQuiz::submit(202,$id,['1','2']),'Reward cap exceeded');
quizBlocked(fn()=>ComprehensionQuiz::toggle(3,$id),'Completed quiz reopened');
$id2=ComprehensionQuiz::create(3,$input);ComprehensionQuiz::toggle(3,$id2);
quizBlocked(fn()=>ComprehensionQuiz::submit(202,$id2,['1','2']),'Paused quiz paid reward');
ComprehensionQuiz::toggle(3,$id2);ComprehensionQuiz::submit(202,$id2,['1','2']);
$invalid=$input;$invalid['questions'][0]['options'][1]='Three';quizBlocked(fn()=>ComprehensionQuiz::create(3,$invalid),'Duplicate options accepted');
$invalid=$input;$invalid['questions'][0]['correct']='4';quizBlocked(fn()=>ComprehensionQuiz::create(3,$invalid),'Invalid answer key accepted');
$invalid=$input;$invalid['questions']=[];quizBlocked(fn()=>ComprehensionQuiz::create(3,$invalid),'Empty quiz accepted');
$invalid=$input;$invalid['questions']=array_fill(0,11,$input['questions'][0]);quizBlocked(fn()=>ComprehensionQuiz::create(3,$invalid),'Oversize quiz accepted');
$invalid=$input;$invalid['budget_confirmed']='';quizBlocked(fn()=>ComprehensionQuiz::create(3,$invalid),'Unconfirmed automatic reward budget published');
// Reward edits affect future attempts only and require a paused quiz and funded budget.
$editInput=sampleQuiz();$editInput['slots']='10';$editId=ComprehensionQuiz::create(3,$editInput);
$oldAttempt=ComprehensionQuiz::submit(200,$editId,['1','2']);
quizBlocked(fn()=>ComprehensionQuiz::updateReward(3,$editId,['reward'=>'250','budget_confirmed'=>'1']),'Active reward changed');
ComprehensionQuiz::toggle(3,$editId);
quizBlocked(fn()=>ComprehensionQuiz::updateReward(200,$editId,['reward'=>'250','budget_confirmed'=>'1']),'Member changed reward');
quizBlocked(fn()=>ComprehensionQuiz::updateReward(3,$editId,['reward'=>'250']),'Unconfirmed budget changed');
quizBlocked(fn()=>ComprehensionQuiz::updateReward(3,$editId,['reward'=>'0','budget_confirmed'=>'1']),'Invalid reward accepted');
ComprehensionQuiz::updateReward(3,$editId,['reward'=>'250','budget_confirmed'=>'1']);
expect((int)Database::fetchOne('SELECT reward FROM comprehension_attempts WHERE id=?',[$oldAttempt['id']])['reward']===100,'Historical reward rewritten');
expect(Database::fetchOne('SELECT status FROM comprehension_quizzes WHERE id=?',[$editId])['status']==='paused','Update unexpectedly resumed quiz');
ComprehensionQuiz::toggle(3,$editId);$newAttempt=ComprehensionQuiz::submit(201,$editId,['1','2']);
expect((int)$newAttempt['reward']===250,'New attempt did not receive updated reward');
// A failed ledger write must roll back the attempt, slot count and notification together.
$id3=ComprehensionQuiz::create(3,$input);
$db->exec("CREATE TRIGGER fail_quiz_ledger BEFORE INSERT ON wallet_transactions WHEN NEW.reference_type='quiz_attempt' BEGIN SELECT RAISE(ABORT,'Simulated ledger failure'); END");
$before=Wallet::getWallet(200);
try{ComprehensionQuiz::submit(200,$id3,['1','2']);throw new RuntimeException('Expected ledger failure');}catch(PDOException $e){}
expect(Wallet::getWallet(200)===$before,'Failed ledger write changed wallet');
expect(Database::count('comprehension_attempts','quiz_id=?',[$id3])===0,'Failed ledger write kept paid attempt');
expect((int)Database::fetchOne('SELECT paid_count FROM comprehension_quizzes WHERE id=?',[$id3])['paid_count']===0,'Failed ledger write consumed reward slot');
echo "Quizzes: private answers, all-correct scoring, exactly-once withdrawable rewards, attempt/slot caps, rollback and publisher roles passed. No external payments sent.\n";
