/* Only query existing withdrawals. Never create a payout from History. */
(function () {
    'use strict';
    var config = window.transactionVerification;
    if (!config) return;
    var ids = Array.from(document.querySelectorAll('[data-pending-withdrawal]'))
        .map(function (row) { return row.dataset.pendingWithdrawal; })
        .filter(function (id, index, all) { return /^[1-9][0-9]*$/.test(id) && all.indexOf(id) === index; });
    if (!ids.length) return;
    var rounds = 0, stopped = false;
    async function check() {
        if (stopped || rounds >= 12) return;
        if (document.hidden) { setTimeout(check, 10000); return; }
        rounds++;
        for (var i = 0; i < ids.length; i++) {
            var controller = new AbortController();
            var timeout = setTimeout(function () { controller.abort(); }, 25000);
            try {
                var response = await fetch(config.endpoint + '?withdrawal_id=' + encodeURIComponent(ids[i]),
                    {method: 'GET', cache: 'no-store', signal: controller.signal});
                var result = await response.json();
                if (response.ok && result.success && ['completed', 'failed', 'rejected'].includes(result.status)) {
                    stopped = true;
                    window.location.reload();
                    return;
                }
                if (response.status === 401 || response.status === 403) { stopped = true; return; }
            } catch (error) {
                // Connection failure is not payment failure: keep the recorded state.
            } finally { clearTimeout(timeout); }
        }
        if (!stopped && rounds < 12) setTimeout(check, 10000);
    }
    check();
}());
