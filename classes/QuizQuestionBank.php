<?php
require_once __DIR__.'/ComprehensionQuiz.php';

class QuizQuestionBank {
    public static function catalog(int $actor): array {
        if (!StaffAccess::canForUser($actor, 'quizzes')) throw new InvalidArgumentException('Quiz publishing permission required.');
        $data=json_decode(file_get_contents(dirname(__DIR__).'/database/quiz_bank/daily_life.json'),true,512,JSON_THROW_ON_ERROR);
        return $data['quizzes'];
    }

    public static function draft(int $actor, $ids): array {
        $catalog=self::catalog($actor);
        if (!is_array($ids) || count($ids)<1 || count($ids)>10) throw new InvalidArgumentException('Choose between 1 and 10 questions.');
        $known=[];
        foreach ($catalog as $batch) foreach ($batch['questions'] as $question) $known[$question['id']]=[$question,$batch['language']];
        $selected=[];$languages=[];$seen=[];
        foreach ($ids as $id) {
            if (!is_string($id) || !isset($known[$id]) || isset($seen[$id])) throw new InvalidArgumentException('Choose distinct questions from the question bank.');
            $seen[$id]=true;$selected[]=$known[$id][0];$languages[$known[$id][1]]=true;
        }
        $sw=isset($languages['sw']) && count($languages)===1;
        return ['title'=>$sw?'Maswali ya maisha ya kila siku':'Everyday life quiz',
            'instructions'=>$sw?'Chagua jibu moja sahihi kwa kila swali. Pata yote ili kupokea zawadi iliyowekwa.':'Choose one correct answer for each question. Answer all correctly to receive the configured reward.',
            'platform'=>'none','source_url'=>'','questions'=>ComprehensionQuiz::validateQuestions($selected)];
    }
}
