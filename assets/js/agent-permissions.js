(() => {
    'use strict';
    const inputs = [...document.querySelectorAll('[data-agent-permission]')];
    const preview = document.getElementById('agent-sidebar-preview');
    if (!preview) return;
    function update(changed) {
        if (changed && changed.checked && changed.dataset.parent) {
            const parent = inputs.find(input => input.value === changed.dataset.parent);
            if (parent) parent.checked = true;
        }
        if (changed && !changed.checked) inputs.filter(input => input.dataset.parent === changed.value).forEach(input => { input.checked = false; });
        const links = inputs.filter(input => input.checked && input.dataset.page).map(input => input.dataset.label);
        preview.textContent = links.length + ' work sidebar link(s): ' + (links.join(', ') || 'none') + '. My Profile, View Site and Logout are always available.';
    }
    inputs.forEach(input => input.addEventListener('change', () => update(input)));
    update();
})();
