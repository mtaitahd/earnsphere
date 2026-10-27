const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
class Element {
 constructor(){this.children=[];this.style={};this.listeners={};this.textContent='';this.value='';}
 append(...els){this.children.push(...els);}appendChild(el){this.append(el);}setAttribute(k,v){this[k]=v;}
 addEventListener(k,fn){this.listeners[k]=fn;}remove(){this.removed=true;}
 set innerHTML(v){if(v!=='')throw Error('Unsafe HTML insertion: '+v);this.children=[];}
}
(async()=>{
 const payload='<img src=x onerror=alert(1)><script>alert(2)</script>',container=new Element();
 const doc={querySelector:s=>s==='.toast-container'?container:null,createElement:()=>new Element(),addEventListener:()=>{}};
 vm.runInNewContext(fs.readFileSync('assets/js/app.js','utf8')+';App.showToast('+JSON.stringify(payload)+', "<svg/onload=alert(3)>");',{document:doc,window:{location:{origin:'https://example.test'}},setTimeout:()=>{}});
 const toast=container.children[0];assert.equal(toast.children[1].textContent,payload);assert.equal(toast.children[0].className,'fas fa-info-circle');toast.children[2].listeners.click();assert(toast.removed);
 const source=fs.readFileSync('admin/sms.php','utf8'),start=source.indexOf('    function addUser('),end=source.indexOf('    // ===== Edit template modal',start);
 const input=new Element(),results=new Element(),selected=new Element(),map={};
 const ctx={searchTimer:null,document:doc,selectedMap:map,selectedUsers:selected,searchResults:results,searchInput:input,clearTimeout:()=>{},setTimeout:fn=>fn(),fetch:async()=>({json:async()=>({success:true,results:[{id:7,name:payload,phone:payload}]})})};
 vm.createContext(ctx);vm.runInContext(source.slice(start,end),ctx);input.value='abc';input.listeners.input();await new Promise(resolve=>setImmediate(resolve));
 const item=results.children[0];assert.equal(item.children[0].children[0].textContent,payload);item.listeners.click();assert.equal(selected.children[0].children[1].textContent,payload);assert.equal(selected.children[0].children[0].value,'7');selected.children[0].children[3].listeners.click();assert(!map[7]);
 console.log('Security UI: malicious toast text and SMS recipients remain inert; selection/removal work.');
})().catch(e=>{console.error(e);process.exit(1);});
