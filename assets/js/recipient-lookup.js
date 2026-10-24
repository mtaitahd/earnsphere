(() => {
    'use strict';
    document.querySelectorAll('[data-recipient-lookup]').forEach(input => {
        const form = input.closest('form');
        if (!form) return;
        const panel = document.createElement('div');
        panel.className = 'alert alert-light mt-2 mb-3';
        panel.setAttribute('aria-live', 'polite');
        const message = document.createElement('span');
        const retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'btn btn-sm btn-outline-primary ms-2';
        retry.textContent = 'Verify number';
        panel.append(message, retry);
        input.closest('.form-floating, .input-group')?.insertAdjacentElement('afterend', panel)
            || input.insertAdjacentElement('afterend', panel);
        let confirmation;
        if (input.dataset.requireConfirmation) {
            const label = document.createElement('label');
            label.className = 'd-block mt-2';
            confirmation = document.createElement('input');
            confirmation.type = 'checkbox';
            confirmation.name = 'confirm_recipient';
            confirmation.value = '1';
            confirmation.required = true;
            label.append(confirmation, document.createTextNode(' I confirm this is the correct recipient.'));
            panel.append(label);
        }
        let timer, generation = 0, verified = '';
        const normalized = () => {
            let phone = input.value.replace(/[\s()+-]/g, '');
            if (/^0[67]\d{8}$/.test(phone)) phone = '255' + phone.slice(1);
            if (/^[67]\d{8}$/.test(phone)) phone = '255' + phone;
            return /^255[67]\d{8}$/.test(phone) ? phone : '';
        };
        const reset = () => {
            generation++;
            verified = '';
            if (confirmation) { confirmation.checked = false; confirmation.disabled = true; }
            input.setCustomValidity('Verify the registered name before continuing.');
            message.textContent = 'Enter a mobile money number to check its registered name and network.';
        };
        async function lookup() {
            const phone = normalized();
            if (!phone) { message.textContent = 'Enter a valid Tanzania mobile money number.'; return; }
            const current = ++generation;
            verified = '';
            input.setCustomValidity('Please wait for number verification.');
            message.textContent = 'Checking registered name…';
            const body = new URLSearchParams({ phone });
            body.set(input.dataset.csrfName, input.dataset.csrf);
            try {
                const response = await fetch(input.dataset.lookupUrl, { method: 'POST', body, credentials: 'same-origin' });
                const data = await response.json();
                if (current !== generation || phone !== normalized()) return;
                if (!response.ok || !data.success) throw new Error(data.error || 'Name verification is unavailable.');
                verified = phone;
                message.textContent = data.name + ' · ' + data.network;
                input.setCustomValidity('');
                if (confirmation) confirmation.disabled = false;
            } catch (error) {
                if (current !== generation) return;
                verified = '';
                message.textContent = error.message || 'Could not verify this number. Try again.';
                input.setCustomValidity('Verify this number before continuing.');
                if (confirmation) { confirmation.checked = false; confirmation.disabled = true; }
            }
        }
        input.addEventListener('input', () => { reset(); clearTimeout(timer); if (normalized()) timer = setTimeout(lookup, 700); });
        retry.addEventListener('click', () => { clearTimeout(timer); reset(); lookup(); });
        form.addEventListener('submit', event => {
            if (!verified || verified !== normalized() || (confirmation && !confirmation.checked)) {
                event.preventDefault(); event.stopImmediatePropagation(); input.reportValidity();
            }
        }, true);
        reset();
        if (normalized()) lookup();
    });
})();
