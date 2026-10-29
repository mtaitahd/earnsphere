<?php
require_once __DIR__ . '/SocialHub.php';

class ComprehensionQuiz {
    private static function publisher(int $id): void {
        if (!StaffAccess::canForUser($id, 'quizzes')) throw new InvalidArgumentException('Quiz publishing permission required.');
    }

    public static function hasPaidRegistration(int $userId): bool {
        return (bool)Database::fetchOne("SELECT id FROM payments WHERE user_id = ? AND purpose = 'registration' AND status = 'completed' AND amount > 0 LIMIT 1",[$userId]);
    }

    public static function validateQuestions($questions): array {
        if (!is_array($questions) || count($questions)<1 || count($questions)>10) throw new InvalidArgumentException('Add between 1 and 10 questions.');
        $validated=[];
        foreach (array_values($questions) as $question) {
            if (!is_array($question)) throw new InvalidArgumentException('Invalid question.');
            $prompt=SocialHub::text($question['question']??'',500,'Question');
            $raw=$question['options']??null;
            if (!is_array($raw) || count($raw)!==4 || array_keys($raw)!==[0,1,2,3]) throw new InvalidArgumentException('Each question requires four answer choices.');
            $options=[];
            foreach ($raw as $option) $options[]=SocialHub::text($option,300,'Answer choice');
            if (count(array_unique(array_map(fn($value)=>mb_strtolower($value),$options)))!==4) throw new InvalidArgumentException('Answer choices must be distinct.');
            $correct=SocialHub::number($question['correct']??'',0,3,'Correct answer');
            $validated[]=['question'=>$prompt,'options'=>$options,'correct'=>$correct];
        }
        return $validated;
    }

    public static function create(int $actor,array $input): int {
        self::publisher($actor);
        $title=SocialHub::text($input['title']??'',150,'Quiz title');
        $instructions=SocialHub::text($input['instructions']??'',4000,'Instructions');
        $questions=self::validateQuestions($input['questions']??null);
        $reward=SocialHub::number($input['reward']??'',1,100000,'Reward');
        $slots=SocialHub::number($input['slots']??'',1,10000,'Maximum paid participants');
        $platform=$input['platform']??'none';$source='';
        if ($platform!=='none') $source=SocialHub::url($platform,$input['source_url']??'');
        elseif (!empty($input['source_url'])) throw new InvalidArgumentException('Choose the platform for the source link, or leave the link empty.');
        if (($input['budget_confirmed']??'')!=='1') throw new InvalidArgumentException('Confirm the full reward budget before publishing.');
        return Database::insert('comprehension_quizzes',['title'=>$title,'instructions'=>$instructions,'questions'=>json_encode($questions,JSON_THROW_ON_ERROR),'platform'=>$platform,'source_url'=>$source,'reward'=>$reward,'slots'=>$slots,'created_by'=>$actor,'created_at'=>gmdate('Y-m-d H:i:s')]);
    }

    // Only this projection can be sent to the user interface. Correct answers stay server-side.
    public static function publicQuestions(string $json): array {
        $questions=self::validateQuestions(json_decode($json,true,512,JSON_THROW_ON_ERROR));
        return array_map(fn($question)=>['question'=>$question['question'],'options'=>$question['options']],$questions);
    }

    public static function toggle(int $actor,int $id): void {
        self::publisher($actor);Database::beginTransaction();
        try {
            $quiz=Database::fetchOne('SELECT * FROM comprehension_quizzes WHERE id = ? FOR UPDATE',[$id]);
            if (!$quiz || !in_array($quiz['status'],['active','paused'],true)) throw new InvalidArgumentException('Only an active or paused quiz can change visibility.');
            DailyContent::requireCurrent($quiz);
            Database::update('comprehension_quizzes',['status'=>$quiz['status']==='active'?'paused':'active'],'id = ?',[$id]);
            Database::commit();
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
    }

    public static function updateReward(int $actor,int $id,array $input): void {
        self::publisher($actor);
        $reward=SocialHub::number($input['reward']??'',1,100000,'Reward');
        if (($input['budget_confirmed']??'')!=='1') throw new InvalidArgumentException('Confirm the updated reward budget.');
        Database::beginTransaction();
        try {
            $quiz=Database::fetchOne('SELECT * FROM comprehension_quizzes WHERE id = ? FOR UPDATE',[$id]);
            if (!$quiz || $quiz['status']!=='paused') throw new InvalidArgumentException('Pause the quiz before changing its reward. Completed quizzes cannot be changed.');
            DailyContent::requireCurrent($quiz);
            Database::update('comprehension_quizzes',['reward'=>$reward],'id = ?',[$id]);
            Database::commit();
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
    }

    public static function submit(int $userId,int $quizId,$answers): array {
        Database::beginTransaction();
        try {
            SocialHub::activeUser($userId);
            if (!self::hasPaidRegistration($userId)) throw new InvalidArgumentException('Kamilisha malipo ya usajili kabla ya kufanya quiz.');
            // Shared lock order with social rewards: user, content, then wallet.
            $quiz=Database::fetchOne('SELECT * FROM comprehension_quizzes WHERE id = ? FOR UPDATE',[$quizId]);
            if (!$quiz) throw new InvalidArgumentException('Quiz not found.');
            $existing=Database::fetchOne('SELECT * FROM comprehension_attempts WHERE quiz_id = ? AND user_id = ?',[$quizId,$userId]);
            if ($existing) {Database::commit();return $existing;}
            if ($quiz['status']!=='active' || (int)$quiz['paid_count'] >= (int)$quiz['slots']) throw new InvalidArgumentException('This quiz is closed or its reward slots are full.');
            DailyContent::requireCurrent($quiz);
            $questions=self::validateQuestions(json_decode($quiz['questions'],true,512,JSON_THROW_ON_ERROR));
            if (!is_array($answers) || array_keys($answers)!==range(0,count($questions)-1)) throw new InvalidArgumentException('Answer every question before submitting.');
            $score=0;$validated=[];
            foreach ($questions as $index=>$question) {
                $choice=SocialHub::number($answers[$index],0,3,'Selected answer');$validated[]=$choice;
                if ($choice===$question['correct']) $score++;
            }
            $passed=$score===count($questions);$reward=$passed?(int)$quiz['reward']:0;
            $id=Database::insert('comprehension_attempts',['quiz_id'=>$quizId,'user_id'=>$userId,'answers'=>json_encode($validated,JSON_THROW_ON_ERROR),'score'=>$score,'question_count'=>count($questions),'reward'=>$reward,'status'=>$passed?'paid':'failed','created_at'=>gmdate('Y-m-d H:i:s')]);
            if ($passed) {
                Wallet::credit($userId,$reward,'social_reward','Quiz reward: '.$quiz['title'],$id,'quiz_attempt');
                $count=(int)$quiz['paid_count']+1;
                Database::update('comprehension_quizzes',['paid_count'=>$count,'status'=>$count >= (int)$quiz['slots']?'completed':'active'],'id = ?',[$quizId]);
            }
            SocialHub::notification('quiz:'.$id,$userId,$passed?'Quiz reward received':'Quiz result', $passed?'Hongera! Umepata majibu yote '.$score.' sahihi. '.formatCurrency($reward).' imeongezwa kwenye wallet yako.':'Umepata '.$score.' kati ya '.count($questions).'. Reward hulipwa kwa majibu yote sahihi. Hakuna fedha iliyoongezwa.');
            $result=Database::fetchOne('SELECT * FROM comprehension_attempts WHERE id = ?',[$id]);
            Database::commit();return $result;
        }catch(Throwable $e){if(Database::inTransaction())Database::rollback();throw $e;}
    }
}
