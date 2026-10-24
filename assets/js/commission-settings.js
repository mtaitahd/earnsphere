(() => {
    'use strict';
    const field = key => document.querySelector('input[name="settings[' + key + ']"]');
    const fee = field('registration_fee');
    const l1 = field('commission_l1');
    const l2 = field('commission_l2');
    if (!fee || !l1 || !l2) return;
    const amount = input => Number(input.value) || 0;
    function update() {
        const total = amount(l1) + amount(l2);
        const company = amount(fee) - total;
        field('commission_total').value = total;
        field('company_earning').value = company;
        document.getElementById('previewL1').textContent = 'TZS ' + amount(l1).toLocaleString();
        document.getElementById('previewL2').textContent = 'TZS ' + amount(l2).toLocaleString();
        document.getElementById('previewCompany').textContent = 'TZS ' + company.toLocaleString();
        document.getElementById('previewTotal').textContent = 'TZS ' + amount(fee).toLocaleString();
        const invalid = [fee, l1, l2].some(input => !Number.isInteger(Number(input.value)) || Number(input.value) < 0)
            || amount(fee) < 500 || amount(fee) > 3000000 || company < 0;
        fee.setCustomValidity(invalid ? 'Use whole TZS amounts; Level 1 + Level 2 must not exceed a registration fee between TZS 500 and 3,000,000.' : '');
        const message = document.getElementById('validationMsg');
        message.style.display = 'block';
        message.style.color = invalid ? '#e74a3b' : '#1cc88a';
        message.textContent = invalid ? fee.validationMessage : 'Level 1 + Level 2 = TZS ' + total.toLocaleString() + '. Company share: TZS ' + company.toLocaleString() + '.';
    }
    [fee, l1, l2].forEach(input => input.addEventListener('input', update));
    update();
})();
