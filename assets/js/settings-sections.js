(() => {
    function openSection() {
        const target = document.getElementById(window.location.hash.slice(1));
        const section = target && target.closest('details.settings-section');
        if (section) { section.open = true; target.scrollIntoView({block: 'start'}); }
    }
    window.addEventListener('hashchange', openSection);
    openSection();
})();
