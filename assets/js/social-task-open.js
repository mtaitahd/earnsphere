document.querySelectorAll('[data-social-open]').forEach(form => {
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = form.querySelector('button[type="submit"]');
        const message = form.querySelector('[data-open-message]');
        if (button.disabled) return;
        const tab = window.open('about:blank', '_blank');
        if (!tab) { message.textContent = 'Allow popups to open this task.'; return; }
        tab.opener = null;
        const label = button.textContent;
        button.disabled = true;
        button.textContent = 'Opening…';
        message.textContent = '';
        try {
            const response = await fetch(form.action, {method: 'POST', body: new FormData(form), headers: {'Accept':'application/json'}, credentials: 'same-origin', redirect: 'error'});
            const isJson = (response.headers.get('content-type') || '').toLowerCase().includes('application/json');
            if (!isJson) throw new Error(response.status === 403 ? 'Your session or security token has expired. Refresh this page and sign in again if needed.' : 'The server could not open this task. Refresh this page and try again.');
            let result;
            try { result = await response.json(); }
            catch (_) { throw new Error('The server returned an invalid response. Refresh this page and try again.'); }
            if (!result || typeof result !== 'object') throw new Error('The server returned an invalid response. Refresh this page and try again.');
            if (!response.ok || !result.success) throw new Error(result.message || 'Please refresh and check this task.');
            let destination;
            try { destination = new URL(result.url); }
            catch (_) { throw new Error('This task did not return a valid video link. Please contact support.'); }
            if (destination.protocol !== 'https:') throw new Error('This task did not return a secure video link. Please contact support.');
            tab.location.replace(destination.href);
            button.textContent = 'Opened';
        } catch (error) {
            tab.close();
            button.textContent = label;
            button.disabled = false;
            message.textContent = error instanceof TypeError ? 'The request was interrupted or redirected. Refresh this page, sign in if needed, and try again.' : (error.message || 'Please refresh and check this task.');
        }
    });
});
