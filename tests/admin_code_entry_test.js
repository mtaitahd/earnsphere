const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('assets/js/admin-code-entry.js', 'utf8');
function field(length, secret=false) {
    const events={}, formEvents={};let sends=0;
    const form={requestSubmit(){sends++;if(formEvents.submit)formEvents.submit();},addEventListener(k,v){formEvents[k]=v;}};
    const input={value:'',form,addEventListener(k,v){events[k]=v;}};
    const boxes=Array.from({length},()=>({textContent:'',classList:{toggle(){}}}));
    const entry={dataset:{codeLength:String(length)},querySelector(){return input;},querySelectorAll(){return boxes;},hasAttribute(){return secret;},classList:{add(){}}};
    vm.runInNewContext(source,{document:{querySelectorAll(){return [entry];}}});
    return {input,boxes,events,sends:()=>sends};
}
let f=field(4,true);
for(const value of ['0','01','012']){f.input.value=value;f.events.input();assert.equal(f.sends(),0);}
f.input.value='01';f.events.input();assert.equal(f.boxes[2].textContent,'');
f.input.value='0123';f.events.input();assert.equal(f.sends(),1);assert.equal(f.boxes[0].textContent,'•');
f.events.input();assert.equal(f.sends(),1,'Duplicate submission');
f=field(6);let prevented=false;
f.events.paste({clipboardData:{getData(){return '012 345';}},preventDefault(){prevented=true;}});
assert.equal(f.input.value,'012345');assert.equal(f.sends(),1);assert.equal(f.boxes.map(b=>b.textContent).join(''),'012345');assert.ok(prevented);
f=field(4,true);f.events.paste({clipboardData:{getData(){return '00-19';}},preventDefault(){}});
assert.equal(f.input.value,'0019');assert.equal(f.sends(),1);
f=field(6);f.input.value='a12';f.events.input();assert.equal(f.input.value,'12');assert.equal(f.sends(),0);
f.input.value='001234';f.events.input();assert.equal(f.sends(),1,'OTP autofill should submit');
console.log('Code boxes: partial typing, deletion, leading zeros, masked PIN, formatted paste, numeric filtering, autofill and one submission passed.');
