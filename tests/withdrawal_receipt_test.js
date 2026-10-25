const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../withdrawal.php'), 'utf8');
const scripts = [...source.matchAll(/<script>([\s\S]*?)<\/script>/g)]
    .map(match => match[1].replace(/<\?=[\s\S]*?\?>/g, '0'));
let intervals = 0;
const context = {
    document: {getElementById: () => null, querySelectorAll: () => [], querySelector: () => null},
    setTimeout: () => {}, setInterval: () => { intervals++; }, clearInterval: () => {}
};
for (const script of scripts) vm.runInNewContext(script, context);
assert.equal(intervals, 1, 'Receipt did not initialize safe history polling');
assert(!source.includes('window.location.reload()'), 'Status check could resubmit the withdrawal POST');
console.log('Withdrawal receipt without a form and GET refresh checks passed.');
