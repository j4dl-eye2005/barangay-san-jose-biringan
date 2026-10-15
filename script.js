// @ts-nocheck
const recaptchaPending = Object.create(null);

function finishRecaptcha(action, error, token = '') {
    const pending = recaptchaPending[action];
    if (!pending) return;
    clearTimeout(pending.timeoutId);
    delete recaptchaPending[action];
    if (error) pending.reject(error);
    else pending.resolve(token);
}

function renderRecaptchaWidget(action) {
    const widgetKey = action === 'login' ? '_recaptchaLoginWidgetId' : '_recaptchaRegisterWidgetId';
    const elementId = action === 'login' ? 'recaptcha-login' : 'recaptcha-register';
    if (window[widgetKey] !== undefined) return window[widgetKey];
    const siteKey = document.querySelector('meta[name="recaptcha-site-key"]')?.content || '';
    const element = document.getElementById(elementId);
    if (!siteKey || !element || !window.grecaptcha?.render) return undefined;

    window[widgetKey] = grecaptcha.render(element, {
        sitekey: siteKey,
        size: 'invisible',
        badge: 'bottomright',
        callback: token => finishRecaptcha(action, null, token),
        'expired-callback': () => finishRecaptcha(action, new Error('The security check expired. Please try again.')),
        'error-callback': () => finishRecaptcha(action, new Error('The security check failed to load. Check your connection and try again.'))
    });
    return window[widgetKey];
}

function executeRecaptcha(action) {
    if (document.querySelector('meta[name="recaptcha-local-bypass"]')?.content === 'true') {
        return Promise.resolve('');
    }
    const widgetId = renderRecaptchaWidget(action);
    return new Promise((resolve, reject) => {
        if (!window.grecaptcha || widgetId === undefined) {
            reject(new Error('The security check is still loading. Please try again in a moment.'));
            return;
        }
        const timeoutId = setTimeout(() => {
            finishRecaptcha(action, new Error('The security check timed out. Please try again.'));
        }, 120000);
        recaptchaPending[action] = { resolve, reject, timeoutId };
        try {
            grecaptcha.reset(widgetId);
            grecaptcha.execute(widgetId);
        } catch (error) {
            finishRecaptcha(action, new Error('The security check could not start. Please try again.'));
        }
    });
}

window.onRecaptchaLoad = function onRecaptchaLoad() {
    // Render only the current auth page's widget. Rendering a second widget in
    // a hidden section can make mobile browsers scroll to that off-screen form.
    const route = document.body.dataset.routeStart;
    const activeSection = document.querySelector('.section.active')?.id;
    const currentSection = route || activeSection;
    if (currentSection === 'login' || currentSection === 'register') {
        renderRecaptchaWidget(currentSection);
    }
};

let currentUser = null;
let editingDisclosureId = null;
let editingDisclosureAttachment = null;
let disclosureSavedSnapshot = '';
let disclosureFileObjectUrl = null;
let adminRequestRows = [];
let adminRequestStatusFilter = 'All';
let adminRequestPage = 1;
let adminRequestPages = 0;
let adminRequestTotal = 0;
let adminRequestStatusCounts = {};
let adminRequestSearchTimer = null;
let pendingResidentPage = 1;
let pendingResidentPages = 0;
let pendingBulletinDelete = null;
let bulletinDeleteReturnFocus = null;
let publicDisclosuresLoaded = false;
let registerSubmitting = false;
let toastHideTimer = null;
let toastExitTimer = null;
const adminPanelLoadedAt = new Map();
const ADMIN_PANEL_CACHE_MS = 30000;
const resetFragmentParams = new URLSearchParams(window.location.hash.split('?')[1] || '');
const resetFragmentRoute = window.location.hash.slice(1).split('?')[0];
let passwordResetToken = ['password-reset', 'forgot-password'].includes(resetFragmentRoute) ? (resetFragmentParams.get('token') || '') : '';
let passwordResetContext = resetFragmentParams.get('context') === 'admin' ? 'admin' : 'resident';
const emailVerificationToken = () => document.querySelector('meta[name="email-verify-token"]')?.content || '';
const appDocumentTitle = document.body?.dataset.appTitle || document.title;

window.INITIAL_ADMIN_SECTION = document.body?.dataset.initialAdminSection || null;
const delegatedActionNames = new Set([
    'activateDisclosureCategory', 'changePickupCalendarMonth', 'checkAuthAndRedirect',
    'clearActivityFilters', 'clearDisclosureFile', 'closeAdminRequestDetail', 'closeMobileMenu',
    'closeBulletinDeleteDialog', 'closeModal', 'confirmBulletinDelete', 'exportActivityCsv',
    'focusContactInfo', 'handleActivityFilterChange', 'handleAdminLogin',
    'handleDisclosureCardKeydown', 'handleLogin', 'handlePickupDateKeydown',
    'handlePostDisclosure', 'handleRegister', 'handleRequest', 'filterAdminRequests', 'loadAdminActivity', 'logout',
    'openDashboard', 'openDisclosureCategory', 'openDisclosureComposer', 'openForgotPassword',
    'openOverviewDestination', 'openRegisterPolicy', 'regClearFile', 'requestDoc',
    'requestPasswordReset', 'returnFromPasswordReset', 'scrollToId',
    'selectResidentRequestTab', 'setActivityPreset', 'setAdminRequestFilter', 'showSection',
    'sortActivity', 'submitModal', 'switchAdminTab', 'toggleActivityExtraFilters',
    'toggleMobileMenu', 'togglePassword', 'togglePickupCalendar', 'updatePickupReadback',
    'openFilePicker', 'scrollPageTop'
]);

function splitActionArguments(source) {
    const values = [];
    let start = 0, depth = 0, quote = '', escaped = false;
    for (let index = 0; index < source.length; index += 1) {
        const char = source[index];
        if (quote) {
            if (escaped) escaped = false;
            else if (char === '\\') escaped = true;
            else if (char === quote) quote = '';
        } else if (char === '"' || char === "'") quote = char;
        else if ('([{'.includes(char)) depth += 1;
        else if (')]}'.includes(char)) depth -= 1;
        else if (char === ',' && depth === 0) {
            values.push(source.slice(start, index).trim());
            start = index + 1;
        }
    }
    if (source.slice(start).trim()) values.push(source.slice(start).trim());
    return values;
}

function parseActionArgument(source, element, event) {
    if (source === 'this') return element;
    if (source === 'event') return event;
    if (/^\d+$/.test(source)) return Number(source);
    const pageOffset = source.match(/^activityCurrentPage\s*([+-])\s*(\d+)$/);
    if (pageOffset) return (Number(activityCurrentPage) || 1) + (pageOffset[1] === '+' ? 1 : -1) * Number(pageOffset[2]);
    if (/^(['"]).*\1$/s.test(source)) {
        if (source[0] === '"') {
            try { return JSON.parse(source); } catch { return ''; }
        }
        return source.slice(1, -1).replace(/\\'/g, "'").replace(/\\\\/g, '\\').replace(/\\n/g, '\n');
    }
    return undefined;
}

function runDelegatedAction(element, type, event) {
    const statements = (element.getAttribute(`data-action-${type}`) || '').split(';').map(part => part.trim()).filter(Boolean);
    let cancelDefault = false;
    for (const statement of statements) {
        if (statement === 'return false') { cancelDefault = true; continue; }
        if (statement.startsWith('window.scrollTo(')) { scrollPageTop(); continue; }
        const match = statement.match(/^([A-Za-z_$][\w$]*)\((.*)\)$/s);
        if (!match || !delegatedActionNames.has(match[1])) continue;
        const args = splitActionArguments(match[2]).map(arg => parseActionArgument(arg, element, event));
        if (window[match[1]](...args) === false) cancelDefault = true;
    }
    if (cancelDefault) event.preventDefault();
}

function openFilePicker(id) {
    document.getElementById(id)?.click();
}

function scrollPageTop() {
    window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
}

['click', 'change', 'input', 'submit', 'keydown', 'keyup'].forEach(type => {
    document.addEventListener(type, event => {
        const element = event.target instanceof Element ? event.target.closest(`[data-action-${type}]`) : null;
        if (element) runDelegatedAction(element, type, event);
    }, true);
});

/** Scroll smoothly to an element by id, accounting for the sticky nav */
function scrollToId(id) {
    const el = document.getElementById(id);
    if (!el) return;
    const policy = el.closest('.policy-section');
    const legalPageIds = ['privacy-policy', 'terms', 'moderation', 'copyright'];
    const isMobileLegalPage = window.matchMedia('(max-width: 720px)').matches && legalPageIds.includes(policy?.id);
    const topbar = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--topbar-height')) || 56;
    const railHeight = isMobileLegalPage ? policy.querySelector('.policy-toc')?.getBoundingClientRect().height || 0 : 28;
    const offset = el.getBoundingClientRect().top + window.scrollY - topbar - railHeight - 12;
    const reduceMotion = isMobileLegalPage && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({ top: offset, behavior: reduceMotion ? 'auto' : 'smooth' });
}

/* ============================================================
   POLICY PAGE: progress bar + active TOC tracking
   ============================================================ */
(function () {
    let currentPolicySection = null;
    let rafId = null;

    function onScroll() {
        if (rafId) return;
        rafId = requestAnimationFrame(() => {
            rafId = null;
            updatePolicy();
        });
    }

    function updatePolicy() {
        const active = document.querySelector('.section.active');
        if (!active || !active.classList.contains('policy-section')) {
            currentPolicySection = null;
            return;
        }

        // Progress bar
        const progressFill = active.querySelector('.policy-progress-fill');
        if (progressFill) {
            const scrollTop = window.scrollY;
            const docH = document.documentElement.scrollHeight - window.innerHeight;
            progressFill.style.width = docH > 0 ? Math.min(100, (scrollTop / docH) * 100) + '%' : '0%';
        }

        // Active TOC — whichever heading is closest to (but above) 40% down the viewport
        const headings = Array.from(active.querySelectorAll('.policy-page h2[id]'));
        const threshold = window.innerHeight * 0.4;
        let activeId = headings.length ? headings[0].id : null;
        for (const h of headings) {
            if (h.getBoundingClientRect().top < threshold) activeId = h.id;
        }
        let activeButton = null;
        active.querySelectorAll('.policy-toc-btn').forEach(btn => {
            const target = btn.getAttribute('data-action-click')?.match(/scrollToId\('([^']+)'\)/)?.[1];
            const isActive = target === activeId;
            btn.classList.toggle('toc-active', isActive);
            if (isActive) activeButton = btn;
        });

        const backToTop = document.getElementById('policy-back-top');
        const isLegalPage = ['privacy-policy', 'terms', 'moderation', 'copyright'].includes(active.id);
        if (backToTop) backToTop.classList.toggle('is-visible', isLegalPage && window.scrollY > 320);
        if (activeButton && isLegalPage && window.matchMedia('(max-width: 720px)').matches) {
            const rail = active.querySelector('.policy-toc-list');
            const railBox = rail?.getBoundingClientRect();
            const buttonBox = activeButton.getBoundingClientRect();
            if (railBox && (buttonBox.left < railBox.left || buttonBox.right > railBox.right)) {
                rail.scrollBy({ left: buttonBox.left < railBox.left ? buttonBox.left - railBox.left - 8 : buttonBox.right - railBox.right + 8, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
            }
        }
    }

    window.addEventListener('scroll', onScroll, { passive: true });

    // Re-run when section changes
    const origShow = window.showSection;
    if (origShow) {
        window.showSection = function (id, ...args) {
            origShow(id, ...args);
            setTimeout(updatePolicy, 50);
        };
    }
})();

/** Format a date as "Oct 8, 2026" — unambiguous for PH readers */
function formatDate(d) {
    const dt = new Date(d);
    return isNaN(dt) ? d : dt.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
}

/* ============================================================
   DOM BUILDER HELPERS
   ============================================================ */
// Build elements programmatically instead of assigning HTML strings.
// All text goes through textContent, so server-supplied values can
// never be interpreted as markup (no XSS surface).
function el(tag, options = {}, children = []) {
    const node = document.createElement(tag);
    if (options.className) node.className = options.className;
    if (options.text != null) node.textContent = options.text;
    if (options.attrs) {
        for (const [name, value] of Object.entries(options.attrs)) {
            node.setAttribute(name, value);
        }
    }
    if (options.style) {
        for (const [prop, value] of Object.entries(options.style)) {
            node.style[prop] = value;
        }
    }
    if (options.on) {
        for (const [event, handler] of Object.entries(options.on)) {
            node.addEventListener(event, handler);
        }
    }
    for (const child of [].concat(children)) {
        if (child == null) continue;
        node.appendChild(typeof child === 'string' ? document.createTextNode(child) : child);
    }
    return node;
}

// Remove all children of a node without parsing markup.
function clearNode(node) {
    while (node.firstChild) node.removeChild(node.firstChild);
}

function statusClass(status) {
    return 'status-badge status-' + (status || '').toLowerCase().replace(/ /g, '');
}

function adminPanelNeedsRefresh(panel) {
    const loadedAt = adminPanelLoadedAt.get(panel) || 0;
    return Date.now() - loadedAt >= ADMIN_PANEL_CACHE_MS;
}

function markAdminPanelLoaded(panel) {
    adminPanelLoadedAt.set(panel, Date.now());
}

function invalidateAdminPanelCache(...panels) {
    panels.forEach(panel => adminPanelLoadedAt.delete(panel));
}

/* ============================================================
   NAVIGATION
   ============================================================ */
function showSection(id, fromHistory = false, adminSection = null) {
    if (document.body.dataset.adminIpAllowed === '0' && (id === 'admin-login' || id === 'admin-dashboard' || id.startsWith('admin-'))) {
        id = 'page-unavailable';
    }
    if (id === 'admin-dashboard' && currentUser?.role !== 'Admin') {
        id = currentUser?.role === 'Resident' ? 'resident-dashboard' : 'admin-login';
    } else if (id === 'resident-dashboard' && currentUser?.role !== 'Resident') {
        id = currentUser?.role === 'Admin' ? 'admin-dashboard' : 'login';
    }

    const activeSectionId = document.querySelector('.section.active')?.id;
    if (id !== 'page-unavailable' && id !== activeSectionId && !confirmLeavingDisclosureComposer()) return;

    // Keep the Overview destination explicit when the global Dashboard link is
    // used, even if the admin was previously viewing Public Records.
    if (id === 'admin-dashboard' && currentUser?.role === 'Admin' && adminSection) {
        window.INITIAL_ADMIN_SECTION = adminSection;
    }

    let section = document.getElementById(id);
    if (!section) {
        id = 'page-unavailable';
        section = document.getElementById(id);
    }
    if (!section) return;
    // First-paint routing only applies until the app's normal navigation takes over.
    document.body.removeAttribute('data-route-start');
    document.body.removeAttribute('data-entry-route');

    const unavailableView = id === 'page-unavailable' || (id === 'admin-login' && document.body.dataset.adminIpAllowed === '0');
    document.body.classList.toggle('unavailable-view', unavailableView);
    document.documentElement.classList.toggle('hide-unavailable-scrollbar', unavailableView);
    document.title = unavailableView ? 'Page unavailable' : appDocumentTitle;
    if (unavailableView) {
        const attemptedAddress = (document.body.dataset.unavailableAddress || window.location.href).replace(/([?&#](?:token|secret|password|key)=)[^&#]*/gi, '$1[redacted]');
        document.body.dataset.unavailableAddress = attemptedAddress;
        const activeUnavailable = document.querySelector('.unavailable-section.active');
        const address = activeUnavailable?.querySelector('.unavailable-address code');
        if (address) address.textContent = attemptedAddress;
        const reportLink = activeUnavailable?.querySelector('.unavailable-report');
        if (reportLink) reportLink.href = `mailto:deleonjuliusakeem@gmail.com?subject=${encodeURIComponent('Report a broken link')}&body=${encodeURIComponent(`Broken link: ${attemptedAddress}`)}`;
    } else delete document.body.dataset.unavailableAddress;
    document.body.classList.toggle('hide-site-disclaimer', ['login', 'admin-login', 'forgot-password', 'register', 'resident-dashboard', 'admin-dashboard', 'page-unavailable'].includes(id) || (id === 'bulletin' && currentUser?.role === 'Admin'));
    document.body.classList.toggle('admin-dashboard-view', id === 'admin-dashboard');
    document.body.classList.toggle('admin-bulletin-view', id === 'bulletin' && currentUser?.role === 'Admin');

    document.querySelectorAll('.section').forEach(item => {
        const active = item === section;
        item.classList.toggle('active', active);
        item.setAttribute('aria-hidden', active ? 'false' : 'true');
    });
    if (id === 'login' || id === 'register') renderRecaptchaWidget(id);

    const nav = document.getElementById('main-nav');
    nav.classList.toggle('hidden-nav', ['login', 'register', 'admin-login', 'forgot-password', 'page-unavailable'].includes(id));
    closeMobileMenu();

    document.querySelectorAll('.nav-links [data-section]').forEach(item => {
        const target = item.dataset.section;
        const active = target === id || (target === 'dashboard' && id.endsWith('-dashboard'));
        if (active) item.setAttribute('aria-current', 'page');
        else item.removeAttribute('aria-current');
    });

    if (id === 'resident-dashboard') { loadResidentData(); loadDocTypes(); }
    if (id === 'admin-dashboard') {
        const activeAdminSection = window.INITIAL_ADMIN_SECTION || 'overview';
        // The active panel is loaded by switchAdminTab. Refresh the other
        // datasets only to keep the sidebar counts current, without fetching
        // the visible panel twice during a navigation.
        switchAdminTab(activeAdminSection);
        if (activeAdminSection !== 'requests' && adminPanelNeedsRefresh('requests')) loadAdminData();
        // Do NOT pre-fetch residents in the background — the sidebar count comes
        // from loadAdminOverview(). Pre-fetching marks the panel as loaded and
        // causes the Residents tab to show stale empty data when first opened.
    }
    if (id === 'bulletin') loadDisclosures();
    if (id === 'landing') loadLatestAnnouncements();
    if (id === 'register') {
        setTimeout(regInitDropzone, 50);
        // Wire up live password checklist
        const pwField  = document.getElementById('register-password');
        const cpwField = document.getElementById('register-confirm-password');
        if (pwField  && !pwField._pwListenerAdded)  { pwField.addEventListener('input',  updatePasswordChecklist); pwField._pwListenerAdded  = true; }
        if (cpwField && !cpwField._pwListenerAdded) { cpwField.addEventListener('input', updatePasswordChecklist); cpwField._pwListenerAdded = true; }
        updatePasswordChecklist();
    }
    if (id === 'forgot-password') configurePasswordResetPage();
    // Reset login context message when navigating to login directly (not via requestDoc)
    if (id === 'login') {
        const msg = document.getElementById('login-context-msg');
        if (msg && !msg.dataset.fromDoc) {
            msg.textContent = 'Enter your credentials to continue to the portal.';
        }
        if (msg) delete msg.dataset.fromDoc;
    }

    if (!fromHistory) {
        let destinationPath = location.pathname;
        let destinationHash = `#${id}`;
        let nextState = { section: id };

        if (id === 'admin-dashboard' && currentUser?.role === 'Admin') {
            const sectionKey = window.INITIAL_ADMIN_SECTION || 'overview';
            const routes = { overview: 'overview', requests: 'requests', residents: 'residents', disclosures: 'disclosures', activity: 'activity' };
            destinationPath = new URL(`admin_${routes[sectionKey] || 'overview'}.php`, document.baseURI).pathname;
            destinationHash = `#admin-${sectionKey}`;
            nextState = { section: id, adminSection: sectionKey };
        } else if (['landing', 'bulletin', 'resident-dashboard'].includes(id)) {
            destinationPath = new URL('index.php', document.baseURI).pathname;
        }

        if (location.pathname !== destinationPath || location.hash !== destinationHash || history.state?.section !== id) {
            history.pushState(nextState, '', `${destinationPath}${destinationHash}`);
        }
    }

    window.scrollTo({ top: 0, left: 0, behavior: 'auto' });

    const heading = section.querySelector('h1');
    if (heading) {
        heading.setAttribute('tabindex', '-1');
        requestAnimationFrame(() => heading.focus({ preventScroll: true }));
    }
}

// Admin APIs and malformed verification/reset links return a common marker.
// Turn those asynchronous denials into the same full-screen unavailable view.
const portalFetch = window.fetch.bind(window);
window.fetch = async (...args) => {
    const response = await portalFetch(...args);
    if (response.status === 404) {
        const payload = await response.clone().json().catch(() => null);
        if (payload?.pageUnavailable) showSection('page-unavailable');
    }
    return response;
};

let mobileMenuCloseTimer = null;

function toggleMobileMenu() {
    const toggle = document.getElementById('nav-toggle');
    const menu = document.getElementById('nav-menu');
    const backdrop = document.getElementById('nav-backdrop');
    const open = !menu.classList.contains('open');
    window.clearTimeout(mobileMenuCloseTimer);
    menu.classList.remove('closing');
    menu.classList.toggle('open', open);
    toggle.setAttribute('aria-expanded', String(open));
    if (backdrop) {
        backdrop.classList.remove('closing');
        backdrop.classList.toggle('open', open);
        backdrop.hidden = !open;
    }
    document.body.classList.toggle('menu-open', open);
}

function closeMobileMenu() {
    const toggle = document.getElementById('nav-toggle');
    const menu = document.getElementById('nav-menu');
    if (!toggle || !menu) return;
    const wasOpen = menu.classList.contains('open');
    menu.classList.remove('open');
    menu.classList.toggle('closing', wasOpen);
    toggle.setAttribute('aria-expanded', 'false');
    const backdrop = document.getElementById('nav-backdrop');
    if (backdrop) {
        backdrop.classList.remove('open');
        backdrop.classList.toggle('closing', wasOpen);
    }
    document.body.classList.remove('menu-open');
    if (wasOpen) {
        mobileMenuCloseTimer = window.setTimeout(() => {
            menu.classList.remove('closing');
            if (backdrop) {
                backdrop.classList.remove('closing');
                backdrop.hidden = true;
            }
        }, 180);
    } else if (backdrop) {
        backdrop.hidden = true;
    }
}

// Handle browser Back/Forward buttons.
window.addEventListener('popstate', (event) => {
    if (event.state?.adminSection || (event.state?.section === 'admin-dashboard' && currentUser?.role === 'Admin')) {
        const section = event.state.adminSection || 'overview';
        window.INITIAL_ADMIN_SECTION = section;
        switchAdminTab(section, { updateHistory: false });
        return;
    }
    const id = event.state?.section || 'landing';
    showSection(id, true);
});

window.addEventListener('hashchange', () => {
    const rawHash = location.hash.slice(1);
    let decodedHash = rawHash;
    try { decodedHash = decodeURIComponent(rawHash); } catch { /* Malformed fragments use the common unavailable screen. */ }
    const [route, query = ''] = decodedHash.split('?', 2);
    const validSections = ['landing', 'bulletin', 'login', 'admin-login', 'forgot-password', 'register', 'resident-dashboard', 'admin-dashboard', 'privacy-policy', 'terms', 'moderation', 'copyright', 'page-unavailable'];
    const resetQuery = new URLSearchParams(query);
    const invalidResetToken = route === 'forgot-password' && resetQuery.has('token') && !/^[a-f0-9]{64}$/i.test(resetQuery.get('token') || '');

    const adminTabs = ['overview', 'requests', 'residents', 'disclosures', 'activity'];
    if (route.startsWith('admin-') && adminTabs.includes(route.slice(6))) {
        if (document.body.dataset.adminIpAllowed === '0') {
            showSection('page-unavailable');
            return;
        }
        const tab = route.slice(6);
        window.INITIAL_ADMIN_SECTION = tab;
        showSection('admin-dashboard', true, tab);
        return;
    }

    if (route === 'admin-dashboard' || document.getElementById(route)?.classList.contains('section')) {
        if (invalidResetToken) {
            showSection('page-unavailable');
            return;
        }
        showSection(route, true);
        return;
    }
    if (rawHash) showSection('page-unavailable');
    else showSection('landing', true);
});

function checkAuthAndRedirect(target) {
    // 'request' means "request a document" and requires an account.
    if (target === 'request') {
        showSection(currentUser ? 'resident-dashboard' : 'login');
        return;
    }
    // 'login' and 'register' are reachable whether or not you're signed in.
    showSection(target);
}

/**
 * Called from each document card. If logged in, goes straight to the
 * resident dashboard. If not, routes to login with a contextual message
 * so the resident knows exactly why they're being asked to log in.
 */
function requestDoc(docName) {
    if (currentUser) {
        showSection('resident-dashboard');
        // Pre-select the document type after the select is populated.
        // loadDocTypes is async, so poll briefly for the option to appear.
        const trySelect = (attempts) => {
            const select = document.getElementById('doc-type');
            if (!select) return;
            const match = Array.from(select.options).find(o => o.text.startsWith(docName));
            if (match) {
                select.value = match.value;
                updateDocumentTypeInfo();
            } else if (attempts > 0) {
                setTimeout(() => trySelect(attempts - 1), 100);
            }
        };
        setTimeout(() => trySelect(10), 50);
        return;
    }
    const msg = document.getElementById('login-context-msg');
    if (msg) {
        msg.textContent = `Log in to request a ${docName}. New here? Register below.`;
        msg.dataset.fromDoc = '1';
    }
    showSection('login');
}

function focusContactInfo() {
    showSection('landing');
    const contact = document.getElementById('contact-info');
    if (!contact) return;
    contact.scrollIntoView({ behavior: 'smooth', block: 'center' });
    // Move focus after the scroll settles so screen readers land on the info.
    window.setTimeout(() => contact.focus({ preventScroll: true }), 300);
}

function updateNavBar() {
    const info = document.getElementById('user-info');
    const welcome = document.getElementById('welcome-user');
    const dashboardLink = document.getElementById('dashboard-nav-link');
    const bulletinAdminAction = document.getElementById('bulletin-admin-action');
    const heroGuestActions = document.getElementById('hero-guest-actions');
    const heroUserActions = document.getElementById('hero-user-actions');
    const heroGuestHelper = document.getElementById('hero-guest-helper');
    const heroBulletinLink = document.getElementById('hero-bulletin-link');
    const mobileRegisterCta = document.getElementById('nav-register-cta');
    document.body.classList.toggle('admin-user-view', currentUser?.role === 'Admin');
    document.body.classList.toggle('resident-user-view', currentUser?.role === 'Resident');
    document.body.classList.toggle('guest-user-view', !currentUser);
    // Show admin workspace nav section in hamburger only for admins
    const adminNavSection = document.getElementById('admin-nav-section');
    if (adminNavSection) adminNavSection.hidden = currentUser?.role !== 'Admin';
    const guestAuthActions = document.getElementById('guest-auth-actions');
    if (guestAuthActions) guestAuthActions.classList.toggle('hidden', Boolean(currentUser));
    if (mobileRegisterCta) mobileRegisterCta.classList.toggle('hidden', Boolean(currentUser));
    if (currentUser) { 
        info.classList.remove('hidden'); 
        welcome.textContent = `Hi, ${currentUser.name}`; 
        dashboardLink.classList.remove('hidden');
        if (bulletinAdminAction) bulletinAdminAction.classList.toggle('hidden', currentUser.role !== 'Admin');
        if (heroGuestActions) heroGuestActions.classList.add('hidden');
        if (heroGuestHelper) heroGuestHelper.classList.add('hidden');
        if (heroBulletinLink) heroBulletinLink.classList.add('hidden');
        if (heroUserActions) heroUserActions.classList.remove('hidden');
    } else {
        info.classList.add('hidden');
        dashboardLink.classList.add('hidden');
        if (bulletinAdminAction) bulletinAdminAction.classList.add('hidden');
        if (heroGuestActions) heroGuestActions.classList.remove('hidden');
        if (heroGuestHelper) heroGuestHelper.classList.remove('hidden');
        if (heroBulletinLink) heroBulletinLink.classList.remove('hidden');
        if (heroUserActions) heroUserActions.classList.add('hidden');
    }
}

function openDashboard() {
    if (!currentUser) {
        showSection('login');
        return;
    }

    showSection(currentUser.role === 'Admin' ? 'admin-dashboard' : 'resident-dashboard', false, 'overview');
}

function showToast(msg, type='info') {
    const t = document.getElementById('toast');
    if (!t) return;
    window.clearTimeout(toastHideTimer);
    window.clearTimeout(toastExitTimer);
    t.textContent = msg;
    t.classList.remove('toast-error', 'toast-success', 'is-hiding');
    if (type === 'error') t.classList.add('toast-error');
    if (type === 'success') t.classList.add('toast-success');
    t.classList.remove('hidden');
    requestAnimationFrame(() => t.classList.add('is-visible'));
    toastHideTimer = window.setTimeout(() => {
        t.classList.remove('is-visible');
        t.classList.add('is-hiding');
        toastExitTimer = window.setTimeout(() => {
            t.classList.add('hidden');
            t.classList.remove('is-hiding');
        }, 240);
    }, 3760);
}

function openDisclosureCategory(categoryId) {
    window.location.href = `bulletin.html?category=${categoryId}`;
}

function activateDisclosureCategory(event, categoryId) {
    if (event.target.closest('a, button')) return;
    openDisclosureCategory(categoryId);
}

function handleDisclosureCardKeydown(event, categoryId) {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    event.preventDefault();
    openDisclosureCategory(categoryId);
}

/* ============================================================
   MODAL HELPERS
   ============================================================ */
let modalCallback = null;
let modalReturnFocus = null;
let modalInputId = 'modal-input';
let modalInputRequired = true;
let actionModalCloseTimer = null;
let activePickupRequest = null;
let pickupSelectedDate = '';
let pickupCalendarMonth = new Date(new Date().getFullYear(), new Date().getMonth(), 1);

function getModalFocusable() {
    return [...document.querySelectorAll('#action-modal button:not([disabled]), #action-modal textarea:not([disabled]), #action-modal input:not([disabled]), #action-modal select:not([disabled])')]
        .filter(node => node.getClientRects().length > 0);
}

function handleModalKeydown(event) {
    if (event.key === 'Escape') {
        event.preventDefault();
        closeModal();
        return;
    }
    if (event.key !== 'Tab') return;

    const focusable = getModalFocusable();
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

function openModal(title, desc, label, placeholder, callback, inputType = 'textarea', options = {}) {
    document.getElementById('modal-title').textContent = title;
    document.getElementById('modal-desc').textContent = desc;
    document.getElementById('modal-label').textContent = label;
    const textInput = document.getElementById('modal-input');
    const dateInput = document.getElementById('modal-date-input');
    const input = inputType === 'datetime-local' ? dateInput : textInput;
    textInput.classList.toggle('hidden', input !== textInput);
    dateInput.classList.add('hidden');
    document.getElementById('modal-standard-field').classList.toggle('hidden', options.showInput === false);
    document.getElementById('pickup-fields').classList.add('hidden');
    document.getElementById('pickup-request-context').classList.add('hidden');
    document.getElementById('action-modal-close').classList.add('hidden');
    const confirmButton = document.getElementById('action-modal-confirm');
    confirmButton.textContent = options.confirmLabel || 'Confirm';
    confirmButton.className = options.confirmClass || 'btn-solid';
    confirmButton.disabled = false;
    confirmButton.onclick = submitModal;
    document.getElementById('action-modal-cancel').textContent = 'Cancel';
    document.getElementById('action-modal').dataset.closeBackdrop = 'false';
    document.getElementById('modal-label').htmlFor = input.id;
    if (input === textInput) input.placeholder = placeholder;
    if (options.maxLength) input.setAttribute('maxlength', String(options.maxLength));
    else input.removeAttribute('maxlength');
    input.value = '';
    modalInputId = input.id;
    modalInputRequired = options.showInput !== false && options.inputRequired !== false;
    modalReturnFocus = document.activeElement;
    modalCallback = callback;

    const modal = document.getElementById('action-modal');
    modal.style.zIndex = document.getElementById('proof-preview') ? '3600' : '';
    modal.classList.toggle('resident-confirmation', options.residentConfirmation === true);
    modal.onclick = event => {
        if (event.target === modal && modal.dataset.closeBackdrop === 'true') closeModal();
    };
    window.clearTimeout(actionModalCloseTimer);
    modal.classList.remove('is-visible', 'is-closing');
    modal.classList.remove('hidden');
    modal.addEventListener('keydown', handleModalKeydown);
    const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
    document.body.style.paddingRight = scrollbarWidth > 0 ? `${scrollbarWidth}px` : '';
    document.body.classList.add('modal-open');
    document.documentElement.classList.add('modal-open-root');
    document.getElementById('main-nav').inert = true;
    document.getElementById('app-container').inert = true;
    requestAnimationFrame(() => {
        if (!modal.classList.contains('hidden')) modal.classList.add('is-visible');
        if (options.showInput === false) confirmButton.focus();
        else input.focus();
    });
}

function localDateKey(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function dateFromKey(key) {
    const [year, month, day] = key.split('-').map(Number);
    return new Date(year, month - 1, day);
}

function formatPickupDate(key, options) {
    return dateFromKey(key).toLocaleDateString('en-US', options);
}

function openPickupModal(request) {
    activePickupRequest = request;
    pickupSelectedDate = '';
    pickupCalendarMonth = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
    const modal = document.getElementById('action-modal');
    modal.onclick = event => {
        if (event.target === modal && modal.dataset.closeBackdrop === 'true') closeModal();
    };
    document.getElementById('modal-title').textContent = 'Set ready for pickup';
    document.getElementById('modal-desc').textContent = 'Choose the pickup date and time. The resident will see this in their dashboard.';
    document.getElementById('modal-standard-field').classList.add('hidden');
    document.getElementById('pickup-fields').classList.remove('hidden');
    document.getElementById('pickup-request-context').textContent = `${request.tracking_code || ''} · ${titleCaseName(request.residentName || '')} · ${request.documentType || ''}`;
    document.getElementById('pickup-request-context').classList.remove('hidden');
    document.getElementById('pickup-date-display').value = '';
    document.getElementById('pickup-date-display').setAttribute('aria-expanded', 'false');
    document.getElementById('pickup-calendar').classList.add('hidden');
    document.getElementById('pickup-time-select').value = '';
    document.getElementById('pickup-note').value = '';
    document.getElementById('pickup-readback').textContent = '';
    document.getElementById('pickup-readback').classList.add('hidden');
    document.getElementById('action-modal-close').classList.remove('hidden');
    document.getElementById('action-modal-confirm').textContent = 'Set ready for pickup';
    document.getElementById('action-modal-confirm').disabled = true;
    document.getElementById('action-modal-confirm').onclick = submitPickupModal;
    modal.dataset.closeBackdrop = 'true';

    const timeSelect = document.getElementById('pickup-time-select');
    while (timeSelect.options.length > 1) timeSelect.remove(1);
    for (let minutes = 8 * 60; minutes <= 17 * 60; minutes += 30) {
        const hour = Math.floor(minutes / 60);
        const minute = minutes % 60;
        const value = `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
        const date = new Date(2020, 0, 1, hour, minute);
        const label = date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        timeSelect.add(new Option(label, value));
    }
    renderPickupCalendar();
    modalReturnFocus = document.activeElement;
    modalCallback = null;
    window.clearTimeout(actionModalCloseTimer);
    modal.classList.remove('is-visible', 'is-closing');
    modal.classList.remove('hidden');
    modal.addEventListener('keydown', handleModalKeydown);
    const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
    document.body.style.paddingRight = scrollbarWidth > 0 ? `${scrollbarWidth}px` : '';
    document.body.classList.add('modal-open');
    document.documentElement.classList.add('modal-open-root');
    document.getElementById('main-nav').inert = true;
    document.getElementById('app-container').inert = true;
    requestAnimationFrame(() => {
        if (!modal.classList.contains('hidden')) modal.classList.add('is-visible');
        document.getElementById('pickup-date-display').focus();
    });
}

function togglePickupCalendar() {
    const calendar = document.getElementById('pickup-calendar');
    const open = calendar.classList.contains('hidden');
    calendar.classList.toggle('hidden', !open);
    document.getElementById('pickup-date-display').setAttribute('aria-expanded', String(open));
    if (open) renderPickupCalendar();
}

function handlePickupDateKeydown(event) {
    if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        togglePickupCalendar();
    } else if (event.key === 'ArrowDown') {
        event.preventDefault();
        document.getElementById('pickup-calendar').classList.remove('hidden');
        document.getElementById('pickup-date-display').setAttribute('aria-expanded', 'true');
        renderPickupCalendar();
    }
}

function changePickupCalendarMonth(offset) {
    pickupCalendarMonth = new Date(pickupCalendarMonth.getFullYear(), pickupCalendarMonth.getMonth() + offset, 1);
    renderPickupCalendar();
}

function selectPickupDate(key) {
    const date = dateFromKey(key);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    if (date < today || date.getDay() === 0 || date.getDay() === 6) return;
    pickupSelectedDate = key;
    document.getElementById('pickup-date-display').value = formatPickupDate(key, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
    document.getElementById('pickup-calendar').classList.add('hidden');
    document.getElementById('pickup-date-display').setAttribute('aria-expanded', 'false');
    updatePickupReadback();
    document.getElementById('pickup-time-select').focus();
}

function renderPickupCalendar() {
    const grid = document.getElementById('pickup-calendar-grid');
    if (!grid) return;
    clearNode(grid);
    const weekdays = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
    weekdays.forEach(day => grid.appendChild(el('span', { className: 'pickup-calendar-weekday', text: day, attrs: { role: 'columnheader' } })));
    const year = pickupCalendarMonth.getFullYear();
    const month = pickupCalendarMonth.getMonth();
    const firstWeekday = new Date(year, month, 1).getDay();
    const dayCount = new Date(year, month + 1, 0).getDate();
    for (let blank = 0; blank < firstWeekday; blank++) grid.appendChild(el('span', { className: 'pickup-calendar-empty', attrs: { role: 'gridcell', 'aria-hidden': 'true' } }));
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    for (let day = 1; day <= dayCount; day++) {
        const date = new Date(year, month, day);
        const key = localDateKey(date);
        const disabled = date < today || date.getDay() === 0 || date.getDay() === 6;
        const dayButton = el('button', {
            className: `pickup-calendar-day${key === pickupSelectedDate ? ' selected' : ''}`,
            text: String(day),
            attrs: { type: 'button', role: 'gridcell', 'aria-pressed': String(key === pickupSelectedDate), 'aria-label': formatPickupDate(key, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }) },
            on: { click: () => selectPickupDate(key) }
        });
        dayButton.disabled = disabled;
        grid.appendChild(dayButton);
    }
    document.getElementById('pickup-calendar-month').textContent = pickupCalendarMonth.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
    const currentMonth = new Date(today.getFullYear(), today.getMonth(), 1);
    document.getElementById('pickup-calendar-prev').disabled = pickupCalendarMonth <= currentMonth;
}

function updatePickupReadback() {
    const time = document.getElementById('pickup-time-select').value;
    const readback = document.getElementById('pickup-readback');
    const confirmButton = document.getElementById('action-modal-confirm');
    if (!pickupSelectedDate || !time) {
        readback.textContent = '';
        readback.classList.add('hidden');
        confirmButton.disabled = true;
        return;
    }
    const [hour, minute] = time.split(':').map(Number);
    const timeLabel = new Date(2020, 0, 1, hour, minute).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    readback.textContent = `Pickup: ${formatPickupDate(pickupSelectedDate, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })} at ${timeLabel}`;
    readback.classList.remove('hidden');
    confirmButton.disabled = false;
}

async function submitPickupModal() {
    const confirmButton = document.getElementById('action-modal-confirm');
    const time = document.getElementById('pickup-time-select').value;
    if (!activePickupRequest || !pickupSelectedDate || !time || confirmButton.disabled) return;
    const [hour, minute] = time.split(':').map(Number);
    const timeLabel = new Date(2020, 0, 1, hour, minute).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    const fullPickup = `Pickup: ${formatPickupDate(pickupSelectedDate, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })} at ${timeLabel}`;
    const note = document.getElementById('pickup-note').value.trim();
    const details = note ? `${fullPickup}\nNote to resident: ${note}` : fullPickup;
    confirmButton.disabled = true;
    confirmButton.classList.add('is-loading');
    confirmButton.setAttribute('aria-busy', 'true');
    confirmButton.textContent = 'Saving…';
    const succeeded = await sendProcessRequest(activePickupRequest.request_id, 'Ready', details);
    confirmButton.classList.remove('is-loading');
    confirmButton.removeAttribute('aria-busy');
    if (succeeded) {
        closeModal();
    } else {
        confirmButton.textContent = 'Set ready for pickup';
        confirmButton.disabled = false;
    }
}

function closeModal() {
    const modal = document.getElementById('action-modal');
    if (modal.classList.contains('hidden') || modal.classList.contains('is-closing')) return;
    modal.classList.remove('is-visible');
    modal.classList.add('is-closing');
    modal.removeEventListener('keydown', handleModalKeydown);
    modalCallback = null;
    const returnFocus = modalReturnFocus;
    modalReturnFocus = null;
    actionModalCloseTimer = window.setTimeout(() => {
        modal.classList.add('hidden');
        modal.classList.remove('is-closing');
        modal.style.removeProperty('z-index');
        document.body.classList.remove('modal-open');
        document.body.style.paddingRight = '';
        document.documentElement.classList.remove('modal-open-root');
        document.getElementById('main-nav').inert = false;
        document.getElementById('app-container').inert = false;
        if (returnFocus && document.contains(returnFocus)) returnFocus.focus();
        actionModalCloseTimer = null;
    }, 240);
}

async function submitModal() {
    const value = document.getElementById(modalInputId).value.trim();
    if (modalInputRequired && !value) {
        showToast('Please enter a value.', 'error');
        return;
    }
    const confirmButton = document.getElementById('action-modal-confirm');
    const originalLabel = confirmButton.textContent;
    confirmButton.disabled = true;
    confirmButton.setAttribute('aria-busy', 'true');
    confirmButton.textContent = 'Processing…';
    try {
        const result = modalCallback ? await modalCallback(value) : true;
        if (result === false) {
            confirmButton.disabled = false;
            confirmButton.removeAttribute('aria-busy');
            confirmButton.textContent = originalLabel;
            return;
        }
        closeModal();
    } catch (error) {
        showToast(error?.message || 'The action could not be completed. Please try again.', 'error');
        confirmButton.disabled = false;
        confirmButton.removeAttribute('aria-busy');
        confirmButton.textContent = originalLabel;
    }
}

/* ============================================================
   PROOF PREVIEW (in-page lightbox; no new tab)
   ============================================================ */
let proofReturnFocus = null;

function handleProofKeydown(event) {
    const overlay = document.getElementById('proof-preview');
    if (!overlay) return;
    if (event.key === 'Escape') {
        event.preventDefault();
        closeProofPreview();
        return;
    }
    if (event.key !== 'Tab') return;
    const focusable = [...overlay.querySelectorAll('button:not([disabled]), a[href]')];
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

async function openProofPreview(path, resident) {
    if (typeof path !== 'string' || !/^api\/resident_proof\.php\?id=\d+$/.test(path)) {
        showToast('Proof file is unavailable.', 'error');
        return;
    }
    const residentId = Number(resident?.residentId);
    if (!residentId) return;
    const mobileProofPreview = window.matchMedia('(max-width: 767px)').matches;
    const title = mobileProofPreview ? 'Proof of residency' : `Proof of residency — ${resident.name || 'Resident'}`;
    closeProofPreview();

    const proofType = String(resident?.proofType || '').toLowerCase();
    const isPdf = proofType === 'pdf';
    const isImage = ['jpg', 'jpeg', 'png'].includes(proofType);
    let media;
    if (isPdf) {
        media = el('object', {
            className: 'proof-preview-media proof-preview-pdf',
            attrs: { data: path, type: 'application/pdf', 'aria-label': title }
        }, [
            el('p', { className: 'proof-preview-fallback' }, [
                'Preview unavailable. ',
                el('a', { text: 'Open the file', attrs: { href: path, target: '_blank', rel: 'noopener' } })
            ])
        ]);
    } else if (isImage) {
        media = el('img', {
            className: 'proof-preview-media',
            attrs: { src: path, alt: title }
        });
    } else {
        // Word and other office formats can't render inside the browser,
        // so show an explicit in-page notice instead of silently downloading.
        media = el('div', { className: 'proof-preview-doc' }, [
            el('p', { className: 'proof-preview-doc-title', text: 'This document can\u2019t be previewed in the browser' }),
            el('p', { className: 'proof-preview-fallback', text: 'Word documents (.docx) must be downloaded to view.' }),
            el('a', {
                className: 'btn-solid proof-preview-download',
                text: 'Download document',
                attrs: { href: path, download: '', rel: 'noopener' }
            })
        ]);
    }

    const closeBtn = el('button', {
        className: 'proof-preview-close',
        attrs: { type: 'button', 'aria-label': 'Close preview' },
        text: '\u00d7',
        on: { click: closeProofPreview }
    });

    const residentInfo = el('aside', { className: 'proof-preview-resident', attrs: { 'aria-label': 'Resident registration details' } }, [
        el('h3', { text: resident.name || 'Resident' }),
        ...[
            ['Email', resident.email],
            ['Address', resident.address],
            ['Registered', resident.createdAt ? `${formatDate(resident.createdAt)} · ${new Date(String(resident.createdAt).replace(' ', 'T')).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })}` : '—']
        ].map(([label, value]) => el('div', { className: 'proof-preview-info-row' }, [
            el('span', { text: label }),
            el('strong', { text: String(value || 'Not provided') })
        ]))
    ]);

    let zoom = 1;
    const zoomLabel = el('span', { className: 'proof-preview-zoom-label', text: '100%' });
    const viewerControls = el('div', { className: 'proof-preview-controls' }, [
        el('button', { className: 'proof-preview-control', text: '−', attrs: { type: 'button', 'aria-label': 'Zoom out' }, on: { click: () => {
            zoom = Math.max(0.5, Math.round((zoom - 0.25) * 100) / 100);
            if (isImage) media.style.transform = `scale(${zoom})`;
            zoomLabel.textContent = `${Math.round(zoom * 100)}%`;
        } } }),
        zoomLabel,
        el('button', { className: 'proof-preview-control', text: '+', attrs: { type: 'button', 'aria-label': 'Zoom in' }, on: { click: () => {
            zoom = Math.min(3, Math.round((zoom + 0.25) * 100) / 100);
            if (isImage) media.style.transform = `scale(${zoom})`;
            zoomLabel.textContent = `${Math.round(zoom * 100)}%`;
        } } }),
        el('a', { className: 'proof-preview-open-link', attrs: { href: path, target: '_blank', rel: 'noopener noreferrer' } }, [
            el('i', { className: 'fas fa-arrow-up-right-from-square', attrs: { 'aria-hidden': 'true' } }), ' Open in new tab'
        ])
    ]);
    if (!isImage) viewerControls.querySelectorAll('button').forEach(button => { button.disabled = true; });

    const viewer = el('section', { className: 'proof-preview-viewer', attrs: { 'aria-label': 'Residency proof' } }, [
        viewerControls,
        el('div', { className: 'proof-preview-image-wrap' }, [media])
    ]);

    const footer = el('div', { className: 'proof-preview-actions' }, [
        el('button', { className: 'action-btn btn-approve', text: 'Verify', attrs: { type: 'button' }, on: { click: () => {
            closeProofPreview();
            openResidentConfirmation(residentId, 'verify', resident.name || 'this resident');
        } } }),
        el('button', { className: 'action-btn btn-reject', text: 'Reject', attrs: { type: 'button' }, on: { click: () => {
            closeProofPreview();
            openResidentConfirmation(residentId, 'reject', resident.name || 'this resident');
        } } })
    ]);

    const dialog = el('div', {
        className: 'proof-preview-box',
        attrs: { role: 'dialog', 'aria-modal': 'true', 'aria-label': title }
    }, [
        el('div', { className: 'proof-preview-head' }, [
            el('span', { className: 'proof-preview-title', text: title }),
            closeBtn
        ]),
        el('div', { className: 'proof-preview-body' }, [residentInfo, viewer]),
        footer
    ]);

    const overlay = el('div', {
        className: 'proof-preview-overlay',
        attrs: { id: 'proof-preview' },
        on: { click: (e) => { if (e.target === e.currentTarget) closeProofPreview(); } }
    }, [dialog]);

    proofReturnFocus = document.activeElement;
    document.body.appendChild(overlay);
    document.addEventListener('keydown', handleProofKeydown);
    const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
    document.body.style.paddingRight = scrollbarWidth > 0 ? `${scrollbarWidth}px` : '';
    document.body.classList.add('modal-open');
    document.documentElement.classList.add('modal-open-root');
    document.getElementById('main-nav').inert = true;
    document.getElementById('app-container').inert = true;
    requestAnimationFrame(() => closeBtn.focus());
}

function closeProofPreview() {
    const overlay = document.getElementById('proof-preview');
    if (!overlay) return;
    document.removeEventListener('keydown', handleProofKeydown);
    overlay.remove();
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('padding-right');
    document.documentElement.classList.remove('modal-open-root');
    document.getElementById('main-nav').inert = false;
    document.getElementById('app-container').inert = false;
    if (proofReturnFocus && document.contains(proofReturnFocus)) proofReturnFocus.focus();
    proofReturnFocus = null;
}

/* ============================================================
   INFO NOTICE (acknowledged in-page dialog)
   ============================================================ */
let noticeReturnFocus = null;
let noticeOnClose = null;

function handleNoticeKeydown(event) {
    const overlay = document.getElementById('info-notice');
    if (!overlay) return;
    if (event.key === 'Escape') {
        event.preventDefault();
        closeNotice();
        return;
    }
    if (event.key !== 'Tab') return;
    const focusable = [...overlay.querySelectorAll('button:not([disabled])')];
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

function showNotice(eyebrow, title, lines, onClose = null) {
    closeNotice(true, false);
    noticeOnClose = onClose;

    const okBtn = el('button', {
        className: 'btn-solid', text: 'Got it',
        attrs: { type: 'button' },
        on: { click: closeNotice }
    });

    const body = [].concat(lines).filter(Boolean).map(line =>
        el('p', { className: 'notice-text', text: line })
    );

    const dialog = el('div', {
        className: 'modal-box',
        attrs: { role: 'alertdialog', 'aria-modal': 'true', 'aria-labelledby': 'notice-title' }
    }, [
        el('div', { className: 'modal-heading' }, [
            el('p', { className: 'eyebrow', text: eyebrow }),
            el('h2', { attrs: { id: 'notice-title' }, text: title }),
            ...body
        ]),
        el('div', { className: 'modal-actions' }, [okBtn])
    ]);

    const overlay = el('div', {
        className: 'modal-overlay info-notice-overlay',
        attrs: { id: 'info-notice' },
        on: { click: (e) => { if (e.target === e.currentTarget) closeNotice(); } }
    }, [dialog]);

    noticeReturnFocus = document.activeElement;
    document.body.appendChild(overlay);
    document.addEventListener('keydown', handleNoticeKeydown);
    const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
    document.body.style.paddingRight = scrollbarWidth > 0 ? `${scrollbarWidth}px` : '';
    document.body.classList.add('modal-open');
    document.documentElement.classList.add('modal-open-root');
    document.getElementById('main-nav').inert = true;
    document.getElementById('app-container').inert = true;
    requestAnimationFrame(() => {
        overlay.classList.add('is-visible');
        okBtn.focus();
    });
}

function closeNotice(immediate = false, runCallback = true) {
    const overlay = document.getElementById('info-notice');
    if (!overlay) return;
    if (overlay.dataset.closing === 'true') return;
    overlay.dataset.closing = 'true';
    document.removeEventListener('keydown', handleNoticeKeydown);
    overlay.classList.remove('is-visible');
    const finishClose = () => {
        overlay.remove();
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
        document.documentElement.classList.remove('modal-open-root');
        document.getElementById('main-nav').inert = false;
        document.getElementById('app-container').inert = false;
        if (noticeReturnFocus && document.contains(noticeReturnFocus)) noticeReturnFocus.focus();
        noticeReturnFocus = null;
        const cb = noticeOnClose;
        noticeOnClose = null;
        if (runCallback && cb) cb();
    };
    if (immediate) finishClose();
    else window.setTimeout(finishClose, 240);
}

/* ============================================================
   PASSWORD VISIBILITY TOGGLE
   ============================================================ */
function togglePassword(inputId, btn) {
    const input = document.getElementById(inputId);
    if (!input) return;
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';

    const icon = btn.querySelector('i');
    if (icon) icon.className = show ? 'fas fa-eye-slash' : 'fas fa-eye';
    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
}

/* ============================================================
   LOGIN
   ============================================================ */
function handleLogin(e) {
    e.preventDefault();
    const email = document.getElementById('login-email').value;
    const password = document.getElementById('login-password').value;
    const error = document.getElementById('login-error');
    if (error) {
        error.textContent = '';
        error.classList.add('hidden');
    }
    performLogin(email, password, 'resident');
}

function handleAdminLogin(e) {
    e.preventDefault();
    const email = document.getElementById('admin-login-email').value;
    const password = document.getElementById('admin-login-password').value;
    performLogin(email, password, 'admin');
}

function openForgotPassword(context = 'resident') {
    passwordResetToken = '';
    passwordResetContext = context === 'admin' ? 'admin' : 'resident';
    const emailInput = document.getElementById('password-reset-email');
    const sourceEmail = document.getElementById(passwordResetContext === 'admin' ? 'admin-login-email' : 'login-email');
    if (emailInput) emailInput.value = sourceEmail?.value || '';
    const contextInput = document.getElementById('password-reset-context');
    if (contextInput) contextInput.value = passwordResetContext;
    clearPasswordResetFeedback();
    showSection('forgot-password');
}

function clearPasswordResetFeedback() {
    const error = document.getElementById('password-reset-error');
    const success = document.getElementById('password-reset-success');
    if (error) { error.textContent = ''; error.classList.add('hidden'); }
    if (success) { success.textContent = ''; success.classList.add('hidden'); }
}

function configurePasswordResetPage() {
    const requestForm = document.getElementById('forgot-password-form');
    const resetForm = document.getElementById('reset-password-form');
    const title = document.getElementById('forgot-password-title');
    const intro = document.getElementById('password-reset-intro');
    const back = document.querySelector('#forgot-password .back-button');
    if (!requestForm || !resetForm) return;
    clearPasswordResetFeedback();
    const hasToken = /^[a-f0-9]{64}$/i.test(passwordResetToken);
    requestForm.classList.toggle('hidden', hasToken);
    resetForm.classList.toggle('hidden', !hasToken);
    if (title) title.textContent = hasToken ? 'Choose a new password' : 'Forgot your password?';
    if (intro) intro.textContent = hasToken
        ? 'Create a new password for your Barangay San Jose portal account.'
        : 'Enter the email address for your portal account. We’ll send a secure link if it matches an account.';
    if (back) {
        clearNode(back);
        back.append(el('i', { className: 'fas fa-arrow-left', attrs: { 'aria-hidden': 'true' } }), document.createTextNode(' Back to log in'));
    }
}

function returnFromPasswordReset() {
    if (passwordResetToken) {
        const cleanUrl = `${window.location.pathname}${window.location.hash || '#forgot-password'}`;
        history.replaceState(history.state, '', cleanUrl);
        passwordResetToken = '';
    }
    showSection(passwordResetContext === 'admin' ? 'admin-login' : 'login');
}

async function requestPasswordReset(event) {
    event.preventDefault();
    clearPasswordResetFeedback();
    const button = document.getElementById('password-reset-submit');
    const email = document.getElementById('password-reset-email').value.trim();
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    try {
        const response = await fetch('api/request_password_reset.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, context: passwordResetContext })
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'We could not send the reset link. Please try again.');
        const success = document.getElementById('password-reset-success');
        success.textContent = result.message;
        success.classList.remove('hidden');
    } catch (error) {
        const message = document.getElementById('password-reset-error');
        message.textContent = error.message || 'Unable to contact the server. Please try again.';
        message.classList.remove('hidden');
    } finally {
        button.disabled = false;
        button.removeAttribute('aria-busy');
    }
}

async function completePasswordReset(event) {
    event.preventDefault();
    clearPasswordResetFeedback();
    const password = document.getElementById('new-password').value;
    const confirmation = document.getElementById('confirm-new-password').value;
    const button = document.getElementById('reset-password-submit');
    if (password !== confirmation) {
        const error = document.getElementById('password-reset-error');
        error.textContent = 'The passwords do not match.';
        error.classList.remove('hidden');
        return;
    }
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    try {
        const response = await fetch('api/reset_password.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: passwordResetToken, password })
        });
        const result = await response.json();
        if (result.pageUnavailable) {
            showSection('page-unavailable');
            return;
        }
        if (!response.ok || !result.success) throw new Error(result.message || 'We could not update your password.');
        const success = document.getElementById('password-reset-success');
        success.textContent = result.message;
        success.classList.remove('hidden');
        document.getElementById('reset-password-form').classList.add('hidden');
        document.getElementById('password-reset-intro').classList.add('hidden');
        document.getElementById('forgot-password-title').textContent = 'Password updated';
        passwordResetToken = '';
        history.replaceState(history.state, '', `${window.location.pathname}#forgot-password`);
    } catch (error) {
        const message = document.getElementById('password-reset-error');
        message.textContent = error.message || 'Unable to contact the server. Please try again.';
        message.classList.remove('hidden');
    } finally {
        button.disabled = false;
        button.removeAttribute('aria-busy');
    }
}

async function performLogin(email, password, context) {
    if (window.location.protocol === 'file:') {
        showToast('Open the portal through XAMPP: http://localhost/barangay_portal_new/', 'error');
        return;
    }

    if (window.location.port && !['80', '443'].includes(window.location.port)) {
        showToast('This page is running on a development server. Open http://localhost/barangay_portal_new/ through XAMPP.', 'error');
        return;
    }

    const loginError = context === 'resident' ? document.getElementById('login-error') : null;
    const submitButton = context === 'resident' ? document.getElementById('login-submit') : null;
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.setAttribute('aria-busy', 'true');
        clearNode(submitButton);
        submitButton.append(el('i', { className: 'fas fa-spinner fa-spin', attrs: { 'aria-hidden': 'true' } }), document.createTextNode(' Logging in…'));
    }

    try {
        let recaptchaToken = '';
        if (context === 'resident') {
            recaptchaToken = await executeRecaptcha('login');
        }
        const res = await fetch('api/login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                email, password, context,
                website: document.getElementById(context === 'admin' ? 'admin-login-website' : 'login-website')?.value || '',
                recaptchaToken
            })
        });
        const data = await res.json();
        if (data.pageUnavailable) {
            showSection('page-unavailable');
            return;
        }
        if (!res.ok && !data.message) throw new Error(`Login request failed with HTTP ${res.status}`);
        if (data.success) {
            const expectedRole = context === 'admin' ? 'Admin' : 'Resident';
            if (data.role !== expectedRole) {
                showToast('This account must use its designated login page.', 'error');
                return;
            }
            currentUser = { id: data.id, name: data.name, role: data.role };
            updateNavBar();
            showSection(context === 'admin' ? 'admin-dashboard' : 'resident-dashboard');
        } else {
            if (loginError) {
                loginError.textContent = data.message === 'Account Pending Verification'
                    ? "Your account is still under review. We'll notify you once it's approved."
                    : (data.message || 'Incorrect email or password.');
                loginError.classList.remove('hidden');
            } else {
                showToast(data.message || 'Login failed.', 'error');
            }
            if (context === 'resident' && window.grecaptcha && window._recaptchaLoginWidgetId !== undefined) {
                grecaptcha.reset(window._recaptchaLoginWidgetId);
            }
        }
    } catch (error) {
        showToast(error.message.includes('JSON')
            ? 'The server returned an invalid response.'
            : error.message.includes('HTTP')
                ? error.message
                : (error.message || 'Unable to contact the server. Check Apache and the portal URL.'), 'error');
    } finally {
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.removeAttribute('aria-busy');
            submitButton.textContent = 'Log in';
        }
    }
}

/* ============================================================
   REGISTER (FormData for proof upload)
   ============================================================ */
const registerPoliciesRead = new Set();
let registerPolicyReturnFocus = null;

function updateRegisterPolicyReadState() {
    const dialog = document.getElementById('register-policy-dialog');
    const content = document.getElementById('register-policy-content');
    const consent = document.getElementById('register-terms-accepted');
    const consentLabel = document.getElementById('register-consent-label');
    const hint = document.getElementById('register-policy-read-hint');
    const closeButton = document.getElementById('register-policy-close');
    const progress = dialog?.querySelector('.register-policy-progress');
    const progressFill = document.getElementById('register-policy-progress-fill');
    const activePolicy = dialog?.dataset.policyId;
    if (!content || !consent || !consentLabel || !hint || !closeButton || !['terms', 'privacy-policy'].includes(activePolicy)) return;

    dialog.classList.toggle('has-scrolled', content.scrollTop > 0);

    const scrollableHeight = Math.max(0, content.scrollHeight - content.clientHeight);
    const percent = scrollableHeight === 0 ? 100 : Math.min(100, Math.round(content.scrollTop / scrollableHeight * 100));
    if (progress) progress.setAttribute('aria-valuenow', String(percent));
    if (progressFill) progressFill.style.width = `${percent}%`;

    if (content.scrollTop + content.clientHeight >= content.scrollHeight - 4) {
        registerPoliciesRead.add(activePolicy);
        closeButton.disabled = false;
        if (registerPoliciesRead.has('terms') && registerPoliciesRead.has('privacy-policy')) {
            consent.disabled = false;
            consentLabel.classList.remove('is-locked');
            hint.classList.add('is-ready');
            hint.textContent = 'Both documents read. You can now agree.';
        }
    }
}

function openRegisterPolicy(sectionId) {
    const source = document.querySelector(`#${sectionId} .policy-page`);
    const dialog = document.getElementById('register-policy-dialog');
    const title = document.getElementById('register-policy-title');
    const content = document.getElementById('register-policy-content');
    const closeButton = document.getElementById('register-policy-close');
    const closeXButton = document.getElementById('register-policy-close-x');
    const academicNotice = document.getElementById('register-policy-academic-notice');
    const academicText = document.getElementById('register-policy-academic-text');
    const lastUpdated = document.getElementById('register-policy-last-updated');
    if (!source || !dialog || !title || !content || !closeButton || !closeXButton || !academicNotice || !academicText || !lastUpdated) return;

    title.textContent = source.querySelector('h1')?.textContent || 'Policy';
    clearNode(content);
    Array.from(source.childNodes).forEach(child => content.appendChild(child.cloneNode(true)));
    content.dataset.policyId = sectionId;
    academicNotice.classList.add('hidden');
    academicText.textContent = '';
    lastUpdated.classList.add('hidden');
    lastUpdated.textContent = '';
    closeButton.textContent = 'I understand';
    closeButton.disabled = true;
    if (sectionId === 'terms' || sectionId === 'privacy-policy') {
        const updated = content.querySelector('.policy-updated');
        const academicNote = updated?.querySelector('.policy-academic-note');
        updated?.querySelector('i')?.remove();
        if (academicNote) {
            academicText.textContent = academicNote.textContent;
            academicNotice.classList.remove('hidden');
            academicNote.remove();
        }
        lastUpdated.textContent = (updated?.textContent || '').replace(/\s*[·•]\s*$/, '').trim();
        lastUpdated.classList.toggle('hidden', !lastUpdated.textContent);
        content.querySelector('.policy-header')?.remove();
    }
    content.scrollTop = 0;
    const modal = dialog.querySelector('.register-policy-modal');
    if (modal) modal.scrollTop = 0;
    const progress = dialog.querySelector('.register-policy-progress');
    const progressFill = document.getElementById('register-policy-progress-fill');
    if (progress) progress.setAttribute('aria-valuenow', '0');
    if (progressFill) progressFill.style.width = '0%';
    dialog.dataset.policyId = sectionId;
    registerPolicyReturnFocus = document.activeElement;
    dialog.classList.remove('is-visible');
    dialog.classList.remove('has-scrolled');
    dialog.classList.remove('hidden');
    const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
    document.body.style.paddingRight = scrollbarWidth > 0 ? `${scrollbarWidth}px` : '';
    document.body.classList.add('modal-open');
    document.documentElement.classList.add('modal-open-root');
    requestAnimationFrame(() => {
        content.scrollTop = 0;
        if (modal) modal.scrollTop = 0;
        dialog.classList.add('is-visible');
        updateRegisterPolicyReadState();
    });
    closeXButton.focus();
}

(() => {
    const dialog = document.getElementById('register-policy-dialog');
    const closeButton = document.getElementById('register-policy-close');
    const closeXButton = document.getElementById('register-policy-close-x');
    const content = document.getElementById('register-policy-content');
    const consent = document.getElementById('register-terms-accepted');
    const consentLabel = document.getElementById('register-consent-label');
    const hint = document.getElementById('register-policy-read-hint');
    if (!dialog || !closeButton || !closeXButton || !content || !consent || !consentLabel || !hint) return;
    consent.disabled = true;
    consentLabel.classList.add('is-locked');
    content.addEventListener('scroll', updateRegisterPolicyReadState, { passive: true });
    content.addEventListener('keydown', event => {
        if (['End', 'PageDown', 'ArrowDown', ' '].includes(event.key)) requestAnimationFrame(updateRegisterPolicyReadState);
    });
    let closeTimer;
    const close = () => {
        if (dialog.classList.contains('hidden')) return;
        dialog.classList.remove('is-visible');
        clearTimeout(closeTimer);
        closeTimer = setTimeout(() => {
            if (dialog.classList.contains('is-visible')) return;
            dialog.classList.add('hidden');
            document.body.classList.remove('modal-open');
            document.body.style.removeProperty('padding-right');
            document.documentElement.classList.remove('modal-open-root');
            registerPolicyReturnFocus?.focus();
        }, 250);
    };
    closeButton.addEventListener('click', close);
    closeXButton.addEventListener('click', close);
    dialog.addEventListener('click', event => { if (event.target === dialog) close(); });
    dialog.addEventListener('keydown', event => { if (event.key === 'Escape') close(); });
})();

(() => {
    const termsPanel = document.getElementById('register-terms-scroll');
    const consent = document.getElementById('register-terms-accepted');
    const consentLabel = document.getElementById('register-consent-label');
    const hint = document.getElementById('register-dialog-hint');
    const readButton = document.getElementById('register-read-terms');
    const dialog = document.getElementById('register-terms-dialog');
    const closeButton = document.getElementById('register-terms-close');
    if (!termsPanel || !consent || !consentLabel || !hint || !readButton || !dialog || !closeButton) return;

    let termsRead = false;
    const unlockConsent = () => {
        if (termsRead) return;
        if (termsPanel.scrollTop + termsPanel.clientHeight >= termsPanel.scrollHeight - 4) {
            termsRead = true;
            termsPanel.dataset.read = 'true';
            consent.disabled = false;
            consentLabel.classList.remove('is-locked');
            hint.classList.add('is-ready');
            hint.textContent = 'You’ve reached the end. You can close this window and agree on the form.';
            closeButton.disabled = false;
        }
    };
    termsPanel.addEventListener('scroll', unlockConsent, { passive: true });
    termsPanel.addEventListener('keydown', event => {
        if (event.key === 'End' || event.key === 'PageDown' || event.key === 'ArrowDown' || event.key === ' ') {
            requestAnimationFrame(unlockConsent);
        }
    });
    readButton.addEventListener('click', () => {
        dialog.classList.remove('hidden');
        document.body.classList.add('modal-open');
        termsPanel.focus();
        requestAnimationFrame(unlockConsent);
    });
    const closeDialog = () => {
        if (!termsRead) return;
        dialog.classList.add('hidden');
        document.body.classList.remove('modal-open');
        readButton.focus();
    };
    closeButton.addEventListener('click', closeDialog);
    dialog.addEventListener('keydown', event => {
        if (event.key === 'Escape' && termsRead) closeDialog();
    });
})();

/* ============================================================
   REGISTER — drop zone
   ============================================================ */
function regInitDropzone() {
    const zone = document.getElementById('reg-dropzone');
    const input = document.getElementById('register-proof');
    if (!zone || !input) return;

    input.addEventListener('change', () => regShowPreview(input.files?.[0]));

    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
    zone.addEventListener('drop', e => {
        e.preventDefault();
        zone.classList.remove('drag-over');
        const file = e.dataTransfer.files?.[0];
        if (file) {
            const dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
            regShowPreview(file);
        }
    });
}

function regShowPreview(file) {
    const zone = document.getElementById('reg-dropzone');
    const preview = document.getElementById('reg-dropzone-preview');
    const name = document.getElementById('reg-dropzone-filename');
    const size = document.getElementById('reg-dropzone-filesize');
    if (!file || !zone || !preview || !name) return;
    name.textContent = file.name;
    if (size) size.textContent = file.size < 1024 * 1024
        ? (file.size / 1024).toFixed(0) + ' KB'
        : (file.size / (1024 * 1024)).toFixed(1) + ' MB';
    zone.classList.add('hidden');
    preview.classList.remove('hidden');
}

function regClearFile() {
    const input = document.getElementById('register-proof');
    const zone = document.getElementById('reg-dropzone');
    const preview = document.getElementById('reg-dropzone-preview');
    if (input) input.value = '';
    if (zone) zone.classList.remove('hidden');
    if (preview) preview.classList.add('hidden');
}

/* ============================================================
   PASSWORD STRENGTH CHECKLIST
   ============================================================ */
function updatePasswordChecklist() {
    const pw  = document.getElementById('register-password')?.value || '';
    const cpw = document.getElementById('register-confirm-password')?.value || '';
    const started = pw.length > 0;

    const rules = [
        { id: 'pwc-length',  pass: pw.length >= 12 },
        { id: 'pwc-upper',   pass: /[A-Z]/.test(pw) },
        { id: 'pwc-lower',   pass: /[a-z]/.test(pw) },
        { id: 'pwc-number',  pass: /[0-9]/.test(pw) },
    ];

    let passCount = 0;
    rules.forEach(({ id, pass }) => {
        const item = document.getElementById(id);
        if (!item) return;
        if (pass) passCount++;
        item.classList.toggle('pwc-pass', pass);
        const icon = item.querySelector('.pwc-icon');
        if (icon) icon.className = pass ? 'fas fa-check-circle pwc-icon' : 'far fa-circle pwc-icon';
    });

    // Strength bar — 4 segments
    for (let i = 1; i <= 4; i++) {
        document.getElementById(`pwseg-${i}`)?.classList.toggle('pwseg-active', started && i <= passCount);
    }

    // Match line under confirm field
    const matchEl = document.getElementById('pwc-match');
    if (matchEl) {
        const match = pw.length > 0 && pw === cpw;
        matchEl.classList.toggle('pwc-pass', match);
        const icon = matchEl.querySelector('.pwc-icon');
        if (icon) icon.className = match ? 'fas fa-check-circle pwc-icon' : 'far fa-circle pwc-icon';
    }
}

async function handleRegister(e) {
    e.preventDefault();
    if (registerSubmitting) return;
    const form = document.getElementById('register-form');
    const consent = document.getElementById('register-terms-accepted');
    const pw = document.getElementById('register-password');
    const cpw = document.getElementById('register-confirm-password');
    if (pw && cpw && pw.value !== cpw.value) {
        showToast('Passwords do not match.', 'error');
        cpw.focus();
        return;
    }
    if (pw && (!/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{12,256}$/.test(pw.value))) {
        showToast('Use at least 12 characters with uppercase and lowercase letters and a number.', 'error');
        pw.focus();
        return;
    }
    if (!consent?.checked) {
        showToast('Please agree to the Terms & Conditions before registering.', 'error');
        consent?.focus();
        return;
    }
    const formData = new FormData(form);
    registerSubmitting = true;
    const submitButton = document.getElementById('reg-submit-btn');
    if (submitButton) submitButton.disabled = true;

    try {
        const recaptchaToken = await executeRecaptcha('register');
        formData.set('g-recaptcha-response', recaptchaToken);
        const res = await fetch('api/register.php', { method: 'POST', body: formData });
        let result;
        try {
            result = await res.json();
        } catch (parseError) {
            showToast(`The server returned an invalid response (HTTP ${res.status}).`, 'error');
            return;
        }

        if (res.ok && result.success) {
            form.reset();
            regClearFile();
            showNotice(
                'Verify your email',
                'Check your inbox to continue',
                [
                    'Use the verification link we sent to your email address.',
                    'After you verify, a barangay administrator will review your residency details before your account is activated.'
                ],
                () => showSection('login')
            );
        } else {
            showToast(result.message || `Registration failed (HTTP ${res.status}).`, 'error');
            if (window.grecaptcha && window._recaptchaRegisterWidgetId !== undefined) {
                grecaptcha.reset(window._recaptchaRegisterWidgetId);
            }
        }
    } catch (error) {
        showToast(error.message || 'Unable to contact the server. Check Apache and the portal URL.', 'error');
    } finally {
        registerSubmitting = false;
        if (submitButton) submitButton.disabled = false;
    }
}

/* ============================================================
   LOGOUT
   ============================================================ */
async function logout() {
    const wasAdmin = currentUser?.role === 'Admin' || document.body.classList.contains('admin-user-view');
    try {
        await fetch('api/logout.php', { method: 'POST' });
    } catch (error) {
        // The local interface still clears the signed-in state if the network is unavailable.
    }
    currentUser = null;
    updateNavBar();
    window.location.href = wasAdmin ? 'index.php#admin-login' : 'index.php#landing';
}

/* ============================================================
   LOAD DOCUMENT TYPES
   ============================================================ */
async function loadDocTypes() {
    const res = await fetch('api/document_types.php');
    const types = await res.json();
    const select = document.getElementById('doc-type');
    clearNode(select);

    const placeholder = el('option', { text: 'Select a document', attrs: { value: '', selected: '', disabled: '' } });
    select.appendChild(placeholder);

    types.forEach(t => {
        const fee = getDocumentFee(t);
        const feeLabel = fee === null ? 'Fee confirmed at the barangay hall' : fee === 0 ? 'Free' : `₱${fee}`;
        const option = el('option', { text: `${t.name} · ${feeLabel}` });
        option.value = t.document_type_id;
        option.dataset.name = t.name;
        if (fee !== null) option.dataset.fee = String(fee);
        select.appendChild(option);
    });
    select.value = '';
    select.onchange = updateDocumentTypeInfo;
    updateDocumentTypeInfo();
}

function updateDocumentTypeInfo() {
    const select = document.getElementById('doc-type');
    const info = document.getElementById('doc-type-info');
    if (!select || !info) return;
    const selected = select.selectedOptions[0];
    const name = selected?.dataset.name;
    const fee = selected?.dataset.fee != null ? Number(selected.dataset.fee) : getDocumentFee(name);
    clearNode(info);
    if (name) {
        const feeText = fee === null ? 'Fee confirmed at the barangay hall' : fee === 0 ? 'Free' : `₱${fee}`;
        info.append(
            el('span', { className: 'request-summary-label', text: 'Request summary' }),
            el('strong', { className: 'request-summary-document', text: name }),
            el('p', { className: 'request-summary-details', text: 'Processing time: 1–3 working days · Bring a valid ID and your tracking number.' }),
            el('strong', { className: 'request-summary-total', text: `Total: ${feeText}` }),
            el('p', { className: 'request-summary-payment', text: 'Pay at the barangay hall when you pick up your document.' })
        );
    }
    info.hidden = !name;
}

function getDocumentFee(documentType) {
    if (documentType?.fee != null && documentType.fee !== '') return Number(documentType.fee);
    const name = String(documentType?.name ?? documentType ?? '').trim().toLowerCase();
    if (name.includes('indigency')) return 0;
    if (name.includes('business')) return 200;
    if (name.includes('clearance') || name.includes('residency')) return 50;
    return null;
}

function getRequestPaymentStatus(paymentStatus, requestStatus, pickupDetails) {
    if (paymentStatus === 'Paid') return 'Paid';
    if (!['Ready for Pickup', 'Completed'].includes(requestStatus) || typeof pickupDetails !== 'string') return 'Unpaid';
    const pickupLine = pickupDetails.split(/\r?\n/).map(line => line.trim()).find(line => /^Pickup:/i.test(line));
    const match = pickupLine?.match(/^Pickup:\s*(.+?)\s+at\s+(.+)$/i);
    if (!match) return 'Unpaid';
    const deadline = new Date(`${match[1]} ${match[2]}`);
    return !Number.isNaN(deadline.getTime()) && Date.now() > deadline.getTime() ? 'Overdue' : 'Unpaid';
}
function formatPickupDetails(value) {
    if (typeof value !== 'string') return '';
    const normalized = value.trim();
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(normalized)) return normalized;
    const date = new Date(normalized);
    return Number.isNaN(date.getTime())
        ? normalized
        : date.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' });
}

/* ============================================================
   RESIDENT: REQUEST DOCUMENT
   ============================================================ */
function selectResidentRequestTab(tab) {
    const selected = tab === 'list' ? 'list' : 'new';
    document.querySelectorAll('.resident-request-tabs [role="tab"]').forEach(button => {
        const active = button.id === `resident-request-tab-${selected}`;
        button.classList.toggle('active', active);
        button.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    document.querySelectorAll('[data-request-panel]').forEach(panel => {
        const active = panel.dataset.requestPanel === selected;
        panel.classList.toggle('active', active);
    });
}

async function handleRequest(e) {
    e.preventDefault();
    const documentTypeId = document.getElementById('doc-type').value;
    const purpose = document.getElementById('doc-purpose').value;
    const button = document.getElementById('request-submit');
    const confirmation = document.getElementById('request-submit-confirmation');
    confirmation.hidden = true;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.textContent = 'Submitting…';
    try {
        const res = await fetch('api/requests.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ documentTypeId, purpose })
        });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.message || 'Unable to submit your request. Please try again.');

        clearNode(confirmation);
        confirmation.append(
            el('strong', { text: 'Request submitted' }),
            el('p', { text: 'We’ll review your request. Keep your tracking number to check its progress.' }),
            el('div', { className: 'request-confirmation-tracking' }, [
                el('span', { text: data.trackingCode }),
                el('button', { className: 'request-copy-button', attrs: { type: 'button', 'aria-label': 'Copy tracking number' }, on: { click: () => copyRequestTracking(data.trackingCode) } }, [el('i', { className: 'fas fa-copy', attrs: { 'aria-hidden': 'true' } })])
            ])
        );
        confirmation.hidden = false;
        document.getElementById('request-form').reset();
        updateDocumentTypeInfo();
        selectResidentRequestTab('list');
        await loadResidentData();
    } catch (error) {
        showToast(error.message || 'Unable to submit your request. Please try again.', 'error');
    } finally {
        button.disabled = false;
        button.removeAttribute('aria-busy');
        button.textContent = 'Submit request';
    }
}

/* ============================================================
   RESIDENT: LOAD MY REQUESTS
   ============================================================ */
async function loadResidentData() {
    document.getElementById('resident-name').textContent = currentUser.name;
    const res = await fetch('api/requests.php');
    const requests = await res.json();
    const list = document.getElementById('request-list');
    clearNode(list);

    if (requests.length === 0) {
        list.append(
            el('p', { className: 'empty-state', text: "You haven't submitted any document requests yet." }),
            el('div', { className: 'request-empty-state' }, [
                el('strong', { text: 'No requests yet' }),
                el('p', { text: 'Your document requests will appear here.' }),
                el('button', { className: 'btn-solid', text: 'Request a document', attrs: { type: 'button' }, on: { click: () => selectResidentRequestTab('new') } })
            ])
        );
        return;
    }

    requests.forEach(r => {
        const tracking = String(r.tracking_code ?? '');
        const status = String(r.current_status ?? 'Pending');
        const fee = getDocumentFee(r.documentType);
        const paymentStatus = getRequestPaymentStatus(r.payment_status, status, r.pickupDetails);
        let displayStatus = status;
        if (['Approved', 'Ready for Pickup'].includes(status) && fee > 0 && paymentStatus !== 'Paid') {
            displayStatus = 'Awaiting payment';
        } else if (status === 'Approved' && fee > 0 && paymentStatus === 'Paid') {
            displayStatus = 'Ready for pickup';
        } else if (status === 'Ready for Pickup') {
            displayStatus = 'Ready for pickup';
        }
        const statusSteps = window.matchMedia('(max-width: 767px)').matches
            ? ['Submitted', 'Approved', 'Pickup', 'Completed']
            : ['Submitted', 'Approved', 'Ready for pickup', 'Completed'];
        const progressIndex = displayStatus === 'Completed' ? 3 : ['Ready for Pickup', 'Ready for pickup'].includes(displayStatus) ? 2 : ['Approved', 'Awaiting payment'].includes(displayStatus) ? 1 : 0;
        const progress = el('ol', { className: `request-progress${status === 'Rejected' ? ' is-rejected' : ''}`, attrs: { 'aria-label': `Request progress: ${displayStatus}` } },
            statusSteps.map((label, index) => el('li', {
                className: index < progressIndex || (status === 'Rejected' && index === 0) ? 'is-done' : index === progressIndex && status !== 'Rejected' ? 'is-current' : '',
                text: label
            }))
        );
        const item = el('article', { className: 'request-item' }, [
            el('div', { className: 'request-item-heading' }, [
                el('h4', { text: r.documentType }),
                el('span', { className: statusClass(displayStatus), text: displayStatus })
            ]),
            el('p', { className: 'request-tracking-line' }, [
                el('strong', { text: 'Tracking: ' }),
                el('span', { text: tracking }),
                el('button', { className: 'request-copy-button', attrs: { type: 'button', 'aria-label': `Copy tracking number ${tracking}` }, on: { click: () => copyRequestTracking(tracking) } }, [el('i', { className: 'fas fa-copy', attrs: { 'aria-hidden': 'true' } })])
            ]),
            el('p', {}, [el('strong', { text: 'Requested: ' }), formatDate(r.created_at)]),
            progress
        ]);

        const feeText = fee === null ? 'To be confirmed' : fee === 0 ? 'Free' : `₱${fee}`;
        const paymentRow = el('div', { className: 'request-payment-line' }, [
            el('strong', { text: `Fee: ${feeText}` }),
            fee === 0
                ? el('span', { className: 'payment-status-chip payment-free', text: 'No payment required' })
                : displayStatus === 'Awaiting payment' && paymentStatus === 'Unpaid'
                    ? null
                    : el('span', { className: `payment-status-chip payment-${paymentStatus.toLowerCase()}`, text: paymentStatus })
        ]);
        item.appendChild(paymentRow);

        if (r.current_status === 'Rejected') {
            item.appendChild(el('div', { className: 'request-note request-note-rejected' }, [
                el('strong', { text: 'Reason: ' }), r.rejection_reason || 'N/A'
            ]));
        }

        if (r.current_status === 'Ready for Pickup') {
            const details = String(r.pickupDetails || '').split(/\r?\n/).map(line => line.trim()).filter(Boolean);
            const pickup = details.find(line => /^Pickup:/i.test(line)) || '';
            const residentNote = details.find(line => /^Note to resident:/i.test(line)) || '';
            item.appendChild(el('div', { className: 'request-note request-note-ready' }, [
                el('p', { text: pickup ? formatPickupDetails(pickup.replace(/^Pickup:\s*/i, '')) : 'Please proceed to Barangay Hall during office hours.' }),
                residentNote ? el('p', { text: residentNote.replace(/^Note to resident:\s*/i, '') }) : null
            ]));
        }

        list.appendChild(item);
    });
}

/* ============================================================
   ADMIN: OVERVIEW AND ACTIVITY
   ============================================================ */
let activityCurrentPage = 1;
let activityPageCount = 0;
let activitySearchTimer = null;
let activitySort = 'date';
let activityDirection = 'DESC';
let activityLastRows = [];

function formatDateTime(value) {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value || '') : date.toLocaleString('en-PH', {
        year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit'
    });
}

async function loadAdminOverview() {
    const chart = document.getElementById('overview-document-chart');
    const oldestList = document.getElementById('overview-oldest-list');
    if (!chart || !oldestList) return;
    try {
        const response = await fetch('api/admin_overview.php');
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'Could not load the overview.');
        markAdminPanelLoaded('overview');
        const summary = data.summary;
        document.getElementById('overview-needs-action').textContent = summary.needs_action;
        const approvedWaiting = Number(summary.approved_waiting) || 0;
        const pendingResidents = Number(summary.pending_residents) || 0;
        document.getElementById('overview-needs-action-caption').textContent = `${approvedWaiting} approved ${approvedWaiting === 1 ? 'request' : 'requests'} · ${pendingResidents} pending ${pendingResidents === 1 ? 'resident' : 'residents'}`;
        document.getElementById('overview-ready-count').textContent = summary.ready_for_pickup;
        document.getElementById('overview-completed-count').textContent = summary.completed_this_week;
        document.getElementById('overview-new-residents-count').textContent = summary.new_residents_this_week;

        clearNode(chart);
        const documentCounts = Array.isArray(data.requests_by_document) ? data.requests_by_document : [];
        const maxCount = Math.max(1, ...documentCounts.map(item => Number(item.request_count) || 0));
        const populated = documentCounts.filter(item => Number(item.request_count) > 0);
        if (!populated.length) {
            chart.appendChild(el('p', { className: 'empty-state', text: 'No request data yet.' }));
        } else {
            populated.forEach(item => {
                const count = Number(item.request_count) || 0;
                chart.appendChild(el('div', { className: 'overview-bar-row' }, [
                    el('span', { className: 'overview-bar-label', text: item.document_type }),
                    el('span', { className: 'overview-bar-track', attrs: { 'aria-label': `${count} requests` } }, [
                        el('span', { className: 'overview-bar-fill', style: { width: `${Math.max(4, count / maxCount * 100)}%` } })
                    ]),
                    el('strong', { className: 'overview-bar-count', text: String(count) })
                ]));
            });
        }

        clearNode(oldestList);
        if (!Array.isArray(data.oldest_waiting) || !data.oldest_waiting.length) {
            oldestList.appendChild(el('p', { className: 'empty-state', text: 'No requests are waiting.' }));
        } else {
            data.oldest_waiting.forEach(request => {
                const open = () => openAdminRequestDetail(Number(request.request_id));
                oldestList.appendChild(el('button', {
                    className: 'overview-oldest-item',
                    attrs: { type: 'button', 'aria-label': `View ${request.tracking_code}, ${request.resident_name}, ${request.document_type}` },
                    on: { click: open }
                }, [
                    el('span', { className: 'overview-oldest-main' }, [
                        el('strong', { text: request.tracking_code }),
                        el('span', { text: `${titleCaseName(request.resident_name)} · ${request.document_type}` })
                    ]),
                    el('span', { className: 'overview-oldest-meta' }, [
                        el('span', { className: statusClass(request.current_status), text: request.current_status }),
                        el('small', { text: formatDate(request.created_at) })
                    ])
                ]));
            });
        }
    } catch (error) {
        chart.replaceChildren(el('p', { className: 'empty-state', text: 'Overview data is unavailable.' }));
        oldestList.replaceChildren(el('p', { className: 'empty-state', text: 'Could not load waiting requests.' }));
    }
}

function openOverviewDestination(destination) {
    if (destination === 'new-residents') {
        switchAdminTab('residents');
        return;
    }
    const status = destination === 'ready' ? 'Ready for Pickup' : destination === 'completed' ? 'Completed' : destination === 'all-requests' ? 'All' : 'Approved';
    switchAdminTab('requests');
    const button = status === 'All'
        ? document.querySelector('.request-filter[data-filter-status="All"]')
        : document.querySelector(`.request-filter[data-filter-status="${status}"]`);
    if (button) setAdminRequestFilter(status, button);
}

function formatActivityAction(action) {
    const labels = {
        approve_resident: 'Resident verified',
        reject_resident: 'Resident rejected',
        proof_viewed: 'Proof viewed',
        request_approved: 'Request approved',
        request_rejected: 'Request rejected',
        request_ready_for_pickup: 'Marked ready for pickup',
        request_completed: 'Request completed',
        disclosure_published: 'Disclosure posted',
        disclosure_draft_saved: 'Draft saved',
        disclosure_edited: 'Disclosure edited',
        disclosure_unpublished: 'Disclosure unpublished', admin_login: 'Admin logged in', failed_admin_login: 'Failed login attempt', admin_deleted: 'Admin deleted'
    };
    return labels[action] || String(action || '').replaceAll('_', ' ');
}
function formatMobileActivityAction(action) {
    const labels = { approve_resident: 'Verified', reject_resident: 'Rejected', admin_login: 'Admin login' };
    return labels[action] || formatActivityAction(action);
}
function getMobileActivityTarget(entry, detailText = '') {
    const raw = String(entry.details || '').trim();
    const requestMatch = raw.match(/^Tracking number: ([^ ·]+) · Resident: (.+?) · Document: (.+)$/);
    if (requestMatch) return `Resident: ${requestMatch[2].trim()}`;
    const residentMatch = raw.match(/^Resident:\s*(.+)$/i);
    if (residentMatch) return `Resident: ${residentMatch[1].replace(/\s*\(ID\s.*$/i, '').trim()}`;
    const legacyResidentMatch = raw.match(/^Resident\s+(.+?)\s*\(ID\s/i);
    if (legacyResidentMatch) return `Resident: ${legacyResidentMatch[1].trim()}`;
    if (entry.action.startsWith('request_')) {
        const documentMatch = raw.match(/Document:\s*(.+)$/i);
        if (documentMatch) return `Document: ${documentMatch[1].trim()}`;
    }
    if (entry.action.startsWith('disclosure_')) {
        let title = detailText || raw;
        if (entry.edit_details) {
            try { title = JSON.parse(entry.edit_details).title || title; } catch (_) {}
        }
        const disclosureMatch = String(title).match(/Disclosure\s+#?\d*:?\s*(.*)$/i);
        return `Disclosure: ${(disclosureMatch?.[1] || title).trim()}`;
    }
    if (entry.action.includes('login')) return raw ? `Account: ${raw}` : '';
    return raw ? `Target: ${raw}` : '';
}
function getActivityDatePresentation(value, sort = activitySort, now = new Date()) {
    const date = new Date(value);
    const key = date.toLocaleDateString('en-CA');
    const yesterdayDate = new Date(now);
    yesterdayDate.setDate(yesterdayDate.getDate()-1);
    const fullDate = date.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'});
    const dayLabel = key === now.toLocaleDateString('en-CA') ? `Today · ${fullDate}`
        : key === yesterdayDate.toLocaleDateString('en-CA') ? `Yesterday · ${fullDate}` : fullDate;
    const time = date.toLocaleTimeString('en-PH',{hour:'numeric',minute:'2-digit'});
    const fullTimestamp = `${date.toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric'})}, ${date.toLocaleTimeString('en-US',{hour:'numeric',minute:'2-digit',second:'2-digit'})}`;
    return {key, fullDate, groupLabel:sort === 'date' ? dayLabel : '', timeLabel:sort === 'date' ? time : `${fullDate} · ${time}`, fullTimestamp};
}

function clearActivityFilters() {
    ['activity-action-filter', 'activity-from-date', 'activity-to-date', 'activity-search'].forEach(id => { const input = document.getElementById(id); if (input) input.value = ''; });
    syncActivityFilterControls();
    loadAdminActivity(1);
}
function activityInputDate(date) {
    return `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
}
function activityPresetRange(preset, now = new Date()) {
    const end = activityInputDate(now), start = new Date(now);
    if (preset === 'week') start.setDate(start.getDate()-6);
    else if (preset === 'month') start.setDate(1);
    return {from:activityInputDate(start), to:end};
}
function syncActivityFilterControls() {
    const action = document.getElementById('activity-action-filter')?.value || '';
    const from = document.getElementById('activity-from-date')?.value || '';
    const to = document.getElementById('activity-to-date')?.value || '';
    const search = document.getElementById('activity-search')?.value.trim() || '';
    const clear = document.querySelector('.activity-clear');
    if (clear) clear.hidden = !(action || from || to || search);
    document.querySelectorAll('.activity-chip[data-activity-preset]').forEach(chip => {
        const range = activityPresetRange(chip.dataset.activityPreset);
        chip.classList.toggle('is-active', from === range.from && to === range.to);
    });
}
function handleActivityFilterChange() {
    syncActivityFilterControls();
    loadAdminActivity(1);
}
function toggleActivityExtraFilters(button) {
    const filters = document.getElementById('activity-extra-filters');
    if (!filters || !button) return;
    const expanded = filters.classList.toggle('is-expanded');
    button.setAttribute('aria-expanded', String(expanded));
}
function setActivityPreset(preset) {
    const range = activityPresetRange(preset);
    document.getElementById('activity-to-date').value = range.to;
    document.getElementById('activity-from-date').value = range.from;
    syncActivityFilterControls();
    loadAdminActivity(1);
}
function sortActivity(column) { if (activitySort === column) activityDirection = activityDirection === 'ASC' ? 'DESC' : 'ASC'; else { activitySort = column; activityDirection = 'ASC'; } loadAdminActivity(1); }
async function exportActivityCsv() {
    const quote = value => `"${String(value ?? '').replaceAll('"', '""')}"`;
    const rows = [['Date', 'Admin', 'Role', 'Action', 'Details']];
    const base = new URLSearchParams({page:'1',action:document.getElementById('activity-action-filter')?.value||'',from:document.getElementById('activity-from-date')?.value||'',to:document.getElementById('activity-to-date')?.value||'',search:document.getElementById('activity-search')?.value.trim()||'',sort:activitySort,direction:activityDirection});
    const first = await fetch(`api/admin_activity.php?${base}`), firstData = await first.json();
    for (let page=1; page<=Math.max(1,firstData.pages||0); page++) {
        if (page > 1) base.set('page',String(page));
        const payload = page===1 ? firstData : await (await fetch(`api/admin_activity.php?${base}`)).json();
        (payload.entries||[]).forEach(r=>rows.push([r.created_at,r.admin_name,r.admin_role,formatActivityAction(r.action),r.details]));
    }
    const blob = new Blob([rows.map(row => row.map(quote).join(',')).join('\r\n')], {type:'text/csv;charset=utf-8'});
    const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'activity-log.csv'; a.click(); URL.revokeObjectURL(a.href);
}

function debouncedLoadAdminActivity() {
    window.clearTimeout(activitySearchTimer);
    syncActivityFilterControls();
    activitySearchTimer = window.setTimeout(() => loadAdminActivity(1), 250);
}

async function loadAdminActivity(page = 1) {
    activityCurrentPage = Math.max(1, Number(page) || 1);
    const tbody = document.getElementById('admin-activity-list');
    const mobileList = document.getElementById('admin-activity-mobile-list');
    if (!tbody) return;
    const isMobileActivityView = window.matchMedia('(max-width: 767px)').matches;
    const query = new URLSearchParams({
        page: String(activityCurrentPage),
        action: document.getElementById('activity-action-filter')?.value || '',
        from: document.getElementById('activity-from-date')?.value || '',
        to: document.getElementById('activity-to-date')?.value || '',
        search: document.getElementById('activity-search')?.value.trim() || ''
        ,sort: isMobileActivityView ? 'date' : activitySort, direction: isMobileActivityView ? 'DESC' : activityDirection
    });
    try {
        const response = await fetch(`api/admin_activity.php?${query}`);
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'Could not load activity.');
        markAdminPanelLoaded('activity');
        clearNode(tbody);
        if (mobileList) clearNode(mobileList);
        activityPageCount = data.pages;
        activityLastRows = data.entries;
        if (!data.entries.length) {
            const td = el('td', { className: 'table-empty-state', attrs: { colspan: '4' } }, [el('strong', {text:'No activity matches these filters.'}), el('button', {className:'btn-outline', text:'Clear filters', attrs:{type:'button'}, on:{click:clearActivityFilters}})]);
            tbody.appendChild(el('tr', { className: 'table-empty-row' }, [td]));
            if (mobileList) mobileList.appendChild(el('p', { className: 'activity-mobile-empty', text: 'No activity matches these filters.' }));
        } else {
            let group = '';
            const mobileGroups = [];
            const mobileGroupMap = new Map();
            data.entries.forEach(entry => {
                const date = new Date(entry.created_at);
                const datePresentation = getActivityDatePresentation(date, activitySort);
                if (datePresentation.groupLabel && datePresentation.key !== group) { group = datePresentation.key; tbody.appendChild(el('tr',{className:'activity-date-group'},[el('th',{text:datePresentation.groupLabel,attrs:{colspan:'4'}})])); }
                const detail = el('td',{className:'activity-detail-cell',attrs:{'data-label':'Details'}});
                const match = String(entry.details||'').match(/^Tracking number: ([^ ·]+) · Resident: (.+?) · Document: (.+)$/);
                if (match) {
                    const tracking = el('a',{className:'activity-tracking',text:match[1],attrs:{href:'#admin-dashboard'},on:{click:e=>{e.preventDefault();openAdminRequestDetail(Number(entry.request_id));}}});
                    detail.append(el('strong',{text:match[3]}), el('small',{className:'activity-request-meta'},[match[2], ' · ', tracking]));
                }
                else if(entry.action==='disclosure_edited' && entry.edit_details) { let snapshot; try { snapshot=JSON.parse(entry.edit_details); } catch (_) {} const title=snapshot ? `Disclosure #${snapshot.disclosure_id}: ${snapshot.title}` : entry.details; detail.appendChild(el('strong',{text:title||'Disclosure edited'})); }
                else if(entry.details === 'Name not recorded') detail.appendChild(el('em',{className:'activity-name-missing',text:'Name not recorded'}));
                else if(entry.action==='approve_resident' || entry.action==='reject_resident' || entry.action==='proof_viewed') {
                    detail.appendChild(el('strong',{text:String(entry.details||'').replace(/^Resident:\s*/,'').replace(/\s*\(ID\s.*$/,'')||'—'}));
                    if (entry.action === 'reject_resident' && entry.reason) detail.appendChild(el('small',{className:'activity-request-meta',text:`Reason: ${entry.reason}`}));
                }
                else detail.appendChild(el('strong',{text:entry.details||'—'}));
                if(entry.action==='disclosure_edited' && entry.edit_details) {
                    let snapshot; try { snapshot=JSON.parse(entry.edit_details); } catch (_) { snapshot=null; }
                    detail.appendChild(el('details',{className:'activity-edit-details'},[el('summary',{text:'View details'}),el('p',{text:snapshot ? `Before: ${snapshot.before?.title||''} — ${snapshot.before?.content||''}\nAfter: ${snapshot.after?.title||''} — ${snapshot.after?.content||''}` : entry.edit_details})]));
                }
                const row = el('tr',{},[
                    el('td',{text:datePresentation.timeLabel,attrs:{'data-label':'Time',title:datePresentation.fullTimestamp}}),
                    el('td',{attrs:{'data-label':'Admin'}},[el('strong',{text:entry.admin_name}),el('small',{className:'activity-admin-role',text:entry.admin_role})]),
                    el('td',{attrs:{'data-label':'Action'}},[el('span',{className:`activity-badge ${entry.action.includes('reject')?'is-red':entry.action.includes('edit')?'is-blue':entry.action.includes('ready')?'is-amber':entry.action.includes('approve')||entry.action.includes('completed')?'is-green':'is-neutral'}`,text:formatActivityAction(entry.action)})]),detail
                ]); tbody.appendChild(row);

                if (mobileList) {
                    const minuteKey = `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}-${date.getHours()}-${date.getMinutes()}`;
                    const identity = `${entry.admin_id ?? entry.admin_name}|${entry.action}|${minuteKey}`;
                    const existing = mobileGroupMap.get(identity);
                    if (existing) {
                        existing.count += 1;
                    } else {
                        const actionClass = entry.action === 'proof_viewed' || entry.action.includes('edit') ? 'is-blue' : entry.action.includes('reject') ? 'is-red' : entry.action.includes('ready') ? 'is-amber' : entry.action.includes('approve') || entry.action.includes('completed') ? 'is-green' : 'is-neutral';
                        const mobileDetail = entry.action === 'disclosure_edited' && entry.edit_details
                            ? (detail.querySelector('strong')?.textContent || 'Disclosure edited')
                            : (Array.from(detail.children).map(node => node.textContent.trim()).filter(Boolean).join(' · ') || '—');
                        const mobileGroup = {
                            identity,
                            dateKey: getActivityDatePresentation(date, 'date').key,
                            dateLabel: getActivityDatePresentation(date, 'date').groupLabel,
                            timeLabel: getActivityDatePresentation(date, 'date').timeLabel,
                            fullTimestamp: getActivityDatePresentation(date, 'date').fullTimestamp,
                            adminName: entry.admin_name || '—',
                            actionLabel: formatMobileActivityAction(entry.action),
                            actionClass,
                            target: getMobileActivityTarget(entry, mobileDetail),
                            count: 1
                        };
                        mobileGroupMap.set(identity, mobileGroup);
                        mobileGroups.push(mobileGroup);
                    }
                }
            });
            if (mobileList) {
                let mobileDateGroup = '';
                mobileGroups.forEach(item => {
                    if (item.dateKey !== mobileDateGroup) {
                        mobileDateGroup = item.dateKey;
                        mobileList.appendChild(el('h3', { className: 'activity-mobile-date-group', text: item.dateLabel }));
                    }
                    const actionContent = [el('span', { className: `activity-badge ${item.actionClass}`, text: item.actionLabel })];
                    if (item.count > 1) actionContent.push(el('span', { className: 'activity-mobile-count', text: `×${item.count}`, attrs: { 'aria-label': `${item.count} matching entries` } }));
                    mobileList.appendChild(el('article', { className: 'activity-mobile-entry' }, [
                        el('div', { className: 'activity-mobile-topline' }, [
                            el('div', { className: 'activity-mobile-action-group' }, actionContent),
                            el('time', { className: 'activity-mobile-time', text: item.timeLabel, attrs: { title: item.fullTimestamp } })
                        ]),
                        el('strong', { className: 'activity-mobile-admin', text: item.adminName }),
                        item.target && item.target.slice(item.target.indexOf(':') + 1).trim().toLocaleLowerCase() !== item.adminName.trim().toLocaleLowerCase()
                            ? el('p', { className: 'activity-mobile-details', text: item.target })
                            : null
                    ]));
                });
            }
        }
        const first=data.total?(activityCurrentPage-1)*data.per_page+1:0, last=Math.min(activityCurrentPage*data.per_page,data.total);
        document.getElementById('activity-page-status').textContent = data.total ? `Page ${activityCurrentPage} of ${Math.max(1,data.pages)}` : 'No entries';
        document.getElementById('activity-result-count').textContent = `Showing ${first}-${last} of ${data.total}`;
        ['date','admin','action'].forEach(key=>{const icon=document.getElementById(`activity-sort-${key}`); if(icon){icon.textContent=activitySort===key?(activityDirection==='ASC'?'↑':'↓'):'↕';icon.classList.toggle('is-active',activitySort===key);}});
        document.getElementById('activity-prev').disabled = activityCurrentPage <= 1 || data.pages === 0;
        document.getElementById('activity-next').disabled = activityCurrentPage >= data.pages;
    } catch (error) {
        tbody.replaceChildren(el('tr', { className: 'table-empty-row' }, [
            el('td', { className: 'table-empty-state', text: 'Activity log is unavailable.', attrs: { colspan: '4' } })
        ]));
        if (mobileList) mobileList.replaceChildren(el('p', { className: 'activity-mobile-empty', text: 'Activity log is unavailable.' }));
        document.getElementById('activity-page-status').textContent = 'Activity unavailable';
        document.getElementById('activity-result-count').textContent = 'Showing 0-0 of 0';
        document.getElementById('activity-prev').disabled = true;
        document.getElementById('activity-next').disabled = true;
    }
}

/* ============================================================
   ADMIN: LOAD REQUESTS
   ============================================================ */
async function loadAdminData() {
    const query = new URLSearchParams({
        page: String(adminRequestPage),
        status: adminRequestStatusFilter,
        search: document.getElementById('admin-request-search')?.value.trim() || ''
    });
    const res = await fetch(`api/requests.php?${query}`);
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.message || 'Could not load requests.');
    markAdminPanelLoaded('requests');
    adminRequestRows = Array.isArray(data.requests) ? data.requests : [];
    adminRequestPages = Number(data.pages) || 0;
    adminRequestTotal = Number(data.total) || 0;
    adminRequestStatusCounts = data.status_counts || {};
    const pendingCount = (Number(adminRequestStatusCounts.Pending) || 0) + (Number(adminRequestStatusCounts.Approved) || 0);
    const requestBadge = document.getElementById('requests-queue-count');
    if (requestBadge) {
        requestBadge.textContent = String(pendingCount);
        requestBadge.setAttribute('aria-label', `${pendingCount} requests need action`);
        requestBadge.hidden = pendingCount === 0;
    }
    // Mirror badge in the nav hamburger
    const navRequestBadge = document.getElementById('nav-requests-queue-count');
    if (navRequestBadge) {
        navRequestBadge.textContent = String(pendingCount);
        navRequestBadge.setAttribute('aria-label', `${pendingCount} requests need action`);
        navRequestBadge.hidden = pendingCount === 0;
    }

    renderAdminRequests();
}

function changeAdminRequestPage(direction) {
    const nextPage = adminRequestPage + Number(direction || 0);
    if (nextPage < 1 || nextPage > adminRequestPages || nextPage === adminRequestPage) return;
    adminRequestPage = nextPage;
    loadAdminData().catch(() => showToast('Could not load this request page.', 'error'));
}

function setAdminRequestFilter(status, button) {
    adminRequestStatusFilter = status;
    adminRequestPage = 1;
    document.querySelectorAll('.request-filter').forEach(filterButton => {
        const active = filterButton === button;
        filterButton.classList.toggle('active', active);
        filterButton.setAttribute('aria-pressed', String(active));
    });
    loadAdminData().catch(() => showToast('Could not load requests.', 'error'));
}

function filterAdminRequests() {
    clearTimeout(adminRequestSearchTimer);
    adminRequestSearchTimer = setTimeout(() => {
        adminRequestPage = 1;
        loadAdminData().catch(() => showToast('Could not search requests.', 'error'));
    }, 250);
}

function renderAdminRequests() {
    const tbody = document.getElementById('admin-request-list');
    const mobileList = document.getElementById('admin-request-mobile-list');
    if (!tbody) return;
    clearNode(tbody);
    if (mobileList) clearNode(mobileList);
    document.querySelectorAll('.request-filter').forEach(filterButton => {
        const status = filterButton.dataset.filterStatus;
        const count = Number(adminRequestStatusCounts[status]) || 0;
        filterButton.textContent = `${filterButton.dataset.filterLabel} (${count})`;
    });
    const filtered = adminRequestRows;
    const pageStatus = document.getElementById('request-page-status');
    const previous = document.getElementById('request-prev');
    const next = document.getElementById('request-next');
    if (pageStatus) pageStatus.textContent = adminRequestTotal ? `Page ${adminRequestPage} of ${Math.max(1, adminRequestPages)} · ${adminRequestTotal} requests` : 'No requests';
    if (previous) previous.disabled = adminRequestPage <= 1 || adminRequestPages === 0;
    if (next) next.disabled = adminRequestPage >= adminRequestPages || adminRequestPages === 0;

    if (filtered.length === 0) {
        tbody.appendChild(el('tr', { className: 'table-empty-row' }, [el('td', { className: 'table-empty-state', text: 'No requests here yet.', attrs: { colspan: '7' } })]));
        if (mobileList) {
            const activeFilter = document.querySelector('.request-filter.active');
            const filterName = activeFilter?.dataset.filterStatus === 'All' ? 'All requests' : `${activeFilter?.dataset.filterLabel || adminRequestStatusFilter} requests`;
            mobileList.appendChild(el('div', { className: 'admin-request-empty-state' }, [
                el('strong', { text: 'No requests here' }),
                el('span', { text: filterName })
            ]));
        }
        return;
    }

    filtered.forEach(r => {
        const requestId = Number(r.request_id);
        const tracking = String(r.tracking_code ?? '');
        const actionGroup = el('div', { className: 'table-action-group' });
        const actionsCell = el('td', { attrs: { 'data-label': 'Actions' } }, [actionGroup]);
        const paymentStatus = getRequestPaymentStatus(r.payment_status, r.current_status, r.pickupDetails);
        const fee = getDocumentFee(r.documentType);
        if (r.current_status === 'Pending') {
            actionGroup.appendChild(el('button', {
                className: 'action-btn btn-approve', text: 'Approve',
                attrs: { type: 'button', 'aria-label': `Approve request ${tracking}` },
                on: { click: () => processRequest(requestId, 'Approve') }
            }));
            actionGroup.appendChild(el('button', {
                className: 'action-btn btn-reject', text: 'Reject',
                attrs: { type: 'button', 'aria-label': `Reject request ${tracking}` },
                on: { click: () => processRequest(requestId, 'Reject') }
            }));
        } else if (r.current_status === 'Approved') {
            actionGroup.appendChild(el('button', {
                className: 'action-btn btn-set-ready', text: 'Set ready',
                attrs: { type: 'button', 'aria-label': `Set request ${tracking} ready for pickup` },
                on: { click: () => processRequest(requestId, 'Ready', r) }
            }));
        } else if (r.current_status === 'Ready for Pickup') {
            actionGroup.appendChild(el('button', {
                className: 'action-btn btn-ready', text: 'Mark as picked up',
                attrs: { type: 'button', 'aria-label': `Mark request ${tracking} as picked up` },
                on: { click: () => processRequest(requestId, 'Pickup') }
            }));
        } else {
            actionGroup.appendChild(el('span', { className: 'action-dash', text: '—', attrs: { 'aria-label': 'No action available' } }));
        }

        const row = el('tr', {
            className: 'request-row-clickable',
            attrs: { tabindex: '0', 'aria-label': `Open request ${tracking} for ${r.residentName}` },
            on: {
                click: event => { if (!event.target.closest('button, a')) openAdminRequestDetail(requestId); },
                keydown: event => {
                    if ((event.key === 'Enter' || event.key === ' ') && !event.target.closest('button, a')) {
                        event.preventDefault();
                        openAdminRequestDetail(requestId);
                    }
                }
            }
        }, [
            el('td', { attrs: { 'data-label': 'Tracking' } }, [el('strong', { text: tracking })]),
            el('td', { text: r.residentName, attrs: { 'data-label': 'Resident' } }),
            el('td', { text: r.documentType, attrs: { 'data-label': 'Document' } }),
            el('td', { text: formatDate(r.created_at), attrs: { 'data-label': 'Requested', title: String(r.created_at ?? '') } }),
            el('td', { attrs: { 'data-label': 'Status' } }, [el('span', { className: statusClass(r.current_status), text: r.current_status })]),
            el('td', { attrs: { 'data-label': 'Payment' } }, [el('span', {
                className: `payment-status-chip ${fee === 0 ? 'payment-free' : `payment-${paymentStatus.toLowerCase()}`}`,
                text: fee === 0 ? 'No payment required' : paymentStatus
            })]),
            actionsCell
        ]);
        tbody.appendChild(row);

        if (mobileList) {
            const openRequest = () => openAdminRequestDetail(requestId);
            const paymentPill = r.current_status === 'Rejected'
                ? el('span', { className: 'admin-request-mobile-payment-dash', text: '–', attrs: { 'aria-label': 'No payment status for rejected request' } })
                : el('span', {
                    className: `payment-status-chip ${fee === 0 ? 'payment-free' : `payment-${paymentStatus.toLowerCase()}`}`,
                    text: fee === 0 ? 'No payment required' : paymentStatus
                });
            mobileList.appendChild(el('article', {
                className: 'admin-request-mobile-card',
                attrs: { tabindex: '0', role: 'group', 'aria-label': `Open request ${tracking} for ${r.residentName}` },
                on: {
                    click: event => { if (!event.target.closest('button, a')) openRequest(); },
                    keydown: event => {
                        if ((event.key === 'Enter' || event.key === ' ') && !event.target.closest('button, a')) {
                            event.preventDefault();
                            openRequest();
                        }
                    }
                }
            }, [
                el('div', { className: 'admin-request-mobile-top' }, [
                    el('strong', { className: 'admin-request-mobile-tracking', text: tracking }),
                    el('span', { className: statusClass(r.current_status), text: r.current_status })
                ]),
                el('div', { className: 'admin-request-mobile-resident', text: r.residentName || 'Resident' }),
                el('div', { className: 'admin-request-mobile-document', text: r.documentType || 'Document' }),
                el('div', { className: 'admin-request-mobile-bottom' }, [
                    paymentPill,
                    el('button', { className: 'admin-request-mobile-view', text: 'View', attrs: { type: 'button', 'aria-label': `View request ${tracking}` }, on: { click: openRequest } })
                ])
            ]));
        }
    });
}

let currentAdminDetailId = null;

function closeAdminRequestDetail() {
    currentAdminDetailId = null;
    document.getElementById('admin-request-detail-panel').hidden = true;
    document.getElementById('admin-requests-panel').hidden = false;
}

async function copyRequestTracking(tracking) {
    try {
        await navigator.clipboard.writeText(tracking);
        showToast('Tracking number copied.', 'success');
    } catch (error) {
        const temporary = document.createElement('textarea');
        temporary.value = tracking;
        temporary.style.position = 'fixed';
        temporary.style.opacity = '0';
        document.body.appendChild(temporary);
        temporary.select();
        document.execCommand('copy');
        temporary.remove();
        showToast('Tracking number copied.', 'success');
    }
}

async function saveRequestInternalNote(requestId) {
    const field = document.getElementById('request-internal-note');
    const button = document.getElementById('save-request-internal-note');
    if (!field || !button) return;
    button.disabled = true;
    button.textContent = 'Saving…';
    try {
        const response = await fetch('api/admin_request_note.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ request_id: requestId, note: field.value })
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Could not save the note.');
        showToast(result.message || 'Internal note saved.', 'success');
    } catch (error) {
        showToast(error.message || 'Could not save the note.', 'error');
    } finally {
        button.disabled = window.matchMedia('(max-width: 767px)').matches && !field.value.trim();
        button.textContent = 'Save note';
    }
}

async function updateAdminRequestPayment(requestId, paymentStatus, button) {
    if (!button) return;
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = 'Saving…';
    try {
        const response = await fetch('api/admin_request_payment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ request_id: requestId, payment_status: paymentStatus })
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Could not update payment status.');
        showToast(`Payment marked ${paymentStatus.toLowerCase()}.`, 'success');
        await openAdminRequestDetail(requestId);
    } catch (error) {
        showToast(error.message || 'Could not update payment status.', 'error');
        button.disabled = false;
        button.textContent = originalText;
    }
}
async function openAdminRequestDetail(requestId) {
    switchAdminTab('requests');
    currentAdminDetailId = requestId;
    document.getElementById('admin-requests-panel').hidden = true;
    const panel = document.getElementById('admin-request-detail-panel');
    const content = document.getElementById('request-detail-content');
    panel.hidden = false;
    content.replaceChildren(el('p', { className: 'empty-state', text: 'Loading request details…' }));
    try {
        const response = await fetch(`api/admin_request_detail.php?id=${encodeURIComponent(requestId)}`);
        const data = await response.json().catch(() => null);
        if (!data) throw new Error('Could not load this request. Please refresh and try again.');
        if (!response.ok || !data.success) throw new Error(data.message || 'Could not load this request.');
        const mobileRequestDetail = window.matchMedia('(max-width: 767px)').matches;
        const request = data.request;
        const residentName = titleCaseName([request.first_name, request.middle_name, request.last_name].filter(Boolean).join(' '));
        const actionRequest = {
            request_id: Number(request.request_id),
            tracking_code: request.tracking_code,
            residentName,
            documentType: request.document_type,
            current_status: request.current_status
        };
        const infoList = (entries) => el('dl', { className: 'request-detail-dl' }, entries.map(([label, value]) =>
            el('div', {}, [el('dt', { text: label }), el('dd', { text: value || '—' })])
        ));
        const timelineItems = [el('li', { className: 'request-timeline-item is-complete' }, [
            el('span', { className: 'request-timeline-dot', attrs: { 'aria-hidden': 'true' } }),
            el('div', {}, [el('strong', { text: 'Submitted' }), el('p', { text: `${formatDateTime(request.created_at)} · Resident submission` })])
        ])];
        const statuses = { Approved: 'Approved', 'Ready for Pickup': 'Ready for pickup', Completed: 'Completed', Rejected: 'Rejected' };
        const history = Array.isArray(data.timeline) ? data.timeline : [];
        const rejectedStep = history.find(step => step.status === 'Rejected');
        const timelineStatuses = rejectedStep ? ['Rejected'] : ['Approved', 'Ready for Pickup', 'Completed'];
        timelineStatuses.forEach(status => {
            const step = history.find(item => item.status === status);
            const label = statuses[status] || status;
            timelineItems.push(el('li', { className: `request-timeline-item${step ? ' is-complete' : ''}` }, [
                el('span', { className: 'request-timeline-dot', attrs: { 'aria-hidden': 'true' } }),
                el('div', {}, step ? [
                    el('strong', { text: label }),
                    el('p', { text: `${formatDateTime(step.created_at)} · ${step.admin_name || 'Admin'}` }),
                    status === 'Rejected' && step.notes ? el('small', { text: `Reason: ${step.notes}` }) : null
                ] : [el('strong', { text: label }), el('p', { text: 'Not completed' })])
            ]));
        });
        const readyHistory = history.find(step => step.status === 'Ready for Pickup' && step.notes);
        const actionGroup = el('div', { className: 'table-action-group request-detail-actions' });
        if (request.current_status === 'Pending') {
            actionGroup.appendChild(el('button', { className: 'action-btn btn-approve', text: 'Approve', attrs: { type: 'button' }, on: { click: () => processRequest(requestId, 'Approve') } }));
            actionGroup.appendChild(el('button', { className: 'action-btn btn-reject', text: 'Reject', attrs: { type: 'button' }, on: { click: () => processRequest(requestId, 'Reject') } }));
        } else if (request.current_status === 'Approved') {
            actionGroup.appendChild(el('button', { className: 'action-btn btn-set-ready', text: 'Set ready', attrs: { type: 'button' }, on: { click: () => processRequest(requestId, 'Ready', actionRequest) } }));
        } else if (request.current_status === 'Ready for Pickup') {
            actionGroup.appendChild(el('button', { className: 'action-btn btn-ready', text: 'Mark as picked up', attrs: { type: 'button' }, on: { click: () => processRequest(requestId, 'Pickup') } }));
        } else if (!mobileRequestDetail) {
            actionGroup.appendChild(el('span', { className: 'action-dash', text: '—' }));
        }

        const requestHeading = el('div', { className: 'panel-header request-detail-header' }, [
            el('div', {}, [el('p', { className: 'eyebrow', text: 'Request record' }), el('h2', { id: 'request-detail-title', text: request.document_type }), el('p', { text: `Requested ${formatDateTime(request.created_at)}` })]),
            el('span', { className: statusClass(request.current_status), text: request.current_status })
        ]);
        const trackingLine = el('div', { className: 'request-tracking-line' }, [
            el('strong', { text: request.tracking_code }),
            el('button', { className: 'link-button', text: 'Copy', attrs: { type: 'button', 'aria-label': 'Copy tracking number' }, on: { click: () => copyRequestTracking(request.tracking_code) } })
        ]);
        const paymentStatus = getRequestPaymentStatus(request.payment_status, request.current_status, readyHistory?.notes);
        const fee = getDocumentFee(request.document_type);
        const feeText = fee === null ? 'To be confirmed' : fee === 0 ? 'Free' : `₱${fee}`;
        const paymentButton = fee === 0 ? null : el('button', {
            className: paymentStatus === 'Paid' ? 'btn-outline' : 'btn-solid',
            text: paymentStatus === 'Paid' ? 'Mark as unpaid' : 'Mark as paid',
            attrs: { type: 'button' },
            on: { click: (event) => updateAdminRequestPayment(requestId, paymentStatus === 'Paid' ? 'Unpaid' : 'Paid', event.currentTarget) }
        });
        const pickupText = typeof readyHistory?.notes === 'string' ? readyHistory.notes.trim() : '';
        const mobilePickupCard = pickupText ? el('section', { className: 'card request-detail-card request-detail-pickup' }, [
            el('h3', { text: 'Pickup details' }),
            el('p', { className: 'pickup-detail-text', text: formatPickupDetails(pickupText) }),
            el('p', { className: 'pickup-detail-entered', text: `Entered ${formatDate(readyHistory.created_at)}` })
        ]) : null;
        const desktopPickupCard = el('section', { className: 'card request-detail-card request-detail-pickup' }, [
            el('h3', { text: 'Pickup details' }),
            el('p', { className: 'pickup-detail-text', text: readyHistory ? formatPickupDetails(readyHistory.notes) : 'Pickup date and time have not been set.' })
        ]);
        const paymentStatusPill = fee === 0
            ? el('span', { className: 'payment-status-chip payment-free', text: 'No payment required' })
            : el('span', { className: `payment-status-chip payment-${paymentStatus.toLowerCase()}`, text: paymentStatus });
        const mobilePaymentSummary = fee === 0
            ? el('div', { className: 'request-admin-payment-summary is-free' }, [paymentStatusPill])
            : el('div', { className: 'request-admin-payment-summary' }, [
                el('div', {}, [el('span', { className: 'field-help', text: 'Document fee' }), el('strong', { text: feeText })]),
                paymentStatusPill
            ]);
        const desktopPaymentSummary = el('div', { className: 'request-admin-payment-summary' }, [
            el('div', {}, [el('span', { className: 'field-help', text: 'Document fee' }), el('strong', { text: feeText })]),
            paymentStatusPill
        ]);
        const paymentSummary = mobileRequestDetail ? mobilePaymentSummary : desktopPaymentSummary;
        const saveNoteButton = el('button', { className: 'btn-solid', text: 'Save note', attrs: { id: 'save-request-internal-note', type: 'button' }, on: { click: () => saveRequestInternalNote(requestId) } });
        const detailGrid = el('div', { className: 'request-detail-grid' }, [
            el('section', { className: 'card request-detail-card' }, [
                el('h3', { text: 'Resident details' }),
                infoList([['Name', residentName], ['Email', request.email], ['Phone', request.phone_number], ['Address', request.address]]),
                el('h3', { className: 'request-detail-subheading', text: 'Purpose of request' }),
                el('p', { className: 'request-detail-purpose', text: request.purpose || 'No purpose provided.' })
            ]),
            el('section', { className: 'card request-detail-card' }, [
                el('h3', { text: 'Request timeline' }),
                el('ol', { className: 'request-timeline' }, timelineItems)
            ]),
            mobileRequestDetail ? mobilePickupCard : desktopPickupCard,
            el('section', { className: 'card request-detail-card request-detail-payment' }, [
                el('h3', { text: 'Payment' }),
                paymentSummary,
                paymentButton
            ]),
            el('section', { className: 'card request-detail-card request-detail-notes' }, [
                el('h3', { text: 'Internal notes' }),
                el('p', { className: 'field-help', text: 'Visible only to administrators.' }),
                el('textarea', { attrs: { id: 'request-internal-note', rows: '4', 'aria-label': 'Internal notes' } }),
                saveNoteButton
            ]),
            (actionGroup.children.length && (mobileRequestDetail || request.current_status !== 'Completed' && request.current_status !== 'Rejected')) ? el('section', { className: 'card request-detail-card request-detail-action-card' }, [
                el('h3', { text: 'Actions' }), actionGroup
            ]) : null
        ]);
        content.replaceChildren(requestHeading, trackingLine, detailGrid);
        const noteField = document.getElementById('request-internal-note');
        noteField.value = data.internal_note?.note_text || '';
        saveNoteButton.disabled = mobileRequestDetail && !noteField.value.trim();
        noteField.addEventListener('input', () => { saveNoteButton.disabled = mobileRequestDetail && !noteField.value.trim(); });
    } catch (error) {
        content.replaceChildren(el('p', { className: 'empty-state', text: error.message || 'Request details are unavailable.' }));
    }
}

/* ============================================================
   ADMIN: PROCESS REQUEST (with Custom Modal)
   ============================================================ */
function processRequest(id, action, request = null) {
    if (action === 'Reject') {
        openModal(
            'Reject Request',
            'Please provide a reason for rejecting this request. This will be shown to the resident.',
            'Rejection Reason',
            'e.g., Incomplete requirements, please resubmit...',
            (reason) => sendProcessRequest(id, action, reason)
        );
    } else if (action === 'Ready') {
        if (request) openPickupModal(request);
    } else if (action === 'Pickup') {
        if (window.confirm('Mark this request as picked up?')) sendProcessRequest(id, action, null);
    } else {
        // Approve — no details needed
        sendProcessRequest(id, action, null);
    }
}

async function sendProcessRequest(id, action, details) {
    try {
        const res = await fetch('api/process_request.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, action, details })
        });
        const result = await res.json();
        if (res.ok && result.success) {
            const messages = { Approve: 'Request approved.', Reject: 'Request rejected.', Ready: 'Request marked ready for pickup.', Pickup: 'Request marked as picked up.' };
            showToast(messages[action] || 'Request updated.', 'success');
            invalidateAdminPanelCache('overview', 'activity');
            loadAdminData();
            if (currentAdminDetailId === Number(id)) openAdminRequestDetail(currentAdminDetailId);
            return true;
        }
        showToast(result.message || 'Something went wrong.', 'error');
        return false;
    } catch (error) {
        showToast('Something went wrong. Please try again.', 'error');
        return false;
    }
}

/* ============================================================
   ADMIN: PENDING RESIDENTS
   ============================================================ */
async function loadPendingResidents() {
    const res = await fetch(`api/pending_residents.php?page=${pendingResidentPage}`);
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.message || 'Could not load pending residents.');
    const residents = Array.isArray(data.residents) ? data.residents : [];
    pendingResidentPage = Number(data.page) || 1;
    pendingResidentPages = Number(data.pages) || 0;
    markAdminPanelLoaded('residents');
    const tbody = document.getElementById('admin-resident-list');
    const mobileList = document.getElementById('pending-residents-mobile-list');
    clearNode(tbody);
    if (mobileList) clearNode(mobileList);
    const pendingCount = Number(data.total) || 0;
    const residentBadge = document.getElementById('pending-residents-count');
    if (residentBadge) {
        residentBadge.textContent = String(pendingCount);
        residentBadge.setAttribute('aria-label', `${pendingCount} pending residents`);
        residentBadge.hidden = pendingCount === 0;
    }
    // Mirror badge in the nav hamburger
    const navResidentBadge = document.getElementById('nav-pending-residents-count');
    if (navResidentBadge) {
        navResidentBadge.textContent = String(pendingCount);
        navResidentBadge.setAttribute('aria-label', `${pendingCount} pending residents`);
        navResidentBadge.hidden = pendingCount === 0;
    }
    const pageStatus = document.getElementById('resident-page-status');
    const previous = document.getElementById('resident-prev');
    const next = document.getElementById('resident-next');
    if (pageStatus) pageStatus.textContent = pendingCount ? `Page ${pendingResidentPage} of ${pendingResidentPages} · ${pendingCount} residents` : 'No pending residents';
    if (previous) previous.disabled = pendingResidentPage <= 1 || pendingResidentPages === 0;
    if (next) next.disabled = pendingResidentPage >= pendingResidentPages || pendingResidentPages === 0;

    if (!Array.isArray(residents) || residents.length === 0) {
        const emptyCell = el('td', {
            className: 'table-empty-state',
            text: 'No residents waiting for verification',
            attrs: { colspan: '6' }
        });
        tbody.appendChild(el('tr', { className: 'table-empty-row' }, [emptyCell]));
        if (mobileList) mobileList.appendChild(el('p', { className: 'resident-mobile-empty', text: 'No residents waiting for verification' }));
        return;
    }

    residents.forEach(r => {
        const residentId = Number(r.resident_id);
        const residentName = titleCaseName(`${r.first_name ?? ''} ${r.middle_name ?? ''} ${r.last_name ?? ''}`);
        const safeProofPath = (typeof r.proof_url === 'string'
            && /^api\/resident_proof\.php\?id=\d+$/.test(r.proof_url))
            ? r.proof_url
            : null;

        const proofCell = el('td', { attrs: { 'data-label': 'Proof' } }, [
            safeProofPath
                ? el('button', {
                    className: 'link-button',
                    text: 'View proof',
                    attrs: { type: 'button', 'aria-haspopup': 'dialog', 'aria-label': `View proof of residency for ${residentName}` },
                on: { click: () => openProofPreview(safeProofPath, {
                    residentId, name: residentName, email: r.email, address: r.address, createdAt: r.created_at, proofType: r.proof_type
                }) }
                })
                : el('span', { className: 'unavailable-label', text: 'No file' })
        ]);

        const actionGroup = el('div', { className: 'table-action-group' }, [
            el('button', {
                className: 'action-btn btn-approve', text: 'Verify',
                attrs: { type: 'button', 'aria-label': `Verify resident ${residentName}` },
                on: { click: () => openResidentConfirmation(residentId, 'verify', residentName) }
            }),
            el('button', {
                className: 'action-btn btn-reject', text: 'Reject',
                attrs: { type: 'button', 'aria-label': `Reject resident ${residentName}` },
                on: { click: () => openResidentConfirmation(residentId, 'reject', residentName) }
            })
        ]);

        tbody.appendChild(el('tr', {}, [
            el('td', { attrs: { 'data-label': 'Name', title: residentName } }, [el('strong', { className: 'resident-admin-name', text: residentName })]),
            el('td', { text: r.email, attrs: { 'data-label': 'Email', title: r.email || '' } }),
            el('td', { text: r.address, attrs: { 'data-label': 'Address', title: r.address || '' } }),
            proofCell,
            el('td', { attrs: { 'data-label': 'Registered' } }, [
                el('span', { className: 'resident-registered-date', text: formatDate(r.created_at) }),
                el('small', { className: 'resident-registered-time', text: new Date(String(r.created_at || '').replace(' ', 'T')).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' }) })
            ]),
            el('td', { attrs: { 'data-label': 'Actions' } }, [actionGroup])
        ]));

        const registeredTime = new Date(String(r.created_at || '').replace(' ', 'T')).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
        const mobileProof = safeProofPath
            ? el('button', {
                className: 'resident-mobile-proof', text: 'View proof',
                attrs: { type: 'button', 'aria-haspopup': 'dialog', 'aria-label': `View proof of residency for ${residentName}` },
                on: { click: () => openProofPreview(safeProofPath, { residentId, name: residentName, email: r.email, address: r.address, createdAt: r.created_at, proofType: r.proof_type }) }
            })
            : el('span', { className: 'resident-mobile-proof unavailable-label', text: 'No proof available' });
        const mobileActions = el('div', { className: 'resident-mobile-actions' }, [
            el('button', { className: 'action-btn btn-reject', text: 'Reject', attrs: { type: 'button', 'aria-label': `Reject resident ${residentName}` }, on: { click: () => openResidentConfirmation(residentId, 'reject', residentName) } }),
            el('button', { className: 'action-btn btn-approve', text: 'Verify', attrs: { type: 'button', 'aria-label': `Verify resident ${residentName}` }, on: { click: () => openResidentConfirmation(residentId, 'verify', residentName) } })
        ]);
        if (mobileList) mobileList.appendChild(el('article', { className: 'resident-mobile-card' }, [
            el('div', { className: 'resident-mobile-person' }, [
                el('strong', { className: 'resident-mobile-name', text: residentName }),
                el('span', { className: 'resident-mobile-email', text: r.email || 'No email provided', attrs: { title: r.email || '' } })
            ]),
            el('p', { className: 'resident-mobile-registered', text: `Registered ${formatDate(r.created_at)} · ${registeredTime}` }),
            el('div', { className: 'resident-mobile-proof-row' }, [mobileProof]),
            mobileActions
        ]));
    });
}

function changePendingResidentPage(direction) {
    const nextPage = pendingResidentPage + Number(direction || 0);
    if (nextPage < 1 || nextPage > pendingResidentPages || nextPage === pendingResidentPage) return;
    pendingResidentPage = nextPage;
    loadPendingResidents().catch(() => showToast('Could not load this resident page.', 'error'));
}

function titleCaseName(name) {
    return String(name || '').trim().replace(/\s+/g, ' ').toLocaleLowerCase('en-PH').replace(/(^|[\s'-])([\p{L}])/gu, (_, boundary, letter) => boundary + letter.toLocaleUpperCase('en-PH'));
}

function openResidentConfirmation(residentId, action, residentName) {
    const verifying = action === 'verify';
    openModal(
        verifying ? 'Verify resident' : 'Reject resident',
        verifying
            ? `Verify ${residentName} and activate their account?`
            : `Reject ${residentName}? You may add a reason for the Activity log.`,
        'Reason (optional)',
        'Add an optional reason',
        reason => verifyResident(residentId, action, reason),
        'textarea',
        {
            residentConfirmation: true,
            showInput: !verifying,
            inputRequired: false,
            maxLength: 1000,
            confirmLabel: verifying ? 'Verify' : 'Reject',
            confirmClass: verifying ? 'btn-solid' : 'btn-outline btn-reject'
        }
    );
}

async function verifyResident(residentId, action, rejectionReason = '') {
    try {
        const res = await fetch('api/approve_resident.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ residentId, action: action === 'verify' ? 'approve' : 'reject', reason: rejectionReason })
        });
        const result = await res.json();
        if (!res.ok || !result.success) {
            showToast(result.message || `Request failed with HTTP ${res.status}.`, 'error');
            return false;
        }
        showToast(result.message, result.emailSent === false ? 'info' : 'success');
        invalidateAdminPanelCache('overview', 'activity');
        try {
            await loadPendingResidents();
        } catch (refreshError) {
            showToast('The action completed, but the pending list could not refresh. Reload the page.', 'info');
        }
        return true;
    } catch (error) {
        showToast('Could not reach the server. Check your connection and try again.', 'error');
        return false;
    }
}

/* ============================================================
   ADMIN TABS
   ============================================================ */
function updateAdminTableScrollFade(scrollArea) {
    if (!scrollArea) return;
    const mobile = window.matchMedia('(max-width: 767px)').matches;
    const hasMoreRight = mobile
        && scrollArea.scrollWidth > scrollArea.clientWidth + 1
        && scrollArea.scrollLeft + scrollArea.clientWidth < scrollArea.scrollWidth - 1;
    scrollArea.classList.toggle('has-more-right', hasMoreRight);
}

function refreshAdminTableScrollFades() {
    document.querySelectorAll('#admin-dashboard .admin-table-scroll').forEach(updateAdminTableScrollFade);
}

function initAdminTableScrollFades() {
    const dashboard = document.getElementById('admin-dashboard');
    if (!dashboard) return;
    dashboard.querySelectorAll('.admin-table-scroll').forEach(area => {
        area.addEventListener('scroll', () => updateAdminTableScrollFade(area), { passive: true });
    });
    window.addEventListener('resize', refreshAdminTableScrollFades, { passive: true });
    const observer = new MutationObserver(() => requestAnimationFrame(refreshAdminTableScrollFades));
    observer.observe(dashboard, { childList: true, subtree: true, attributes: true, attributeFilter: ['hidden'] });
    requestAnimationFrame(refreshAdminTableScrollFades);
}

function switchAdminTab(tab, options = {}) {
    const adminRoutes = {
        overview: 'overview',
        requests: 'requests',
        residents: 'residents',
        disclosures: 'disclosures',
        activity: 'activity'
    };
    const routeChanged = currentUser?.role === 'Admin' && adminRoutes[tab] && window.INITIAL_ADMIN_SECTION !== tab;
    // The hamburger keeps workspace tabs available while an admin browses
    // Public Records. Return to the dashboard before changing its hidden tab.
    if (currentUser?.role === 'Admin' && document.querySelector('.section.active')?.id !== 'admin-dashboard') {
        showSection('admin-dashboard');
    }
    const disclosurePanel = document.getElementById('admin-disclosures-panel');
    if (tab !== 'disclosures' && disclosurePanel && !disclosurePanel.hidden && !confirmLeavingDisclosureComposer()) return;
    if (routeChanged) {
        window.INITIAL_ADMIN_SECTION = tab;
        if (options.updateHistory !== false) {
            const destination = new URL(`admin_${adminRoutes[tab]}.php`, document.baseURI);
            history.pushState({ section: 'admin-dashboard', adminSection: tab }, '', `${destination.pathname}#admin-${tab}`);
        }
    }
    const panels = {
        overview: document.getElementById('admin-overview-panel'),
        requests: document.getElementById('admin-requests-panel'),
        residents: document.getElementById('admin-residents-panel'),
        disclosures: document.getElementById('admin-disclosures-panel'),
        activity: document.getElementById('admin-activity-panel')
    };
    const btns = {
        overview: document.getElementById('tab-overview'),
        requests: document.getElementById('tab-requests'),
        residents: document.getElementById('tab-residents'),
        disclosures: document.getElementById('tab-disclosures'),
        activity: document.getElementById('tab-activity')
    };
    // Nav mirror tab buttons (hamburger menu on mobile)
    const navBtns = {
        overview:    document.getElementById('nav-tab-overview'),
        requests:    document.getElementById('nav-tab-requests'),
        residents:   document.getElementById('nav-tab-residents'),
        disclosures: document.getElementById('nav-tab-disclosures'),
        activity:    document.getElementById('nav-tab-activity'),
    };

    const updatePanel = () => {
        Object.keys(panels).forEach(key => {
            const active = key === tab;
            panels[key].hidden = !active;
            btns[key].classList.toggle('active', active);
            btns[key].setAttribute('aria-selected', String(active));
            btns[key].tabIndex = active ? 0 : -1;
            btns[key].setAttribute('aria-current', active ? 'page' : 'false');
            if (navBtns[key]) {
                navBtns[key].classList.toggle('active', active);
                navBtns[key].setAttribute('aria-current', active ? 'page' : 'false');
            }
        });
        currentAdminDetailId = null;
        const detailPanel = document.getElementById('admin-request-detail-panel');
        if (detailPanel) detailPanel.hidden = true;
        if (tab === 'activity' && adminPanelNeedsRefresh('activity')) loadAdminActivity(1);
        if (tab === 'overview' && adminPanelNeedsRefresh('overview')) loadAdminOverview();
        if (tab === 'requests' && adminPanelNeedsRefresh('requests')) loadAdminData();
        if (tab === 'residents' && adminPanelNeedsRefresh('residents')) loadPendingResidents();
        if (tab === 'disclosures') {
            initDisclosureComposer();
            if (adminPanelNeedsRefresh('disclosures')) loadRecentDisclosures();
        }
        requestAnimationFrame(refreshAdminTableScrollFades);
    };
    updatePanel();
}

function openDisclosureComposer() {
    if (!currentUser || currentUser.role !== 'Admin') return;
    showSection('admin-dashboard');
    switchAdminTab('disclosures');
}

/* ============================================================
   ADMIN: POST DISCLOSURE
   ============================================================ */
async function handlePostDisclosure(e) {
    e.preventDefault();
    const form = document.getElementById('disclosure-form');
    const previewPublish = form.dataset.previewPublish === 'true';
    form.dataset.previewPublish = 'false';
    if (!validateDisclosureForm()) return;
    const categoryId = document.getElementById('disc-category').value;
    const title = document.getElementById('disc-title').value.trim();
    const content = document.getElementById('disc-content').value.trim();
    const fileInput = document.getElementById('disc-file');
    if (!editingDisclosureId && !previewPublish && !window.confirm('This will be visible to all residents. Publish this disclosure?')) return;

    const submitButton = document.getElementById('publish-disclosure-button');
    const previewButton = document.getElementById('disclosure-preview-publish');
    submitButton.disabled = true;
    submitButton.setAttribute('aria-busy', 'true');
    previewButton.disabled = true;

    try {
        let res;
        if (editingDisclosureId) {
            res = await fetch('api/disclosures.php', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: editingDisclosureId, categoryId, title, content })
            });
        } else {
            const formData = new FormData();
            formData.append('categoryId', categoryId);
            formData.append('title', title);
            formData.append('content', content);
            if (fileInput.files.length > 0) formData.append('file', fileInput.files[0]);
            res = await fetch('api/disclosures.php', { method: 'POST', body: formData });
        }
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.message || 'Disclosure could not be saved.');

        const wasEditing = Boolean(editingDisclosureId);
        document.getElementById('disclosure-form').reset();
        clearDisclosureFile();
        updateDisclosureCharacterCount();
        cancelDisclosureEdit();
        try { sessionStorage.removeItem('disclosure-draft'); } catch { /* Ignore unavailable browser storage. */ }
        if (wasEditing) {
            setDisclosureStatus('Disclosure updated successfully.');
            showToast('Disclosure updated successfully.', 'success');
        } else {
            setDisclosureStatus('Disclosure posted successfully.', `bulletin.html?category=${encodeURIComponent(categoryId)}`);
            showToast('Disclosure posted successfully.', 'success');
        }
        updateDisclosureSavedSnapshot();
        invalidateAdminPanelCache('activity');
        await Promise.all([loadRecentDisclosures(), loadDisclosures(true), loadLatestAnnouncements()]);
    } catch (error) {
        setDisclosureStatus(error.message || 'Disclosure could not be saved.', null, true);
        showToast(error.message || 'Disclosure could not be saved.', 'error');
    } finally {
        submitButton.disabled = false;
        submitButton.removeAttribute('aria-busy');
        previewButton.disabled = false;
    }
}

function initDisclosureComposer() {
    const form = document.getElementById('disclosure-form');
    if (!form || form.dataset.initialized === 'true') return;
    form.dataset.initialized = 'true';

    const zone = document.getElementById('disc-dropzone');
    const input = document.getElementById('disc-file');
    const title = document.getElementById('disc-title');
    const description = document.getElementById('disc-content');
    try {
        const savedDraft = sessionStorage.getItem('disclosure-draft');
        if (savedDraft) {
            const draft = JSON.parse(savedDraft);
            if (draft.categoryId) document.getElementById('disc-category').value = String(draft.categoryId);
            if (draft.title) document.getElementById('disc-title').value = draft.title;
            if (draft.content) description.value = draft.content;
        }
    } catch { /* Draft storage is optional when browser storage is unavailable. */ }
    updateDisclosureCharacterCount();
    updateDisclosureSavedSnapshot();
    [document.getElementById('disc-category'), title, description].forEach(field => {
        field.addEventListener('input', () => {
            updateDisclosureCharacterCount();
            clearDisclosureFieldError(field.id);
            clearDisclosureStatus();
        });
        field.addEventListener('change', () => {
            clearDisclosureFieldError(field.id);
            clearDisclosureStatus();
        });
    });
    document.getElementById('save-disclosure-draft').addEventListener('click', saveDisclosureDraft);
    document.getElementById('preview-disclosure-button').addEventListener('click', previewDisclosure);
    document.getElementById('disclosure-preview-back').addEventListener('click', closeDisclosurePreview);
    document.getElementById('disclosure-preview-publish').addEventListener('click', () => {
        form.dataset.previewPublish = 'true';
        form.requestSubmit(document.getElementById('publish-disclosure-button'));
    });
    document.getElementById('cancel-disclosure-edit').addEventListener('click', () => cancelDisclosureEdit());

    input.addEventListener('change', () => {
        setDisclosureFile(input.files?.[0]);
        clearDisclosureStatus();
    });
    zone.addEventListener('dragover', event => { event.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', event => {
        if (!zone.contains(event.relatedTarget)) zone.classList.remove('drag-over');
    });
    zone.addEventListener('drop', event => {
        event.preventDefault();
        zone.classList.remove('drag-over');
        const file = event.dataTransfer.files?.[0];
        if (!file) return;
        const transfer = new DataTransfer();
        transfer.items.add(file);
        input.files = transfer.files;
        setDisclosureFile(file);
        clearDisclosureStatus();
    });

    window.addEventListener('beforeunload', event => {
        if (!hasUnsavedDisclosureChanges()) return;
        event.preventDefault();
        event.returnValue = '';
    });
}

function updateDisclosureCharacterCount() {
    const title = document.getElementById('disc-title');
    const titleCounter = document.getElementById('disc-title-counter');
    const field = document.getElementById('disc-content');
    const counter = document.getElementById('disc-content-counter');
    if (title && titleCounter) titleCounter.textContent = `${title.value.length}/120`;
    if (field && counter) counter.textContent = `${field.value.length}/2000`;
}

function setDisclosureFile(file) {
    const zone = document.getElementById('disc-dropzone');
    const preview = document.getElementById('disc-file-preview');
    const input = document.getElementById('disc-file');
    if (!zone || !preview || !input) return;
    if (!file) return;
    const extension = file.name.split('.').pop()?.toLowerCase();
    const allowed = ['pdf', 'docx', 'jpg', 'jpeg', 'png'];
    if (!allowed.includes(extension) || file.size > 5 * 1024 * 1024) {
        clearDisclosureFile(false);
        const error = document.getElementById('disc-file-error');
        error.textContent = !allowed.includes(extension)
            ? 'Choose a PDF, DOCX, JPG, or PNG file.'
            : 'The attachment must be 5 MB or smaller.';
        error.hidden = false;
        return;
    }
    clearDisclosureFile();
    const currentInput = document.getElementById('disc-file');
    const transfer = new DataTransfer();
    transfer.items.add(file);
    currentInput.files = transfer.files;
    document.getElementById('disc-file-name').textContent = file.name;
    document.getElementById('disc-file-size').textContent = file.size < 1024 * 1024
        ? `${Math.max(1, Math.round(file.size / 1024))} KB`
        : `${(file.size / (1024 * 1024)).toFixed(1)} MB`;
    const isImage = ['jpg', 'jpeg', 'png'].includes(extension);
    const thumbnail = document.getElementById('disc-file-thumbnail');
    document.getElementById('disc-file-icon').classList.toggle('hidden', isImage);
    thumbnail.classList.toggle('hidden', !isImage);
    if (isImage) {
        disclosureFileObjectUrl = URL.createObjectURL(file);
        thumbnail.src = disclosureFileObjectUrl;
    }
    document.getElementById('disc-file-error').hidden = true;
    zone.classList.add('hidden');
    preview.classList.remove('hidden');
}

function clearDisclosureFile(clearError = true) {
    const input = document.getElementById('disc-file');
    const zone = document.getElementById('disc-dropzone');
    const preview = document.getElementById('disc-file-preview');
    if (disclosureFileObjectUrl) URL.revokeObjectURL(disclosureFileObjectUrl);
    disclosureFileObjectUrl = null;
    if (input) input.value = '';
    if (zone) zone.classList.toggle('hidden', Boolean(editingDisclosureId));
    if (preview) preview.classList.add('hidden');
    const thumbnail = document.getElementById('disc-file-thumbnail');
    const icon = document.getElementById('disc-file-icon');
    if (thumbnail) { thumbnail.removeAttribute('src'); thumbnail.classList.add('hidden'); }
    if (icon) icon.classList.remove('hidden');
    if (clearError) {
        const error = document.getElementById('disc-file-error');
        if (error) { error.textContent = ''; error.hidden = true; }
    }
}

function disclosureSnapshot(includeAttachment = true) {
    const file = document.getElementById('disc-file')?.files?.[0];
    return JSON.stringify({
        categoryId: document.getElementById('disc-category')?.value || '',
        title: document.getElementById('disc-title')?.value || '',
        content: document.getElementById('disc-content')?.value || '',
        disclosureId: editingDisclosureId,
        attachment: includeAttachment && file ? `${file.name}:${file.size}` : null
    });
}

function updateDisclosureSavedSnapshot(ignoreAttachment = false) {
    disclosureSavedSnapshot = disclosureSnapshot(!ignoreAttachment);
}

function hasUnsavedDisclosureChanges() {
    const form = document.getElementById('disclosure-form');
    return Boolean(form && disclosureSavedSnapshot && disclosureSnapshot() !== disclosureSavedSnapshot);
}

function confirmLeavingDisclosureComposer() {
    const panel = document.getElementById('admin-disclosures-panel');
    if (!panel || panel.hidden || !hasUnsavedDisclosureChanges()) return true;
    if (!window.confirm('You have unsaved disclosure changes. Leave this page?')) return false;
    updateDisclosureSavedSnapshot();
    return true;
}

function clearDisclosureStatus() {
    const status = document.getElementById('disclosure-status');
    if (!status) return;
    status.replaceChildren();
    status.hidden = true;
    status.classList.remove('is-error');
}

function setDisclosureStatus(message, linkHref = null, isError = false) {
    const status = document.getElementById('disclosure-status');
    status.replaceChildren(document.createTextNode(message));
    if (linkHref) status.append(' ', el('a', { text: 'View on bulletin board', attrs: { href: linkHref } }));
    status.hidden = false;
    status.classList.toggle('is-error', isError);
}

function setDisclosureFieldError(fieldId, message) {
    const field = document.getElementById(fieldId);
    const error = document.getElementById(`${fieldId}-error`);
    if (!field || !error) return;
    field.setAttribute('aria-invalid', 'true');
    error.textContent = message;
    error.hidden = false;
}

function clearDisclosureFieldError(fieldId) {
    const field = document.getElementById(fieldId);
    const error = document.getElementById(`${fieldId}-error`);
    if (field) field.removeAttribute('aria-invalid');
    if (error) { error.textContent = ''; error.hidden = true; }
}

function validateDisclosureForm() {
    const category = document.getElementById('disc-category');
    const title = document.getElementById('disc-title');
    const content = document.getElementById('disc-content');
    [category, title, content].forEach(field => clearDisclosureFieldError(field.id));
    const invalid = [];
    if (!category.value) { setDisclosureFieldError('disc-category', 'Choose a category.'); invalid.push(category); }
    if (!title.value.trim()) { setDisclosureFieldError('disc-title', 'Enter a title.'); invalid.push(title); }
    else if (title.value.length > 120) { setDisclosureFieldError('disc-title', 'Title must be 120 characters or fewer.'); invalid.push(title); }
    if (!content.value.trim()) { setDisclosureFieldError('disc-content', 'Enter a description.'); invalid.push(content); }
    else if (content.value.length > 2000) { setDisclosureFieldError('disc-content', 'Description must be 2,000 characters or fewer.'); invalid.push(content); }
    if (invalid.length) { invalid[0].focus(); return false; }
    return true;
}

function previewDisclosure() {
    if (!validateDisclosureForm()) return;
    const category = document.getElementById('disc-category');
    const title = document.getElementById('disc-title').value.trim();
    const content = document.getElementById('disc-content').value.trim();
    const post = document.getElementById('disclosure-preview-post');
    clearNode(post);
    const article = el('article', { className: 'category-post disclosure-preview-post' }, [
        el('span', { className: 'recent-disclosure-category', text: category.selectedOptions[0]?.textContent || 'Disclosure' }),
        el('h2', { text: title }),
        el('time', { text: formatDate(new Date()), attrs: { datetime: new Date().toISOString() } }),
        el('p', { text: content })
    ]);
    const file = document.getElementById('disc-file').files?.[0];
    const attachmentName = file?.name || editingDisclosureAttachment?.name;
    const existingAttachmentPath = typeof editingDisclosureAttachment?.path === 'string'
        && /^api\/public_attachment\.php\?id=\d+$/.test(editingDisclosureAttachment.path)
        ? editingDisclosureAttachment.path
        : null;
    const attachmentHref = file && disclosureFileObjectUrl ? disclosureFileObjectUrl : existingAttachmentPath;
    if (attachmentName) {
        article.appendChild(el('div', { className: 'attachment-section' }, [
            el('span', { className: 'attachment-label' }, [el('i', { className: 'fas fa-paperclip', attrs: { 'aria-hidden': 'true' } }), ' Attachment']),
            attachmentHref
                ? el('a', { className: 'attachment-link', text: attachmentName, attrs: { href: attachmentHref, target: '_blank', rel: 'noopener' } })
                : el('span', { className: 'attachment-link', text: attachmentName })
        ]));
    }
    post.appendChild(article);
    const publishButton = document.getElementById('disclosure-preview-publish');
    publishButton.textContent = editingDisclosureId ? 'Save changes' : 'Publish disclosure';
    document.getElementById('disclosure-form').classList.add('is-previewing');
    document.getElementById('disclosure-preview').classList.remove('hidden');
    document.getElementById('disclosure-preview').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function closeDisclosurePreview() {
    document.getElementById('disclosure-form').classList.remove('is-previewing');
    document.getElementById('disclosure-preview').classList.add('hidden');
    document.getElementById('preview-disclosure-button').focus();
}

async function saveDisclosureDraft() {
    const draft = {
        categoryId: document.getElementById('disc-category').value,
        title: document.getElementById('disc-title').value,
        content: document.getElementById('disc-content').value,
        savedAt: new Date().toISOString()
    };
    try {
        sessionStorage.setItem('disclosure-draft', JSON.stringify(draft));
        updateDisclosureSavedSnapshot(true);
        const savedTime = new Date(draft.savedAt).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
        setDisclosureStatus(`Draft saved at ${savedTime}.${document.getElementById('disc-file').files.length ? ' The attachment is not stored in the draft.' : ''}`);
        showToast('Draft saved.', 'success');
        try {
            const response = await fetch('api/log_disclosure_draft.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ title: draft.title })
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Activity entry could not be saved.');
        } catch (error) {
            showToast('Draft saved here, but the Activity log entry could not be saved.', 'error');
        }
    } catch {
        setDisclosureStatus('Draft could not be saved in this browser.', null, true);
        showToast('Draft could not be saved in this browser.', 'error');
    }
}

async function loadRecentDisclosures() {
    const list = document.getElementById('recent-disclosures-list');
    if (!list) return;
    try {
        const res = await fetch('api/disclosures.php');
        if (!res.ok) throw new Error('Could not load disclosures.');
        const items = await res.json();
        markAdminPanelLoaded('disclosures');
        clearNode(list);
        if (!Array.isArray(items) || items.length === 0) {
            list.appendChild(el('p', { className: 'empty-state', text: 'No recent disclosures.' }));
            return;
        }
        items.forEach(item => {
            const actions = el('div', { className: 'recent-disclosure-actions' }, [
                el('button', { className: 'action-btn', text: 'Edit', attrs: { type: 'button' }, on: { click: () => editDisclosure(item) } }),
                el('button', { className: 'action-btn btn-reject', text: 'Unpublish', attrs: { type: 'button' }, on: { click: () => unpublishDisclosure(item.disclosure_id, item.title) } })
            ]);
            const isDraft = String(item.status || '').toLowerCase() === 'draft' || item.is_draft === true || Number(item.is_draft) === 1;
            list.appendChild(el('article', { className: 'recent-disclosure-item' }, [
                el('div', { className: 'recent-disclosure-copy' }, [
                    el('h4', { text: item.title }),
                    el('div', { className: 'recent-disclosure-meta' }, [
                        el('span', { className: 'recent-disclosure-category', text: item.category_name || 'Disclosure' }),
                        el('span', { className: `recent-disclosure-status ${isDraft ? 'is-draft' : 'is-published'}`, text: isDraft ? 'Draft' : 'Published' }),
                        el('time', { text: formatDate(item.timestamp_posted), attrs: { datetime: String(item.timestamp_posted ?? '') } })
                    ])
                ]),
                actions
            ]));
        });
    } catch (error) {
        clearNode(list);
        list.appendChild(el('p', { className: 'empty-state', text: error.message || 'Could not load disclosures.' }));
    }
}

function editDisclosure(item) {
    editingDisclosureId = Number(item.disclosure_id);
    editingDisclosureAttachment = item.file_path ? { name: item.file_name || 'Open attached file', path: item.file_path } : null;
    document.getElementById('disc-category').value = String(item.category_id);
    document.getElementById('disc-title').value = item.title || '';
    document.getElementById('disc-content').value = item.content || '';
    clearDisclosureFile();
    document.getElementById('disc-dropzone').classList.add('hidden');
    document.getElementById('disclosure-attachment-edit-note').classList.remove('hidden');
    updateDisclosureCharacterCount();
    document.getElementById('publish-disclosure-button').textContent = 'Save changes';
    document.getElementById('disclosure-edit-actions').classList.remove('hidden');
    clearDisclosureStatus();
    closeDisclosurePreview();
    updateDisclosureSavedSnapshot();
    document.getElementById('disc-title').focus();
    document.getElementById('disclosure-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function cancelDisclosureEdit() {
    editingDisclosureId = null;
    editingDisclosureAttachment = null;
    const button = document.getElementById('publish-disclosure-button');
    const actions = document.getElementById('disclosure-edit-actions');
    if (button) button.textContent = 'Publish disclosure';
    const previewPublish = document.getElementById('disclosure-preview-publish');
    if (previewPublish) previewPublish.textContent = 'Publish disclosure';
    if (actions) actions.classList.add('hidden');
    document.getElementById('disclosure-form')?.reset();
    clearDisclosureFile();
    document.getElementById('disclosure-attachment-edit-note')?.classList.add('hidden');
    document.getElementById('disclosure-form')?.classList.remove('is-previewing');
    document.getElementById('disclosure-preview')?.classList.add('hidden');
    updateDisclosureCharacterCount();
    ['disc-category', 'disc-title', 'disc-content'].forEach(clearDisclosureFieldError);
    updateDisclosureSavedSnapshot();
}

async function unpublishDisclosure(id, title) {
    if (!window.confirm(`Unpublish “${title}”? It will no longer be visible to residents.`)) return;
    try {
        const res = await fetch('api/disclosures.php', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.message || 'Disclosure could not be unpublished.');
        showToast('Disclosure unpublished.', 'success');
        invalidateAdminPanelCache('activity');
        await loadRecentDisclosures();
    } catch (error) {
        showToast(error.message || 'Disclosure could not be unpublished.', 'error');
    }
}

async function deleteBulletinDisclosure(id, title) {
    if (currentUser?.role !== 'Admin') return;
    if (window.matchMedia('(max-width: 767px)').matches) {
        pendingBulletinDelete = { id, title };
        bulletinDeleteReturnFocus = document.activeElement;
        document.getElementById('bulletin-delete-dialog')?.classList.remove('hidden');
        document.body.classList.add('modal-open');
        document.documentElement.classList.add('modal-open-root');
        document.getElementById('bulletin-delete-cancel')?.focus();
        return;
    }
    if (!window.confirm(`Delete “${title}” from Public Records?`)) return;
    await performBulletinDelete(id, title);
}

function closeBulletinDeleteDialog() {
    const dialog = document.getElementById('bulletin-delete-dialog');
    if (dialog) dialog.classList.add('hidden');
    pendingBulletinDelete = null;
    if (!document.querySelector('.modal-overlay:not(.hidden)')) {
        document.body.classList.remove('modal-open');
        document.documentElement.classList.remove('modal-open-root');
    }
    bulletinDeleteReturnFocus?.focus?.();
    bulletinDeleteReturnFocus = null;
}

async function confirmBulletinDelete() {
    if (!pendingBulletinDelete) return;
    const { id, title } = pendingBulletinDelete;
    closeBulletinDeleteDialog();
    await performBulletinDelete(id, title);
}

async function performBulletinDelete(id, title) {
    try {
        const res = await fetch('api/disclosures.php', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.message || 'Announcement could not be deleted.');
        showToast('Announcement deleted from Public Records.', 'success');
        await Promise.all([loadDisclosures(true), loadLatestAnnouncements(), loadRecentDisclosures()]);
    } catch (error) {
        showToast(error.message || 'Announcement could not be deleted.', 'error');
    }
}

function toggleBulletinReadMore(button) {
    const item = button?.closest('.bulletin-list li');
    if (!item) return;
    const expanded = item.classList.toggle('is-expanded');
    button.textContent = expanded ? 'Read less' : 'Read more';
    button.setAttribute('aria-expanded', String(expanded));
}

/* ============================================================
   PUBLIC: LOAD DISCLOSURES
   ============================================================ */
async function loadDisclosures(force = false) {
    if (publicDisclosuresLoaded && !force) return;
    const res = await fetch('api/disclosures.php');
    if (!res.ok) throw new Error('Public records could not be loaded.');
    const items = await res.json();
    const lists = {
        1: document.getElementById('list-1'),
        2: document.getElementById('list-2'),
        3: document.getElementById('list-3'),
        4: document.getElementById('list-4')
    };
    // Reset each list to a single "No items yet" placeholder.
    Object.values(lists).forEach(l => {
        if (!l) return;
        clearNode(l);
        l.appendChild(el('li', {}, [el('p', { className: 'empty-state', text: 'No items yet.' })]));
    });

    items.forEach(item => {
        const list = lists[item.category_id];
        if (!list) return;

        // Drop the placeholder the first time a real item lands in this list.
        const placeholder = list.querySelector('.empty-state');
        if (placeholder) clearNode(list);

        const isAdmin = currentUser?.role === 'Admin';
        const content = el('p', { className: 'bulletin-item-content', text: item.content });
        const readMoreButton = el('button', {
            className: 'bulletin-read-more',
            text: 'Read more',
            attrs: { type: 'button', 'aria-expanded': 'false' },
            on: { click: event => toggleBulletinReadMore(event.currentTarget) }
        });
        const itemActions = el('div', { className: `bulletin-item-actions${isAdmin ? ' has-admin-actions' : ''}` }, [readMoreButton]);
        const li = el('li', { className: isAdmin ? 'bulletin-item-admin' : '' }, [
            el('strong', { className: 'bulletin-item-title', text: item.title }),
            el('time', {
                className: 'bulletin-item-date',
                text: formatDate(item.timestamp_posted),
                attrs: { datetime: String(item.timestamp_posted ?? '') }
            }),
            content
        ]);

        // Only allow relative upload paths; reject absolute URLs and
        // dangerous schemes (e.g. javascript:) that could be injected.
        const safeFilePath = (typeof item.file_path === 'string'
            && /^api\/public_attachment\.php\?id=\d+$/.test(item.file_path))
            ? item.file_path
            : null;

        if (safeFilePath) {
            const icon = el('i', { className: 'fas fa-paperclip' });
            const label = el('span', { className: 'attachment-label' }, [icon, ' Attachment']);
            const link = el('a', {
                className: 'attachment-link',
                text: item.file_name || 'Open attached file',
                attrs: { href: safeFilePath, target: '_blank', rel: 'noopener' }
            });
            li.appendChild(el('div', { className: 'attachment-section' }, [label, link]));
        }

        if (isAdmin) {
            itemActions.appendChild(el('button', {
                className: 'action-btn btn-reject bulletin-delete-button',
                text: 'Delete',
                attrs: { type: 'button', 'aria-label': `Delete ${item.title}` },
                on: { click: () => deleteBulletinDisclosure(item.disclosure_id, item.title) }
            }));
        }

        li.appendChild(itemActions);
        list.appendChild(li);
    });
    publicDisclosuresLoaded = true;
    requestAnimationFrame(() => {
        document.querySelectorAll('#bulletin .bulletin-list li').forEach(item => {
            const content = item.querySelector('.bulletin-item-content');
            const link = item.querySelector('.bulletin-read-more');
            if (!content || !link) return;
            link.hidden = !window.matchMedia('(max-width: 767px)').matches || content.scrollHeight <= content.clientHeight + 1;
        });
    });
}

/* ============================================================
   LANDING: LATEST ANNOUNCEMENTS
   ============================================================ */
function setupMobileCarousel(track, dots) {
    if (!track || !dots) return;
    if (track.__mobileCarouselScroll) {
        track.removeEventListener('scroll', track.__mobileCarouselScroll);
    }
    dots.replaceChildren();

    const cards = Array.from(track.children).filter(card =>
        card.matches('.doc-item, .announcement-card')
    );
    if (cards.length < 2) {
        dots.hidden = true;
        return;
    }

    dots.hidden = false;
    const dotButtons = cards.map((card, index) => {
        const dot = document.createElement('button');
        dot.type = 'button';
        dot.className = 'mobile-carousel-dot';
        dot.setAttribute('aria-label', `Show card ${index + 1} of ${cards.length}`);
        if (index === 0) dot.setAttribute('aria-current', 'true');
        dot.addEventListener('click', () => {
            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            track.scrollTo({
                left: card.offsetLeft - cards[0].offsetLeft,
                behavior: reducedMotion ? 'auto' : 'smooth'
            });
        });
        dots.appendChild(dot);
        return dot;
    });

    let frame = 0;
    const updateActiveDot = () => {
        frame = 0;
        const start = cards[0].offsetLeft;
        const activeIndex = cards.reduce((closestIndex, card, index) => {
            const distance = Math.abs(card.offsetLeft - start - track.scrollLeft);
            const closestDistance = Math.abs(cards[closestIndex].offsetLeft - start - track.scrollLeft);
            return distance < closestDistance ? index : closestIndex;
        }, 0);
        dotButtons.forEach((dot, index) => {
            if (index === activeIndex) dot.setAttribute('aria-current', 'true');
            else dot.removeAttribute('aria-current');
        });
    };
    const onScroll = () => {
        if (!frame) frame = window.requestAnimationFrame(updateActiveDot);
    };
    track.addEventListener('scroll', onScroll, { passive: true });
    track.__mobileCarouselScroll = onScroll;
}

async function loadLatestAnnouncements() {
    const container = document.getElementById('latest-announcements');
    if (!container) return;
    const status = document.getElementById('latest-announcements-status');

    try {
        const res = await fetch('api/disclosures.php');
        if (!res.ok) throw new Error('Request failed');
        const items = await res.json();
        const latest = Array.isArray(items) ? items.slice(0, 3) : [];

        clearNode(container);
        container.setAttribute('aria-busy', 'false');

        if (latest.length === 0) {
            container.appendChild(el('p', { className: 'empty-state', text: 'No announcements posted yet.' }));
            setupMobileCarousel(container, document.getElementById('announcement-carousel-dots'));
            return;
        }

        latest.forEach(item => {
            const card = el('button', {
                className: 'announcement-card',
                attrs: { type: 'button' }
            }, [
                el('span', { className: 'announcement-category', text: item.category_name || 'Notice' }),
                el('strong', { className: 'announcement-title', text: item.title }),
                el('p', { className: 'announcement-excerpt', text: item.content }),
                el('time', {
                    className: 'announcement-date',
                    text: item.timestamp_posted ? formatDate(item.timestamp_posted) : '',
                    attrs: { datetime: String(item.timestamp_posted ?? '') }
                })
            ]);
            card.addEventListener('click', () => showSection('bulletin'));
            container.appendChild(card);
        });
        setupMobileCarousel(container, document.getElementById('announcement-carousel-dots'));
    } catch (error) {
        clearNode(container);
        container.setAttribute('aria-busy', 'false');
        container.appendChild(el('p', { className: 'empty-state', text: 'Unable to load announcements right now.' }));
        setupMobileCarousel(container, document.getElementById('announcement-carousel-dots'));
    }
}

/* ============================================================
   INITIALIZATION
   ============================================================ */
document.addEventListener('DOMContentLoaded', async () => {
    const footerYear = document.getElementById('footer-year');
    if (footerYear) footerYear.textContent = String(new Date().getFullYear());
    initAdminTableScrollFades();
    setupMobileCarousel(document.querySelector('.doc-strip'), document.getElementById('doc-carousel-dots'));

    window.addEventListener('resize', () => {
        if (window.innerWidth > 720) closeMobileMenu();
    });

    const navMenu = document.getElementById('nav-menu');
    navMenu.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            closeMobileMenu();
            document.getElementById('nav-toggle').focus();
        }
    });
    navMenu.addEventListener('click', event => {
        if (navMenu.classList.contains('open') && event.target.closest('a, button')) closeMobileMenu();
    });

    const navBackdrop = document.getElementById('nav-backdrop');
    if (navBackdrop) {
        navBackdrop.addEventListener('click', () => {
            closeMobileMenu();
            document.getElementById('nav-toggle').focus();
        });
    }

    if (location.hash === '#admin-login') {
        history.replaceState({ section: 'admin-login' }, '', '#admin-login');
        updateNavBar();
        showSection('admin-login', true);
    }

    // Auto-set year
    const yr = new Date().getFullYear();
    ['year-1', 'year-2', 'year-admin'].forEach(id => {
        const yearEl = document.getElementById(id);
        if (yearEl) yearEl.textContent = yr;
    });

    // Read password reset token from meta tag (set by index.php when ?reset=TOKEN is in the URL).
    // This is more reliable than reading from the hash which can be stripped by server redirects.
    const metaResetToken = document.querySelector('meta[name="password-reset-token"]')?.content || '';
    const metaResetContext = document.querySelector('meta[name="password-reset-context"]')?.content || '';
    if (metaResetToken && /^[a-f0-9]{64}$/.test(metaResetToken)) {
        passwordResetToken = metaResetToken;
        passwordResetContext = metaResetContext === 'admin' ? 'admin' : 'resident';
    }

    // Handle email verification link — must run before the session check so an
    // admin being logged in on the same browser doesn't hijack the navigation.
    if (emailVerificationToken() && /^[a-f0-9]{64}$/.test(emailVerificationToken())) {
        history.replaceState(history.state, '', `${location.pathname}#login`);
        try {
            const response = await fetch('api/verify_email.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token: emailVerificationToken() })
            });
            const result = await response.json();
            if (result.pageUnavailable) {
                showSection('page-unavailable');
            } else {
                showSection('login', true);
                showToast(result.message || 'Email verification could not be completed.', response.ok && result.success ? 'success' : 'error');
            }
        } catch {
            showSection('login', true);
            showToast('Email verification could not be completed. Try the link again.', 'error');
        }
        document.body.removeAttribute('data-entry-route');
        return;
    }

    // If a password reset token came in via query param, go straight to the reset form.
    if (passwordResetToken && /^[a-f0-9]{64}$/.test(passwordResetToken)) {
        try {
            const response = await fetch('api/validate_reset_token.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ token: passwordResetToken })
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                showSection('page-unavailable');
                return;
            }
            history.replaceState(history.state, '', `${location.pathname}#forgot-password`);
            document.body.removeAttribute('data-entry-route');
            showSection('forgot-password', true);
        } catch {
            showSection('page-unavailable');
        }
        return;
    }

    // Check session
    const sessionRes = await fetch('api/session.php');
    if (sessionRes.ok) {
        const sessionData = await sessionRes.json();
        if (sessionData.loggedIn) {
            currentUser = { id: sessionData.id, name: sessionData.name, role: sessionData.role };
            updateNavBar();
            if (location.hash === '#admin-login') {
                history.replaceState({ section: 'admin-login' }, '', '#admin-login');
                showSection('admin-login', true);
                return;
            }
            // Admins can browse the public home and records in the same SPA.
            // Honor those index.php hashes on direct loads instead of always
            // forcing the dashboard route after a refresh.
            if (!window.INITIAL_ADMIN_SECTION && ['landing', 'bulletin'].includes(location.hash.slice(1))) {
                const publicSection = location.hash.slice(1);
                history.replaceState({ section: publicSection }, '', `${location.pathname}#${publicSection}`);
                showSection(publicSection, true);
                return;
            }
            const dash = sessionData.role === 'Admin' ? 'admin-dashboard' : 'resident-dashboard';
            if (sessionData.role === 'Admin' && window.INITIAL_ADMIN_SECTION) {
                history.replaceState(
                    { section: dash, adminSection: window.INITIAL_ADMIN_SECTION },
                    '',
                    `${location.pathname}#admin-${window.INITIAL_ADMIN_SECTION}`
                );
            } else {
                history.replaceState({ section: dash }, '', `#${dash}`);
            }
            showSection(dash, true);
            return;
        }
    }
    updateNavBar();
    if (window.INITIAL_ADMIN_SECTION) {
        showSection('admin-login', true);
        return;
    }
    // Seed the first history entry, honoring any #hash already in the URL.
    const rawInitialHash = location.hash.slice(1);
    let decodedInitialHash = rawInitialHash;
    try { decodedInitialHash = decodeURIComponent(rawInitialHash); } catch { /* Invalid fragments resolve to the common unavailable page. */ }
    const [initialRoute, initialQuery = ''] = decodedInitialHash.split('?', 2);
    const initialParams = new URLSearchParams(initialQuery);
    const validHashRoutes = ['landing', 'bulletin', 'login', 'admin-login', 'forgot-password', 'register', 'resident-dashboard', 'admin-dashboard', 'privacy-policy', 'terms', 'moderation', 'copyright', 'page-unavailable'];
    const initialResetRoute = initialRoute === 'password-reset' || initialRoute === 'forgot-password';
    const initialHasResetToken = initialParams.has('token');
    const initialResetTokenValid = initialHasResetToken && /^[a-f0-9]{64}$/i.test(initialParams.get('token') || '');
    const invalidInitialResetToken = initialResetRoute && (initialRoute === 'password-reset' ? !initialResetTokenValid : initialHasResetToken && !initialResetTokenValid);
    const initialIsKnown = validHashRoutes.includes(initialRoute) || (initialRoute.startsWith('admin-') && ['overview', 'requests', 'residents', 'disclosures', 'activity'].includes(initialRoute.slice(6)));
    const initialIsAdminRoute = initialRoute === 'admin-dashboard' || (initialRoute.startsWith('admin-') && ['overview', 'requests', 'residents', 'disclosures', 'activity'].includes(initialRoute.slice(6)));
    const start = (passwordResetToken && /^[a-f0-9]{64}$/i.test(passwordResetToken)) || initialResetTokenValid
        ? 'forgot-password'
        : (initialIsAdminRoute
            ? (document.body.dataset.adminIpAllowed === '1' ? 'admin-login' : 'page-unavailable')
            : (invalidInitialResetToken || (rawInitialHash && (!initialIsKnown || !initialRoute))
            ? 'page-unavailable'
            : (initialIsKnown ? initialRoute : 'landing')));
    const resolvedStart = start === 'admin-login' && document.body.dataset.adminIpAllowed === '0' ? 'page-unavailable' : start;
    history.replaceState({ section: resolvedStart }, '', `#${resolvedStart}`);
    showSection(resolvedStart, true);
});
