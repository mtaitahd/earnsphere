(() => {
    const dialog = document.getElementById('referral-payment-notice');
    if (!dialog || typeof dialog.showModal !== 'function') return;
    document.addEventListener('click', event => {
        if (event.target.closest('[data-referral-pay-required]')) {
            event.preventDefault();
            if (!dialog.open) dialog.showModal();
        }
        if (event.target.closest('[data-close-referral-payment]')) dialog.close();
    });
})();
