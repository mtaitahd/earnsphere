/* Verify the existing order only; polling must never send another push. */
(function () {
    'use strict';
    var config = window.paymentWaiting;
    if (!config || !config.orderId) return;
    var timer, busy = false, finished = false, attempts = 0;
    var btn = document.getElementById('checkStatusBtn');
    var notice = document.getElementById('payment-notice');
    var spinner = document.querySelector('.waiting-spinner');
    function stopSpinner() { if (spinner) spinner.style.display = 'none'; }
    function schedule() {
        if (!finished && attempts < 36) timer = setTimeout(check, 5000);
        else if (!finished) {
            stopSpinner();
            notice.textContent = 'Still awaiting confirmation. If no prompt arrived, contact support with your order ID. Do not pay again until this request is checked. You can use Check payment status to verify it again.';
        }
    }
    async function check() {
        if (busy || finished) return;
        clearTimeout(timer);
        busy = true;
        attempts++;
        btn.disabled = true;
        btn.textContent = 'Checking...';
        var controller = new AbortController();
        var timeout = setTimeout(function () { controller.abort(); }, 25000);
        try {
            var response = await fetch(config.siteUrl + '/api/check_payment.php?order_id=' + encodeURIComponent(config.orderId), { signal: controller.signal, cache: 'no-store' });
            var data = await response.json();
            if (!response.ok) throw new Error('Verification unavailable');
            if (data.status === 'completed') {
                finished = true;
                stopSpinner();
                notice.textContent = 'Payment confirmed! Redirecting to login...';
                setTimeout(function () { window.location.href = config.siteUrl + '/login'; }, 1000);
            } else if (['failed', 'voided', 'expired'].includes(data.status)) {
                finished = true;
                stopSpinner();
                notice.textContent = 'This payment failed. You can return to the payment form and submit a new request.';
                var link = document.createElement('a');
                link.href = config.siteUrl + '/payment?action=show';
                link.textContent = ' Try again';
                notice.appendChild(link);
            } else {
                notice.textContent = data.message || (config.provider || 'The provider') + ' has not confirmed this payment. If no prompt arrived, contact support with the order ID. No new push has been sent.';
            }
        } catch (error) {
            notice.textContent = 'Payment verification is temporarily unavailable. We have not confirmed payment. Check your connection or contact support with the order ID before paying again.';
        } finally {
            clearTimeout(timeout);
            busy = false;
            btn.disabled = finished;
            btn.textContent = 'Check payment status';
            schedule();
        }
    }
    window.checkStatusNow = check;
    window.toggleChangeNum = function () {
        var section = document.getElementById('changeNumSection');
        var open = section.style.display === 'none';
        section.style.display = open ? 'block' : 'none';
        document.getElementById('toggleChangeNumBtn').textContent = open ? 'Cancel' : 'Change Number';
    };
    btn.textContent = 'Check payment status';
    check();
}());
