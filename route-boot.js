// Select the URL's section before the browser reaches the portal markup.
// This prevents the default landing section from flashing during startup.
(() => {
    const body = document.body;
    const adminSections = ['overview', 'requests', 'residents', 'disclosures', 'activity'];
    const sections = [
        'landing', 'bulletin', 'login', 'admin-login', 'forgot-password', 'register',
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
    body.classList.toggle('public-portal-view', [
        'landing', 'bulletin', 'login', 'admin-login', 'forgot-password', 'register',
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
})();
