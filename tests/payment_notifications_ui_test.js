const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
class Element {
    constructor(){this.children=[];this.textContent='';this.className='';this.disabled=false;this.listeners={};this.style={};this.attributes={};}
    setAttribute(name,value){this.attributes[name]=value;}
    appendChild(child){this.children.push(child);}
    replaceChildren(){this.children=[];}
    addEventListener(type,fn){this.listeners[type]=fn;}
    set innerHTML(value){throw new Error('Unsafe notification HTML rendering');}
}
(async()=>{
    const list=new Element(),counter=new Element(),button=new Element(),clearButton=new Element();
    const others={}; for(const id of ['annBadge','announcementBell','annModalBody','annModal','notificationNewsTab','notificationPaymentsTab']) others[id]=new Element();
    const panel={dataset:{endpoint:'/api/notifications.php',csrfName:'csrf_token',csrf:'safe-token'},style:{display:'none'},querySelector:s=>s.includes('list')?list:s.includes('count')?counter:s.includes('clear-all')?clearButton:button};
    const calls=[];
    const item={id:7,title:'<img src=x onerror=alert(1)>',message:'Wallet TZS 1,000; ada TZS 40; umepokea TZS 960.',created_at:'2026-10-25 08:15:00',read_at:null};
    const context={window:{announcementUnreadCount:2},document:{hidden:false,getElementById:id=>id==='financial-notifications'?panel:others[id],createElement:()=>new Element()},URLSearchParams,AbortSignal:{timeout:()=>({})},setInterval:()=>1,clearInterval:()=>{},
      fetch:async(url,options)=>{calls.push({url,options});const read=options.method==='POST'; const cleared=read && options.body.get('action')==='clear_all';return{ok:true,json:async()=>({success:true,items:cleared?[]:[{...item,read_at:read?'now':null}],unread:read?0:1,latest_id:7})};}};
    vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../assets/js/payment-notifications.js'),'utf8'),context);
    await new Promise(resolve=>setImmediate(resolve));
    assert.equal(list.children[0].children[0].textContent,item.title,'Notification text was not rendered safely');
    assert(list.children[0].children[2].textContent.includes('11:15'),'Notification time not Tanzania time');
    assert.equal(counter.textContent,'1 unread');
    assert.equal(others.annBadge.textContent,'3','Bell did not combine News and Payments unread counts');
    assert.equal(panel.style.display,'none','Notifications were shown inline');
    context.window.openFinancialNotifications();await new Promise(resolve=>setImmediate(resolve));
    assert.equal(panel.style.display,'block');assert.equal(others.annModal.style.display,'flex');
    assert.equal(others.annModalBody.style.display,'none');
    button.listeners.click();await new Promise(resolve=>setImmediate(resolve));
    assert.equal(calls[2].options.method,'POST');
    assert.equal(calls[2].options.body.get('csrf_token'),'safe-token');
    assert.equal(calls[2].options.body.get('through_id'),'7');
    assert.equal(counter.textContent,'All read');assert.equal(button.disabled,true);
    clearButton.listeners.click(); await new Promise(resolve=>setImmediate(resolve));
    assert.equal(calls[3].options.body.get('action'),'clear_all');
    assert.equal(list.children.length,1); assert.equal(clearButton.disabled,true);
    const dashboard=fs.readFileSync(path.join(__dirname,'../dashboard.php'),'utf8');
    assert(dashboard.includes('background:#15803d') && dashboard.includes('font-weight:700;color:#fff;'),'Paid card contrast fix missing');
    console.log('Notification UI text safety, Tanzania time, read-all POST/CSRF and paid-card style checks passed.');
})().catch(error=>{console.error(error);process.exit(1);});
