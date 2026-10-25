const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const script = fs.readFileSync(path.join(__dirname, '../assets/js/transaction-verification.js'), 'utf8');
async function run(status, ok = true, http = 200, throws = false) {
    let calls = [], reloads = 0, timers = [];
    const context = {
        window: {transactionVerification: {endpoint:'/api/check_withdrawal.php'},location:{reload(){reloads++;}}},
        document: {hidden:false,querySelectorAll:()=>[{dataset:{pendingWithdrawal:'12'}},{dataset:{pendingWithdrawal:'12'}}]},
        AbortController,
        setTimeout:(fn,ms)=>{timers.push(ms);return timers.length;},clearTimeout:()=>{},
        fetch:async (url,options)=>{calls.push({url,options});if(throws)throw new Error('offline');return {ok,status:http,json:async()=>({success:ok,status})};}
    };
    vm.runInNewContext(script,context);
    await new Promise(resolve=>setImmediate(resolve));
    return {calls,reloads,timers};
}
(async()=>{
    let result=await run('completed');
    assert.equal(result.reloads,1);
    assert.equal(result.calls.length,1);
    assert.equal(result.calls[0].options.method,'GET');
    assert.equal(result.calls[0].options.cache,'no-store');
    assert(result.calls[0].url.endsWith('withdrawal_id=12'));
    result=await run('pending');
    assert.equal(result.reloads,0);
    assert.equal(result.calls.length,1);
    assert(result.timers.includes(10000));
    result=await run('pending',false,503,true);
    assert.equal(result.reloads,0);
    result=await run('pending',false,401);
    assert.equal(result.reloads,0);
    assert(!result.timers.includes(10000));
    console.log('Transaction History verification tests passed: completed, pending, connection error, auth and GET-only.');
})().catch(error=>{console.error(error);process.exit(1);});
