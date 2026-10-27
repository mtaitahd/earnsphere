(() => {
    'use strict';
    const dialog = document.getElementById('welcomeMediaDialog');
    if (!dialog) return;
    const guides = Array.from(dialog.querySelectorAll('[data-guide]'));
    const stop = guide => {
        guide.querySelectorAll('audio, video').forEach(media => media.pause());
        guide.querySelectorAll('iframe').forEach(frame => frame.removeAttribute('src'));
    };
    const load = guide => {
        guide.querySelectorAll('iframe').forEach(frame => {
            if (!frame.getAttribute('src')) frame.src = frame.dataset.welcomeSrc;
        });
    };
    document.querySelectorAll('[data-welcome-open]').forEach(button => {
        button.addEventListener('click', () => {
            if (!dialog.open) dialog.showModal();
            guides.filter(guide => guide.open).forEach(load);
        });
    });
    guides.forEach(guide => {
        guide.addEventListener('toggle', () => {
            if (!guide.open || !dialog.open) { stop(guide); return; }
            guides.filter(other => other !== guide).forEach(other => { other.open = false; stop(other); });
            load(guide);
        });
        guide.querySelector('[data-welcome-retry]')?.addEventListener('click', () => {
            const frame = guide.querySelector('iframe');
            if (!frame || !dialog.open) return;
            const replacement = frame.cloneNode(false);
            const alternate = frame.dataset.welcomeAlternate;
            const next = alternate && frame.getAttribute('src') !== alternate ? alternate : frame.dataset.welcomeSrc;
            replacement.setAttribute('src', next);
            frame.replaceWith(replacement);
            const status = guide.querySelector('[data-welcome-status]');
            if (status) status.textContent = 'Video inapakiwa upya hapa. Subiri kisha bonyeza Play.';
        });
        guide.querySelectorAll('audio, video').forEach(media => {
            media.addEventListener('play', () => {
                guides.filter(other => other !== guide).forEach(stop);
            });
        });
    });
    dialog.querySelector('[data-welcome-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => {
        const rect = dialog.getBoundingClientRect();
        if (event.target === dialog && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) dialog.close();
    });
    dialog.addEventListener('close', () => guides.forEach(stop));
})();
