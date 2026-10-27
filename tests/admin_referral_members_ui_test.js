const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
class Element{
 constructor(){this.children=[];this.listeners={};this.dataset={};this.attributes={};this.textContent='';this.open=false;}
 append(...nodes){this.children.push(...nodes);}appendChild(node){this.append(node);}replaceChildren(...nodes){this.children=nodes;}
 addEventListener(type,fn){this.listeners[type]=fn;}setAttribute(key,value){this.attributes[key]=value;}
 set innerHTML(_){throw Error('Unsafe popup HTML');}
 showModal(){this.open=true;}close(){this.open=false;this.listeners.close();}
}
(async()=>{
 const dialog=new Element(),title=new Element(),body=new Element(),status=new Element(),pages=new Element(),close=new Element();
 const nodes={'[data-members-title]':title,'[data-members-body]':body,'[data-members-status]':status,'[data-members-pages]':pages,'[data-members-close]':close};dialog.querySelector=s=>nodes[s];
 dialog.dataset={endpoint:'https://example.test/api/admin_referrals.php',lookupUrl:'https://example.test/admin/referral-lookup'};
 const payload='<img src=x onerror=alert(1)><script>alert(2)</script>',triggers=[new Element(),new Element()];
 triggers.forEach((t,i)=>t.dataset={referralCode:'ROOT123',referralLevel:String(i+1),referralName:payload});
 let mode='normal',resolvePending;const calls=[];
 const resultFor=url=>{const u=new URL(url),level=Number(u.searchParams.get('level')),page=Number(u.searchParams.get('page'));return{ok:true,json:async()=>({success:true,data:{items:level===2?[]:[{full_name:payload,phone:'255700000000',referral_code:'CHILD123',status:payload,inviter_name:payload,inviter_code:'ROOT123',created_at:'2026-10-27'}],page,pages:level===2?1:2,total:level===2?0:26}})};};
 const fetch=(url,options)=>{calls.push({url,options});if(mode==='fail')return Promise.reject(Error('offline'));if(mode==='pending')return new Promise(resolve=>resolvePending=()=>resolve(resultFor(url)));return Promise.resolve(resultFor(url));};
 vm.runInNewContext(fs.readFileSync('assets/js/admin-referral-members.js','utf8'),{document:{getElementById:()=>dialog,querySelectorAll:()=>triggers,createElement:()=>new Element()},window:{location:{href:'https://example.test/admin/referral-lookup?code=ROOT123'}},fetch,URL,AbortController});
 const flush=()=>new Promise(resolve=>setImmediate(resolve));
 triggers[0].listeners.click();assert(dialog.open);await flush();assert.equal(title.textContent,'Level 1 · '+payload);assert.equal(body.children[0].children[0].children[0].textContent,payload);assert.equal(body.children[0].children[2].textContent,'Aliunganishwa na: '+payload+' · ROOT123');assert.equal(calls[0].options.cache,'no-store');
 pages.children[1].listeners.click();await flush();assert.equal(new URL(calls.at(-1).url).searchParams.get('page'),'2');assert(dialog.open);pages.children[0].listeners.click();await flush();assert.equal(new URL(calls.at(-1).url).searchParams.get('page'),'1');
 close.listeners.click();assert(!dialog.open);assert.equal(body.children.length,0);assert(calls.at(-1).options.signal.aborted);
 triggers[1].listeners.click();await flush();assert(status.textContent.includes('Hakuna watu kwenye Level 2'));assert.equal(body.children.length,0);
 mode='fail';triggers[0].listeners.click();await flush();assert(status.textContent.includes('Jaribu tena'));mode='normal';pages.children[0].listeners.click();await flush();assert.equal(body.children.length,1);
 mode='pending';triggers[0].listeners.click();const old=resolvePending;mode='normal';triggers[1].listeners.click();await flush();old();await flush();assert(status.textContent.includes('Level 2'));assert.equal(body.children.length,0);
 mode='pending';triggers[0].listeners.click();close.listeners.click();resolvePending();await flush();assert(!dialog.open);assert.equal(body.children.length,0);
 console.log('Referral popup: opens in place, safe text, paging, empty state, retry, abort on close and stale level responses passed.');
})().catch(e=>{console.error(e);process.exit(1);});
