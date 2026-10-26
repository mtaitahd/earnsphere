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
            const response = await fetch(form.action, {method: 'POST', body: new FormData(form), headers: {'Accept':'application/json'}});
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Please refresh and check this task.');
            tab.location.replace(result.url);
            button.textContent = 'Opened';
        } catch (error) {
            tab.close();
            button.textContent = label;
            button.disabled = false;
            message.textContent = error.message || 'Please refresh and check this task.';
        }
    });
});
