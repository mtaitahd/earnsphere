(() => {
    'use strict';
    const dialog = document.getElementById('supportModal');
    if (!dialog || !dialog.dataset.endpoint) return;
    const endpoint = dialog.dataset.endpoint;
    const token = dialog.dataset.csrf;
    const form = dialog.querySelector('[data-support-form]');
    const list = dialog.querySelector('[data-support-tickets]');
    const status = dialog.querySelector('[data-support-status]');
    let tickets = [];
    const node = (tag, text, className) => {
        const element = document.createElement(tag);
        if (text !== undefined) element.textContent = String(text);
        if (className) element.className = className;
        return element;
    };
    function updateBadge(count) {
        [document.getElementById('supportBadge'), document.querySelector('[data-help-unread]')].filter(Boolean).forEach(badge => {
            badge.textContent = count > 99 ? '99+' : String(count);
            badge.hidden = count <= 0;
            badge.style.display = count > 0 ? 'inline-flex' : 'none';
        });
    }
    async function request(action, fields) {
        const options = {headers: {'X-Requested-With':'XMLHttpRequest'}};
        if (fields) {
            options.method = 'POST';
            options.headers['X-CSRF-Token'] = token;
            options.body = new URLSearchParams(fields);
        }
        const response = await fetch(endpoint + '?action=' + action, options);
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.error || result.message || 'Could not complete your request.');
        return result;
    }
    function render() {
        list.replaceChildren();
        if (!tickets.length) { list.appendChild(node('p','No help requests yet. Send a message above.')); return; }
        tickets.forEach(ticket => {
            const article = node('article',undefined,'account-support-ticket');
            article.appendChild(node('h4',ticket.subject || 'Help Request'));
            article.appendChild(node('small',String(ticket.status || 'open') + ' · ' + String(ticket.created_at || '')));
            article.appendChild(node('p',ticket.message || ''));
            if (ticket.has_reply && ticket.admin_reply) {
                const reply = node('div',undefined,'account-support-reply');
                reply.appendChild(node('strong','EarnSphere Support'));
                reply.appendChild(node('p',ticket.admin_reply));
                if (ticket.unread) {
                    const read = node('button','Mark reply as read','account-support-read');
                    read.type = 'button';
                    read.addEventListener('click',async () => {
                        read.disabled = true;
                        try { await request('mark_read',{id:String(ticket.id)}); await loadTickets(); }
                        catch(error) { status.textContent = error.message; read.disabled = false; }
                    });
                    reply.appendChild(read);
                }
                article.appendChild(reply);
            }
            list.appendChild(article);
        });
    }
    async function loadTickets(renderList = true) {
        try {
            const result = await request('fetch');
            tickets = Array.isArray(result.data) ? result.data : [];
            updateBadge(Number(result.unread) || 0);
            if (renderList) render();
        } catch (error) {
            if (renderList) list.replaceChildren(node('p',error.message));
        }
    }
    window.openSupport = () => {
        if (!dialog.open) dialog.showModal();
        list.replaceChildren(node('p','Loading your requests...'));
        loadTickets();
    };
    window.closeSupportModal = () => dialog.close();
    window.updateSupportBadge = updateBadge;
    document.querySelector('[data-account-help]')?.addEventListener('click',window.openSupport);
    dialog.querySelector('[data-support-close]').addEventListener('click',window.closeSupportModal);
    dialog.addEventListener('click',event => {
        const rect = dialog.getBoundingClientRect();
        if (event.target === dialog && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) dialog.close();
    });
    form.addEventListener('submit',async event => {
        event.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        if (button.disabled) return;
        button.disabled = true;
        status.textContent = 'Sending your message...';
        try {
            await request('create',{subject:form.elements.subject.value.trim(),message:form.elements.message.value.trim()});
            form.reset();
            status.textContent = 'Ujumbe umetumwa. Jibu la support litaonekana hapa.';
            await loadTickets();
        } catch (error) { status.textContent = error.message; }
        finally { button.disabled = false; }
    });
    loadTickets(false);
})();
