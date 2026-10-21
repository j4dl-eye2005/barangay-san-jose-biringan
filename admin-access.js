(() => {
    const form = document.getElementById('admin-access-form');
    const keyField = document.getElementById('admin-entry-key');
    const submitButton = document.getElementById('admin-access-submit');
    const visibilityToggle = document.getElementById('access-key-toggle');
    const errorMessage = document.getElementById('admin-entry-error');

    if (!form || !keyField || !submitButton || !visibilityToggle) return;

    const updateSubmitState = () => {
        submitButton.disabled = keyField.value.trim().length === 0;
    };

    keyField.addEventListener('input', () => {
        if (keyField.value.length > 0 && errorMessage && !errorMessage.hidden) {
            errorMessage.hidden = true;
            keyField.removeAttribute('aria-invalid');
        }
        updateSubmitState();
    });

    visibilityToggle.addEventListener('click', () => {
        const reveal = keyField.type === 'password';
        keyField.type = reveal ? 'text' : 'password';
        visibilityToggle.setAttribute('aria-pressed', reveal ? 'true' : 'false');
        visibilityToggle.setAttribute('aria-label', reveal ? 'Hide access key' : 'Show access key');
        const showIcon = visibilityToggle.querySelector('.eye-icon');
        const hideIcon = visibilityToggle.querySelector('.eye-off-icon');
        if (showIcon) showIcon.hidden = reveal;
        if (hideIcon) hideIcon.hidden = !reveal;
        keyField.focus({ preventScroll: true });
    });

    form.addEventListener('submit', () => {
        if (!keyField.value.trim()) return;
        submitButton.classList.add('is-loading');
        submitButton.setAttribute('aria-busy', 'true');
        submitButton.disabled = true;
    });

    const navToggle = document.getElementById('nav-toggle');
    const navMenu = document.getElementById('nav-menu');
    const navBackdrop = document.getElementById('nav-backdrop');
    const footerYear = document.getElementById('footer-year');
    const portalDate = document.getElementById('portal-current-date');
    const languageSelect = document.getElementById('portal-language-select');
    const mainNav = document.getElementById('main-nav');
    if (footerYear) footerYear.textContent = String(new Date().getFullYear());
    const updatePortalDate = () => {
        if (!portalDate) return;
        const locale = languageSelect?.value === 'tl' ? 'fil-PH' : 'en-PH';
        portalDate.textContent = new Intl.DateTimeFormat(locale, {
            weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
        }).format(new Date());
    };
    updatePortalDate();
    languageSelect?.addEventListener('change', updatePortalDate);
    if (mainNav) {
        const updateStickyHeader = () => mainNav.classList.toggle('is-scrolled', window.scrollY > 0);
        updateStickyHeader();
        window.addEventListener('scroll', updateStickyHeader, { passive: true });
    }
    let menuCloseTimer = null;

    const closeMenu = () => {
        if (!navToggle || !navMenu) return;
        const wasOpen = navMenu.classList.contains('open');
        window.clearTimeout(menuCloseTimer);
        navMenu.classList.remove('open');
        navMenu.classList.toggle('closing', wasOpen);
        navToggle.setAttribute('aria-expanded', 'false');
        if (navBackdrop) {
            navBackdrop.classList.remove('open');
            navBackdrop.classList.toggle('closing', wasOpen);
        }
        document.body.classList.remove('menu-open');
        if (wasOpen) {
            menuCloseTimer = window.setTimeout(() => {
                navMenu.classList.remove('closing');
                navBackdrop?.classList.remove('closing');
                if (navBackdrop) navBackdrop.hidden = true;
            }, 180);
        } else if (navBackdrop) navBackdrop.hidden = true;
    };

    navToggle?.addEventListener('click', () => {
        if (!navMenu) return;
        const open = !navMenu.classList.contains('open');
        window.clearTimeout(menuCloseTimer);
        navMenu.classList.remove('closing');
        navMenu.classList.toggle('open', open);
        navToggle.setAttribute('aria-expanded', String(open));
        if (navBackdrop) {
            navBackdrop.classList.remove('closing');
            navBackdrop.classList.toggle('open', open);
            navBackdrop.hidden = !open;
        }
        document.body.classList.toggle('menu-open', open);
    });

    navBackdrop?.addEventListener('click', closeMenu);
    navMenu?.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            closeMenu();
            navToggle?.focus();
        }
    });
    navMenu?.addEventListener('click', event => {
        if (navMenu.classList.contains('open') && event.target.closest('a, button')) closeMenu();
    });

    document.addEventListener('click', event => {
        const control = event.target instanceof Element ? event.target.closest('[data-action-click]') : null;
        if (!control) return;
        const action = control.dataset.actionClick || '';
        if (action.startsWith('showSection(')) {
            const section = action.match(/^showSection\(['"]([a-z-]+)['"]\)/)?.[1];
            if (!section) return;
            event.preventDefault();
            const destination = new URL(form.action, window.location.href);
            destination.search = '';
            destination.hash = section;
            window.location.assign(destination.href);
            return;
        }
        if (action === 'toggleMobileMenu()') event.preventDefault();
    }, true);

    updateSubmitState();
})();
