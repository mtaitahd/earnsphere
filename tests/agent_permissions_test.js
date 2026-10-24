const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
function checkbox(value,parent,page,label){return {value,checked:false,dataset:{parent,page,label},addEventListener(event,callback){this.change=callback;}};}
const support=checkbox('support','','support','Support tickets');
const users=checkbox('users','','users','View users');
const manageUsers=checkbox('users_manage','users','','Manage users');
const inputs=[support,users,manageUsers];
const preview={textContent:''};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../assets/js/agent-permissions.js'),'utf8'),{document:{querySelectorAll:()=>inputs,getElementById:()=>preview}});
assert(preview.textContent.startsWith('0 work'));
support.checked=true;support.change();assert(preview.textContent.startsWith('1 work'));assert(preview.textContent.includes('Support tickets'));
manageUsers.checked=true;manageUsers.change();assert(users.checked,'Manage permission did not select view permission');assert(preview.textContent.startsWith('2 work'),'Manage checkbox incorrectly added a duplicate sidebar link');
users.checked=false;users.change();assert(!manageUsers.checked,'Unchecking view left management enabled');assert(preview.textContent.startsWith('1 work'));
support.checked=false;support.change();assert(preview.textContent.startsWith('0 work'));
console.log('Agent checkbox dependencies and sidebar preview checks passed.');
