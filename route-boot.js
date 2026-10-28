// Select the URL's section before the browser reaches the portal markup.
// This prevents the default landing section from flashing during startup.
(() => {
    const body = document.body;
    const routeCssPromises = new Map();
    const routeCssLoaded = new Set();
    const authRoutes = new Set([
        'login', 'admin-login', 'forgot-password', 'register', 'resident-dashboard',
        'resident-profile', 'resident-change-email', 'resident-change-password'
    ]);

    function routeCssGroupsFor(route) {
        const groups = [];
        const role = body.dataset.accountRole || 'Guest';
        if (authRoutes.has(route)) groups.push('auth');
        if (route === 'admin-dashboard' || (role === 'Admin' && ['bulletin', 'resident-profile'].includes(route))) groups.push('admin');
        if (route === 'page-unavailable') groups.push('unavailable');
        return groups;
    }

    function loadRouteCssGroup(group) {
        if (routeCssLoaded.has(group)) return Promise.resolve();
        if (routeCssPromises.has(group)) return routeCssPromises.get(group);
        const placeholders = [...document.querySelectorAll(`link[data-portal-route-css="${group}"]`)];
        if (!placeholders.length) {
            routeCssLoaded.add(group);
            return Promise.resolve();
        }
        const pending = Promise.all(placeholders.map(placeholder => new Promise(resolve => {
            const link = document.createElement('link');
            let finished = false;
            let timeoutId;
            const finish = () => {
                if (finished) return;
                finished = true;
                if (timeoutId) window.clearTimeout(timeoutId);
                resolve();
            };
            link.rel = 'stylesheet';
            link.href = placeholder.dataset.portalCssHref;
            link.addEventListener('load', finish, { once: true });
            link.addEventListener('error', finish, { once: true });
            // Keep route styles at their placeholder position in the cascade.
            // Appending them to the end lets admin-responsive.css override the
            // shared portal header and footer rules that intentionally follow it.
            placeholder.parentNode.insertBefore(link, placeholder);
            placeholder.remove();
            timeoutId = window.setTimeout(finish, 5000);
        }))).then(() => {
            routeCssLoaded.add(group);
        });
        routeCssPromises.set(group, pending);
        return pending;
    }

    window.ensurePortalRouteStyles = route => Promise.all(routeCssGroupsFor(route).map(loadRouteCssGroup));
    window.portalRouteStylesAreReady = route => routeCssGroupsFor(route).every(group => routeCssLoaded.has(group));
    const adminSections = ['overview', 'requests', 'residents', 'disclosures', 'activity'];
    const sections = [
        'landing', 'bulletin', 'record', 'login', 'admin-login', 'forgot-password', 'register',
        'resident-dashboard', 'resident-profile', 'resident-change-email', 'resident-change-password',
        'admin-dashboard', 'privacy-policy', 'terms', 'moderation', 'copyright', 'page-unavailable'
    ];

    const rawHash = location.hash.slice(1);
    let decodedHash = rawHash;
    try { decodedHash = decodeURIComponent(rawHash); } catch { /* An invalid URL fragment gets the common unavailable screen. */ }
    const [requested, query = ''] = decodedHash.split('?', 2);
    const fragmentParams = new URLSearchParams(query);
    if (requested.startsWith('admin-') && adminSections.includes(requested.slice(6))) {
        body.dataset.initialAdminSection = requested.slice(6);
    }

    let route = '';
    if (body.dataset.entryRoute === 'verification') {
        route = 'login';
    } else if (body.dataset.entryRoute === 'password-reset') {
        route = 'forgot-password';
    } else {
        const hasResetToken = fragmentParams.has('token');
        const resetRoute = requested === 'password-reset' || requested === 'forgot-password';
        const resetTokenValid = hasResetToken && /^[a-f0-9]{64}$/i.test(fragmentParams.get('token') || '');
        const hasInvalidResetToken = resetRoute && (requested === 'password-reset' ? !resetTokenValid : hasResetToken && !resetTokenValid);
        const requestedAdminRoute = requested === 'admin-dashboard' || (requested.startsWith('admin-') && adminSections.includes(requested.slice(6)));
        if (body.dataset.initialAdminSection && !['resident-profile', 'resident-change-email', 'resident-change-password'].includes(requested)) {
            route = 'admin-dashboard';
        } else if (rawHash && (hasInvalidResetToken || (!requested || (!sections.includes(requested) && requested !== 'password-reset' && !requestedAdminRoute)))) {
            route = 'page-unavailable';
        } else if (requestedAdminRoute && body.dataset.adminEntryAuthorized === '0') {
            route = 'page-unavailable';
        } else if (requested === 'password-reset' && resetTokenValid) {
            route = 'forgot-password';
        } else if (requested.startsWith('admin-') && adminSections.includes(requested.slice(6))) {
            route = 'admin-dashboard';
        } else if (sections.includes(requested)) {
            route = requested;
        }
    }

    body.dataset.routeStart = route || 'landing';
    if (['landing', 'login', 'admin-login', 'forgot-password', 'register'].includes(body.dataset.routeStart)) {
        const heroImagePreload = document.createElement('link');
        heroImagePreload.rel = 'preload';
        heroImagePreload.as = 'image';
        heroImagePreload.href = new URL('assets/barangay-hall-background.webp', document.baseURI).href;
        heroImagePreload.type = 'image/webp';
        heroImagePreload.fetchPriority = 'high';
        document.head.appendChild(heroImagePreload);
    }
    body.classList.toggle('public-portal-view', [
        'landing', 'bulletin', 'record', 'login', 'admin-login', 'forgot-password', 'register',
        'resident-dashboard', 'resident-change-email', 'resident-change-password',
        'privacy-policy', 'terms', 'moderation', 'copyright', 'page-unavailable'
    ].includes(body.dataset.routeStart));
    const unavailableView = route === 'page-unavailable' || (route === 'admin-login' && body.dataset.adminEntryAuthorized === '0');
    body.dataset.appTitle = document.title;
    if (unavailableView) {
        body.dataset.unavailableAddress = location.href;
        document.title = 'Page unavailable';
    }
    body.classList.toggle('unavailable-view', unavailableView);
    document.documentElement.classList.toggle('hide-unavailable-scrollbar', unavailableView);
    if (!window.portalRouteStylesAreReady(body.dataset.routeStart)) {
        body.dataset.routeStylesPending = '1';
        void window.ensurePortalRouteStyles(body.dataset.routeStart).finally(() => {
            delete body.dataset.routeStylesPending;
        });
    }
})();
