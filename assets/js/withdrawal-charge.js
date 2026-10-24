(() => {
    'use strict';
    const amount = document.getElementById('amount');
    const output = document.getElementById('withdrawal-quote');
    const consent = document.getElementById('confirm-withdrawal-terms');
    if (!amount || !output || !consent) return;
    const charge = Number(output.dataset.charge);
    function update() {
        const gross = Number(amount.value);
        const net = gross - charge;
        consent.checked = false;
        if (!Number.isInteger(gross) || net <= 0) {
            amount.setCustomValidity('Enter a whole TZS amount greater than the withdrawal charge.');
            output.textContent = 'Withdrawal charge: TZS ' + charge.toLocaleString() + '. Enter a larger amount to withdraw.';
            return;
        }
        amount.setCustomValidity('');
        output.textContent = 'From your wallet: TZS ' + gross.toLocaleString() + ' · Withdrawal charge: TZS ' + charge.toLocaleString() + ' · You receive: TZS ' + net.toLocaleString() + '.';
    }
    amount.addEventListener('input', update);
    update();
})();
