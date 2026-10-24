const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../assets/js/admin-wallet.js'), 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
function setup(status = 'pending', replies = []) {
    const timers = new Map();
    const requests = [];
    const listeners = {};
    const cell = { textContent: status };
    const balance = { textContent: '' };
    const message = { textContent: '' };
    const notice = { textContent: '' };
    const button = { dataset: { order: 'ES-TEST', status }, disabled: false,
        closest: () => ({ querySelector: () => cell }), addEventListener: (name, callback) => { listeners.click = callback; } };
    const root = { dataset: { balanceUrl: '/api/abliner_balance.php', statusUrl: '/api/check_payment.php' }, querySelectorAll: () => [button] };
    const elements = { 'abliner-wallet': root, 'wallet-balance': balance, 'deposit-message': message, 'deposit-notice': notice,
        'refresh-balance': { addEventListener() {} } };
    const context = {
        document: { hidden: false, getElementById: id => elements[id], addEventListener: (event, callback) => { listeners[event] = callback; } },
        window: { location: { href: 'https://example.test/admin/abliner-wallet' } }, URL,
        setTimeout: (callback, delay) => { const key = {}; timers.set(key, { callback, delay }); return key; },
        clearTimeout: key => timers.delete(key),
        fetch: async (url, options) => {
            requests.push({ url: String(url), options });
            if (String(url).includes('abliner_balance')) return { ok: true, json: async () => ({ success: true, data: { balances: { TZS: 1250, USD: 0 } } }) };
            const next = replies.shift();
            if (next instanceof Error) throw next;
            const result = typeof next === 'function' ? await next() : next;
            return { ok: true, json: async () => result };
        }
    };
    vm.runInNewContext(source, context);
    return { context, timers, requests, listeners, cell, balance, message, button,
        async tick() { const [key, timer] = timers.entries().next().value; timers.delete(key); await timer.callback(); await flush(); } };
}
(async () => {
    const success = setup('pending', [{ status: 'pending' }, { status: 'completed' }]);
    await flush();
    assert.equal(success.cell.textContent, 'pending');
    assert.equal(success.timers.size, 1);
    await success.tick();
    assert.equal(success.cell.textContent, 'completed');
    assert.equal(success.timers.size, 0, 'Polling continued after completion');
    assert.match(success.message.textContent, /Payment received/);
    assert.match(success.balance.textContent, /TZS/);
    assert.equal(success.requests.filter(r => r.url.includes('abliner_balance')).length, 2, 'Completion did not refresh wallet balance');
    assert(success.requests.every(r => !r.options.method || r.options.method === 'GET'), 'Polling initiated a new charge');
    const failure = setup('pending', [{ status: 'failed' }]);
    await flush();
    assert.equal(failure.cell.textContent, 'failed');
    assert.equal(failure.timers.size, 0);
    const recovery = setup('pending', [new Error('network'), { status: 'completed' }]);
    await flush();
    assert.equal(recovery.cell.textContent, 'pending', 'Network failure changed payment status');
    assert.equal([...recovery.timers.values()][0].delay, 10000);
    await recovery.tick();
    assert.equal(recovery.cell.textContent, 'completed');
    const finished = setup('completed');
    await flush();
    assert.equal(finished.requests.filter(r => r.url.includes('check_payment')).length, 0, 'Terminal deposits were polled');
    let resolveStatus;
    const inFlight = setup('pending', [() => new Promise(resolve => { resolveStatus = resolve; })]);
    await flush();
    inFlight.listeners.click();
    assert.equal(inFlight.requests.filter(r => r.url.includes('check_payment')).length, 1, 'Overlapping status requests were sent');
    resolveStatus({ status: 'completed' });
    await flush();
    assert.equal(inFlight.cell.textContent, 'completed');
    console.log('Admin wallet automatic verification tests passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
