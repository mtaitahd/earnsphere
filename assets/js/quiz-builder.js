(function () {
    'use strict';
    var bank=document.getElementById('quiz-bank-form');
    if(bank){
        var choices=bank.querySelectorAll('input[name="question_ids[]"]'),count=document.getElementById('quiz-bank-count');
        function updateSelection(){
            var total=bank.querySelectorAll('input[name="question_ids[]"]:checked').length;
            count.textContent=total+' / 10 selected';
            choices.forEach(function(choice){choice.disabled=!choice.checked && total>=10;});
        }
        bank.addEventListener('change',updateSelection);updateSelection();
    }
    var list=document.getElementById('quiz-question-list'), add=document.getElementById('add-quiz-question');
    if(!list || !add) return;
    var template=list.firstElementChild.cloneNode(true);
    function renumber(){
        var questions=list.querySelectorAll('[data-quiz-question]');
        questions.forEach(function(question,index){
            question.querySelector('[data-question-number]').textContent=String(index+1);
            question.querySelectorAll('[data-field]').forEach(function(field){
                var key=field.dataset.field;
                field.name='questions['+index+']'+(key.indexOf('option-')===0?'[options]['+key.slice(7)+']':'['+key+']');
            });
            question.querySelector('[data-remove-question]').disabled=questions.length===1;
        });
        add.disabled=questions.length>=10;
    }
    add.addEventListener('click',function(){
        if(list.children.length>=10)return;
        var question=template.cloneNode(true);
        question.querySelectorAll('input,select').forEach(function(field){field.value='';});
        list.appendChild(question);renumber();
    });
    list.addEventListener('click',function(event){
        var button=event.target.closest('[data-remove-question]');
        if(!button || list.children.length<=1)return;
        button.closest('[data-quiz-question]').remove();renumber();
    });
    var reward=document.getElementById('quiz-reward'),slots=document.getElementById('quiz-slots'),budget=document.getElementById('quiz-budget');
    function updateBudget(){var r=Number(reward.value),s=Number(slots.value);budget.textContent=Number.isInteger(r)&&Number.isInteger(s)&&r>0&&s>0?'Maximum reward budget: TZS '+(r*s).toLocaleString()+' ('+s.toLocaleString()+' users × TZS '+r.toLocaleString()+').':'Enter reward and maximum paid users.';}
    reward.addEventListener('input',updateBudget);slots.addEventListener('input',updateBudget);
    renumber();updateBudget();
})();
