(() => {
    'use strict';
    const menu = document.getElementById('accountMenu');
    if (menu) {
        document.querySelector('[data-account-menu]')?.addEventListener('click', () => menu.showModal());
        menu.querySelector('[data-account-close]').addEventListener('click', () => menu.close());
        menu.addEventListener('click', event => {
            const rect = menu.getBoundingClientRect();
            if (event.target === menu && (event.clientX > rect.right || event.clientX < rect.left || event.clientY < rect.top || event.clientY > rect.bottom)) menu.close();
        });
        menu.querySelector('[data-menu-guide]').addEventListener('click', () => {
            menu.close();
            document.querySelector('[data-welcome-open]')?.click();
        });
        menu.querySelector('[data-menu-support]').addEventListener('click', () => {
            menu.close();
            if (typeof openSupport === 'function') openSupport();
        });
    }
    const toggle = document.querySelector('[data-balance-toggle]');
    toggle?.addEventListener('click', () => {
        const hidden = toggle.getAttribute('aria-pressed') !== 'true';
        document.querySelector('[data-account-balance]').hidden = hidden;
        document.querySelector('[data-account-masked]').hidden = !hidden;
        toggle.setAttribute('aria-pressed', String(hidden));
        toggle.setAttribute('aria-label', hidden ? 'Show balance' : 'Hide balance');
        toggle.querySelector('i').className = hidden ? 'fas fa-eye' : 'fas fa-eye-slash';
    });
})();
