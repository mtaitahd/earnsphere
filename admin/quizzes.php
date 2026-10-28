<?php
require_once dirname(__DIR__).'/classes/ComprehensionQuiz.php';
require_once dirname(__DIR__).'/classes/QuizQuestionBank.php';
Auth::initSession();StaffAccess::requirePage('quizzes');
require_once dirname(__DIR__).'/includes/service_view.php';
$actor=(int)$_SESSION['user_id'];
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    try {
        if (($_POST['action']??'')==='create') {
            ComprehensionQuiz::create($actor,$_POST);unset($_SESSION['quiz_draft']);setFlash('success','Quiz published. Perfect scores will automatically receive the reward.');
        }elseif (($_POST['action']??'')==='load_bank') {
            $_SESSION['quiz_draft']=QuizQuestionBank::draft($actor,$_POST['question_ids']??[]);
            setFlash('success','Questions loaded into draft only. Review, set reward and participant limit, then Publish quiz when ready.');
        }elseif (($_POST['action']??'')==='update_reward') {
            ComprehensionQuiz::updateReward($actor,(int)($_POST['id']??0),$_POST);
            setFlash('success','Malipo yamebadilishwa. Waliolipwa awali hawajabadilishwa. Bonyeza Resume kurudisha quiz kwa users.');
        }elseif (($_POST['action']??'')==='toggle') {ComprehensionQuiz::toggle($actor,(int)($_POST['id']??0));setFlash('success','Quiz visibility updated.');}
        else throw new InvalidArgumentException('Unknown quiz action.');
    }catch (Throwable $e) {
        if (($_POST['action']??'')==='create' && strlen(json_encode($_POST)?:'')<=50000) $_SESSION['quiz_draft']=$_POST;
        if ($e instanceof InvalidArgumentException) setFlash('error',$e->getMessage());
        else {error_log('Quiz publishing: '.$e->getMessage());setFlash('error','Quiz could not be saved. Check that the quiz migration has been run.');}
    }
    header('Location: '.SITE_URL.'/admin/quizzes');exit;
}
$draft=is_array($_SESSION['quiz_draft']??null)?$_SESSION['quiz_draft']:[];
function quizField(array $data,string $key,string $default=''): string {return sanitize(is_scalar($data[$key]??null)?(string)$data[$key]:$default);}
$draftQuestions=is_array($draft['questions']??null)?array_slice(array_values($draft['questions']),0,10):[[]];
if (!$draftQuestions)$draftQuestions=[[]];
$page=max(1,min(100000,(int)($_GET['page']??1)));$offset=($page-1)*25;$quizzes=[];$available=true;
try{$quizzes=Database::fetchAll("SELECT q.*,u.full_name,(SELECT COUNT(*) FROM comprehension_attempts a WHERE a.quiz_id=q.id) AS attempt_count FROM comprehension_quizzes q LEFT JOIN users u ON u.id=q.created_by ORDER BY q.id DESC LIMIT 26 OFFSET $offset");}
catch(Throwable $e){$available=false;error_log('Quiz list: '.$e->getMessage());}
$more=count($quizzes)>25;$quizzes=array_slice($quizzes,0,25);$pageTitle='Comprehension quizzes';include __DIR__.'/admin_header.php'; ?>
<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/member-services.css">
<h1>Comprehension quizzes</h1><?php displayFlash(); ?>
<p class="service-notice">Users answer every question once. All answers must be correct to automatically receive the configured reward in their wallet. Correct answers stay private. Published questions cannot be edited; pause the quiz and publish a new one if a correction is needed.</p>
<?php if(!$available): ?><div class="alert alert-warning">Run <code>php database/migrate_quizzes.php</code> before publishing quizzes.</div><?php else: ?>
<details class="service-card"><summary>Question bank · Benki ya maswali (100)</summary>
<p>Chagua maswali 1–10, kisha Load selected questions. Haya ni maandalizi tu: weka zawadi na idadi ya washiriki, kisha publish mwenyewe kila siku. Maswali yasiyochaguliwa yanabaki hapa.</p>
<form method="POST" id="quiz-bank-form"><?php featureToken(); ?><input type="hidden" name="action" value="load_bank">
<?php $bank=QuizQuestionBank::catalog($actor); foreach(['sw'=>'Kiswahili · 50 maswali','en'=>'English · 50 questions'] as $language=>$label): ?>
<details class="service-card"><summary><?= $label ?></summary>
<?php foreach($bank as $batch): if($batch['language']!==$language)continue; ?>
<details class="service-card"><summary><?= sanitize($batch['title']) ?> · 5</summary>
<?php foreach($batch['questions'] as $question): ?>
<label style="display:flex;gap:10px;align-items:flex-start;padding:8px 0"><input style="width:auto;flex-shrink:0" type="checkbox" name="question_ids[]" value="<?= sanitize($question['id']) ?>"> <span><?= sanitize($question['question']) ?></span></label>
<?php endforeach; ?></details><?php endforeach; ?></details><?php endforeach; ?>
<p id="quiz-bank-count" aria-live="polite">0 / 10 selected</p><button class="btn btn-primary">Load selected questions into draft</button>
<p>Loading replaces the current unpublished draft. Nothing is published until you press Publish quiz.</p>
</form></details>
<details class="service-card" open><summary>Weka malipo na uchapishe quiz / Publish quiz</summary>
<form method="POST" id="quiz-publish-form"><?php featureToken(); ?><input type="hidden" name="action" value="create">
<h2>Malipo ya quiz</h2><p>Weka kiasi atakachopokea kila mtumiaji akijibu maswali yote ya quiz kwa usahihi. Mfano: TZS 100 kwa mshindi, washindi 50 = bajeti TZS 5,000.</p>
<div class="service-grid"><div><label for="quiz-reward">Malipo kwa mshindi (TZS)</label><input id="quiz-reward" name="reward" type="number" min="1" max="100000" value="<?= quizField($draft,'reward','100') ?>" required></div><div><label for="quiz-slots">Idadi ya washindi watakaolipwa</label><input id="quiz-slots" name="slots" type="number" min="1" max="10000" value="<?= quizField($draft,'slots','100') ?>" required></div></div>
<p class="service-budget" id="quiz-budget"></p>
<label for="quiz-title">Title</label><input id="quiz-title" name="title" maxlength="150" value="<?= quizField($draft,'title') ?>" required>
<label for="quiz-instructions">Instructions / lesson context</label><textarea id="quiz-instructions" name="instructions" rows="3" maxlength="4000" required><?= quizField($draft,'instructions') ?></textarea>
<div class="service-grid"><div><label for="quiz-platform">Optional lesson platform</label><select id="quiz-platform" name="platform"><option value="none">No external link</option><?php foreach(SocialHub::PLATFORMS as $key=>$label): ?><option value="<?= $key ?>" <?= ($draft['platform']??'none')===$key?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div><div><label for="quiz-url">Optional lesson link</label><input id="quiz-url" name="source_url" type="url" maxlength="600" value="<?= quizField($draft,'source_url') ?>" placeholder="https://..."></div></div>
<div id="quiz-question-list">
<?php foreach($draftQuestions as $index=>$question): $question=is_array($question)?$question:[];$options=is_array($question['options']??null)?$question['options']:[]; ?>
<fieldset class="quiz-question service-card" data-quiz-question><legend>Question <span data-question-number><?= $index+1 ?></span></legend>
<label>Question text<input data-field="question" name="questions[<?= $index ?>][question]" maxlength="500" value="<?= quizField($question,'question') ?>" required></label>
<?php foreach(['A','B','C','D'] as $choice=>$label): ?><label>Option <?= $label ?><input data-field="option-<?= $choice ?>" name="questions[<?= $index ?>][options][<?= $choice ?>]" maxlength="300" value="<?= sanitize(is_scalar($options[$choice]??null)?(string)$options[$choice]:'') ?>" required></label><?php endforeach; ?>
<label>Correct answer<select data-field="correct" name="questions[<?= $index ?>][correct]" required><option value="">Choose the correct answer</option><?php foreach(['A','B','C','D'] as $choice=>$label): ?><option value="<?= $choice ?>" <?= is_scalar($question['correct']??null) && (string)$question['correct']===(string)$choice?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
<button type="button" class="btn btn-outline-danger" data-remove-question>Remove question</button></fieldset>
<?php endforeach; ?></div>
<button type="button" class="btn btn-outline-primary" id="add-quiz-question">Add question (maximum 10)</button>
<label><input type="checkbox" name="budget_confirmed" value="1" required>I have funded the full reward budget. All correct answers will be paid automatically, up to the participant limit.</label>
<button class="btn btn-primary" type="submit">Publish quiz</button></form></details>
<?php endif; ?>
<?php if(!$quizzes && $available): ?><p class="service-empty">No quizzes published yet.</p><?php endif; ?>
<?php foreach($quizzes as $quiz): ?><article class="service-card"><h2><?= sanitize($quiz['title']) ?></h2><p>#<?= (int)$quiz['id'] ?> · <?= featureStatus($quiz['status']) ?> · Published by <?= sanitize($quiz['full_name']??'Staff') ?></p><p class="service-note"><?= sanitize($quiz['instructions']) ?></p><p class="service-budget">Reward <?= formatCurrency($quiz['reward']) ?> · <?= (int)$quiz['paid_count'] ?> / <?= (int)$quiz['slots'] ?> users paid · <?= (int)$quiz['attempt_count'] ?> attempts · Remaining reward budget <?= formatCurrency($quiz['reward']*max(0,(int)$quiz['slots']-(int)$quiz['paid_count'])) ?></p>
<details><summary>Questions and private answer key</summary><?php foreach(ComprehensionQuiz::validateQuestions(json_decode($quiz['questions'],true)) as $index=>$question): ?><p><strong><?= $index+1 ?>. <?= sanitize($question['question']) ?></strong></p><ol type="A"><?php foreach($question['options'] as $choice=>$option): ?><li><?= sanitize($option) ?><?= $choice===$question['correct']?' — Correct':'' ?></li><?php endforeach; ?></ol><?php endforeach; ?></details>
<?php if(in_array($quiz['status'],['active','paused'],true)): ?>
<details class="service-card"><summary>Badilisha malipo / Update reward - <?= formatCurrency($quiz['reward']) ?></summary>
<p>Kiasi hiki ni kwa quiz nzima, si kila swali. Malipo mapya yatatumika kwa majibu mapya tu; fedha na rekodi za waliolipwa awali hazibadilishwi.</p>
<?php if($quiz['status']==='active'): ?><p>Bonyeza <strong>Pause</strong> hapa chini kwanza, kisha fungua sehemu hii kubadilisha malipo.</p>
<?php else: ?><form method="POST"><?php featureToken(); ?><input type="hidden" name="action" value="update_reward"><input type="hidden" name="id" value="<?= (int)$quiz['id'] ?>">
<label>Malipo mapya kwa mshindi (TZS)<input name="reward" type="number" min="1" max="100000" value="<?= (int)$quiz['reward'] ?>" required></label>
<p>Washindi waliobaki: <?= max(0,(int)$quiz['slots']-(int)$quiz['paid_count']) ?>. Bajeti iliyobaki itakuwa idadi hii mara malipo mapya.</p>
<label><input type="checkbox" name="budget_confirmed" value="1" required>Nimethibitisha bajeti ya malipo mapya kwa washindi waliobaki.</label>
<button class="btn btn-primary">Hifadhi malipo mapya</button></form><?php endif; ?></details><?php endif; ?>
<?php if(in_array($quiz['status'],['active','paused'],true)): ?><form method="POST"><?php featureToken(); ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$quiz['id'] ?>"><button class="btn btn-outline-primary"><?= $quiz['status']==='active'?'Pause':'Resume' ?></button></form><?php endif; ?></article><?php endforeach; ?>
<?php featurePages($page,$more); ?><script src="<?= SITE_URL ?>/assets/js/quiz-builder.js"></script>
<?php include __DIR__.'/admin_footer.php'; ?>
