const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const fields = {};
for (const [name, value] of Object.entries({registration_fee:'15000',commission_l1:'3000',commission_l2:'2000',commission_total:'0',company_earning:'0'})) {
    fields[name] = { value, validationMessage:'', setCustomValidity(message) { this.validationMessage = message; }, addEventListener(event, callback) { this.update = callback; } };
}
const displays = Object.fromEntries(['previewL1','previewL2','previewCompany','previewTotal','validationMsg'].map(id => [id, {textContent:'',style:{}}]));
const document = {
    querySelector(selector) { return fields[selector.match(/settings\[([^\]]+)\]/)[1]]; },
    getElementById(id) { return displays[id]; }
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../assets/js/commission-settings.js'),'utf8'), { document });
assert.equal(fields.commission_total.value,5000);
assert.equal(fields.company_earning.value,10000);
fields.commission_l1.value = '4000'; fields.commission_l1.update();
assert.equal(fields.commission_total.value,6000);
assert.equal(fields.company_earning.value,9000);
fields.registration_fee.value = '1000'; fields.registration_fee.update();
assert(fields.registration_fee.validationMessage, 'Over-allocation was accepted');
fields.registration_fee.value = '20000'; fields.registration_fee.update();
assert.equal(fields.company_earning.value,14000);
assert.equal(fields.registration_fee.validationMessage,'');
fields.commission_l2.value = '1.5'; fields.commission_l2.update();
assert(fields.registration_fee.validationMessage, 'Fractional commission was accepted');
console.log('Two-level commission settings preview checks passed.');
