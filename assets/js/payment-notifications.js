(function () {
    'use strict';
    var panel = document.getElementById('financial-notifications');
    if (!panel) return;
    var list = panel.querySelector('[data-notification-list]');
    var counter = panel.querySelector('[data-notification-count]');
    var button = panel.querySelector('[data-notification-read-all]');
    var latestId = 0, busy = false, polls = 0, lastSnapshot = '';
    function render(result) {
        latestId = result.latest_id || 0;
        counter.textContent = result.unread ? result.unread + ' unread' : 'All read';
        button.disabled = !result.unread;
        var snapshot = JSON.stringify({items:result.items, unread:result.unread});
        if (snapshot === lastSnapshot) return;
        lastSnapshot = snapshot;
        list.replaceChildren();
        if (!result.items.length) {
            var empty = document.createElement('p');
            empty.className = 'text-muted mb-0';
            empty.textContent = 'Payment and withdrawal notifications will appear here.';
            list.appendChild(empty);
        }
        result.items.forEach(function (item) {
            var article = document.createElement('article');
            article.className = 'financial-notification' + (item.read_at ? '' : ' is-unread');
            var title = document.createElement('strong');
            title.textContent = item.title;
            var body = document.createElement('p');
            body.textContent = item.message;
            var date = document.createElement('small');
            date.className = 'text-muted';
            date.textContent = new Intl.DateTimeFormat('en-GB', {dateStyle:'medium', timeStyle:'short', timeZone:'Africa/Dar_es_Salaam'}).format(new Date(item.created_at.replace(' ','T') + 'Z')) + ' EAT';
            article.appendChild(title); article.appendChild(body); article.appendChild(date);
            list.appendChild(article);
        });
    }
    async function load(markRead) {
        if (busy) return;
        busy = true; button.disabled = true;
        try {
            var options = {cache:'no-store', signal:AbortSignal.timeout(25000)};
            if (markRead) {
                options.method = 'POST';
                var form = new URLSearchParams();
                form.set('action','mark_all_read'); form.set('through_id',String(latestId));
                form.set(panel.dataset.csrfName,panel.dataset.csrf);
                options.body = form;
            }
            var response = await fetch(panel.dataset.endpoint, options);
            var result = await response.json();
            if (!response.ok || !result.success) throw new Error('Notifications unavailable');
            render(result);
        } catch (error) { counter.textContent = 'Unable to refresh. Try again.'; button.disabled = false; }
        finally { busy = false; }
    }
    button.addEventListener('click', function () { load(true); });
    load(false);
    var timer = setInterval(function () {
        if (++polls > 60) { clearInterval(timer); return; }
        if (!document.hidden) load(false);
    },15000);
}());
