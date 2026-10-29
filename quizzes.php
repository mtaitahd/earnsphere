<?php
require_once __DIR__.'/includes/member_feature.php';require_once __DIR__.'/classes/ComprehensionQuiz.php';
if (!ComprehensionQuiz::hasPaidRegistration((int)$user['id'])) {
    setFlash('error','Kamilisha malipo ya usajili kabla ya kufanya quiz.');
    header('Location: '.SITE_URL.'/dashboard');exit;
}
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try {
        if (($_POST['confirmed']??'')!=='1') throw new InvalidArgumentException('Confirm that you are ready to submit your single attempt.');
        $result=ComprehensionQuiz::submit((int)$user['id'],(int)($_POST['quiz_id']??0),$_POST['answers']??null);
        setFlash($result['status']==='paid'?'success':'error',$result['status']==='paid'?'Hongera! Majibu yote ni sahihi. '.formatCurrency($result['reward']).' imeongezwa kwenye wallet yako.':'Umepata '.$result['score'].' kati ya '.$result['question_count'].'. Unahitaji majibu yote sahihi; hakuna malipo.');
    }catch(InvalidArgumentException $e){setFlash('error',$e->getMessage());}
    catch(Throwable $e){error_log('Quiz submission: '.$e->getMessage());setFlash('error','Your result could not be recorded. Refresh and check your history before trying again.');}
    header('Location: '.SITE_URL.'/quizzes');exit;
}
$page=max(1,min(100000,(int)($_GET['page']??1)));$offset=($page-1)*25;
$historyPage=max(1,min(100000,(int)($_GET['history_page']??1)));$historyOffset=($historyPage-1)*25;
$quizzes=[];$history=[];$available=true;
try {
    [$dayStart,$dayEnd]=DailyContent::bounds();
    $quizzes=Database::fetchAll("SELECT q.id,q.title,q.instructions,q.platform,q.source_url,q.questions,q.reward,q.slots,q.paid_count FROM comprehension_quizzes q WHERE q.status='active' AND q.paid_count<q.slots AND q.created_at>=? AND q.created_at<? AND NOT EXISTS (SELECT 1 FROM comprehension_attempts a WHERE a.quiz_id=q.id AND a.user_id=?) ORDER BY q.id DESC LIMIT 26 OFFSET $offset",[$dayStart,$dayEnd,$user['id']]);
    $history=Database::fetchAll("SELECT a.*,q.title FROM comprehension_attempts a JOIN comprehension_quizzes q ON q.id=a.quiz_id WHERE a.user_id=? AND q.created_at>=? AND q.created_at<? ORDER BY a.id DESC LIMIT 26 OFFSET $historyOffset",[$user['id'],$dayStart,$dayEnd]);
}catch(Throwable $e){$available=false;error_log('Quizzes unavailable: '.$e->getMessage());}
$more=count($quizzes)>25;$quizzes=array_slice($quizzes,0,25);$historyMore=count($history)>25;$history=array_slice($history,0,25);
$pageTitle='Maswali ya uelewa';include __DIR__.'/includes/public_head.php'; ?>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/member-services.css"><main class="services-page"><h1>Maswali ya uelewa</h1><p>Soma au tazama maelekezo, kisha jibu maswali yote.</p><p class="service-note">Quiz za leo zinaisha saa 00:00 kwa saa za Tanzania.</p>
<?php featureNavigation('quizzes');displayFlash(); ?>
<div class="service-notice">Ukijibu maswali yote sahihi, reward iliyowekwa itaingia wallet moja kwa moja. Una jaribio <strong>moja tu kwa kila quiz</strong>; ukikosea hakuna malipo. Hakikisha majibu yako kabla ya kutuma. Reward slots zinaweza kuisha kabla hujatuma.</div>
<?php if(!$available): ?><p class="service-empty">Quizzes are temporarily unavailable.</p><?php elseif(!$quizzes): ?><p class="service-empty">Hakuna maswali mapya kwa sasa. Rudi baadaye.</p><?php endif; ?>
<?php foreach($quizzes as $quiz): $questions=ComprehensionQuiz::publicQuestions($quiz['questions']); ?>
<article class="service-card"><h2><?= sanitize($quiz['title']) ?></h2><div class="service-meta"><strong style="color:#15803d"><?= formatCurrency($quiz['reward']) ?> for all correct</strong><span><?= count($questions) ?> questions</span><span><?= (int)$quiz['slots']-(int)$quiz['paid_count'] ?> reward slots remaining</span></div><p class="service-note"><?= sanitize($quiz['instructions']) ?></p>
<?php if($quiz['source_url']): ?><a class="btn btn-outline-primary" href="<?= sanitize($quiz['source_url']) ?>" target="_blank" rel="noopener noreferrer">Open lesson / content</a><?php endif; ?>
<form method="POST"><?php featureToken(); ?><input type="hidden" name="quiz_id" value="<?= (int)$quiz['id'] ?>">
<?php foreach($questions as $index=>$question): ?><fieldset style="border:1px solid #e2e8f0;padding:1rem;margin:1rem 0"><legend style="font-size:1rem;font-weight:700"><?= $index+1 ?>. <?= sanitize($question['question']) ?></legend>
<?php foreach($question['options'] as $choice=>$option): ?><label class="quiz-answer"><input type="radio" name="answers[<?= $index ?>]" value="<?= $choice ?>" required> <span><?= chr(65+$choice) ?>. <?= sanitize($option) ?></span></label><?php endforeach; ?></fieldset><?php endforeach; ?>
<label><input type="checkbox" name="confirmed" value="1" required>Nimehakiki majibu. Natuma jaribio langu moja la quiz hii.</label><button class="btn btn-primary" type="submit">Submit answers · Earn <?= formatCurrency($quiz['reward']) ?> if all correct</button></form></article><?php endforeach; ?>
<?php featurePages($page,$more); ?><h2>My quiz results</h2><?php foreach($history as $attempt): ?><article class="service-card"><h3><?= sanitize($attempt['title']) ?></h3><p><?= featureStatus($attempt['status']) ?> · <?= (int)$attempt['score'] ?> / <?= (int)$attempt['question_count'] ?> correct · <?= formatLedgerTime($attempt['created_at']) ?> EAT</p><strong style="color:<?= $attempt['status']==='paid'?'#15803d':'#991b1b' ?>"><?= $attempt['status']==='paid'?formatCurrency($attempt['reward']).' credited to wallet':'No reward — all answers must be correct' ?></strong></article><?php endforeach; ?>
<?php featurePages($historyPage,$historyMore,'history_page'); ?></main><?php include __DIR__.'/includes/public_foot.php'; ?>
