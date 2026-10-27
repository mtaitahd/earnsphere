(() => {
    'use strict';
    const dialog = document.getElementById('referral-members-dialog');
    if (!dialog) return;
    const title = dialog.querySelector('[data-members-title]');
    const body = dialog.querySelector('[data-members-body]');
    const status = dialog.querySelector('[data-members-status]');
    const pages = dialog.querySelector('[data-members-pages]');
    const triggers = document.querySelectorAll('[data-referral-level]');
    let selection = null, controller = null, revision = 0;

    function element(tag, className, text) {
        const node = document.createElement(tag);
        node.className = className;
        if (text !== undefined) node.textContent = String(text ?? '');
        return node;
    }

    async function load(page = 1) {
        if (!selection || !dialog.open) return;
        if (controller) controller.abort();
        controller = new AbortController();
        const current = ++revision;
        status.textContent = 'Inapakia watu…';
        body.replaceChildren(); pages.replaceChildren();
        body.setAttribute('aria-busy', 'true');
        const url = new URL(dialog.dataset.endpoint, window.location.href);
        url.searchParams.set('code', selection.code);
        url.searchParams.set('level', selection.level);
        url.searchParams.set('page', page);
        try {
            const response = await fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
            const result = await response.json();
            if (current !== revision || !dialog.open) return;
            if (!response.ok || !result.success || !Array.isArray(result.data?.items)) throw new Error('Members unavailable');
            const data = result.data;
            status.textContent = data.total > 0 ? 'Watu ' + data.total + ' · Level ' + selection.level : 'Hakuna watu kwenye Level ' + selection.level + ' kwa sasa.';
            data.items.forEach(person => {
                const article = element('article', 'referral-member');
                const heading = element('div', 'referral-member-heading');
                const link = element('a', 'fw-bold', person.full_name);
                const destination = new URL(dialog.dataset.lookupUrl, window.location.href);
                destination.searchParams.set('code', person.referral_code);
                destination.searchParams.set('relative', selection.code);
                link.href = destination.toString();
                const badge = element('span', 'badge ' + (person.status === 'active' ? 'bg-success' : 'bg-secondary'), person.status);
                heading.append(link, badge);
                article.append(heading,
                    element('p', 'small mb-1', 'Code: ' + (person.referral_code ?? '') + ' · ' + (person.phone ?? '')),
                    element('p', 'small mb-1', 'Aliunganishwa na: ' + (person.inviter_name ?? '') + ' · ' + (person.inviter_code ?? '')),
                    element('p', 'small text-muted mb-0', 'Joined: ' + (person.created_at ?? '—')));
                body.appendChild(article);
            });
            if (data.pages > 1) {
                if (data.page > 1) addPageButton('Previous', data.page - 1);
                pages.appendChild(element('span', 'small', 'Page ' + data.page + ' / ' + data.pages));
                if (data.page < data.pages) addPageButton('Next', data.page + 1);
            }
        } catch (error) {
            if (current !== revision || !dialog.open || error.name === 'AbortError') return;
            status.textContent = 'Orodha haijafunguka. Jaribu tena; ikiwa session imeisha, ingia upya.';
            addPageButton('Jaribu tena', page);
        } finally {
            if (current === revision) body.setAttribute('aria-busy', 'false');
        }
    }

    function addPageButton(label, page) {
        const button = element('button', 'btn btn-outline-primary', label);
        button.type = 'button';
        button.addEventListener('click', () => load(page));
        pages.appendChild(button);
    }

    triggers.forEach(trigger => trigger.addEventListener('click', () => {
        selection = { code: trigger.dataset.referralCode, level: trigger.dataset.referralLevel };
        title.textContent = 'Level ' + selection.level + ' · ' + trigger.dataset.referralName;
        triggers.forEach(button => button.setAttribute('aria-expanded', String(button === trigger)));
        if (!dialog.open) dialog.showModal();
        load();
    }));
    dialog.querySelector('[data-members-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    dialog.addEventListener('close', () => {
        revision++;
        if (controller) controller.abort();
        body.replaceChildren(); pages.replaceChildren(); status.textContent = '';
        triggers.forEach(button => button.setAttribute('aria-expanded', 'false'));
        selection = null;
    });
})();
