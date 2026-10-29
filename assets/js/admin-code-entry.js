/* One accessible input per code supports paste, autofill and native editing. */
(() => {
    'use strict';
    document.querySelectorAll('[data-code-entry]').forEach(entry => {
        const input = entry.querySelector('input');
        const boxes = Array.from(entry.querySelectorAll('.code-box'));
        const length = Number(entry.dataset.codeLength);
        const form = input.form;
        let submitted = false;
        const render = (submit) => {
            input.value = input.value.replace(/[^0-9]/g, '').slice(0, length);
            boxes.forEach((box, index) => {
                box.textContent = input.value[index] ? (entry.hasAttribute('data-code-secret') ? '•' : input.value[index]) : '';
                box.classList.toggle('filled', index < input.value.length);
                box.classList.toggle('active', index === Math.min(input.value.length, length - 1));
            });
            if (submit && input.value.length === length && !submitted) {
                submitted = true;
                form.requestSubmit();
            }
        };
        if (!form || typeof form.requestSubmit !== 'function') return;
        entry.classList.add('ready');
        const keypad = form.querySelector('[data-code-keypad]');
        if (keypad) {
            input.setAttribute('inputmode', 'none');
            keypad.hidden = false;
            const status = keypad.querySelector('[data-keypad-status]');
            keypad.addEventListener('click', async event => {
                const button = event.target.closest('[data-code-key]');
                if (!button || !keypad.contains(button) || submitted) return;
                const key = button.dataset.codeKey;
                if (/^[0-9]$/.test(key)) input.value += key;
                else if (key === 'backspace') input.value = input.value.slice(0, -1);
                else if (key === 'clear') input.value = '';
                else if (key === 'paste') {
                    try {
                        const value = await navigator.clipboard.readText();
                        if (submitted) return;
                        input.value = value;
                        if (status) status.textContent = '';
                    } catch (_) {
                        if (status) status.textContent = 'Touch and hold the code boxes to paste.';
                        input.focus({ preventScroll: true });
                        return;
                    }
                } else return;
                render(true);
                if (!submitted) input.focus({ preventScroll: true });
            });
        }
        if (input.hasAttribute('data-code-autofocus')) input.focus({ preventScroll: true });
        input.addEventListener('input', () => render(true));
        input.addEventListener('paste', event => {
            const value = event.clipboardData && event.clipboardData.getData('text');
            if (typeof value !== 'string') return;
            event.preventDefault();
            input.value = value;
            render(true);
        });
        input.addEventListener('focus', () => render(false));
        form.addEventListener('submit', () => { submitted = true; });
        render(false);
    });
})();
