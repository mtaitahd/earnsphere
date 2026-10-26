(() => {
    'use strict';
    const dialog = document.getElementById('welcomeMediaDialog');
    if (!dialog) return;
    const video = dialog.querySelector('iframe');
    const audio = dialog.querySelector('audio');
    document.querySelectorAll('[data-welcome-open]').forEach(button => {
        button.addEventListener('click', () => {
            if (video && !video.getAttribute('src')) video.src = video.dataset.welcomeSrc;
            if (!dialog.open) dialog.showModal();
        });
    });
    dialog.querySelector('[data-welcome-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => {
        const rect = dialog.getBoundingClientRect();
        if (event.target === dialog && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) dialog.close();
    });
    dialog.addEventListener('close', () => {
        if (audio) audio.pause();
        if (video) video.removeAttribute('src');
    });
})();
