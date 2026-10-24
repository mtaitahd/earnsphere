(() => {
    'use strict';
    const root = document.getElementById('abliner-wallet');
    if (!root) return;
    const balance = document.getElementById('wallet-balance');
    const message = document.getElementById('deposit-message');
    const notice = document.getElementById('deposit-notice');
    const buttons = [...root.querySelectorAll('.check-deposit')];
    const pending = new Set(buttons.filter(button => button.dataset.status === 'pending'));
    const busy = new Set();
    let timer, polling = false, retryDelay = 5000;
    function showMessage(text) {
        message.textContent = text;
        if (notice) notice.textContent = text;
    }
    async function refreshBalance() {
        balance.textContent = 'Loading…';
        try {
            const response = await fetch(root.dataset.balanceUrl, { cache: 'no-store', credentials: 'same-origin' });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.error || 'Balance unavailable.');
            const balances = result.data.balances || { [result.data.currency || 'TZS']: result.data.balance };
            balance.textContent = Object.entries(balances).map(([currency, amount]) => currency + ' ' + Number(amount).toLocaleString()).join(' · ');
            return true;
        } catch (error) { balance.textContent = error.message; return false; }
    }
    async function checkDeposit(button) {
        if (busy.has(button)) return true;
        busy.add(button);
        button.disabled = true;
        try {
            const url = new URL(root.dataset.statusUrl, window.location.href);
            url.searchParams.set('order_id', button.dataset.order);
            const response = await fetch(url, { cache: 'no-store', credentials: 'same-origin' });
            const result = await response.json();
            if (!response.ok || !['pending', 'completed', 'failed', 'refunded'].includes(result.status)) throw new Error('Status unavailable');
            button.closest('tr').querySelector('[data-status-for]').textContent = result.status;
            const wasPending = pending.has(button);
            button.dataset.status = result.status;
            if (result.status !== 'pending') pending.delete(button);
            if (result.status === 'completed') {
                if (wasPending) showMessage('Payment received. Refreshing wallet balance…');
                const updated = await refreshBalance();
                showMessage(updated ? 'Payment received. Wallet balance updated.' : 'Payment received. Balance refresh is temporarily unavailable.');
            } else if (result.status === 'failed' || result.status === 'refunded') {
                showMessage(result.status === 'failed' ? 'Deposit failed. No payment was confirmed.' : 'This deposit was refunded.');
            } else {
                showMessage(result.message || 'Waiting for payment. Approve the prompt on your phone; confirmation will appear automatically.');
            }
            return true;
        } catch (error) {
            // Keep pending records pending when connectivity fails. Never request another charge.
            showMessage('Verification is temporarily unavailable. We will keep checking; do not pay again.');
            return false;
        } finally { busy.delete(button); button.disabled = false; }
    }
    function schedule() {
        clearTimeout(timer);
        if (pending.size) timer = setTimeout(poll, retryDelay);
    }
    async function poll() {
        if (polling) return;
        if (document.hidden) { schedule(); return; }
        polling = true;
        let healthy = true;
        try {
            for (const button of [...pending]) healthy = (await checkDeposit(button)) && healthy;
            retryDelay = healthy ? 5000 : Math.min(retryDelay * 2, 30000);
        } finally { polling = false; schedule(); }
    }
    buttons.forEach(button => button.addEventListener('click', () => checkDeposit(button)));
    document.getElementById('refresh-balance').addEventListener('click', refreshBalance);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && pending.size) { clearTimeout(timer); poll(); }
    });
    refreshBalance();
    if (pending.size) poll();
})();
