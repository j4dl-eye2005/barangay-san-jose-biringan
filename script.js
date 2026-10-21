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
        }, 20000);
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
let adminSidebarPhotoLookupUserId = null;
let walkInPanelReturnFocus = null;
let walkInPanelCloseTimer = null;
let adminIdleLogoutTimer = null;
let adminAbsoluteLogoutTimer = null;
let adminSessionActivityTrackingBound = false;
let residentEmailAvailabilityTimer = null;
let residentEmailAvailabilitySequence = 0;
let residentRequestItems = [];
let residentRequestStatusFilter = 'active';
let requestProofObjectUrl = null;
let requestSubmitting = false;
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
let residentAccountPage = 1;
let residentAccountPages = 0;
let pendingBulletinDelete = null;
let bulletinDeleteReturnFocus = null;
let publicDisclosuresLoaded = false;
let publicDisclosuresLoadedAt = 0;
let publicDisclosuresItems = [];
let publicDisclosuresLoadPromise = null;
let publicDisclosuresLoadError = false;
let activeBulletinCategory = null;
let activeBulletinFilter = 'all';
const publicBulletinCategories = {
    1: { title: 'Announcements', description: 'Barangay announcements and community updates' },
    2: { title: 'Financial Reports', description: 'SK financial reports and public financial records' },
    3: { title: 'Projects & Budget', description: 'Projects, budgets, and community development updates' },
    4: { title: 'Meeting Minutes', description: 'Published minutes and records of meetings' }
};
const bulletinEmptyMessages = {
    1: { title: 'No announcements yet', message: 'New public updates will appear here once they are published.' },
    2: { title: 'No financial reports yet', message: 'Published financial reports will appear here when available.' },
    3: { title: 'No project or budget records yet', message: 'Project and budget updates will appear here once published.' },
    4: { title: 'No meeting minutes yet', message: 'Published meeting minutes will appear here when available.' }
};

function getBulletinEmptyMessage(categoryId) {
    return bulletinEmptyMessages[Number(categoryId)]?.message || 'New public records will appear here once they are published.';
}

function getBulletinEmptyTitle(categoryId) {
    return bulletinEmptyMessages[Number(categoryId)]?.title || 'No public records yet';
}

function createBulletinEmptyState(title, message, icon = 'fa-folder-open') {
    return el('div', { className: 'bulletin-empty-state', attrs: { role: 'status' } }, [
        el('i', { className: `fas ${icon}`, attrs: { 'aria-hidden': 'true' } }),
        el('div', { className: 'bulletin-empty-state-copy' }, [
            el('strong', { text: title }),
            el('p', { text: message })
        ])
    ]);
}
let registerSubmitting = false;
let registrationEmailCheckTimer = null;
let registrationEmailCheckSequence = 0;
let toastHideTimer = null;
let toastExitTimer = null;
const adminPanelLoadedAt = new Map();
const ADMIN_PANEL_CACHE_MS = 30000;
const PUBLIC_DISCLOSURES_CACHE_MS = 30000;
const resetFragmentParams = new URLSearchParams(window.location.hash.split('?')[1] || '');
const resetFragmentRoute = window.location.hash.slice(1).split('?')[0];
let passwordResetToken = ['password-reset', 'forgot-password'].includes(resetFragmentRoute) ? (resetFragmentParams.get('token') || '') : '';
let passwordResetContext = resetFragmentParams.get('context') === 'admin' ? 'admin' : 'resident';
let passwordResetReturnToProfile = document.querySelector('meta[name="password-reset-return-to-profile"]')?.content === 'true';
const emailVerificationToken = () => document.querySelector('meta[name="email-verify-token"]')?.content || '';
const appDocumentTitle = document.body?.dataset.appTitle || document.title;

window.INITIAL_ADMIN_SECTION = document.body?.dataset.initialAdminSection || null;
const delegatedActionNames = new Set([
    'activateDisclosureCategory', 'changePickupCalendarMonth', 'checkAuthAndRedirect', 'clearPublicRecordsSearchInput',
    'clearActivityFilters', 'clearDisclosureFile', 'closeAdminRequestDetail', 'closeMobileMenu',
    'closeBulletinDeleteDialog', 'closeModal', 'confirmBulletinDelete', 'exportActivityCsv',
    'focusContactInfo', 'handleActivityFilterChange', 'handleAdminLogin', 'handleAdminMfaSubmit', 'handleAdminMfaDigitKeydown', 'handleAdminMfaPaste', 'cancelAdminMfa', 'handleViewProfile',
    'handleDisclosureCardKeydown', 'handleLogin', 'handlePickupDateKeydown',
    'handlePostDisclosure', 'handleRegister', 'handleRequest', 'filterAdminRequests', 'loadAdminActivity', 'logout', 'openWalkInRequestPanel', 'cancelWalkInRequest',
    'resendResidentVerification', 'changeResidentEmail', 'changeResidentPassword', 'submitManualRequest',
    'openDashboard', 'openDisclosureCategory', 'openDisclosureComposer', 'openForgotPassword',
    'openOverviewDestination', 'openRegisterPolicy', 'openResidentProfileQuickLink', 'portalSearch', 'searchPublicRecords', 'showBulletinOverview', 'showHomeAnchor', 'regClearFile', 'requestDoc',
    'requestPasswordReset', 'returnFromPasswordReset', 'scrollToId',
    'selectResidentRequestTab', 'selectResidentRequestStatusTab', 'openRequestProofPicker', 'removeRequestProof',
    'setActivityPreset', 'setAdminRequestFilter', 'setBulletinFilter', 'showSection', 'updateAdminMfaCode', 'updateAdminMfaDigits',
    'sortActivity', 'submitModal', 'saveAdminNotificationPreferences', 'setPortalLanguage', 'switchAdminTab', 'toggleActivityExtraFilters',
    'toggleMobileMenu', 'toggleUserMenu', 'togglePassword', 'togglePickupCalendar', 'updatePickupReadback', 'updateLandingDocumentFinder',
    'openFilePicker', 'openProfilePhotoPicker', 'saveProfilePhoto', 'cancelProfilePhotoPreview',
    'removeProfilePhoto', 'updateAdminNotificationSaveState', 'updateAdminProfileDetailsSaveState', 'scrollPageTop'
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

function isStandaloneUnavailableShell() {
    return document.body?.classList.contains('unavailable-view') && !document.getElementById('app-container');
}

function navigateStandaloneUnavailable(route, params = {}) {
    const url = new URL(document.baseURI);
    url.search = '';
    const routeParams = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
        if (value !== undefined && value !== null && String(value) !== '') routeParams.set(key, String(value));
    });
    url.hash = `${route}${routeParams.size ? `?${routeParams}` : ''}`;
    window.location.assign(url.href);
}

function getStandaloneUnavailableClickTarget(element) {
    const action = element.getAttribute('data-action-click') || '';
    const sectionMatch = action.match(/showSection\(\s*(['"])([a-z-]+)\1\s*\)/);
    if (sectionMatch) return { route: sectionMatch[2] };

    const homeAnchorMatch = action.match(/showHomeAnchor\(\s*(['"])([a-z-]+)\1\s*\)/);
    if (homeAnchorMatch) return { route: 'landing', params: { portalFocus: homeAnchorMatch[2] } };

    if (/\bfocusContactInfo\s*\(/.test(action)) return { route: 'landing', params: { portalFocus: 'contact-info' } };
    if (/\bopenResidentProfileQuickLink\s*\(/.test(action)) {
        return { route: /openResidentProfileQuickLink\(\s*(['"])records\1\s*\)/.test(action) ? 'bulletin' : 'resident-dashboard' };
    }
    if (/\bopenDashboard\s*\(/.test(action)) {
        return { route: currentUser?.role === 'Admin' ? 'admin-dashboard' : 'resident-dashboard' };
    }
    return null;
}

function runDelegatedAction(element, type, event) {
    if (type === 'click' && isStandaloneUnavailableShell()) {
        const target = getStandaloneUnavailableClickTarget(element);
        if (target) {
            event.preventDefault();
            navigateStandaloneUnavailable(target.route, target.params);
            return;
        }
    }
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

['click', 'change', 'input', 'submit', 'keydown', 'keyup', 'paste'].forEach(type => {
    document.addEventListener(type, event => {
        const element = event.target instanceof Element ? event.target.closest(`[data-action-${type}]`) : null;
        if (element) runDelegatedAction(element, type, event);
    }, true);
});

document.addEventListener('click', event => {
    const backLink = event.target instanceof Element ? event.target.closest('.unavailable-back') : null;
    if (!backLink || window.history.length <= 1) return;
    event.preventDefault();
    window.history.back();
});

/** Scroll smoothly to an element by id, accounting for the sticky nav */
function scrollToId(id) {
    const el = document.getElementById(id);
    if (!el) return;
    const policy = el.closest('.policy-section');
    const legalPageIds = ['privacy-policy', 'terms', 'moderation', 'copyright'];
    const isMobileLegalPage = window.matchMedia('(max-width: 720px)').matches && legalPageIds.includes(policy?.id);
    const fallbackHeaderHeight = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--topbar-height')) || 56;
    const headerHeight = document.getElementById('main-nav')?.getBoundingClientRect().height || fallbackHeaderHeight;
    const railHeight = isMobileLegalPage ? policy.querySelector('.policy-toc')?.getBoundingClientRect().height || 0 : 0;
    const offset = el.getBoundingClientRect().top + window.scrollY - headerHeight - railHeight - 12;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.scrollTo({ top: offset, behavior: reduceMotion ? 'auto' : 'smooth' });
}

function setPublicNavCurrent(target) {
    document.querySelectorAll('#main-nav .nav-links [data-section]').forEach(item => {
        if (item.dataset.section === target) item.setAttribute('aria-current', 'page');
        else item.removeAttribute('aria-current');
    });
}

function showHomeAnchor(id) {
    showSection('landing');
    setPublicNavCurrent(id);
    window.requestAnimationFrame(() => scrollToId(id));
    return false;
}

function updatePortalDate() {
    const date = document.getElementById('portal-current-date');
    if (!date) return;
    const locale = document.documentElement.lang === 'tl' ? 'tl-PH' : 'en-PH';
    date.textContent = new Intl.DateTimeFormat(locale, {
        weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'
    }).format(new Date());
}

const portalTagalogCopy = {
    utilityContact: 'Kontak', publicNotices: 'Mga Pabatid', languageLabel: 'Wika', languageSelectLabel: 'Pumili ng wika',
    brandLabel: 'Pumunta sa tahanan ng Barangay San Jose', tagline: 'Mga digital na serbisyo at pampublikong rekord',
    headerSearchLabel: 'Maghanap ng mga dokumento at pampublikong rekord',
    headerSearchPlaceholder: 'Maghanap ng mga dokumento at pampublikong rekord',
    contactUs: 'Makipag-ugnayan', register: 'Magrehistro', menu: 'Menu', home: 'Tahanan',
    services: 'Mga Serbisyo', publicRecords: 'Pampublikong Rekord',
    trackRequests: 'Subaybayan ang Kahilingan', contact: 'Makipag-ugnayan', login: 'Mag-log in'
};

function getPortalLanguagePreference() {
    try { return localStorage.getItem('barangay-portal-language') === 'tl' ? 'tl' : 'en'; }
    catch { return 'en'; }
}

function setPortalLanguage(eventOrLanguage) {
    const requested = typeof eventOrLanguage === 'string' ? eventOrLanguage : eventOrLanguage?.target?.value;
    const language = requested === 'tl' ? 'tl' : 'en';
    document.documentElement.lang = language;
    const languageSelect = document.getElementById('portal-language-select');
    if (languageSelect && languageSelect.value !== language) languageSelect.value = language;
    document.querySelectorAll('[data-portal-i18n]').forEach(element => {
        if (element.dataset.portalI18nDefault === undefined) element.dataset.portalI18nDefault = element.textContent;
        const key = element.dataset.portalI18n;
        element.textContent = language === 'tl' ? (portalTagalogCopy[key] || element.dataset.portalI18nDefault) : element.dataset.portalI18nDefault;
    });
    document.querySelectorAll('[data-portal-i18n-placeholder]').forEach(element => {
        if (element.dataset.portalI18nDefaultPlaceholder === undefined) element.dataset.portalI18nDefaultPlaceholder = element.getAttribute('placeholder') || '';
        const key = element.dataset.portalI18nPlaceholder;
        element.setAttribute('placeholder', language === 'tl' ? (portalTagalogCopy[key] || element.dataset.portalI18nDefaultPlaceholder) : element.dataset.portalI18nDefaultPlaceholder);
    });
    document.querySelectorAll('[data-portal-i18n-aria-label]').forEach(element => {
        if (element.dataset.portalI18nDefaultAriaLabel === undefined) element.dataset.portalI18nDefaultAriaLabel = element.getAttribute('aria-label') || '';
        const key = element.dataset.portalI18nAriaLabel;
        element.setAttribute('aria-label', language === 'tl' ? (portalTagalogCopy[key] || element.dataset.portalI18nDefaultAriaLabel) : element.dataset.portalI18nDefaultAriaLabel);
    });
    try { localStorage.setItem('barangay-portal-language', language); } catch {}
    updatePortalDate();
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
function placeResidentProfileForRole() {
    const profile = document.getElementById('resident-profile');
    const homeSlot = document.getElementById('resident-profile-home-slot');
    if (!profile || !homeSlot) return;
    if (currentUser?.role === 'Admin') {
        const adminContent = document.querySelector('#admin-dashboard .admin-content');
        if (adminContent && profile.parentElement !== adminContent) adminContent.appendChild(profile);
    } else if (profile.parentElement !== homeSlot.parentElement) {
        homeSlot.after(profile);
    }
}

function showSection(id, fromHistory = false, adminSection = null) {
    if (document.body.dataset.adminEntryAuthorized === '0' && (id === 'admin-login' || id === 'admin-dashboard' || id.startsWith('admin-'))) {
        id = 'page-unavailable';
    }
    if (id === 'admin-dashboard' && currentUser?.role !== 'Admin') {
        id = currentUser?.role === 'Resident' ? 'resident-dashboard' : 'admin-login';
    } else if (id === 'resident-profile' && !['Resident', 'Admin'].includes(currentUser?.role)) {
        id = 'login';
    } else if (id === 'resident-dashboard' && currentUser?.role !== 'Resident') {
        id = currentUser?.role === 'Admin' ? 'admin-dashboard' : 'login';
    } else if ((id === 'resident-change-email' || id === 'resident-change-password') && !['Resident', 'Admin'].includes(currentUser?.role)) {
        id = currentUser?.role === 'Admin' ? 'admin-dashboard' : 'login';
    }

    placeResidentProfileForRole();
    const adminProfileSection = id === 'resident-profile' && currentUser?.role === 'Admin';

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
    const residentWorkspaceView = currentUser?.role === 'Resident' && ['resident-dashboard', 'resident-profile'].includes(id);
    document.body.classList.toggle('resident-workspace-view', residentWorkspaceView);
    document.body.classList.toggle('public-portal-view', ['landing', 'bulletin', 'login', 'admin-login', 'forgot-password', 'register', 'resident-change-email', 'resident-change-password', 'privacy-policy', 'terms', 'moderation', 'copyright', 'page-unavailable'].includes(id) || residentWorkspaceView);
    // First-paint routing only applies until the app's normal navigation takes over.
    document.body.removeAttribute('data-route-start');
    document.body.removeAttribute('data-entry-route');

    const unavailableView = id === 'page-unavailable' || (id === 'admin-login' && document.body.dataset.adminEntryAuthorized === '0');
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
    document.body.classList.toggle('hide-site-disclaimer', ['login', 'admin-login', 'forgot-password', 'register', 'resident-dashboard', 'resident-profile', 'resident-change-email', 'resident-change-password', 'admin-dashboard'].includes(id) || (id === 'bulletin' && currentUser?.role === 'Admin'));
    document.body.classList.toggle('admin-dashboard-view', id === 'admin-dashboard');
    document.body.classList.toggle('admin-bulletin-view', id === 'bulletin' && currentUser?.role === 'Admin');
    document.body.classList.toggle('admin-profile-view', id === 'resident-profile' && currentUser?.role === 'Admin');

    document.querySelectorAll('.section').forEach(item => {
        const active = item === section || (adminProfileSection && item.id === 'admin-dashboard');
        item.classList.toggle('active', active);
        item.setAttribute('aria-hidden', active ? 'false' : 'true');
    });
    const adminProfileLink = document.querySelector('#admin-sidebar-account .admin-sidebar-profile');
    if (adminProfileLink) {
        adminProfileLink.classList.toggle('active', adminProfileSection);
        if (adminProfileSection) adminProfileLink.setAttribute('aria-current', 'page');
        else adminProfileLink.removeAttribute('aria-current');
    }
    if (adminProfileSection) {
        document.querySelectorAll('#admin-dashboard .admin-tabs .admin-tab, #admin-nav-section .admin-nav-tab').forEach(tab => {
            tab.classList.remove('active');
            tab.setAttribute('aria-current', 'false');
            if (tab.classList.contains('admin-tab')) tab.setAttribute('aria-selected', 'false');
        });
    }
    if (id === 'landing' && activeSectionId !== id) {
        const announcements = document.querySelector('#landing .landing-sections');
        if (announcements && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            announcements.classList.remove('is-entering');
            void announcements.offsetWidth;
            announcements.classList.add('is-entering');
        }
    }
    if (id === 'login' || id === 'register') renderRecaptchaWidget(id);

    const nav = document.getElementById('main-nav');
    nav.classList.toggle('hidden-nav', ['login', 'register', 'admin-login', 'forgot-password', 'resident-change-email', 'resident-change-password'].includes(id));
    closeUserMenu();
    closeMobileMenu();

    document.querySelectorAll('.nav-links [data-section]').forEach(item => {
        const target = item.dataset.section;
        const active = target === id || (target === 'dashboard' && (id.endsWith('-dashboard') || id === 'resident-profile'));
        if (active) item.setAttribute('aria-current', 'page');
        else item.removeAttribute('aria-current');
    });

    if (id === 'resident-dashboard') { loadResidentData(); loadDocTypes(); }
    if (id === 'resident-profile') renderResidentProfile();
    if (id === 'resident-change-email') updateResidentEmailSubmit();
    if (id === 'resident-change-password') bindResidentPasswordPage();
    if (id === 'admin-dashboard') {
        // The walk-in form lives in the Admin workspace, so ensure its document
        // choices are loaded there as well as in the resident request flow.
        void loadDocTypes();
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
    if (id === 'bulletin') {
        setBulletinCategoryView(fromHistory ? getBulletinCategoryFromLocation() : null);
        const bulletinParams = new URLSearchParams(location.hash.split('?')[1] || '');
        const searchQuery = bulletinParams.get('search');
        const searchInput = document.getElementById('public-records-search-input');
        if (searchQuery !== null && searchInput) {
            searchInput.value = searchQuery;
            syncPublicRecordsSearchControls();
        }
        requestAnimationFrame(() => scrollSelectedBulletinFilterIntoView(activeBulletinFilter));
        const disclosuresLoad = loadDisclosures();
        if (searchQuery?.trim()) {
            renderPublicRecordsSearch(searchQuery);
            void disclosuresLoad.then(() => renderPublicRecordsSearch(searchQuery));
        }
    }
    if (id === 'landing') loadLatestAnnouncements();
    if (id === 'register') {
        setTimeout(regInitDropzone, 50);
        // Wire up live password checklist
        const pwField  = document.getElementById('register-password');
        const cpwField = document.getElementById('register-confirm-password');
        bindPasswordChecklist(pwField, cpwField, 'register');
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
    updateStickyHeader();
    const portalFocus = new URLSearchParams(location.hash.split('?')[1] || '').get('portalFocus');
    if (id === 'landing' && ['services', 'contact-info'].includes(portalFocus)) {
        requestAnimationFrame(() => scrollToId(portalFocus));
    }

    const heading = section.querySelector('h1');
    if (heading) {
        heading.setAttribute('tabindex', '-1');
        requestAnimationFrame(() => heading.focus({ preventScroll: true }));
    }
}

function setRegistrationEmailError(message) {
    const field = document.getElementById('register-email');
    const error = document.getElementById('register-email-error');
    if (error) error.textContent = message;
    if (field) {
        field.setAttribute('aria-invalid', message && message !== 'Checking email…' ? 'true' : 'false');
        field.setCustomValidity(message && message !== 'Checking email…' ? message : '');
    }
}

const knownEmailDomains = new Set([
    'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.com.ph', 'yahoo.co.uk', 'yahoo.ca', 'yahoo.com.au', 'yahoo.co.nz', 'ymail.com', 'rocketmail.com',
    'outlook.com', 'outlook.ph', 'hotmail.com', 'hotmail.co.uk', 'hotmail.com.au', 'live.com', 'live.com.ph', 'msn.com',
    'icloud.com', 'me.com', 'mac.com', 'proton.me', 'protonmail.com', 'protonmail.ch', 'pm.me'
]);

function emailValidationMessage(value) {
    const email = String(value || '').trim().toLowerCase();
    if (!email) return '';
    if (!/^[^\s@]+@[^\s@]+\.[a-z]{2,}$/i.test(email)) return 'Please complete the email address.';
    const domain = email.slice(email.lastIndexOf('@') + 1);
    if (!knownEmailDomains.has(domain)) return 'Please use a known email provider, like Gmail, Yahoo, or Outlook.';
    return '';
}

function fieldErrorElement(field, suffix, preferredId = '') {
    const id = preferredId || `${field.id}-${suffix}`;
    let error = document.getElementById(id);
    if (!error) {
        error = document.createElement('span');
        error.id = id;
        error.className = 'reg-field-error';
        error.setAttribute('role', 'alert');
        field.insertAdjacentElement('afterend', error);
    }
    const describedBy = new Set((field.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
    describedBy.add(id);
    field.setAttribute('aria-describedby', [...describedBy].join(' '));
    return error;
}

function setEmailValidation(field) {
    const message = emailValidationMessage(field.value);
    const error = fieldErrorElement(field, 'error', field.id === 'register-email' ? 'register-email-error' : '');
    error.textContent = message;
    field.setAttribute('aria-invalid', message ? 'true' : 'false');
    field.setCustomValidity(message);
    return !message;
}

const validPhMobilePrefixes = new Set([
    '0900','0904','0905','0906','0907','0908','0909','0910','0911','0912','0913','0914','0915','0916','0917','0918','0919',
    '0920','0921','0922','0923','0924','0925','0926','0927','0928','0929','0930','0931','0932','0933','0934','0935','0936',
    '0937','0938','0939','0940','0941','0942','0943','0944','0945','0946','0947','0948','0949','0950','0951','0952','0953',
    '0954','0955','0956','0957','0958','0959','0961','0962','0963','0964','0965','0966','0967','0968','0969','0970','0971',
    '0972','0973','0974','0975','0976','0977','0978','0979','0980','0981','0982','0985','0989','0990','0991','0992','0993',
    '0994','0995','0996','0997','0998','0999'
]);

function isValidPhilippineMobile(value) {
    const phone = String(value || '').trim();
    if (!phone) return true;
    if (!/^(?:09\d{9}|\+639\d{9})$/.test(phone)) return false;
    const national = phone.startsWith('+63') ? `0${phone.slice(3)}` : phone;
    return validPhMobilePrefixes.has(national.slice(0, 4));
}

function setPhoneValidation(field) {
    const message = isValidPhilippineMobile(field.value) ? '' : 'This is not a valid Philippine mobile number.';
    const error = fieldErrorElement(field, 'phone-error', field.id === 'register-phone' ? 'register-phone-error' : '');
    error.textContent = message;
    field.setAttribute('aria-invalid', message ? 'true' : 'false');
    field.setCustomValidity(message);
    return !message;
}

document.addEventListener('input', event => {
    const field = event.target;
    if (!(field instanceof HTMLInputElement)) return;
    if (['doc-purpose-other'].includes(field.id)) {
        setRequestFieldError(field.id, 'request-purpose-other-error', '');
        const submitError = document.getElementById('request-submit-error');
        if (submitError) submitError.hidden = true;
    }
    if (field.type === 'email') {
        window.clearTimeout(registrationEmailCheckTimer);
        registrationEmailCheckSequence++;
        const isResidentLoginEmail = field.id === 'login-email';
        const isAdminLoginEmail = field.id === 'admin-login-email';
        const isLoginEmail = isResidentLoginEmail || isAdminLoginEmail;
        const valid = isLoginEmail ? true : setEmailValidation(field);
        if (isLoginEmail) {
            const error = document.getElementById(isAdminLoginEmail ? 'admin-login-email-error' : 'login-email-error');
            if (error) error.textContent = '';
            field.setAttribute('aria-invalid', 'false');
            field.setCustomValidity('');
        }
        if (field.id === 'register-email' && valid && field.validity.valid) {
            setRegistrationEmailError('Checking email…');
            registrationEmailCheckTimer = window.setTimeout(() => checkRegistrationEmail(field), 350);
        }
        if (field.id === 'resident-new-email') {
            delete field.dataset.emailExists;
            updateResidentEmailSubmit();
            window.clearTimeout(residentEmailAvailabilityTimer);
            const sequence = ++residentEmailAvailabilitySequence;
            const email = field.value.trim().toLowerCase();
            if (valid && field.validity.valid && email && email !== String(currentUser?.email || '').toLowerCase()) {
                residentEmailAvailabilityTimer = window.setTimeout(() => checkResidentEmailAvailability(field, email, sequence), 350);
            }
        }
    }
    if (field.id === 'resident-email-password') {
        const error = document.getElementById('resident-email-password-error');
        if (error) error.textContent = '';
        field.setAttribute('aria-invalid', 'false');
        updateResidentEmailSubmit();
    }
    if (field.type === 'tel') setPhoneValidation(field);
    if (field.id === 'register-password' || field.id === 'register-confirm-password') updatePasswordChecklist('register');
    if (field.id === 'new-password' || field.id === 'confirm-new-password') updatePasswordChecklist('reset');
}, true);

document.addEventListener('change', event => {
    const field = event.target;
    if (!(field instanceof HTMLInputElement || field instanceof HTMLSelectElement)) return;
    const submitError = document.getElementById('request-submit-error');
    if (submitError) submitError.hidden = true;
    if (field.id === 'doc-type') {
        setRequestFieldError(field.id, 'request-doc-type-error', '');
        updateDocumentTypeInfo();
    } else if (field.id === 'doc-purpose') {
        setRequestFieldError(field.id, 'request-purpose-error', '');
        updateRequestPurposeFields();
    } else if (field.id === 'request-proof-type') {
        setRequestFieldError(field.id, 'request-proof-type-error', '');
    } else if (field.id === 'request-proof') {
        handleRequestProofSelection(event);
    }
}, true);

document.addEventListener('DOMContentLoaded', () => {
    const requestProofPicker = document.getElementById('request-proof-picker');
    if (!requestProofPicker) return;
    requestProofPicker.addEventListener('dragenter', () => requestProofPicker.classList.add('is-drag-over'));
    requestProofPicker.addEventListener('dragover', () => requestProofPicker.classList.add('is-drag-over'));
    requestProofPicker.addEventListener('dragleave', () => requestProofPicker.classList.remove('is-drag-over'));
    requestProofPicker.addEventListener('drop', () => requestProofPicker.classList.remove('is-drag-over'));
});

async function checkResidentEmailAvailability(field, email, sequence) {
    const error = document.getElementById('resident-new-email-error');
    if (!error) return;
    try {
        const response = await fetch('api/check_registration_email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body: new URLSearchParams({ email })
        });
        const result = await response.json();
        if (sequence !== residentEmailAvailabilitySequence || field.value.trim().toLowerCase() !== email) return;
        if (response.ok && result.success && !result.available) {
            field.dataset.emailExists = email;
        } else if (response.ok && result.success) {
            delete field.dataset.emailExists;
        }
    } catch { /* The server validates availability when the email is submitted. */ }
    updateResidentEmailSubmit();
}

document.addEventListener('blur', event => {
    const field = event.target;
    if (field instanceof HTMLInputElement && field.type === 'email' && field.id === 'register-email'
        && setEmailValidation(field) && field.validity.valid) checkRegistrationEmail(field);
    if (field instanceof HTMLInputElement && field.id === 'resident-new-email') {
        const error = document.getElementById('resident-new-email-error');
        const hasValue = Boolean(field.value.trim());
        if (!hasValue) {
            field.setCustomValidity('Enter your new email address.');
            field.setAttribute('aria-invalid', 'true');
            if (error) error.textContent = 'Enter your new email address.';
        } else {
            setEmailValidation(field);
        }
        updateResidentEmailSubmit();
        if (!hasValue) {
            field.setCustomValidity('Enter your new email address.');
            field.setAttribute('aria-invalid', 'true');
            if (error) error.textContent = 'Enter your new email address.';
        }
    }
}, true);

async function checkRegistrationEmail(field) {
    const email = field.value.trim().toLowerCase();
    if (!setEmailValidation(field)) return false;
    field.setCustomValidity('');
    if (!email || !field.validity.valid) return null;
    const sequence = ++registrationEmailCheckSequence;
    const body = new URLSearchParams({ email });
    try {
        const response = await fetch('api/check_registration_email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            body
        });
        const result = await response.json();
        if (sequence !== registrationEmailCheckSequence || field.value.trim().toLowerCase() !== email) return null;
        if (response.ok && result.success) {
            setRegistrationEmailError(result.available ? '' : 'This email already exists.');
            return result.available;
        }
        setRegistrationEmailError('');
    } catch (error) {
        if (sequence === registrationEmailCheckSequence) setRegistrationEmailError('');
    }
    return null;
}

// Admin APIs and malformed verification/reset links return a common marker.
// Turn those asynchronous denials into the same full-screen unavailable view.
const portalFetch = window.fetch.bind(window);
window.fetch = async (...args) => {
    const [request, suppliedInit] = args;
    const init = suppliedInit || {};
    const requestUrl = new URL(typeof request === 'string' ? request : request.url, window.location.href);
    const requestMethod = String(init.method || (request instanceof Request ? request.method : 'GET')).toUpperCase();
    if (requestUrl.origin === window.location.origin && !['GET', 'HEAD', 'OPTIONS'].includes(requestMethod)) {
        const headers = new Headers(request instanceof Request ? request.headers : undefined);
        new Headers(init.headers || undefined).forEach((value, name) => headers.set(name, value));
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        if (csrf) headers.set('X-CSRF-Token', csrf);
        args[1] = { ...init, headers };
    }
    const response = await portalFetch(...args);
    const refreshedCsrf = response.headers.get('X-CSRF-Token');
    if (refreshedCsrf) {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) meta.content = refreshedCsrf;
    }
    if (response.headers.get('content-type')?.includes('application/json')) {
        const payload = await response.clone().json().catch(() => null);
        if (typeof payload?.csrfToken === 'string') {
            const meta = document.querySelector('meta[name="csrf-token"]');
            if (meta) meta.content = payload.csrfToken;
        }
        if (response.status === 404 && payload?.pageUnavailable) showSection('page-unavailable');
        return response;
    }
    if (response.status === 404) {
        const payload = await response.clone().json().catch(() => null);
        if (payload?.pageUnavailable) showSection('page-unavailable');
    }
    return response;
};

let mobileMenuCloseTimer = null;
let mobileMenuLockedScrollY = null;
let mobileMenuViewportHandler = null;

function updateMobileMenuTop() {
    const headerRow = document.querySelector('#main-nav .portal-main-row');
    if (!headerRow || mobileMenuLockedScrollY === null) return;
    const bottom = headerRow.getBoundingClientRect().bottom;
    document.body.style.setProperty('--mobile-menu-top', `${Math.max(0, Math.round(bottom))}px`);
}

function setMobileMenuScrollLock(locked) {
    if (locked) {
        if (!window.matchMedia('(max-width: 767px)').matches) return;
        if (mobileMenuLockedScrollY !== null) return;
        mobileMenuLockedScrollY = window.scrollY;
        document.documentElement.style.overflow = 'hidden';
        document.body.style.position = 'fixed';
        document.body.style.top = `-${mobileMenuLockedScrollY}px`;
        document.body.style.left = '0';
        document.body.style.right = '0';
        document.body.style.width = '100%';
        mobileMenuViewportHandler = updateMobileMenuTop;
        updateMobileMenuTop();
        window.addEventListener('resize', mobileMenuViewportHandler, { passive: true });
        window.visualViewport?.addEventListener('resize', mobileMenuViewportHandler, { passive: true });
        return;
    }
    if (mobileMenuLockedScrollY === null) return;
    const restoreY = mobileMenuLockedScrollY;
    mobileMenuLockedScrollY = null;
    document.documentElement.style.overflow = '';
    document.body.style.position = '';
    document.body.style.top = '';
    document.body.style.left = '';
    document.body.style.right = '';
    document.body.style.width = '';
    document.body.style.removeProperty('--mobile-menu-top');
    if (mobileMenuViewportHandler) {
        window.removeEventListener('resize', mobileMenuViewportHandler);
        window.visualViewport?.removeEventListener('resize', mobileMenuViewportHandler);
        mobileMenuViewportHandler = null;
    }
    window.scrollTo({ top: restoreY, left: 0, behavior: 'auto' });
    updateStickyHeader();
}

function updateStickyHeader() {
    const mainNav = document.getElementById('main-nav');
    if (!mainNav || mobileMenuLockedScrollY !== null) return;
    const isMobilePublic = document.body.classList.contains('public-portal-view')
        && window.matchMedia('(max-width: 767px)').matches;
    const mobileThreshold = mainNav.querySelector('.portal-utility-bar')?.offsetHeight || 40;
    const isScrolled = isMobilePublic ? window.scrollY >= mobileThreshold : window.scrollY > 0;
    mainNav.classList.toggle('is-scrolled', isScrolled);
}

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
    setMobileMenuScrollLock(open);
}

function toggleUserMenu(button) {
    const menu = document.getElementById('user-account-menu');
    const trigger = button || document.getElementById('welcome-user');
    if (!menu || !trigger) return;
    const open = menu.hidden;
    menu.hidden = !open;
    trigger.setAttribute('aria-expanded', String(open));
}

function closeUserMenu() {
    const menu = document.getElementById('user-account-menu');
    const trigger = document.getElementById('welcome-user');
    if (!menu || !trigger) return;
    menu.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
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
    setMobileMenuScrollLock(false);
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
    const validSections = ['landing', 'bulletin', 'login', 'admin-login', 'forgot-password', 'register', 'resident-dashboard', 'resident-profile', 'resident-change-email', 'resident-change-password', 'admin-dashboard', 'privacy-policy', 'terms', 'moderation', 'copyright', 'page-unavailable'];
    const resetQuery = new URLSearchParams(query);
    const invalidResetToken = route === 'forgot-password' && resetQuery.has('token') && !/^[a-f0-9]{64}$/i.test(resetQuery.get('token') || '');

    const adminTabs = ['overview', 'requests', 'residents', 'disclosures', 'activity'];
    if (route.startsWith('admin-') && adminTabs.includes(route.slice(6))) {
        if (document.body.dataset.adminEntryAuthorized === '0') {
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

function updateLandingDocumentFinder(purpose) {
    if (purpose instanceof HTMLSelectElement) purpose = purpose.value;
    const result = document.getElementById('document-finder-result');
    const resultInner = document.getElementById('document-finder-result-inner');
    if (!result || !resultInner) return;

    const suggestions = {
        general: {
            name: 'Barangay Clearance',
            description: 'General-purpose clearance for employment, business, or personal use.',
            fee: '₱50'
        },
        residency: {
            name: 'Certificate of Residency',
            description: 'Confirms you are a registered resident of Barangay San Jose.',
            fee: '₱50'
        },
        indigency: {
            name: 'Certificate of Indigency',
            description: 'For residents who need proof of financial need for government programs.',
            fee: 'Free'
        },
        business: {
            name: 'Barangay Business Clearance',
            description: 'Barangay-level clearance required before applying for a city business permit.',
            fee: '₱200'
        }
    };
    const suggestion = suggestions[purpose];
    if (!suggestion) {
        result.classList.remove('is-visible');
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            result.hidden = true;
        } else {
            window.setTimeout(() => {
                if (!result.classList.contains('is-visible')) result.hidden = true;
            }, 240);
        }
        return;
    }

    const copy = document.createElement('div');
    copy.className = 'document-finder-copy';
    const title = document.createElement('strong');
    title.textContent = suggestion.name;
    const description = document.createElement('p');
    description.textContent = suggestion.description;
    copy.append(title, description);

    const action = document.createElement('div');
    action.className = 'document-finder-action';
    const fee = document.createElement('span');
    fee.textContent = suggestion.fee;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'document-finder-button';
    button.textContent = 'Start request';
    button.addEventListener('click', () => requestDoc(suggestion.name));
    action.append(fee, button);
    resultInner.replaceChildren(copy, action);
    if (result.hidden) {
        result.hidden = false;
        requestAnimationFrame(() => result.classList.add('is-visible'));
    } else {
        result.classList.add('is-visible');
    }
}

function focusContactInfo() {
    showSection('landing');
    setPublicNavCurrent('contact');
    const contact = document.getElementById('contact-info');
    if (!contact) return;
    scrollToId('contact-info');
    // Move focus after the scroll settles so screen readers land on the info.
    window.setTimeout(() => contact.focus({ preventScroll: true }), 300);
}

function renderAdminSidebarAvatar(photoName = null) {
    const sidebarAvatar = document.getElementById('admin-sidebar-avatar');
    if (!sidebarAvatar) return;
    const initials = sidebarAvatar.dataset.initials || 'A';
    sidebarAvatar.dataset.photo = typeof photoName === 'string' ? photoName : '';
    sidebarAvatar.replaceChildren(document.createTextNode(initials));
    if (!photoName || currentUser?.role !== 'Admin') return;

    const image = new Image();
    image.alt = '';
    image.onload = () => {
        if (sidebarAvatar.dataset.photo === photoName && currentUser?.profilePhoto === photoName) {
            sidebarAvatar.replaceChildren(image);
        }
    };
    image.onerror = () => {
        if (sidebarAvatar.dataset.photo === photoName) sidebarAvatar.replaceChildren(document.createTextNode(initials));
    };
    image.src = `api/profile_photo.php?v=${Date.now()}`;
}

function loadAdminSidebarAvatarPhoto() {
    if (currentUser?.role !== 'Admin') return;
    if (typeof currentUser.profilePhoto === 'string' || currentUser.profilePhoto === null) {
        renderAdminSidebarAvatar(currentUser.profilePhoto);
        return;
    }
    const userId = String(currentUser.id || '');
    if (!userId || adminSidebarPhotoLookupUserId === userId) return;
    adminSidebarPhotoLookupUserId = userId;
    fetch('api/profile.php', { cache: 'no-store' })
        .then(async response => {
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || 'Could not load the profile photo.');
            if (currentUser?.role !== 'Admin' || String(currentUser.id || '') !== userId) return;
            currentUser.profilePhoto = data.photo || null;
            renderAdminSidebarAvatar(currentUser.profilePhoto);
        })
        .catch(() => {
            if (adminSidebarPhotoLookupUserId === userId) adminSidebarPhotoLookupUserId = null;
        });
}

function updateNavBar() {
    const info = document.getElementById('user-info');
    const dashboardLink = document.getElementById('dashboard-nav-link');
    const bulletinAdminAction = document.getElementById('bulletin-admin-action');
    const heroGuestActions = document.getElementById('hero-guest-actions');
    const heroUserActions = document.getElementById('hero-user-actions');
    const heroGuestHelper = document.getElementById('hero-guest-helper');
    const heroBulletinLink = document.getElementById('hero-bulletin-link');
    const mobileRegisterCta = document.getElementById('nav-register-cta');
    const profileLink = document.getElementById('user-profile-link');
    const profileDivider = document.getElementById('account-profile-divider');
    const mobileProfileLink = document.getElementById('mobile-profile-nav-item');
    const mobileAccountActions = document.getElementById('mobile-account-actions');
    const adminSidebarAccount = document.getElementById('admin-sidebar-account');
    document.body.classList.toggle('admin-user-view', currentUser?.role === 'Admin');
    document.body.classList.toggle('resident-user-view', currentUser?.role === 'Resident');
    document.body.classList.toggle('guest-user-view', !currentUser);
    const emailBanner = document.getElementById('email-verification-banner');
    if (emailBanner) emailBanner.hidden = !currentUser || currentUser.role !== 'Resident' || currentUser.emailVerified === true;
    // Show admin workspace nav section in hamburger only for admins
    const adminNavSection = document.getElementById('admin-nav-section');
    if (adminNavSection) adminNavSection.hidden = currentUser?.role !== 'Admin';
    if (adminSidebarAccount) adminSidebarAccount.hidden = currentUser?.role !== 'Admin';
    const guestAuthActions = document.getElementById('guest-auth-actions');
    if (guestAuthActions) guestAuthActions.classList.toggle('hidden', Boolean(currentUser));
    if (mobileRegisterCta) mobileRegisterCta.classList.toggle('hidden', Boolean(currentUser));
    if (profileLink) profileLink.classList.toggle('hidden', !['Resident', 'Admin'].includes(currentUser?.role));
    if (profileDivider) profileDivider.hidden = !['Resident', 'Admin'].includes(currentUser?.role);
    if (mobileProfileLink) mobileProfileLink.hidden = !['Resident', 'Admin'].includes(currentUser?.role);
    if (mobileAccountActions) mobileAccountActions.hidden = !currentUser;
    if (currentUser) { 
        info.classList.remove('hidden'); 
        const fullName = String(currentUser.name || '').trim();
        const firstName = fullName.split(/\s+/)[0] || 'Account';
        const nameParts = fullName.split(/\s+/).filter(Boolean);
        const initials = nameParts.length > 1 ? `${nameParts[0][0]}${nameParts[nameParts.length - 1][0]}` : (nameParts[0] || 'A').slice(0, 2);
        const sidebarName = document.getElementById('admin-sidebar-account-name');
        const sidebarAvatar = document.getElementById('admin-sidebar-avatar');
        if (sidebarName) sidebarName.textContent = fullName || 'Administrator';
        if (sidebarAvatar) {
            sidebarAvatar.dataset.initials = initials.toUpperCase();
            renderAdminSidebarAvatar(currentUser.role === 'Admin' ? currentUser.profilePhoto : null);
        }
        if (currentUser.role === 'Admin') loadAdminSidebarAvatarPhoto();
        document.getElementById('welcome-user-name').textContent = `Hi, ${firstName}`;
        const avatar = document.getElementById('welcome-user-avatar');
        if (avatar) avatar.textContent = initials.toUpperCase();
        const menuName = document.getElementById('account-menu-full-name');
        const menuEmail = document.getElementById('account-menu-email');
        if (menuName) menuName.textContent = fullName || 'Account';
        if (menuEmail) menuEmail.textContent = currentUser.email || '';
        dashboardLink.classList.remove('hidden');
        if (bulletinAdminAction) bulletinAdminAction.classList.toggle('hidden', currentUser.role !== 'Admin');
        if (heroGuestActions) heroGuestActions.classList.add('hidden');
        if (heroGuestHelper) heroGuestHelper.classList.add('hidden');
        if (heroBulletinLink) heroBulletinLink.classList.add('hidden');
        if (heroUserActions) heroUserActions.classList.remove('hidden');
    } else {
        adminSidebarPhotoLookupUserId = null;
        closeUserMenu();
        info.classList.add('hidden');
        dashboardLink.classList.add('hidden');
        if (bulletinAdminAction) bulletinAdminAction.classList.add('hidden');
        if (heroGuestActions) heroGuestActions.classList.remove('hidden');
        if (heroGuestHelper) heroGuestHelper.classList.remove('hidden');
        if (heroBulletinLink) heroBulletinLink.classList.remove('hidden');
        if (heroUserActions) heroUserActions.classList.add('hidden');
    }
    renderResidentProfile();
}

function openDashboard() {
    if (!currentUser) {
        showSection('login');
        return;
    }

    showSection(currentUser.role === 'Admin' ? 'admin-dashboard' : 'resident-dashboard', false, 'overview');
}

function openResidentProfileQuickLink(target) {
    if (target === 'records') {
        showSection('bulletin');
        return;
    }
    openDashboard();
    if (currentUser?.role === 'Resident') selectResidentRequestTab(target === 'track' ? 'list' : 'new');
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

const portalSearchDocuments = [
    { title: 'Barangay Clearance', requestName: 'Barangay Clearance' },
    { title: 'Certificate of Residency', requestName: 'Certificate of Residency' },
    { title: 'Business Clearance', requestName: 'Barangay Business Clearance' },
    { title: 'Certificate of Indigency', requestName: 'Certificate of Indigency' }
];

function getPublicDisclosureAttachment(item) {
    return typeof item?.file_path === 'string' && /^api\/public_attachment\.php\?id=\d+$/.test(item.file_path)
        ? item.file_path
        : null;
}

let bulletinExcerptObserver;

function fitBulletinExcerpt(element, text, maxLines) {
    if (!element) return;
    const source = String(text || '').trim().replace(/\s+/g, ' ');
    if (!source) {
        element.textContent = '';
        return;
    }

    const width = element.clientWidth;
    if (!width) return;
    const style = getComputedStyle(element);
    const canvas = fitBulletinExcerpt.canvas || (fitBulletinExcerpt.canvas = document.createElement('canvas'));
    const context = canvas.getContext('2d');
    context.font = `${style.fontStyle} ${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;
    const words = source.split(' ');
    const lineCount = parts => {
        let lines = 1;
        let line = '';
        for (const part of parts) {
            const candidate = line ? `${line} ${part}` : part;
            if (line && context.measureText(candidate).width > width) {
                lines += 1;
                line = part;
            } else {
                line = candidate;
            }
        }
        return lines;
    };

    if (lineCount(words) <= maxLines) {
        element.textContent = source;
        element.setAttribute('aria-label', source);
        return;
    }

    let low = 0;
    let high = words.length;
    while (low < high) {
        const middle = Math.ceil((low + high) / 2);
        if (lineCount([...words.slice(0, middle), '…']) <= maxLines) low = middle;
        else high = middle - 1;
    }
    element.textContent = `${words.slice(0, low).join(' ')}${low ? ' ' : ''}…`;
    element.setAttribute('aria-label', source);
}

function observeBulletinExcerpts() {
    if (bulletinExcerptObserver) bulletinExcerptObserver.disconnect();
    bulletinExcerptObserver = new ResizeObserver(entries => {
        entries.forEach(({ target }) => fitBulletinExcerpt(target, target.dataset.fullExcerpt, Number(target.dataset.maxExcerptLines)));
    });
    document.querySelectorAll('#bulletin-latest-list [data-full-excerpt], #bulletin-archive-list [data-full-excerpt]')
        .forEach(element => bulletinExcerptObserver.observe(element));
}

function getPublicDisclosureRecordHref(item, categoryId) {
    const params = new URLSearchParams({ category: String(categoryId) });
    const recordId = Number(item?.disclosure_id);
    if (Number.isSafeInteger(recordId) && recordId > 0) params.set('record', String(recordId));
    return `index.php#bulletin?${params.toString()}`;
}

function getBulletinRecordIdFromLocation() {
    const [route, query = ''] = window.location.hash.slice(1).split('?', 2);
    if (route !== 'bulletin') return null;
    const recordId = Number(new URLSearchParams(query).get('record'));
    return Number.isSafeInteger(recordId) && recordId > 0 ? recordId : null;
}

function scrollToBulletinRecordFromLocation() {
    const recordId = getBulletinRecordIdFromLocation();
    if (!recordId) return;
    requestAnimationFrame(() => {
        document.getElementById(`bulletin-record-${recordId}`)?.scrollIntoView({
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
            block: 'start'
        });
    });
}

function renderBulletinRecordSkeletons(container, label = 'Loading public records…') {
    container.classList.remove('is-empty');
    container.setAttribute('aria-busy', 'true');
    clearNode(container);
    container.appendChild(el('p', { className: 'visually-hidden', attrs: { role: 'status' }, text: label }));
    for (let index = 0; index < 3; index += 1) {
        container.appendChild(el('div', {
            className: 'bulletin-record-row bulletin-record-skeleton',
            attrs: { 'aria-hidden': 'true' }
        }, [
            el('div', { className: 'bulletin-record-main' }, [
                el('span', { className: 'bulletin-record-skeleton-bar bulletin-record-skeleton-title' }),
                el('span', { className: 'bulletin-record-skeleton-bar bulletin-record-skeleton-meta' }),
                el('span', { className: 'bulletin-record-skeleton-bar bulletin-record-skeleton-summary' }),
                el('span', { className: 'bulletin-record-skeleton-bar bulletin-record-skeleton-summary' })
            ]),
            el('div', { className: 'bulletin-record-actions' }, [
                el('span', { className: 'bulletin-record-skeleton-action' })
            ])
        ]));
    }
}

function renderBulletinAnnouncementSkeletons(container) {
    container.setAttribute('aria-busy', 'true');
    container.style.setProperty('--bulletin-latest-columns', '2');
    clearNode(container);
    container.appendChild(el('p', { className: 'visually-hidden', attrs: { role: 'status' }, text: 'Loading announcements…' }));
    for (let index = 0; index < 2; index += 1) {
        container.appendChild(el('article', {
            className: 'bulletin-announcement-card bulletin-announcement-skeleton',
            attrs: { 'aria-hidden': 'true' }
        }, [
            el('span', { className: 'bulletin-announcement-skeleton-bar bulletin-announcement-skeleton-badge' }),
            el('span', { className: 'bulletin-announcement-skeleton-bar bulletin-announcement-skeleton-title' }),
            el('span', { className: 'bulletin-announcement-skeleton-bar bulletin-announcement-skeleton-date' }),
            el('div', { className: 'bulletin-announcement-skeleton-summary' }, [
                el('span', { className: 'bulletin-announcement-skeleton-bar' }),
                el('span', { className: 'bulletin-announcement-skeleton-bar' }),
                el('span', { className: 'bulletin-announcement-skeleton-bar' })
            ]),
            el('span', { className: 'bulletin-announcement-skeleton-bar bulletin-announcement-skeleton-link' })
        ]));
    }
}

function createBulletinAnnouncementEmptyState() {
    return el('article', {
        className: 'bulletin-announcement-card bulletin-announcement-empty',
        attrs: { role: 'status' }
    }, [
        el('span', { className: 'bulletin-category-badge', text: 'Announcements' }),
        el('i', { className: 'fas fa-bullhorn bulletin-announcement-placeholder-icon', attrs: { 'aria-hidden': 'true' } }),
        el('h3', { text: 'No announcements yet' }),
        el('p', { className: 'bulletin-announcement-placeholder-copy', text: 'New public updates will appear here once they are published.' })
    ]);
}

function renderBulletinLatest(query = '') {
    const container = document.getElementById('bulletin-latest-list');
    const section = document.getElementById('bulletin-latest-section');
    if (!container || !section) return;
    section.hidden = false;

    if (!publicDisclosuresLoaded) {
        if (publicDisclosuresLoadError) {
            clearNode(container);
            container.setAttribute('aria-busy', 'false');
            container.appendChild(el('p', { className: 'bulletin-inline-state', text: 'Announcements are temporarily unavailable.' }));
            container.appendChild(el('button', {
                className: 'bulletin-retry-button', text: 'Try again', attrs: { type: 'button' },
                on: { click: () => loadDisclosures(true) }
            }));
        } else {
            renderBulletinAnnouncementSkeletons(container);
        }
        return;
    }

    container.setAttribute('aria-busy', 'false');
    const announcements = publicDisclosuresItems.filter(item => Number(item.category_id) === 1).slice(0, 3);
    const renderedCardCount = Math.max(1, announcements.length);
    container.style.setProperty('--bulletin-latest-columns', String(Math.max(1, Math.min(3, announcements.length))));
    const announcementKey = JSON.stringify(announcements.map(item => [
        item.disclosure_id,
        item.title,
        item.content,
        item.timestamp_posted
    ]));
    const alreadyRendered = container.__announcementKey === announcementKey
        && container.querySelectorAll('.bulletin-announcement-card').length === renderedCardCount;
    if (alreadyRendered) return;

    clearNode(container);
    container.__announcementKey = announcementKey;
    if (!announcements.length) {
        container.appendChild(createBulletinAnnouncementEmptyState());
        return;
    }

    announcements.forEach(item => {
        const posted = String(item.timestamp_posted ?? '');
        const card = el('article', { className: 'bulletin-announcement-card bulletin-announcement-enter' }, [
            el('span', { className: 'bulletin-category-badge', text: 'Announcement' }),
            el('h3', { text: item.title || 'Untitled record' }),
            el('time', {
                className: 'bulletin-record-date', text: posted ? formatDate(posted) : 'Date unavailable',
                attrs: posted ? { datetime: posted } : {}
            }),
            el('p', {
                className: 'bulletin-announcement-summary', text: item.content || '',
                attrs: { 'data-full-excerpt': item.content || '', 'data-max-excerpt-lines': '3' }
            }),
            el('button', {
                className: 'bulletin-text-link', text: 'Read more →', attrs: { type: 'button' },
                on: { click: () => openDisclosureCategory(1) }
            })
        ]);
        container.appendChild(card);
    });
    container.querySelectorAll('.bulletin-announcement-summary').forEach(element =>
        fitBulletinExcerpt(element, element.dataset.fullExcerpt, Number(element.dataset.maxExcerptLines)));
    observeBulletinExcerpts();
}

function updateBulletinFilterSubtitle() {
    const filters = document.getElementById('bulletin-category-filters');
    const subtitle = document.getElementById('bulletin-filter-subtitle');
    if (!filters || !subtitle) return;
    const activeButton = filters.querySelector(`[data-bulletin-filter="${activeBulletinFilter}"]`);
    subtitle.textContent = activeButton?.querySelector('.bulletin-filter-copy small')?.textContent.trim() || '';
}

function scrollSelectedBulletinFilterIntoView(filter) {
    if (!window.matchMedia('(max-width: 767px)').matches) return;
    const track = document.getElementById('bulletin-category-filters');
    const chip = track?.querySelector(`[data-bulletin-filter="${filter}"]`);
    if (!track || !chip) return;
    window.clearTimeout(track.__bulletinFilterSettleTimer);
    window.clearTimeout(track.__bulletinProgrammaticFilterTimer);
    const trackRect = track.getBoundingClientRect();
    const chipRect = chip.getBoundingClientRect();
    const offset = chipRect.left + chipRect.width / 2 - (trackRect.left + track.clientWidth / 2);
    const maxScroll = Math.max(0, track.scrollWidth - track.clientWidth);
    const target = Math.min(maxScroll, Math.max(0, track.scrollLeft + offset));
    if (Math.abs(target - track.scrollLeft) < 1) {
        track.__bulletinProgrammaticFilterTarget = '';
        return;
    }
    track.__bulletinProgrammaticFilterTarget = String(filter);
    track.__bulletinProgrammaticFilterTimer = window.setTimeout(() => {
        track.__bulletinProgrammaticFilterTarget = '';
        track.__bulletinProgrammaticFilterTimer = 0;
    }, 900);
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    track.scrollTo({ left: target, behavior: reducedMotion ? 'auto' : 'smooth' });
}

function updateBulletinFilterDots(filter = activeBulletinFilter) {
    document.querySelectorAll('#bulletin-filter-dots [data-bulletin-filter-dot]').forEach(dot => {
        const active = String(dot.dataset.bulletinFilterDot) === String(filter);
        if (active) dot.setAttribute('aria-current', 'true');
        else dot.removeAttribute('aria-current');
    });
}

function updateBulletinFilterRailState(filter = activeBulletinFilter) {
    document.querySelectorAll('#bulletin-category-filters [data-bulletin-filter]').forEach(chip => {
        const active = String(chip.dataset.bulletinFilter) === String(filter);
        chip.classList.toggle('is-active', active);
        chip.setAttribute('aria-pressed', String(active));
    });
    updateBulletinFilterDots(filter);
}

function bindBulletinFilterRail() {
    const track = document.getElementById('bulletin-category-filters');
    if (!track || track.__bulletinFilterScrollBound) return;
    track.__bulletinFilterScrollBound = true;
    let frame = 0;
    const getNearestFilter = () => {
        const center = track.getBoundingClientRect().left + track.clientWidth / 2;
        const nearest = Array.from(track.querySelectorAll('[data-bulletin-filter]')).reduce((best, chip) => {
            const rect = chip.getBoundingClientRect();
            const distance = Math.abs(rect.left + rect.width / 2 - center);
            return !best || distance < best.distance ? { value: chip.dataset.bulletinFilter, distance } : best;
        }, null);
        return nearest?.value;
    };
    track.addEventListener('scroll', () => {
        const programmaticTarget = track.__bulletinProgrammaticFilterTarget;
        if (programmaticTarget) {
            if (String(getNearestFilter()) !== programmaticTarget) return;
            track.__bulletinProgrammaticFilterTarget = '';
            window.clearTimeout(track.__bulletinProgrammaticFilterTimer);
            track.__bulletinProgrammaticFilterTimer = 0;
        }
        if (!frame) {
            frame = requestAnimationFrame(() => {
                frame = 0;
                const nearestFilter = getNearestFilter();
                const pendingTarget = track.__bulletinProgrammaticFilterTarget;
                if (pendingTarget && String(nearestFilter) !== pendingTarget) return;
                updateBulletinFilterRailState(nearestFilter);
            });
        }
        window.clearTimeout(track.__bulletinFilterSettleTimer);
        track.__bulletinFilterSettleTimer = window.setTimeout(() => {
            const filter = getNearestFilter();
            const pendingTarget = track.__bulletinProgrammaticFilterTarget;
            if (pendingTarget && String(filter) !== pendingTarget) return;
            if (filter !== undefined && String(filter) !== String(activeBulletinFilter)) setBulletinFilter(filter);
        }, 120);
    }, { passive: true });
}

function createBulletinRecordPlaceholder() {
    return el('article', {
        className: 'bulletin-record-row bulletin-record-placeholder',
        attrs: { role: 'status' }
    }, [
        el('span', { className: 'bulletin-record-placeholder-label', text: 'Coming soon' }),
        el('strong', { className: 'bulletin-record-placeholder-title', text: 'More public records' }),
        el('p', { className: 'bulletin-record-placeholder-copy', text: 'New records will appear here as they are published.' })
    ]);
}

function renderBulletinArchive(query = '') {
    const container = document.getElementById('bulletin-archive-list');
    const filters = document.getElementById('bulletin-category-filters');
    if (!container || !filters) return;
    bindBulletinFilterRail();
    const normalized = String(query || '').trim().toLocaleLowerCase();
    const countAll = document.getElementById('bulletin-count-all');
    updateBulletinFilterRailState();
    updateBulletinFilterSubtitle();

    if (!publicDisclosuresLoaded) {
        [1, 2, 3, 4].forEach(id => {
            const count = document.getElementById(`bulletin-count-${id}`);
            if (count) {
                count.textContent = '—';
                count.hidden = false;
            }
        });
        if (countAll) countAll.textContent = '—';
        container.setAttribute('aria-busy', publicDisclosuresLoadError ? 'false' : 'true');
        clearNode(container);
        if (publicDisclosuresLoadError) {
            container.classList.add('is-empty');
            container.appendChild(el('p', { className: 'bulletin-inline-state', text: 'Public records are temporarily unavailable.' }));
            container.appendChild(el('button', {
                className: 'bulletin-retry-button', text: 'Try again', attrs: { type: 'button' },
                on: { click: () => loadDisclosures(true) }
            }));
        } else {
            renderBulletinRecordSkeletons(container);
        }
        return;
    }

    container.setAttribute('aria-busy', 'false');
    const validRecords = publicDisclosuresItems.filter(item => publicBulletinCategories[Number(item.category_id)]);
    const visibleRecords = validRecords.filter(item => {
        const categoryId = Number(item.category_id);
        if (activeBulletinFilter !== 'all' && categoryId !== Number(activeBulletinFilter)) return false;
        if (!normalized) return true;
        const categoryName = publicBulletinCategories[categoryId]?.title || '';
        return `${item.title || ''} ${item.content || ''} ${item.file_name || ''} ${categoryName}`.toLocaleLowerCase().includes(normalized);
    });

    if (countAll) countAll.textContent = String(validRecords.length);
    [1, 2, 3, 4].forEach(id => {
        const categoryCount = validRecords.filter(item => Number(item.category_id) === id).length;
        const count = document.getElementById(`bulletin-count-${id}`);
        const button = filters.querySelector(`[data-bulletin-filter="${id}"]`);
        const subtitle = filters.querySelector(`[data-bulletin-filter="${id}"] .bulletin-filter-copy small`);
        if (count) {
            count.textContent = String(categoryCount);
            count.hidden = false;
        }
        button?.classList.toggle('has-no-records', categoryCount === 0);
        if (subtitle) {
            subtitle.textContent = categoryCount === 0
                ? 'No records yet'
                : (subtitle.dataset.defaultSubtitle || subtitle.textContent);
            subtitle.classList.toggle('is-empty', categoryCount === 0);
        }
    });
    updateBulletinFilterSubtitle();
    clearNode(container);
    if (!visibleRecords.length) {
        if (!normalized) {
            container.classList.remove('is-empty');
            for (let index = 0; index < 3; index += 1) {
                container.appendChild(createBulletinRecordPlaceholder());
            }
            return;
        }
        container.classList.add('is-empty');
        container.appendChild(createBulletinEmptyState(
            'No matching records',
            'Try a different search or show all records.',
            'fa-magnifying-glass'
        ));
        container.appendChild(el('button', {
            className: 'bulletin-text-link bulletin-reset-link', text: 'Show all records', attrs: { type: 'button' },
            on: { click: () => {
                const input = document.getElementById('public-records-search-input');
                if (input) input.value = '';
                activeBulletinFilter = 'all';
                clearPublicRecordsSearch();
                renderBulletinArchive('');
                renderBulletinLatest('');
            } }
        }));
        return;
    }

    container.classList.remove('is-empty');
    visibleRecords.forEach(item => {
        const categoryId = Number(item.category_id);
        const posted = String(item.timestamp_posted ?? '');
        const recordHref = getPublicDisclosureRecordHref(item, categoryId);
        const row = el('article', { className: 'bulletin-record-row bulletin-record-enter' }, [
            el('div', { className: 'bulletin-record-main' }, [
                el('a', {
                    className: 'bulletin-record-title', text: item.title || 'Untitled record', attrs: { href: recordHref }
                }),
                el('div', { className: 'bulletin-record-meta' }, [
                    el('span', { className: 'bulletin-record-type', text: publicBulletinCategories[categoryId]?.title || 'Public record' }),
                    el('time', {
                        className: 'bulletin-record-date', text: posted ? formatDate(posted) : 'Date unavailable',
                        attrs: posted ? { datetime: posted } : {}
                    })
                ]),
                el('p', {
                    className: 'bulletin-record-summary', text: item.content || '',
                    attrs: { 'data-full-excerpt': item.content || '', 'data-max-excerpt-lines': '2' }
                })
            ])
        ]);
        const actions = el('div', { className: 'bulletin-record-actions' });
        const attachment = getPublicDisclosureAttachment(item);
        actions.appendChild(el('a', {
            className: 'bulletin-record-action', text: 'View record', attrs: { href: recordHref }
        }));
        if (attachment) {
            const fileType = String(item.file_type || item.file_name?.split('.').pop() || 'File')
                .replace(/^\./, '')
                .toLocaleUpperCase();
            const fileSize = item.file_size == null ? '' : formatDisclosureFileSize(Number(item.file_size));
            actions.appendChild(el('span', {
                className: 'bulletin-attachment-chip',
                text: fileSize ? `${fileType} · ${fileSize}` : fileType
            }));
        }
        if (currentUser?.role === 'Admin') {
            actions.appendChild(el('button', {
                className: 'bulletin-record-delete', text: 'Delete', attrs: { type: 'button', 'aria-label': `Delete ${item.title || 'record'}` },
                on: { click: () => deleteBulletinDisclosure(item.disclosure_id, item.title || 'record') }
            }));
        }
        row.appendChild(actions);
        container.appendChild(row);
    });
    const placeholdersNeeded = !normalized && visibleRecords.length % 3
        ? 3 - (visibleRecords.length % 3)
        : 0;
    for (let index = 0; index < placeholdersNeeded; index += 1) {
        container.appendChild(createBulletinRecordPlaceholder());
    }
    container.querySelectorAll('.bulletin-record-summary').forEach(element =>
        fitBulletinExcerpt(element, element.dataset.fullExcerpt, Number(element.dataset.maxExcerptLines)));
    observeBulletinExcerpts();
}

function setBulletinFilter(filter) {
    const value = filter === 'all' ? 'all' : Number(filter);
    if (value !== 'all' && !publicBulletinCategories[value]) return;
    if (activeBulletinCategory) setBulletinCategoryView(null);
    activeBulletinFilter = value;
    updateBulletinFilterRailState(value);
    requestAnimationFrame(() => scrollSelectedBulletinFilterIntoView(value));
    const query = document.getElementById('public-records-search-input')?.value || '';
    if (query.trim()) renderPublicRecordsSearch(query);
    else {
        clearPublicRecordsSearch();
        renderBulletinArchive('');
        renderBulletinLatest('');
    }
}

function clearPublicRecordsSearch() {
    const results = document.getElementById('public-records-search-results');
    const summary = document.getElementById('public-records-search-summary');
    const grid = document.querySelector('#bulletin .bulletin-grid');
    if (results) { results.hidden = true; clearNode(results); }
    if (summary) summary.textContent = '';
    grid?.classList.remove('is-search-filtered');
}

function syncPublicRecordsSearchControls() {
    const input = document.getElementById('public-records-search-input');
    const clearButton = document.querySelector('#bulletin .public-records-search-clear');
    if (input && clearButton) clearButton.hidden = !input.value;
}

function clearPublicRecordsSearchInput() {
    const input = document.getElementById('public-records-search-input');
    if (!input) return;
    input.value = '';
    syncPublicRecordsSearchControls();
    renderPublicRecordsSearch('');
    input.focus();
}

function updatePublicRecordsSearchPlaceholder() {
    const input = document.getElementById('public-records-search-input');
    if (!input) return;
    input.placeholder = window.matchMedia('(max-width: 767px)').matches
        ? 'Search public records'
        : 'Search public records and document names';
}

function appendDocumentMatches(results, matches) {
    if (!matches.length) return;
    results.appendChild(el('h2', { className: 'records-search-heading', text: 'Document matches' }));
    const grid = el('div', { className: 'records-search-grid' });
    matches.forEach(item => grid.appendChild(el('button', {
        className: 'records-search-result', attrs: { type: 'button' },
        on: { click: () => requestDoc(item.requestName) }
    }, [
        el('span', {}, [el('small', { text: 'Document request' }), item.title]),
        el('i', { className: 'fas fa-arrow-right', attrs: { 'aria-hidden': 'true' } })
    ])));
    results.appendChild(grid);
}

function renderPublicRecordsSearch(query) {
    const normalized = String(query || '').trim().toLocaleLowerCase();
    const results = document.getElementById('public-records-search-results');
    const summary = document.getElementById('public-records-search-summary');
    const grid = document.querySelector('#bulletin .bulletin-grid');
    if (!results || !summary || !grid) return;
    renderBulletinArchive(query);
    renderBulletinLatest(query);
    if (!normalized) { clearPublicRecordsSearch(); return; }

    const documentMatches = portalSearchDocuments.filter(item => item.title.toLocaleLowerCase().includes(normalized));
    const recordMatches = publicDisclosuresItems.filter(item => {
        if (activeBulletinFilter !== 'all' && Number(item.category_id) !== Number(activeBulletinFilter)) return false;
        const categoryName = publicBulletinCategories[Number(item.category_id)]?.title || '';
        return `${item.title || ''} ${item.content || ''} ${item.file_name || ''} ${categoryName}`.toLocaleLowerCase().includes(normalized);
    });
    const total = documentMatches.length + recordMatches.length;
    clearNode(results);
    summary.textContent = publicDisclosuresLoaded
        ? `${total} ${total === 1 ? 'match' : 'matches'} across public records and document requests for “${String(query).trim()}”.`
        : publicDisclosuresLoadError
            ? `Public records are temporarily unavailable. ${documentMatches.length} document ${documentMatches.length === 1 ? 'match' : 'matches'} found.`
            : `${documentMatches.length ? `${documentMatches.length} document ${documentMatches.length === 1 ? 'match' : 'matches'} found. ` : ''}Searching public records for “${String(query).trim()}”…`;

    results.hidden = documentMatches.length === 0 && (recordMatches.length > 0 || !publicDisclosuresLoaded);
    if (documentMatches.length) {
        results.hidden = false;
        appendDocumentMatches(results, documentMatches);
    } else if (publicDisclosuresLoaded && !recordMatches.length) {
        results.hidden = false;
        results.appendChild(el('div', { className: 'records-search-empty', attrs: { role: 'status' } }, [
            el('strong', { text: 'No matching results' }),
            el('span', { text: 'Try another search term or browse Public Records.' })
        ]));
    }
}

function portalSearch(form) {
    const query = String(new FormData(form).get('query') || '').trim();
    if (isStandaloneUnavailableShell()) {
        navigateStandaloneUnavailable('bulletin', { search: query });
        return false;
    }
    showSection('bulletin');
    const input = document.getElementById('public-records-search-input');
    if (input) input.value = query;
    syncPublicRecordsSearchControls();
    activeBulletinFilter = 'all';
    setBulletinCategoryView(null);
    renderPublicRecordsSearch(query);
    void loadDisclosures().then(() => renderPublicRecordsSearch(query));
    return false;
}

function searchPublicRecords(form) {
    return portalSearch(form);
}

function renderBulletinCategory(categoryId) {
    const posts = document.getElementById('bulletin-category-posts');
    const list = document.getElementById('bulletin-category-list');
    if (!posts || !list || !publicBulletinCategories[categoryId]) return;
    clearNode(list);
    list.classList.remove('is-empty');

    if (!publicDisclosuresLoaded) {
        posts.setAttribute('aria-busy', publicDisclosuresLoadError ? 'false' : 'true');
        if (publicDisclosuresLoadError) {
            list.classList.add('is-empty');
            list.setAttribute('aria-busy', 'false');
            list.appendChild(el('div', { className: 'bulletin-load-error' }, [
                el('p', { className: 'empty-state', text: 'Public records could not be loaded. Check your connection and try again.' }),
                el('button', {
                    className: 'btn-outline bulletin-retry-button',
                    text: 'Try again',
                    attrs: { type: 'button' },
                    on: { click: () => loadDisclosures(true) }
                })
            ]));
        } else {
            renderBulletinRecordSkeletons(list);
        }
        return;
    }

    posts.setAttribute('aria-busy', 'false');
    list.setAttribute('aria-busy', 'false');
    const categoryItems = publicDisclosuresItems.filter(item => Number(item.category_id) === categoryId);
    if (categoryItems.length === 0) {
        list.classList.add('is-empty');
        list.appendChild(createBulletinEmptyState(
            getBulletinEmptyTitle(categoryId),
            getBulletinEmptyMessage(categoryId)
        ));
        return;
    }

    categoryItems.forEach(item => {
        const posted = String(item.timestamp_posted ?? '');
        const article = el('article', { className: 'category-post bulletin-post-enter' }, [
            el('h2', { text: item.title || 'Untitled record' }),
            el('time', {
                text: posted ? formatDate(posted) : 'Date unavailable',
                attrs: posted ? { datetime: posted } : {}
            }),
            el('p', { text: item.content || '' })
        ]);
        const recordId = Number(item.disclosure_id);
        if (Number.isSafeInteger(recordId) && recordId > 0) article.id = `bulletin-record-${recordId}`;
        const safeFilePath = typeof item.file_path === 'string'
            && /^api\/public_attachment\.php\?id=\d+$/.test(item.file_path)
            ? item.file_path
            : null;
        if (safeFilePath) {
            article.appendChild(el('div', { className: 'attachment-section' }, [
                el('span', { className: 'attachment-label' }, [
                    el('i', { className: 'fas fa-paperclip', attrs: { 'aria-hidden': 'true' } }),
                    ' Attachment'
                ]),
                el('a', {
                    className: 'attachment-link',
                    text: item.file_name || 'Open attached file',
                    attrs: { href: safeFilePath, target: '_blank', rel: 'noopener' }
                })
            ]));
        }
        list.appendChild(article);
    });
    scrollToBulletinRecordFromLocation();
}

function setBulletinCategoryView(categoryId) {
    const id = publicBulletinCategories[Number(categoryId)] ? Number(categoryId) : null;
    const changed = activeBulletinCategory !== id;
    const grid = document.querySelector('#bulletin .bulletin-grid');
    const overview = document.getElementById('bulletin-overview-content');
    const view = document.getElementById('bulletin-category-view');
    const backButton = document.getElementById('bulletin-category-back');
    const header = document.querySelector('#bulletin .dashboard-header');
    const eyebrow = document.getElementById('bulletin-eyebrow');
    const heading = document.getElementById('bulletin-title');
    const description = document.getElementById('bulletin-description');
    const breadcrumbCurrent = document.getElementById('bulletin-breadcrumb-current');
    if (!grid || !view || !overview || !heading || !description) return;

    activeBulletinCategory = id;
    overview.hidden = Boolean(id);
    grid.hidden = Boolean(id);
    if (id) {
        view.hidden = false;
        view.classList.remove('is-leaving');
    } else if (!changed || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        view.hidden = true;
        view.classList.remove('is-leaving');
    } else {
        view.hidden = false;
        view.classList.remove('is-entering', 'is-leaving');
        void view.offsetWidth;
        view.classList.add('is-leaving');
        view.addEventListener('animationend', () => {
            view.classList.remove('is-leaving');
            if (!activeBulletinCategory) view.hidden = true;
        }, { once: true });
    }
    if (backButton) backButton.hidden = !id;

    if (!id) {
        if (breadcrumbCurrent) breadcrumbCurrent.textContent = 'SK Public Bulletin Board';
        if (eyebrow) {
            eyebrow.textContent = 'Transparency center';
            eyebrow.hidden = false;
        }
        heading.textContent = 'SK Public Bulletin Board';
        description.textContent = 'Browse official announcements, financial records, projects, and meeting minutes.';
        document.title = appDocumentTitle;
        if (changed) {
            grid.classList.remove('is-entering');
            if (header) header.classList.remove('is-entering');
            void grid.offsetWidth;
            if (header) void header.offsetWidth;
            if (header) header.classList.add('is-entering');
            grid.classList.add('is-entering');
        }
        return;
    }

    const category = publicBulletinCategories[id];
    if (breadcrumbCurrent) breadcrumbCurrent.textContent = category.title;
    if (eyebrow) {
        eyebrow.textContent = 'Public record';
        eyebrow.hidden = false;
    }
    heading.textContent = category.title;
    description.textContent = category.description;
    document.title = `${category.title} | Barangay San Jose Public Records`;
    if (changed) {
        grid.classList.remove('is-entering');
        view.classList.remove('is-entering', 'is-leaving');
        if (header) header.classList.remove('is-entering');
        void grid.offsetWidth;
        void view.offsetWidth;
        if (header) void header.offsetWidth;
        if (header) header.classList.add('is-entering');
        view.classList.add('is-entering');
        heading.setAttribute('tabindex', '-1');
        requestAnimationFrame(() => heading.focus({ preventScroll: true }));
    }
    renderBulletinCategory(id);
}

function openDisclosureCategory(categoryId) {
    const id = Number(categoryId);
    if (!publicBulletinCategories[id]) return;
    const wasOutsideBulletin = document.querySelector('.section.active')?.id !== 'bulletin';
    if (wasOutsideBulletin) showSection('bulletin');

    const destinationPath = new URL('index.php', document.baseURI).pathname;
    const destination = `${destinationPath}#bulletin?category=${id}`;
    history.replaceState({ section: 'bulletin', bulletinCategory: id }, '', destination);
    setBulletinCategoryView(id);
    window.scrollTo({ top: 0, left: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    loadDisclosures();
}

function getBulletinCategoryFromLocation() {
    const rawHash = window.location.hash.slice(1);
    const [route, query = ''] = rawHash.split('?', 2);
    if (route !== 'bulletin') return null;
    const id = Number(new URLSearchParams(query).get('category'));
    return publicBulletinCategories[id] ? id : null;
}

function showBulletinOverview() {
    const destinationPath = new URL('index.php', document.baseURI).pathname;
    history.replaceState({ section: 'bulletin' }, '', `${destinationPath}#bulletin`);
    setBulletinCategoryView(null);
    activeBulletinFilter = 'all';
    const searchInput = document.getElementById('public-records-search-input');
    if (searchInput) searchInput.value = '';
    clearPublicRecordsSearch();
    renderBulletinArchive('');
    renderBulletinLatest('');
    window.scrollTo({ top: 0, left: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
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
    const lockScroll = options.lockScroll !== false;
    modal.dataset.scrollLocked = lockScroll ? 'true' : 'false';
    if (lockScroll) {
        const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
        document.body.style.paddingRight = scrollbarWidth > 0 ? `${scrollbarWidth}px` : '';
        document.body.classList.add('modal-open');
        document.documentElement.classList.add('modal-open-root');
    }
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
    modal.dataset.scrollLocked = 'true';
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
        if (modal.dataset.scrollLocked === 'true') {
            document.body.classList.remove('modal-open');
            document.body.style.paddingRight = '';
            document.documentElement.classList.remove('modal-open-root');
        }
        delete modal.dataset.scrollLocked;
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
    const requestProof = resident?.proofContext === 'request';
    const validPath = typeof path === 'string' && (requestProof
        ? /^api\/request_proof\.php\?id=\d+$/.test(path)
        : /^api\/resident_proof\.php\?id=\d+$/.test(path));
    if (!validPath) {
        showToast('Proof file is unavailable.', 'error');
        return;
    }
    const residentId = Number(resident?.residentId || 0);
    if (!requestProof && !residentId) return;
    const mobileProofPreview = window.matchMedia('(max-width: 767px)').matches;
    const title = requestProof
        ? (mobileProofPreview ? 'Submitted proof' : `Submitted proof — ${resident.tracking || resident.name || 'Request'}`)
        : (mobileProofPreview ? 'Proof of residency' : `Proof of residency — ${resident.name || 'Resident'}`);
    closeProofPreview();

    const proofType = String(resident?.proofFormat || resident?.proofType || '').toLowerCase();
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

    const proofInfoRows = requestProof ? [
        ['Tracking', resident.tracking],
        ['Document', resident.documentType],
        ['Proof type', resident.proofType],
        ['Email', resident.email],
        ['Address', resident.address],
        ['Submitted', resident.createdAt ? formatDateTime(resident.createdAt) : '—']
    ] : [
        ['Email', resident.email],
        ['Address', resident.address],
        ['Registered', resident.createdAt ? `${formatDate(resident.createdAt)} · ${new Date(String(resident.createdAt).replace(' ', 'T')).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })}` : '—']
    ];
    const residentInfo = el('aside', { className: 'proof-preview-resident', attrs: { 'aria-label': requestProof ? 'Request submission details' : 'Resident registration details' } }, [
        el('h3', { text: resident.name || 'Resident' }),
        ...proofInfoRows.map(([label, value]) => el('div', { className: 'proof-preview-info-row' }, [
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

    const viewer = el('section', { className: 'proof-preview-viewer', attrs: { 'aria-label': requestProof ? 'Submitted proof' : 'Residency proof' } }, [
        viewerControls,
        el('div', { className: 'proof-preview-image-wrap' }, [media])
    ]);

    const dialog = el('div', {
        className: 'proof-preview-box',
        attrs: { role: 'dialog', 'aria-modal': 'true', 'aria-label': title }
    }, [
        el('div', { className: 'proof-preview-head' }, [
            el('span', { className: 'proof-preview-title', text: title }),
            closeBtn
        ]),
        el('div', { className: 'proof-preview-body' }, [residentInfo, viewer])
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

function openRequestProofPreview(request) {
    const path = typeof request?.proof_url === 'string' && /^api\/request_proof\.php\?id=\d+$/.test(request.proof_url)
        ? request.proof_url
        : null;
    if (!path) {
        showToast('Proof file is unavailable.', 'error');
        return;
    }
    openProofPreview(path, {
        proofContext: 'request',
        proofFormat: request.proof_format,
        name: request.residentName || [request.first_name, request.middle_name, request.last_name].filter(Boolean).join(' ') || request.walk_in_name || 'Resident',
        email: request.residentEmail || request.email,
        address: request.residentAddress || request.address,
        tracking: request.tracking_code,
        documentType: request.documentType || request.document_type,
        proofType: request.proof_type,
        createdAt: request.created_at
    });
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
    const emailField = document.getElementById('login-email');
    const emailError = document.getElementById('login-email-error');
    if (emailError) emailError.textContent = '';
    emailField.setAttribute('aria-invalid', 'false');
    emailField.setCustomValidity('');
    if (!emailField.checkValidity()) { emailField.reportValidity(); return; }
    const email = emailField.value;
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
    const emailField = document.getElementById('admin-login-email');
    if (!emailField.checkValidity()) { emailField.reportValidity(); return; }
    const email = emailField.value;
    const password = document.getElementById('admin-login-password').value;
    performLogin(email, password, 'admin');
}

function getAdminMfaDigitInputs() {
    return Array.from(document.querySelectorAll('#admin-mfa-code-fields .admin-mfa-digit'));
}

function clearAdminMfaCodeError() {
    document.getElementById('admin-mfa-code-fields')?.classList.remove('is-error');
    const error = document.getElementById('admin-mfa-error');
    error?.classList.add('hidden');
    const message = document.getElementById('admin-mfa-error-message');
    if (message) message.textContent = '';
}

function showAdminMfaCodeError(message) {
    const fields = document.getElementById('admin-mfa-code-fields');
    const error = document.getElementById('admin-mfa-error');
    const errorMessage = document.getElementById('admin-mfa-error-message');
    if (errorMessage) errorMessage.textContent = message;
    fields?.classList.add('is-error');
    error?.classList.remove('hidden');
    const first = getAdminMfaDigitInputs()[0];
    first?.focus();
    first?.select();
}

function updateAdminMfaCode() {
    const input = document.getElementById('admin-mfa-code');
    const button = document.getElementById('admin-mfa-submit');
    const fields = getAdminMfaDigitInputs();
    const code = fields.map(field => field.value.replace(/\D/g, '').slice(0, 1)).join('');
    if (input) input.value = code;
    if (button) button.disabled = code.length !== 6 || button.getAttribute('aria-busy') === 'true';
    return code;
}

function resetAdminMfaDigits(focusFirst = false) {
    getAdminMfaDigitInputs().forEach(field => { field.value = ''; });
    clearAdminMfaCodeError();
    updateAdminMfaCode();
    if (focusFirst) getAdminMfaDigitInputs()[0]?.focus();
}

function updateAdminMfaDigits(event) {
    const field = event?.target;
    if (!(field instanceof HTMLInputElement) || !field.classList.contains('admin-mfa-digit')) return;
    clearAdminMfaCodeError();
    const fields = getAdminMfaDigitInputs();
    const index = Number(field.dataset.codeIndex) || 0;
    const digits = field.value.replace(/\D/g, '');
    if (digits.length > 1) {
        const code = digits.slice(0, 6).split('');
        fields.forEach((box, boxIndex) => { box.value = code[boxIndex] || ''; });
        fields[Math.min(code.length, 5)]?.focus();
    } else {
        field.value = digits.slice(0, 1);
        if (field.value && index < fields.length - 1) fields[index + 1]?.focus();
    }
    updateAdminMfaCode();
}

function handleAdminMfaDigitKeydown(event) {
    const field = event?.target;
    if (!(field instanceof HTMLInputElement) || !field.classList.contains('admin-mfa-digit')) return;
    const fields = getAdminMfaDigitInputs();
    const index = Number(field.dataset.codeIndex) || 0;
    if (event.key === 'Backspace') {
        clearAdminMfaCodeError();
        event.preventDefault();
        if (field.value) field.value = '';
        else if (index > 0) {
            fields[index - 1].value = '';
            fields[index - 1].focus();
        }
        updateAdminMfaCode();
        return;
    }
    if (event.key === 'Delete') {
        clearAdminMfaCodeError();
        return;
    }
    if (event.key === 'ArrowLeft' && index > 0) {
        event.preventDefault();
        fields[index - 1].focus();
        return;
    }
    if (event.key === 'ArrowRight' && index < fields.length - 1) {
        event.preventDefault();
        fields[index + 1].focus();
        return;
    }
    if (event.key.length === 1 && !/\d/.test(event.key) && !event.ctrlKey && !event.metaKey && !event.altKey) {
        event.preventDefault();
        return;
    }
    if (/^\d$/.test(event.key) && field.value) field.value = '';
}

function handleAdminMfaPaste(event) {
    const field = event?.target;
    if (!(field instanceof HTMLInputElement) || !field.classList.contains('admin-mfa-digit')) return;
    const digits = (event.clipboardData?.getData('text') || '').replace(/\D/g, '').slice(0, 6);
    if (!digits) return;
    event.preventDefault();
    clearAdminMfaCodeError();
    const fields = getAdminMfaDigitInputs();
    const start = digits.length === 6 ? 0 : Number(field.dataset.codeIndex) || 0;
    if (start === 0) fields.forEach(box => { box.value = ''; });
    digits.split('').forEach((digit, offset) => {
        if (fields[start + offset]) fields[start + offset].value = digit;
    });
    updateAdminMfaCode();
    fields[Math.min(start + digits.length, fields.length - 1)]?.focus();
}

async function handleAdminMfaSubmit(event) {
    event.preventDefault();
    const button = document.getElementById('admin-mfa-submit');
    const code = updateAdminMfaCode();
    if (button?.getAttribute('aria-busy') === 'true') return;
    if (!/^\d{6}$/.test(code)) {
        getAdminMfaDigitInputs()[0]?.focus();
        return;
    }
    clearAdminMfaCodeError();
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.querySelector('.admin-mfa-submit-label')?.classList.add('hidden');
    button.querySelector('.admin-mfa-submit-progress')?.classList.remove('hidden');
    try {
        const response = await fetch('api/verify_admin_mfa.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ code })
        });
        const data = await response.json();
        if (data.pageUnavailable) { showSection('page-unavailable'); return; }
        if (!response.ok || !data.success) throw new Error(data.message || 'We could not verify that code.');
        currentUser = { id: data.id, name: data.name, email: data.email || '', role: 'Admin' };
        startAdminSessionExpiry(data.createdAt);
        document.getElementById('admin-login-signin-step')?.classList.remove('hidden');
        document.getElementById('admin-login-form')?.classList.remove('hidden');
        document.getElementById('admin-mfa-step')?.classList.add('hidden');
        document.getElementById('admin-login-password').value = '';
        resetAdminMfaDigits();
        updateNavBar();
        showSection('admin-dashboard');
    } catch (requestError) {
        showAdminMfaCodeError(requestError.message || 'That code is invalid or expired. Check your device time and try again.');
    } finally {
        button.querySelector('.admin-mfa-submit-label')?.classList.remove('hidden');
        button.querySelector('.admin-mfa-submit-progress')?.classList.add('hidden');
        button.removeAttribute('aria-busy');
        updateAdminMfaCode();
    }
}

async function cancelAdminMfa() {
    try { await fetch('api/logout.php', { method: 'POST' }); } catch (_) { /* pending MFA expires automatically */ }
    document.getElementById('admin-mfa-step')?.classList.add('hidden');
    document.getElementById('admin-mfa-secret-wrap')?.classList.add('hidden');
    document.getElementById('admin-mfa-secret').textContent = '';
    document.getElementById('admin-mfa-form')?.reset();
    resetAdminMfaDigits();
    document.getElementById('admin-login-signin-step')?.classList.remove('hidden');
    document.getElementById('admin-login-form')?.classList.remove('hidden');
    document.getElementById('admin-login-password')?.focus();
}

function openForgotPassword(context = 'resident') {
    passwordResetToken = '';
    passwordResetReturnToProfile = context === 'profile' && currentUser?.role === 'Resident';
    passwordResetContext = context === 'admin' ? 'admin' : 'resident';
    const emailInput = document.getElementById('password-reset-email');
    const sourceEmail = document.getElementById(passwordResetContext === 'admin' ? 'admin-login-email' : 'login-email');
    if (emailInput) {
        emailInput.value = passwordResetReturnToProfile ? (currentUser.email || '') : (sourceEmail?.value || '');
        emailInput.readOnly = passwordResetReturnToProfile;
    }
    if (emailInput) setEmailValidation(emailInput);
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
    const eyebrow = document.getElementById('password-reset-eyebrow');
    const back = document.querySelector('#forgot-password .back-button');
    if (!requestForm || !resetForm) return;
    document.getElementById('forgot-password')?.classList.toggle('profile-password-flow', passwordResetReturnToProfile);
    bindPasswordChecklist(document.getElementById('new-password'), document.getElementById('confirm-new-password'), 'reset');
    clearPasswordResetFeedback();
    const hasToken = /^[a-f0-9]{64}$/i.test(passwordResetToken);
    requestForm.classList.toggle('hidden', hasToken);
    resetForm.classList.toggle('hidden', !hasToken);
    if (title) title.textContent = passwordResetReturnToProfile ? 'Change password' : (hasToken ? 'Choose a new password' : 'Forgot your password?');
    if (eyebrow) eyebrow.textContent = passwordResetReturnToProfile ? 'Login and security' : 'Account recovery';
    if (intro) intro.textContent = hasToken
        ? 'Create a new password for your Barangay San Jose portal account.'
        : (passwordResetReturnToProfile
            ? 'We’ll send a secure password-change link to your account email.'
            : 'Enter the email address for your portal account. We’ll send a secure link if it matches an account.');
    if (back) {
        clearNode(back);
        back.append(el('i', { className: 'fas fa-arrow-left', attrs: { 'aria-hidden': 'true' } }), document.createTextNode(passwordResetReturnToProfile ? ' Back to profile' : ' Back to log in'));
    }
    const returnButton = document.querySelector('#forgot-password .switch-form .text-button');
    if (returnButton) returnButton.textContent = passwordResetReturnToProfile ? 'Back to profile' : 'Return to log in';
}

async function returnFromPasswordReset() {
    if (passwordResetToken) {
        const cleanUrl = `${window.location.pathname}${window.location.hash || '#forgot-password'}`;
        history.replaceState(history.state, '', cleanUrl);
        passwordResetToken = '';
    }
    if (passwordResetReturnToProfile && currentUser?.role !== 'Resident') {
        try {
            const response = await fetch('api/session.php');
            const sessionData = await response.json();
            if (response.ok && sessionData.loggedIn && sessionData.role === 'Resident') {
                currentUser = { id: sessionData.id, name: sessionData.name, role: sessionData.role, email: sessionData.email || '', phoneNumber: sessionData.phoneNumber || '', emailVerified: sessionData.emailVerified === true };
                updateNavBar();
            }
        } catch { /* Fall back to sign-in if the profile session is no longer available. */ }
    }
    if (passwordResetReturnToProfile && currentUser?.role === 'Resident') {
        showSection('resident-profile');
        return;
    }
    showSection(passwordResetContext === 'admin' ? 'admin-login' : 'login');
}

async function requestPasswordReset(event) {
    event.preventDefault();
    clearPasswordResetFeedback();
    const button = document.getElementById('password-reset-submit');
    const emailField = document.getElementById('password-reset-email');
    const email = emailField.value.trim();
    if (!setEmailValidation(emailField) || !emailField.checkValidity()) { emailField.reportValidity(); return; }
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    try {
        const response = await fetch('api/request_password_reset.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, context: passwordResetContext, returnToProfile: passwordResetReturnToProfile })
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
    updatePasswordChecklist('reset');
    const passwordField = document.getElementById('new-password');
    const confirmField = document.getElementById('confirm-new-password');
    if (!passwordMeetsClientPolicy(password) || !passwordField.checkValidity()) {
        passwordField.reportValidity();
        return;
    }
    if (password !== confirmation) {
        confirmField.setCustomValidity('Passwords do not match.');
        confirmField.reportValidity();
        confirmField.setCustomValidity('');
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
            body: JSON.stringify({ token: passwordResetToken, password, returnToProfile: passwordResetReturnToProfile })
        });
        const result = await response.json();
        if (result.pageUnavailable) {
            showSection('page-unavailable');
            return;
        }
        if (!response.ok || !result.success) throw new Error(result.message || 'We could not update your password.');
        if (passwordResetReturnToProfile && result.sessionRestored) {
            const sessionResponse = await fetch('api/session.php');
            const sessionData = await sessionResponse.json();
            if (sessionResponse.ok && sessionData.loggedIn && sessionData.role === 'Resident') {
                currentUser = { id: sessionData.id, name: sessionData.name, role: sessionData.role, email: sessionData.email || '', phoneNumber: sessionData.phoneNumber || '', emailVerified: sessionData.emailVerified === true };
                passwordResetToken = '';
                updateNavBar();
                showToast('Your password has been changed.', 'success');
                showSection('resident-profile');
                return;
            }
        }
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

    const loginError = document.getElementById(context === 'resident' ? 'login-error' : 'admin-login-error');
    if (loginError) { loginError.textContent = ''; loginError.classList.add('hidden'); }
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
        if (context === 'admin' && (data.mfaRequired || data.mfaSetupRequired)) {
            document.getElementById('admin-login-signin-step')?.classList.add('hidden');
            document.getElementById('admin-login-form')?.classList.add('hidden');
            document.getElementById('admin-mfa-step')?.classList.remove('hidden');
            const setup = Boolean(data.mfaSetupRequired);
            document.getElementById('admin-mfa-title').textContent = setup ? 'Set up your authenticator' : 'Verify your identity';
            document.getElementById('admin-mfa-instructions').textContent = setup
                ? 'Add an account manually in your authenticator app, then enter its current 6-digit code below.'
                : 'Enter the 6-digit code from your authenticator app.';
            document.getElementById('admin-mfa-secret-wrap')?.classList.toggle('hidden', !setup);
            document.getElementById('admin-mfa-secret').textContent = setup ? String(data.secret || '') : '';
            resetAdminMfaDigits(true);
            return;
        }
        if (data.success) {
            const expectedRole = context === 'admin' ? 'Admin' : 'Resident';
            if (data.role !== expectedRole) {
                showToast('This account must use its designated login page.', 'error');
                return;
            }
            currentUser = { id: data.id, name: data.name, role: data.role, email: data.email || '', phoneNumber: data.phoneNumber || '', emailVerified: data.role === 'Admin' ? true : data.emailVerified === true };
            updateNavBar();
            showSection(context === 'admin' ? 'admin-dashboard' : 'resident-dashboard');
        } else {
            if (loginError) {
                loginError.textContent = data.message === 'Account Pending Verification'
                    ? "Your account is still under review. We'll notify you once it's approved."
                    : (data.message || 'Incorrect email or password.');
                loginError.classList.remove('hidden');
            } else if (loginError) {
                loginError.textContent = data.message || 'Incorrect email or password.';
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
    if (!zone || !input || input._dropzoneInitialized) return;
    input._dropzoneInitialized = true;

    input.addEventListener('change', async () => {
        const selected = input.files?.[0];
        if (!selected) return;
        const extension = selected.name.split('.').pop()?.toLowerCase();
        const supportedImageUnderLimit = ['jpg', 'jpeg', 'png'].includes(extension) && selected.size <= 5 * 1024 * 1024;
        if (selected.type.startsWith('image/') && !supportedImageUnderLimit) {
            const prepared = await prepareRegistrationPhoto(selected);
            if (!prepared) {
                input.value = '';
                return;
            }
            const transfer = new DataTransfer();
            transfer.items.add(prepared);
            input.files = transfer.files;
            regShowPreview(prepared);
            return;
        }
        regShowPreview(selected);
    });

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

async function prepareRegistrationPhoto(photo) {
    const maxBytes = 5 * 1024 * 1024;
    if (!photo.type.startsWith('image/')) {
        showToast('The camera did not return an image. Please try again.', 'error');
        return null;
    }

    // Resize/compress camera photos to a supported JPEG so common phone
    // camera files fit the same 5 MB limit as uploaded proof documents.
    if (typeof createImageBitmap === 'function') {
        let bitmap;
        try {
            bitmap = await createImageBitmap(photo);
            const scale = Math.min(1, 1920 / Math.max(bitmap.width, bitmap.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(bitmap.width * scale));
            canvas.height = Math.max(1, Math.round(bitmap.height * scale));
            const context = canvas.getContext('2d');
            if (!context) throw new Error('Photo processing is unavailable.');
            context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);

            for (const quality of [0.84, 0.72, 0.60]) {
                const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', quality));
                if (blob && blob.size <= maxBytes) {
                    return new File([blob], 'residency-proof.jpg', { type: 'image/jpeg', lastModified: Date.now() });
                }
            }
        } catch (error) {
            // Fall through to accepting an already-supported, small image.
        } finally {
            bitmap?.close();
        }
    }

    const extension = photo.name.split('.').pop()?.toLowerCase();
    if (photo.size <= maxBytes && ['jpg', 'jpeg', 'png'].includes(extension)) return photo;
    showToast('Could not prepare that photo. Please take another photo or choose a JPG or PNG under 5 MB.', 'error');
    return null;
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
function passwordMeetsClientPolicy(password) {
    return password.length >= 8 && password.length <= 256 && /[A-Z]/.test(password) && /[a-z]/.test(password) && /[0-9]/.test(password);
}

function bindPasswordChecklist(passwordField, confirmField, prefix = 'register') {
    if (passwordField && !passwordField._pwChecklistBound) {
        passwordField.addEventListener('input', () => updatePasswordChecklist(prefix));
        passwordField._pwChecklistBound = true;
    }
    if (confirmField && !confirmField._pwChecklistBound) {
        confirmField.addEventListener('input', () => updatePasswordChecklist(prefix));
        confirmField._pwChecklistBound = true;
    }
    updatePasswordChecklist(prefix);
}

function updatePasswordChecklist(prefix = 'register') {
    const isReset = prefix === 'reset';
    const isResidentChange = prefix === 'resident-change-password';
    const pwId = isReset ? 'new-password' : (isResidentChange ? 'resident-new-password' : 'register-password');
    const confirmId = isReset ? 'confirm-new-password' : (isResidentChange ? 'resident-confirm-new-password' : 'register-confirm-password');
    const pw = document.getElementById(pwId)?.value || '';
    const cpw = document.getElementById(confirmId)?.value || '';
    const started = pw.length > 0;

    const rules = [
        { suffix: 'length', pass: pw.length >= 8 },
        { suffix: 'upper', pass: /[A-Z]/.test(pw) },
        { suffix: 'lower', pass: /[a-z]/.test(pw) },
        { suffix: 'number', pass: /[0-9]/.test(pw) },
    ];

    let passCount = 0;
    rules.forEach(({ suffix, pass }, index) => {
        const id = isReset ? `reset-pwc-${suffix}` : (isResidentChange ? `resident-change-pwc-${suffix}` : `pwc-${suffix}`);
        const item = document.getElementById(id);
        if (!item) return;
        if (pass) passCount++;
        item.classList.toggle('pwc-pass', pass);
        const icon = item.querySelector('.pwc-icon');
        if (icon) icon.className = pass ? 'fas fa-check-circle pwc-icon' : 'far fa-circle pwc-icon';
    });

    // Strength bar — 4 segments
    if (!isReset && !isResidentChange) {
        for (let i = 1; i <= 4; i++) {
            document.getElementById(`pwseg-${i}`)?.classList.toggle('pwseg-active', started && i <= passCount);
        }
    }

    // Match line under confirm field
    const matchEl = document.getElementById(isReset ? 'reset-pwc-match' : (isResidentChange ? 'resident-change-pwc-match' : 'pwc-match'));
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
    if (pw && (!/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,256}$/.test(pw.value))) {
        showToast('Use at least 8 characters with uppercase and lowercase letters and a number.', 'error');
        pw.focus();
        return;
    }
    if (!consent?.checked) {
        showToast('Please agree to the Terms & Conditions before registering.', 'error');
        consent?.focus();
        return;
    }
    const emailField = document.getElementById('register-email');
    if (emailField && (!setEmailValidation(emailField) || !emailField.checkValidity())) {
        emailField.reportValidity();
        return;
    }
    const phoneField = document.getElementById('register-phone');
    if (phoneField && !setPhoneValidation(phoneField)) { phoneField.reportValidity(); return; }
    if (form && !form.reportValidity()) return;
    if (emailField && emailField.value.trim()) {
        if (emailField.validity.customError) {
            emailField.focus();
            showToast('This email already exists. Use another email or sign in to the existing account.', 'error');
            return;
        }
        const emailAvailable = await checkRegistrationEmail(emailField);
        if (emailAvailable === false) {
            emailField.focus();
            showToast('This email already exists. Use another email or sign in to the existing account.', 'error');
            return;
        }
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
                'Account created',
                'You can log in right away',
                [
                    result.message || 'Verify your email before submitting a document request.',
                    'You can browse the portal while your email is unverified.'
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
    clearTimeout(adminIdleLogoutTimer);
    clearTimeout(adminAbsoluteLogoutTimer);
    try { void fetch('api/logout.php', { method: 'POST', keepalive: true }).catch(() => {}); }
    catch (_) { /* The page still returns to the public landing page if logout cannot reach the server. */ }
    currentUser = null;
    updateNavBar();
    window.location.replace('index.php#landing');
}

function startAdminSessionExpiry(createdAt = Math.floor(Date.now() / 1000)) {
    clearTimeout(adminIdleLogoutTimer);
    clearTimeout(adminAbsoluteLogoutTimer);
    if (currentUser?.role !== 'Admin') return;

    const createdAtMs = Number(createdAt) * 1000;
    const sessionCreatedAt = Number.isFinite(createdAtMs) && createdAtMs > 0 ? createdAtMs : Date.now();
    const expireForInactivity = () => {
        clearTimeout(adminIdleLogoutTimer);
        adminIdleLogoutTimer = setTimeout(() => { void logout(); }, 30 * 60 * 1000);
    };
    if (!adminSessionActivityTrackingBound) {
        const recordAdminActivity = () => {
            if (currentUser?.role === 'Admin') expireForInactivity();
        };
        ['pointerdown', 'keydown', 'touchstart', 'scroll'].forEach(eventName => {
            document.addEventListener(eventName, recordAdminActivity, { passive: true });
        });
        adminSessionActivityTrackingBound = true;
    }

    const absoluteRemaining = 8 * 60 * 60 * 1000 - (Date.now() - sessionCreatedAt);
    if (absoluteRemaining <= 0) {
        void logout();
        return;
    }
    adminAbsoluteLogoutTimer = setTimeout(() => { void logout(); }, absoluteRemaining);
    expireForInactivity();
}

/* ============================================================
   LOAD DOCUMENT TYPES
   ============================================================ */
let documentTypesPromise = null;
async function loadDocTypes() {
    if (!documentTypesPromise) {
        documentTypesPromise = fetch('api/document_types.php')
            .then(async response => {
                if (!response.ok) throw new Error('Could not load document types.');
                const types = await response.json();
                if (!Array.isArray(types)) throw new Error(types.message || 'Could not load document types.');
                return types;
            })
            .catch(error => {
                documentTypesPromise = null;
                console.error('Document types could not be loaded.', error);
                return null;
            });
    }
    const types = await documentTypesPromise;
    const select = document.getElementById('doc-type');
    if (select) clearNode(select);

    const placeholder = el('option', { text: 'Select a document', attrs: { value: '', selected: '', disabled: '' } });
    if (select) select.appendChild(placeholder);

    if (!types) {
        if (select) select.options[0].textContent = 'Document types unavailable';
        const walkInSelect = document.getElementById('walkin-doc-type');
        if (walkInSelect) {
            clearNode(walkInSelect);
            walkInSelect.appendChild(el('option', { text: 'Document types unavailable', attrs: { value: '', selected: '', disabled: '' } }));
        }
        return;
    }

    types.forEach(t => {
        const hasFeeField = Object.prototype.hasOwnProperty.call(t, 'fee');
        const fee = hasFeeField && (t.fee == null || t.fee === '') ? null : getDocumentFee(t);
        const feeLabel = fee === null ? 'Fee confirmed at the barangay hall' : fee === 0 ? 'Free' : `₱${fee}`;
        const option = el('option', { text: `${t.name} · ${feeLabel}` });
        option.value = t.document_type_id;
        option.dataset.name = t.name;
        if (hasFeeField && (t.fee == null || t.fee === '')) option.dataset.feeAvailable = 'false';
        if (fee !== null) option.dataset.fee = String(fee);
        const requirements = t.requirements ?? t.requirements_text ?? t.requirement;
        const normalizedRequirements = Array.isArray(requirements)
            ? requirements.map(item => String(item || '').trim()).filter(Boolean).join(' · ')
            : String(requirements || '').trim();
        const hasRequirementsField = ['requirements', 'requirements_text', 'requirement'].some(key => Object.prototype.hasOwnProperty.call(t, key));
        option.dataset.requirements = normalizedRequirements || (hasRequirementsField ? '' : 'Bring a valid ID and your tracking number.');
        const processingTime = t.processing_time ?? t.processingTime ?? t.processing_days;
        const hasProcessingField = ['processing_time', 'processingTime', 'processing_days'].some(key => Object.prototype.hasOwnProperty.call(t, key));
        option.dataset.processingTime = String(processingTime || '').trim() || (hasProcessingField ? '' : '1–3 working days');
        select?.appendChild(option);
    });
    const walkInSelect = document.getElementById('walkin-doc-type');
    if (walkInSelect) {
        clearNode(walkInSelect);
        walkInSelect.appendChild(el('option', { text: 'Select a document', attrs: { value: '', selected: '', disabled: '' } }));
        types.forEach(type => {
            const option = el('option', { text: type.name });
            option.value = type.document_type_id;
            walkInSelect.appendChild(option);
        });
    }
    if (select) select.value = '';
    updateDocumentTypeInfo();
}

function updateDocumentTypeInfo() {
    const select = document.getElementById('doc-type');
    const info = document.getElementById('doc-type-info');
    if (!select || !info) return;
    let content = info.querySelector('.request-summary-content');
    if (!content) {
        content = el('div', { className: 'request-summary-content' });
        info.appendChild(content);
    }
    const selected = select.selectedOptions[0];
    const name = selected?.dataset.name;
    const fee = selected?.dataset.feeAvailable === 'false'
        ? null
        : selected?.dataset.fee != null ? Number(selected.dataset.fee) : getDocumentFee(name);
    const requirements = selected?.dataset.requirements?.trim() || '';
    const processingTime = selected?.dataset.processingTime?.trim() || '';
    clearNode(content);
    if (name) {
        const feeText = fee === null ? 'Fee confirmed at the barangay hall' : fee === 0 ? 'Free' : `₱${fee}`;
        content.append(el('span', { className: 'request-summary-label', text: 'Request summary' }));
        if (requirements) {
            content.appendChild(el('p', {
                className: 'request-summary-details request-summary-requirements',
                title: `Requirements: ${requirements}`
            }, [el('strong', { text: 'Requirements: ' }), requirements]));
        }
        const meta = el('div', { className: 'request-summary-meta' });
        if (fee !== null) meta.appendChild(el('p', { className: 'request-summary-details' }, [el('strong', { text: 'Fee: ' }), feeText]));
        if (processingTime) meta.appendChild(el('p', { className: 'request-summary-details' }, [el('strong', { text: 'Processing time: ' }), processingTime]));
        if (meta.childElementCount) content.appendChild(meta);
    } else {
        content.append(
            el('span', { className: 'request-summary-label', text: 'Request summary' }),
            el('p', { className: 'request-summary-placeholder', text: 'Select a document to view its details.' })
        );
    }
    content.classList.remove('is-entering');
    void content.offsetWidth;
    content.classList.add('is-entering');
    const processingGuidance = document.getElementById('request-processing-guidance');
    if (processingGuidance) processingGuidance.textContent = name && processingTime ? ` Processing time: ${processingTime}.` : '';
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
function setRequestFieldError(fieldId, errorId, message) {
    const field = document.getElementById(fieldId);
    const error = document.getElementById(errorId);
    if (field) field.setAttribute('aria-invalid', message ? 'true' : 'false');
    if (error) error.textContent = message;
    return Boolean(message);
}

function openRequestProofPicker() {
    document.getElementById('request-proof')?.click();
}

function formatRequestFileSize(bytes) {
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1).replace(/\.0$/, '')} MB`;
}

function clearRequestProofPreview() {
    if (requestProofObjectUrl) URL.revokeObjectURL(requestProofObjectUrl);
    requestProofObjectUrl = null;
    const preview = document.getElementById('request-proof-preview');
    const thumbnail = document.getElementById('request-proof-thumbnail');
    if (preview) { preview.hidden = true; preview.classList.remove('has-thumbnail'); }
    if (thumbnail) { thumbnail.hidden = true; thumbnail.removeAttribute('src'); }
    const name = document.getElementById('request-proof-name');
    const size = document.getElementById('request-proof-size');
    if (name) name.textContent = '';
    if (size) size.textContent = '';
}

function removeRequestProof() {
    const input = document.getElementById('request-proof');
    if (input) input.value = '';
    clearRequestProofPreview();
    setRequestFieldError('request-proof-picker', 'request-proof-error', '');
}

function handleRequestProofSelection(event) {
    const input = event.target;
    const file = input.files?.[0];
    if (!file) return;
    const extension = file.name.split('.').pop()?.toLowerCase() || '';
    const acceptedExtensions = new Set(['pdf', 'jpg', 'jpeg', 'png']);
    const acceptedMimes = new Set(['application/pdf', 'image/jpeg', 'image/png']);
    let error = '';
    if (!file.size) {
        error = 'That file is empty. Please choose a different file.';
    } else if (file.size > 5 * 1024 * 1024) {
        error = 'File is too large. Maximum is 5 MB.';
    } else if (!acceptedExtensions.has(extension) || (file.type && !acceptedMimes.has(file.type))) {
        error = 'File type not supported. Use PDF, JPG, or PNG.';
    }
    if (error) {
        input.value = '';
        clearRequestProofPreview();
        setRequestFieldError('request-proof-picker', 'request-proof-error', error);
        return;
    }

    setRequestFieldError('request-proof-picker', 'request-proof-error', '');
    clearRequestProofPreview();
    const preview = document.getElementById('request-proof-preview');
    const thumbnail = document.getElementById('request-proof-thumbnail');
    const name = document.getElementById('request-proof-name');
    const size = document.getElementById('request-proof-size');
    if (name) name.textContent = file.name;
    if (size) size.textContent = formatRequestFileSize(file.size);
    const isImageFile = file.type.startsWith('image/') || ['jpg', 'jpeg', 'png'].includes(extension);
    if (thumbnail && isImageFile) {
        requestProofObjectUrl = URL.createObjectURL(file);
        thumbnail.src = requestProofObjectUrl;
        thumbnail.hidden = false;
        preview?.classList.add('has-thumbnail');
    }
    if (preview) preview.hidden = false;
}

function updateRequestPurposeFields() {
    const purpose = document.getElementById('doc-purpose');
    const group = document.getElementById('request-purpose-other-group');
    const other = document.getElementById('doc-purpose-other');
    const useOther = purpose?.value === 'Other';
    if (group) group.hidden = !useOther;
    if (other) { other.disabled = !useOther; other.required = Boolean(useOther); if (!useOther) other.value = ''; }
    if (!useOther) setRequestFieldError('doc-purpose-other', 'request-purpose-other-error', '');
}

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
    if (selected === 'new') {
        const form = document.getElementById('request-form');
        const confirmation = document.getElementById('request-submit-confirmation');
        if (form?.dataset.submitted === 'true') {
            form.hidden = false;
            form.dataset.submitted = 'false';
            if (confirmation) confirmation.hidden = true;
        }
    }
}

function selectResidentRequestStatusTab(filter) {
    residentRequestStatusFilter = filter === 'completed' ? 'completed' : 'active';
    document.querySelectorAll('.request-filter-tabs [role="tab"]').forEach(button => {
        const active = button.id === `request-filter-${residentRequestStatusFilter}`;
        button.classList.toggle('active', active);
        button.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    renderResidentRequestList(residentRequestItems);
}

function goToResidentRequestDashboard() {
    showSection('resident-dashboard');
    selectResidentRequestTab('list');
    selectResidentRequestStatusTab('active');
}

async function handleRequest(e) {
    e.preventDefault();
    if (requestSubmitting) return;
    const form = document.getElementById('request-form');
    const button = document.getElementById('request-submit');
    const confirmation = document.getElementById('request-submit-confirmation');
    const submitError = document.getElementById('request-submit-error');
    const documentType = document.getElementById('doc-type');
    const purpose = document.getElementById('doc-purpose');
    const otherPurpose = document.getElementById('doc-purpose-other');
    const proofType = document.getElementById('request-proof-type');
    const proof = document.getElementById('request-proof');
    if (!form || !button || !confirmation || !documentType || !purpose || !proofType || !proof) return;

    confirmation.hidden = true;
    if (submitError) { submitError.hidden = true; submitError.textContent = ''; }
    const errors = [
        setRequestFieldError('doc-type', 'request-doc-type-error', documentType.value ? '' : 'Choose a document type.'),
        setRequestFieldError('doc-purpose', 'request-purpose-error', purpose.value ? '' : 'Choose a purpose.'),
        setRequestFieldError('doc-purpose-other', 'request-purpose-other-error', purpose.value === 'Other' && !otherPurpose?.value.trim() ? 'Enter your purpose.' : ''),
        setRequestFieldError('request-proof-type', 'request-proof-type-error', proofType.value ? '' : 'Choose a proof type.')
    ];

    const selectedProof = proof.files?.[0];
    if (!selectedProof) {
        const existingProofError = document.getElementById('request-proof-error')?.textContent || '';
        errors.push(setRequestFieldError('request-proof-picker', 'request-proof-error', existingProofError || 'Choose a proof of residency file.'));
    } else {
        const fileError = !selectedProof.size
            ? 'That file is empty. Please choose a different file.'
            : selectedProof.size > 5 * 1024 * 1024
            ? `That file is ${formatRequestFileSize(selectedProof.size)}. Please upload a file under 5 MB.`
            : !new Set(['pdf', 'jpg', 'jpeg', 'png']).has(selectedProof.name.split('.').pop()?.toLowerCase() || '')
                || (selectedProof.type && !new Set(['application/pdf', 'image/jpeg', 'image/png']).has(selectedProof.type))
                ? 'That file type is not supported. Please upload a PDF, JPG, or PNG.'
                : '';
        errors.push(setRequestFieldError('request-proof-picker', 'request-proof-error', fileError));
    }
    if (errors.some(Boolean)) {
        const firstInvalid = [
            !documentType.value && documentType,
            !purpose.value && purpose,
            purpose.value === 'Other' && !otherPurpose?.value.trim() && otherPurpose,
            !proofType.value && proofType,
            !selectedProof && document.getElementById('request-proof-picker'),
            selectedProof && document.getElementById('request-proof-error')?.textContent && document.getElementById('request-proof-picker')
        ].find(Boolean);
        firstInvalid?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        firstInvalid?.focus({ preventScroll: true });
        return;
    }

    if (currentUser?.emailVerified !== true) {
        const verificationNote = document.getElementById('request-verification-note');
        if (verificationNote) {
            verificationNote.textContent = 'Verify your email to submit a request.';
            verificationNote.hidden = false;
            verificationNote.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        return;
    }

    requestSubmitting = true;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.textContent = 'Submitting…';
    try {
        const payload = new FormData(form);
        payload.set('purpose', purpose.value === 'Other' ? otherPurpose.value.trim() : purpose.value);
        const res = await fetch('api/requests.php', { method: 'POST', body: payload });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.message || 'Unable to submit your request. Please try again.');

        const reference = data.trackingCode || data.referenceNumber || data.reference || '';
        clearNode(confirmation);
        confirmation.append(el('strong', { text: 'Request submitted' }));
        if (reference) confirmation.appendChild(el('p', { className: 'request-success-reference', text: `Reference number: ${reference}` }));
        confirmation.appendChild(el('button', {
            className: 'btn-solid request-success-dashboard', text: 'Go to dashboard', attrs: { type: 'button' },
            on: { click: goToResidentRequestDashboard }
        }));
        form.reset();
        form.hidden = true;
        form.dataset.submitted = 'true';
        clearRequestProofPreview();
        setRequestFieldError('request-proof-picker', 'request-proof-error', '');
        updateRequestPurposeFields();
        updateDocumentTypeInfo();
        confirmation.hidden = false;
        loadResidentData().catch(() => {});
    } catch (error) {
        const message = error.message || 'Unable to submit your request. Please try again.';
        if (/verify your email/i.test(message)) {
            const verificationNote = document.getElementById('request-verification-note');
            if (verificationNote) { verificationNote.textContent = message; verificationNote.hidden = false; }
        } else if (submitError) {
            submitError.textContent = message;
            submitError.hidden = false;
        }
    } finally {
        requestSubmitting = false;
        button.disabled = false;
        button.removeAttribute('aria-busy');
        button.textContent = 'Submit request';
    }
}

async function resendResidentVerification() {
    try {
        const response = await fetch('api/resend_verification.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Could not send the verification email.');
        showToast(result.message, 'success');
        if (result.alreadyVerified) {
            currentUser.emailVerified = true;
            updateNavBar();
            renderResidentProfile();
        }
    } catch (error) {
        setResidentEmailFeedback(error.message || 'Could not send the verification email.', 'error');
        showToast(error.message || 'Could not send the verification email.', 'error');
    }
}

function setResidentEmailFeedback(message, type = '') {
    const feedback = document.getElementById('resident-email-feedback');
    if (!feedback) return;
    feedback.textContent = message;
    feedback.classList.toggle('is-error', type === 'error');
    feedback.classList.toggle('is-success', type === 'success');
}

function renderResidentProfile() {
    if (!['Resident', 'Admin'].includes(currentUser?.role)) return;
    const phone = document.getElementById('resident-profile-phone');
    const phoneRow = document.getElementById('resident-profile-phone-row');
    const accountTypeRow = document.getElementById('admin-profile-account-type-row');
    const correctionNote = document.getElementById('resident-profile-correction-note');
    const summaryStatus = document.getElementById('resident-profile-email-summary-status');
    const resend = document.getElementById('resident-profile-resend');
    const changeEmail = document.querySelector('#resident-profile .resident-security-row[aria-labelledby="resident-email-summary-label"] .resident-security-change');
    const changePassword = document.querySelector('#resident-profile .resident-security-row[aria-labelledby="resident-password-summary-label"] .resident-security-change');
    const headerName = document.getElementById('resident-profile-header-name');
    const headerEmail = document.getElementById('resident-profile-header-email');
    const accountLabel = document.getElementById('resident-profile-account-label');
    const profileAvatar = document.getElementById('resident-profile-avatar');
    const banner = document.getElementById('email-verification-banner');
    const isAdmin = currentUser.role === 'Admin';
    if (accountLabel) accountLabel.textContent = isAdmin ? 'Administrator account' : 'Barangay San Jose portal account';
    const verified = isAdmin || currentUser.emailVerified === true;
    const fullName = currentUser.name || '—';
    if (headerName) headerName.textContent = fullName;
    if (headerEmail) headerEmail.textContent = currentUser.email || '—';
    const photoInput = document.getElementById('resident-profile-photo-input');
    if (photoInput && !photoInput.dataset.profilePhotoBound) {
        photoInput.addEventListener('change', () => prepareProfilePhoto(photoInput.files?.[0]));
        photoInput.dataset.profilePhotoBound = 'true';
    }
    if (profileAvatar) {
        const nameParts = String(fullName).split(/\s+/).filter(Boolean);
        const initials = nameParts.length > 1 ? `${nameParts[0][0]}${nameParts[nameParts.length - 1][0]}`.toUpperCase() : String(fullName).slice(0, 2).toUpperCase();
        const initialsNode = document.getElementById('resident-profile-initials');
        if (initialsNode) initialsNode.textContent = initials;
    }
    if (phone) phone.textContent = formatProfilePhone(currentUser.phoneNumber) || '—';
    if (phoneRow) phoneRow.hidden = isAdmin;
    if (accountTypeRow) accountTypeRow.hidden = !isAdmin;
    if (correctionNote) correctionNote.hidden = isAdmin;
    if (changeEmail) changeEmail.hidden = false;
    if (changePassword) changePassword.hidden = false;
    document.querySelectorAll('#resident-profile .admin-profile-only').forEach(node => { node.hidden = !isAdmin; });
    const residentQuickLinks = document.querySelector('#resident-profile > .page-shell > .resident-profile-quick-links:not(.admin-profile-only)');
    if (residentQuickLinks) residentQuickLinks.hidden = isAdmin;
    if (summaryStatus) {
        const badge = summaryStatus;
        badge.textContent = verified ? 'Verified' : 'Unverified';
        badge.classList.toggle('is-verified', verified);
        badge.classList.toggle('is-unverified', !verified);
    }
    if (resend) resend.classList.toggle('hidden', verified);
    if (banner) banner.hidden = verified;
    loadEnhancedProfile();
    const emailHint = document.getElementById('resident-new-email-hint');
    const emailSubmit = document.getElementById('resident-email-submit');
    if (emailHint) emailHint.textContent = isAdmin ? 'Your administrator email changes as soon as you confirm your password.' : 'We’ll send a verification link to your new email. Your current email stays active until you verify the new one.';
    if (emailSubmit) emailSubmit.textContent = isAdmin ? 'Update email' : 'Send verification link';
    if (isAdmin) return;
    const requestSubmit = document.getElementById('request-submit');
    const requestNote = document.getElementById('request-verification-note');
    if (requestSubmit && !requestSubmitting) requestSubmit.disabled = false;
    if (requestNote) {
        requestNote.hidden = verified;
        if (!verified) requestNote.textContent = 'Verify your email to submit a request.';
    }
}

let profilePhotoPreviewBlob = null;
let profilePhotoPreviewUrl = '';

let adminProfileDetailsSavedTimer = null;

async function loadEnhancedProfile() {
    if (!['Resident', 'Admin'].includes(currentUser?.role)) return;
    try {
        const response = await fetch('api/profile.php', { cache: 'no-store' });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'Could not load profile details.');
        const image = document.getElementById('resident-profile-photo-image');
        const initials = document.getElementById('resident-profile-initials');
        const remove = document.getElementById('resident-profile-photo-remove');
        if (data.photo && image) {
            image.src = `api/profile_photo.php?v=${Date.now()}`;
            image.hidden = false;
            if (initials) initials.hidden = true;
            if (remove) remove.hidden = false;
            currentUser.profilePhoto = data.photo;
        } else {
            if (image) { image.hidden = true; image.removeAttribute('src'); }
            if (initials) initials.hidden = false;
            if (remove) remove.hidden = true;
            currentUser.profilePhoto = null;
        }
        if (currentUser.role === 'Admin') {
            adminSidebarPhotoLookupUserId = String(currentUser.id || '');
            renderAdminSidebarAvatar(currentUser.profilePhoto);
        }
        const changed = data.passwordChangedAt ? new Date(`${String(data.passwordChangedAt).replace(' ', 'T')}Z`) : null;
        const changedLabel = changed && !Number.isNaN(changed.getTime()) ? `Last changed: ${changed.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })}` : 'Last changed: Not available';
        document.querySelectorAll('.resident-password-last-changed').forEach(node => { node.textContent = changedLabel; node.hidden = false; });
        if (currentUser.role !== 'Admin') return;
        const position = document.getElementById('admin-profile-position');
        const contact = document.getElementById('admin-profile-contact');
        if (position) { position.value = data.position || ''; position.dataset.savedValue = position.value; }
        if (contact) { contact.value = data.contactNumber || ''; contact.dataset.savedValue = contact.value; }
        updateAdminProfileDetailsSaveState(false);
        const permissions = document.getElementById('admin-profile-permissions');
        if (permissions) permissions.replaceChildren(...(data.permissions || []).map(value => { const item = document.createElement('li'); item.textContent = value; return item; }));
        const mfaBadge = document.getElementById('admin-profile-mfa-enabled-badge');
        if (mfaBadge) mfaBadge.hidden = !data.mfaEnabled;
        document.getElementById('admin-profile-mfa-status').textContent = data.mfaEnabled ? `Enabled since ${formatProfileDate(data.mfaEnabledAt)}` : 'Not enabled.';
        document.getElementById('admin-profile-mfa-setup').hidden = Boolean(data.mfaEnabled);
        document.getElementById('admin-profile-mfa-reset').hidden = !data.mfaEnabled;
        renderAdminProfileSessions(data.sessions);
        renderAdminProfileEvents('admin-profile-signins', data.signIns, 'signin');
        renderAdminProfileEvents('admin-profile-recent-actions', data.recentActions, 'action');
        const requestsToggle = document.getElementById('admin-notify-requests');
        const paymentsToggle = document.getElementById('admin-notify-payments');
        if (requestsToggle) { requestsToggle.checked = Boolean(data.notifications?.newRequests); requestsToggle.dataset.savedValue = String(requestsToggle.checked); }
        if (paymentsToggle) { paymentsToggle.checked = Boolean(data.notifications?.paymentVerification); paymentsToggle.dataset.savedValue = String(paymentsToggle.checked); }
        updateAdminNotificationSaveState();
    } catch (error) { showToast(error.message || 'Could not load profile details.', 'error'); }
}

function formatProfileDate(value) {
    const date = new Date(`${String(value || '').replace(' ', 'T')}Z`);
    return Number.isNaN(date.getTime()) ? 'date unavailable' : new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' }).format(date);
}
function parseProfileDateTime(value) {
    const date = new Date(`${String(value || '').replace(' ', 'T')}Z`);
    return Number.isNaN(date.getTime()) ? null : date;
}
function formatProfileDateTime(value) {
    const date = parseProfileDateTime(value);
    if (!date) return 'Time unavailable';
    const day = new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' }).format(date);
    const time = new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit' }).format(date);
    return `${day} · ${time}`;
}
function formatProfileTime(value) {
    const date = parseProfileDateTime(value);
    return date ? new Intl.DateTimeFormat('en-US', { hour: 'numeric', minute: '2-digit' }).format(date) : 'Time unavailable';
}
function profileEventDayLabel(date) {
    if (!date) return 'Date unavailable';
    const startToday = new Date();
    startToday.setHours(0, 0, 0, 0);
    const startEvent = new Date(date);
    startEvent.setHours(0, 0, 0, 0);
    if (startEvent.getTime() === startToday.getTime()) return 'Today';
    const startYesterday = new Date(startToday);
    startYesterday.setDate(startYesterday.getDate() - 1);
    if (startEvent.getTime() === startYesterday.getTime()) return 'Yesterday';
    return new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', year: 'numeric' }).format(date);
}
function profileActionLabel(value) { return String(value || '').replaceAll('_', ' ').replace(/\b\w/g, letter => letter.toUpperCase()); }
function renderProfileList(id, rows, formatter) {
    const list = document.getElementById(id);
    if (!list) return;
    if (!Array.isArray(rows) || !rows.length) { const empty = document.createElement('li'); empty.textContent = 'No recent activity.'; list.replaceChildren(empty); return; }
    list.replaceChildren(...rows.map(row => { const item = document.createElement('li'); item.textContent = formatter(row); return item; }));
}

function profileDeviceName(userAgent) {
    const ua = String(userAgent || '');
    const browser = /Edg\//.test(ua) ? 'Edge' : /Firefox\//.test(ua) ? 'Firefox' : /(?:Chrome|CriOS)\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : /Opera|OPR\//.test(ua) ? 'Opera' : 'Browser';
    const os = /Windows/.test(ua) ? 'Windows' : /Android/.test(ua) ? 'Android' : /iPhone|iPad|iPod/.test(ua) ? 'iOS' : /Mac OS X|Macintosh/.test(ua) ? 'macOS' : /Linux/.test(ua) ? 'Linux' : 'device';
    return `${browser} on ${os}`;
}
function renderAdminProfileSessions(rows) {
    const list = document.getElementById('admin-profile-sessions');
    if (!list) return;
    const allSessions = Array.isArray(rows) ? rows : [];
    const currentSession = allSessions.find(session => session.current);
    const sessions = (currentSession ? [currentSession, ...allSessions.filter(session => !session.current)] : allSessions).slice(0, 5);
    const revokeOthers = document.getElementById('admin-profile-revoke-sessions');
    if (revokeOthers) revokeOthers.hidden = !allSessions.some(session => !session.current);
    if (!sessions.length) { renderProfileList('admin-profile-sessions', [], () => ''); return; }
    list.replaceChildren(...sessions.map(session => {
        const item = document.createElement('li');
        item.className = `admin-profile-session-row${session.current ? ' is-current' : ''}`;
        const icon = document.createElement('span'); icon.className = 'admin-profile-session-icon';
        const glyph = document.createElement('i'); glyph.className = /Android|iOS/.test(profileDeviceName(session.device)) ? 'fas fa-mobile-screen-button' : 'fas fa-desktop'; glyph.setAttribute('aria-hidden', 'true'); icon.append(glyph);
        const copy = document.createElement('span'); copy.className = 'admin-profile-session-copy';
        const heading = document.createElement('span'); heading.className = 'admin-profile-session-heading';
        const name = document.createElement('strong'); name.textContent = profileDeviceName(session.device); heading.append(name);
        if (session.current) { const badge = document.createElement('span'); badge.className = 'admin-profile-enabled-badge'; badge.textContent = 'This device'; heading.append(badge); }
        const active = document.createElement('small'); active.textContent = session.current ? 'Active now' : `Last active ${formatProfileDateTime(session.lastActiveAt)}`;
        copy.append(heading, active);
        if (!session.current && session.createdAt) { const loggedIn = document.createElement('small'); loggedIn.textContent = `Logged in ${formatProfileDateTime(session.createdAt)}`; copy.append(loggedIn); }
        item.append(icon, copy); return item;
    }));
}
function profileEntityId(details, kind) {
    const value = String(details || '');
    const patterns = kind === 'request' ? [/Request\s+ID\s*#?\s*(\d+)/i, /request\s*#\s*(\d+)/i] : [/Resident(?:\s+account)?[^\n]*?\(ID\s*(\d+)/i, /Resident\s+ID\s*#?\s*(\d+)/i, /resident\s+account\s*#\s*(\d+)/i];
    for (const pattern of patterns) { const match = value.match(pattern); if (match) return Number(match[1]); }
    return null;
}
function adminProfileEventTitle(row, type) {
    const details = String(row.details || '');
    if (type === 'signin') {
        if (details.includes('enrolled authenticator MFA')) return 'Authenticator enrolled';
        if (row.action === 'admin_login_mfa') return 'Logged in with authenticator code';
        return 'Failed login attempt';
    }
    const requestId = profileEntityId(details, 'request');
    const residentId = profileEntityId(details, 'resident');
    if (row.action === 'request_viewed' && requestId) return `Viewed request #${requestId}`;
    if (row.action === 'request_approved' && requestId) return `Approved request #${requestId}`;
    if (row.action === 'request_completed' && requestId) return `Completed request #${requestId}`;
    if (row.action === 'request_payment_updated' && requestId) {
        const payment = details.match(/to\s+(Paid|Unpaid)/i)?.[1]?.toLowerCase() || 'updated';
        return payment === 'paid' ? `Marked request #${requestId} as paid` : payment === 'unpaid' ? `Marked request #${requestId} as unpaid` : `Updated payment for request #${requestId}`;
    }
    if ((row.action === 'resident_approved' || row.action === 'approve_resident') && residentId) return `Approved resident account #${residentId}`;
    if (row.action === 'resident_archived' && residentId) return `Archived resident account #${residentId}`;
    if (row.action === 'resident_restored' && residentId) return `Restored resident account #${residentId}`;
    if (row.action === 'resident_deleted' && residentId) return `Deleted resident account #${residentId}`;
    if (row.action === 'admin_mfa_profile_updated') return 'Authenticator enrolled';
    return profileActionLabel(row.action);
}
function renderAdminProfileEvents(id, rows, type) {
    const list = document.getElementById(id);
    if (!list) return;
    const events = Array.isArray(rows) ? rows.slice(0, 5) : [];
    if (!events.length) { renderProfileList(id, [], () => ''); return; }
    const rendered = [];
    let previousDayKey = null;
    events.forEach(row => {
        const eventDate = type === 'signin' ? parseProfileDateTime(row.created_at) : null;
        if (type === 'signin') {
            const dayKey = eventDate ? `${eventDate.getFullYear()}-${eventDate.getMonth()}-${eventDate.getDate()}` : 'unavailable';
            if (dayKey !== previousDayKey) {
                const heading = document.createElement('li');
                heading.className = 'admin-profile-event-day';
                heading.textContent = profileEventDayLabel(eventDate);
                rendered.push(heading);
                previousDayKey = dayKey;
            }
        }
        const item = document.createElement('li'); item.className = `admin-profile-event-row ${type === 'signin' && row.action !== 'admin_login_mfa' ? 'is-failed' : ''}`;
        const icon = document.createElement('span'); icon.className = 'admin-profile-event-icon';
        const glyph = document.createElement('i');
        if (type === 'signin') glyph.className = row.action === 'admin_login_mfa' ? 'fas fa-check' : 'fas fa-xmark';
        else glyph.className = row.action === 'request_viewed' ? 'fas fa-eye' : row.action === 'request_payment_updated' ? 'fas fa-peso-sign' : row.action === 'request_completed' ? 'fas fa-check-double' : row.action.includes('resident') ? 'fas fa-user-check' : 'fas fa-check';
        glyph.setAttribute('aria-hidden', 'true'); icon.append(glyph);
        const title = document.createElement('span'); title.className = 'admin-profile-event-title';
        const requestId = type === 'action' ? profileEntityId(row.details, 'request') : null;
        const residentId = type === 'action' && row.action === 'approve_resident' ? profileEntityId(row.details, 'resident') : null;
        const text = adminProfileEventTitle(row, type);
        if ((requestId || residentId) && /#\d+/.test(text)) {
            const match = text.match(/^(.*?)(#\d+)(.*)$/i);
            title.append(document.createTextNode(match[1]));
            const link = document.createElement('a'); link.href = requestId ? '#admin-requests' : '#admin-residents'; link.textContent = match[2];
            link.addEventListener('click', event => {
                event.preventDefault();
                if (requestId) void openAdminRequestDetail(requestId);
                else switchAdminTab('residents');
            });
            title.append(link, document.createTextNode(match[3]));
        } else title.textContent = text;
        const when = document.createElement('time'); when.className = 'admin-profile-event-time'; when.dateTime = String(row.created_at || ''); when.textContent = type === 'signin' ? formatProfileTime(row.created_at) : formatProfileDateTime(row.created_at);
        item.append(icon, title, when);
        rendered.push(item);
    });
    list.replaceChildren(...rendered);
}
function updateAdminNotificationSaveState() {
    const button = document.getElementById('admin-profile-notifications-save');
    const requests = document.getElementById('admin-notify-requests');
    const payments = document.getElementById('admin-notify-payments');
    if (!button || !requests || !payments) return;
    button.disabled = String(requests.checked) === requests.dataset.savedValue && String(payments.checked) === payments.dataset.savedValue;
}

function openProfilePhotoPicker() { document.getElementById('resident-profile-photo-input')?.click(); }
function profilePhotoError(message = '') { const node = document.getElementById('resident-profile-photo-error'); if (node) node.textContent = message; }
function clearProfilePhotoPreview() {
    if (profilePhotoPreviewUrl) URL.revokeObjectURL(profilePhotoPreviewUrl);
    profilePhotoPreviewBlob = null; profilePhotoPreviewUrl = '';
    document.getElementById('resident-profile-photo-preview-actions').hidden = true;
    document.getElementById('resident-profile-photo-input').value = '';
    loadEnhancedProfile();
}
function cancelProfilePhotoPreview() { clearProfilePhotoPreview(); profilePhotoError(); }
async function prepareProfilePhoto(file) {
    profilePhotoError();
    if (!file) return;
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) { profilePhotoError('Choose a JPG, PNG, or WebP image.'); return; }
    if (file.size > 2 * 1024 * 1024) { profilePhotoError('Choose an image under 2 MB.'); return; }
    try {
        const bitmap = await createImageBitmap(file);
        const side = Math.min(bitmap.width, bitmap.height);
        const canvas = document.createElement('canvas'); canvas.width = 256; canvas.height = 256;
        canvas.getContext('2d').drawImage(bitmap, (bitmap.width-side)/2, (bitmap.height-side)/2, side, side, 0, 0, 256, 256);
        bitmap.close?.();
        profilePhotoPreviewBlob = await new Promise(resolve => canvas.toBlob(resolve, 'image/webp', .88));
        if (!profilePhotoPreviewBlob) throw new Error('Could not prepare that photo.');
        profilePhotoPreviewUrl = URL.createObjectURL(profilePhotoPreviewBlob);
        const image = document.getElementById('resident-profile-photo-image'); image.src = profilePhotoPreviewUrl; image.hidden = false;
        document.getElementById('resident-profile-initials').hidden = true;
        document.getElementById('resident-profile-photo-preview-actions').hidden = false;
    } catch (error) { profilePhotoError(error.message || 'Choose a valid image file.'); }
}
async function saveProfilePhoto() {
    if (!profilePhotoPreviewBlob) return;
    const form = new FormData(); form.set('action', 'upload'); form.set('photo', profilePhotoPreviewBlob, 'profile.webp');
    try {
        const response = await fetch('api/profile_photo.php', { method: 'POST', body: form }); const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Could not save that photo.');
        cancelProfilePhotoPreview(); showToast('Profile photo updated.', 'success');
    } catch (error) { profilePhotoError(error.message || 'Could not save that photo.'); }
}
async function removeProfilePhoto() {
    if (!currentUser?.profilePhoto || !window.confirm('Remove your profile photo?')) return;
    const body = new FormData(); body.set('action', 'remove');
    try { const response = await fetch('api/profile_photo.php', { method: 'POST', body }); const result = await response.json(); if (!response.ok || !result.success) throw new Error(result.message || 'Could not remove the photo.'); await loadEnhancedProfile(); showToast('Profile photo removed.', 'success'); }
    catch (error) { profilePhotoError(error.message || 'Could not remove the photo.'); }
}

async function postProfileAction(action, extra = {}) {
    const response = await fetch('api/profile.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action, ...extra }) });
    const result = await response.json(); if (!response.ok || !result.success) throw new Error(result.message || 'Could not save profile settings.'); return result;
}
function updateAdminProfileDetailsSaveState(hideFeedback = true) {
    const position = document.getElementById('admin-profile-position');
    const contact = document.getElementById('admin-profile-contact');
    const save = document.getElementById('admin-profile-details-save');
    const feedback = document.getElementById('admin-profile-details-saved');
    if (!position || !contact || !save) return;
    const unchanged = position.value === (position.dataset.savedValue || '') && contact.value === (contact.dataset.savedValue || '');
    save.disabled = save.dataset.saving === 'true' || unchanged;
    if (hideFeedback && feedback) {
        feedback.hidden = true;
        if (adminProfileDetailsSavedTimer) window.clearTimeout(adminProfileDetailsSavedTimer);
        adminProfileDetailsSavedTimer = null;
    }
}

async function saveAdminProfileDetails(event) {
    event.preventDefault();
    const position = document.getElementById('admin-profile-position');
    const contact = document.getElementById('admin-profile-contact');
    const save = document.getElementById('admin-profile-details-save');
    const feedback = document.getElementById('admin-profile-details-saved');
    if (!position || !contact || !save || save.disabled) return;
    const submittedPosition = position.value;
    const submittedContact = contact.value;
    save.dataset.saving = 'true';
    updateAdminProfileDetailsSaveState(false);
    try {
        const result = await postProfileAction('save_admin_details', { position: submittedPosition, phone: submittedContact });
        position.dataset.savedValue = submittedPosition;
        contact.dataset.savedValue = submittedContact;
        updateAdminProfileDetailsSaveState(false);
        if (feedback) {
            feedback.hidden = false;
            if (adminProfileDetailsSavedTimer) window.clearTimeout(adminProfileDetailsSavedTimer);
            adminProfileDetailsSavedTimer = window.setTimeout(() => { feedback.hidden = true; adminProfileDetailsSavedTimer = null; }, 3000);
        }
        showToast(result.message, 'success');
    } catch (error) {
        showToast(error.message, 'error');
    } finally {
        save.dataset.saving = 'false';
        updateAdminProfileDetailsSaveState(false);
    }
}
async function saveAdminNotificationPreferences(event) { event.preventDefault(); try { const requests = document.getElementById('admin-notify-requests'); const payments = document.getElementById('admin-notify-payments'); const result = await postProfileAction('save_notifications', { newRequests: requests.checked, paymentVerification: payments.checked }); requests.dataset.savedValue = String(requests.checked); payments.dataset.savedValue = String(payments.checked); updateAdminNotificationSaveState(); showToast(result.message, 'success'); } catch (error) { showToast(error.message, 'error'); } }
async function revokeOtherAdminSessions() { if (!window.confirm('Log out of all other signed-in devices?')) return; try { const result = await postProfileAction('sessions_revoke_others'); showToast(result.message, 'success'); await loadEnhancedProfile(); } catch (error) { showToast(error.message, 'error'); } }
async function beginAdminProfileMfa() { const password = window.prompt('Enter your current password to continue.'); if (password === null) return; try { const result = await postProfileAction('mfa_begin', { password }); document.getElementById('admin-profile-mfa-secret').textContent = result.secret; document.getElementById('admin-profile-mfa-secret-hint').textContent = result.replacing ? 'Add this new key to your authenticator app:' : 'Add this key to your authenticator app:'; document.getElementById('admin-profile-mfa-setup-box').hidden = false; document.getElementById('admin-profile-mfa-code').focus(); } catch (error) { showToast(error.message, 'error'); } }
function cancelAdminProfileMfa() { document.getElementById('admin-profile-mfa-setup-box').hidden = true; document.getElementById('admin-profile-mfa-confirm-form').reset(); }
async function confirmAdminProfileMfa(event) { event.preventDefault(); try { await postProfileAction('mfa_confirm', { code: document.getElementById('admin-profile-mfa-code').value.trim() }); cancelAdminProfileMfa(); showToast('Authenticator enabled.', 'success'); await loadEnhancedProfile(); } catch (error) { showToast(error.message, 'error'); } }

function formatProfilePhone(value) {
    const original = String(value || '').trim();
    let digits = original.replace(/\D/g, '');
    if (digits.startsWith('63') && digits.length === 12) digits = `0${digits.slice(2)}`;
    if (/^09\d{9}$/.test(digits)) return `${digits.slice(0, 4)} ${digits.slice(4, 7)} ${digits.slice(7)}`;
    return original;
}

function updateResidentEmailSubmit() {
    const emailField = document.getElementById('resident-new-email');
    const passwordField = document.getElementById('resident-email-password');
    const submit = document.getElementById('resident-email-submit');
    if (!emailField || !passwordField || !submit) return;
    const emailValid = setEmailValidation(emailField);
    const value = emailField.value.trim().toLowerCase();
    const sameEmail = value === String(currentUser?.email || '').toLowerCase();
    const emailError = document.getElementById('resident-new-email-error');
    const exists = emailField.dataset.emailExists === value;
    if (emailError && emailValid && value && (sameEmail || exists)) {
        emailError.textContent = sameEmail ? 'Enter an email address different from your current one.' : 'This email already exists.';
        emailField.setAttribute('aria-invalid', 'true');
        emailField.setCustomValidity(emailError.textContent);
    }
    else if (emailValid) emailField.setCustomValidity('');
    submit.disabled = !emailValid || !value || sameEmail || exists || !passwordField.value;
}

async function changeResidentEmail(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const emailField = document.getElementById('resident-new-email');
    const passwordField = document.getElementById('resident-email-password');
    const emailError = document.getElementById('resident-new-email-error');
    const passwordError = document.getElementById('resident-email-password-error');
    const feedback = document.getElementById('resident-email-feedback');
    if (emailError) emailError.textContent = '';
    if (passwordError) passwordError.textContent = '';
    if (feedback) { feedback.textContent = ''; feedback.classList.remove('is-error', 'is-success'); }
    updateResidentEmailSubmit();
    const emailValid = setEmailValidation(emailField);
    const emailValue = emailField.value.trim().toLowerCase();
    const sameEmail = emailValue === String(currentUser?.email || '').toLowerCase();
    const emailExists = emailField.dataset.emailExists === emailValue;
    if (!emailValue) {
        if (emailError) emailError.textContent = 'Enter your new email address.';
        emailField.setAttribute('aria-invalid', 'true');
    } else if (!emailValid) {
        if (emailError) emailError.textContent = emailValidationMessage(emailValue);
        emailField.setAttribute('aria-invalid', 'true');
    } else if (sameEmail) {
        if (emailError) emailError.textContent = 'Enter an email address different from your current one.';
        emailField.setAttribute('aria-invalid', 'true');
    } else if (emailExists) {
        if (emailError) emailError.textContent = 'This email already exists.';
        emailField.setAttribute('aria-invalid', 'true');
    }
    if (!passwordField.value && passwordError) passwordError.textContent = 'Enter your password.';
    if (!emailValid || !emailField.checkValidity() || !passwordField.value || sameEmail || emailExists) {
        updateResidentEmailSubmit();
        if (!passwordField.value) passwordField.focus(); else emailField.focus();
        return;
    }
    const submit = document.getElementById('resident-email-submit');
    submit.disabled = true;
    submit.setAttribute('aria-busy', 'true');
    submit.dataset.idleLabel = submit.textContent;
    submit.textContent = 'Sending…';
    try {
        const response = await fetch('api/change_resident_email.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.fromEntries(new FormData(form))) });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Could not change the email address.');
        currentUser.email = result.email;
        currentUser.emailVerified = result.emailVerified === true;
        renderResidentProfile();
        updateNavBar();
        form.reset();
        showToast(result.message, result.emailVerificationSent ? 'success' : 'error');
        showSection('resident-profile');
    } catch (error) {
        const message = error.message || 'Could not change the email address.';
        if (/password/i.test(message)) {
            if (passwordError) passwordError.textContent = 'That password is incorrect.';
            passwordField.setAttribute('aria-invalid', 'true');
            passwordField.focus();
        } else if (/email|provider|already in use|valid/i.test(message)) {
            const inlineMessage = /already in use|already exists/i.test(message) ? 'This email already exists.' : message;
            if (/already in use|already exists/i.test(message)) emailField.dataset.emailExists = emailValue;
            if (emailError) emailError.textContent = inlineMessage;
            emailField.setAttribute('aria-invalid', 'true');
            emailField.setCustomValidity(inlineMessage);
            emailField.focus();
        } else {
            setResidentEmailFeedback(message, 'error');
            showToast(message, 'error');
        }
    } finally {
        submit.removeAttribute('aria-busy');
        submit.textContent = submit.dataset.idleLabel || 'Send verification link';
        delete submit.dataset.idleLabel;
        updateResidentEmailSubmit();
    }
}

function bindResidentPasswordPage() {
    const passwordField = document.getElementById('resident-new-password');
    const confirmField = document.getElementById('resident-confirm-new-password');
    if (!passwordField || !confirmField) return;
    bindPasswordChecklist(passwordField, confirmField, 'resident-change-password');
    [passwordField, confirmField].forEach(field => {
        if (field.dataset.changePasswordBound) return;
        field.addEventListener('input', () => {
            const error = document.getElementById('resident-password-error');
            if (error) { error.textContent = ''; error.classList.add('hidden'); }
            updateResidentPasswordSubmit();
        });
        field.dataset.changePasswordBound = 'true';
    });
    updateResidentPasswordSubmit();
}

function updateResidentPasswordSubmit() {
    const password = document.getElementById('resident-new-password')?.value || '';
    const confirmation = document.getElementById('resident-confirm-new-password')?.value || '';
    const submit = document.getElementById('resident-password-submit');
    if (submit) submit.disabled = !passwordMeetsClientPolicy(password) || password !== confirmation;
}

async function changeResidentPassword(event) {
    event.preventDefault();
    const passwordField = document.getElementById('resident-new-password');
    const confirmField = document.getElementById('resident-confirm-new-password');
    const error = document.getElementById('resident-password-error');
    const submit = document.getElementById('resident-password-submit');
    if (error) { error.textContent = ''; error.classList.add('hidden'); }
    updatePasswordChecklist('resident-change-password');
    if (!passwordMeetsClientPolicy(passwordField.value)) {
        if (error) { error.textContent = 'Use at least 8 characters with uppercase and lowercase letters and a number.'; error.classList.remove('hidden'); }
        passwordField.focus();
        return;
    }
    if (passwordField.value !== confirmField.value) {
        if (error) { error.textContent = 'The passwords do not match.'; error.classList.remove('hidden'); }
        confirmField.focus();
        return;
    }
    submit.disabled = true;
    submit.setAttribute('aria-busy', 'true');
    submit.dataset.idleLabel = submit.textContent;
    submit.textContent = 'Updating…';
    try {
        const response = await fetch('api/change_resident_password.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password: passwordField.value, confirmation: confirmField.value })
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Could not update your password.');
        document.getElementById('resident-password-form')?.reset();
        updatePasswordChecklist('resident-change-password');
        showToast(result.message || 'Your password has been updated.', 'success');
        showSection('resident-profile');
    } catch (changeError) {
        if (error) { error.textContent = changeError.message || 'Could not update your password. Please try again.'; error.classList.remove('hidden'); }
    } finally {
        submit.removeAttribute('aria-busy');
        submit.textContent = submit.dataset.idleLabel || 'Save new password';
        delete submit.dataset.idleLabel;
        updateResidentPasswordSubmit();
    }
}

function openWalkInRequestPanel() {
    const panel = document.getElementById('walkin-request-panel');
    const overlay = document.getElementById('walkin-panel-overlay');
    if (!panel || !overlay) return;
    void loadDocTypes();
    if (overlay.parentElement !== document.body) document.body.append(overlay, panel);
    const siteHeader = document.getElementById('main-nav');
    const headerOffset = Math.max(0, siteHeader?.getBoundingClientRect().bottom || 0);
    const offsetValue = `${headerOffset}px`;
    panel.style.setProperty('--walkin-header-offset', offsetValue);
    overlay.style.setProperty('--walkin-header-offset', offsetValue);
    window.clearTimeout(walkInPanelCloseTimer);
    panel.classList.remove('is-closing');
    overlay.classList.remove('is-closing');
    walkInPanelReturnFocus = document.activeElement;
    panel.hidden = false;
    overlay.hidden = false;
    document.body.classList.add('walkin-panel-open');
    document.getElementById('walkin-name')?.focus();
}

function handleViewProfile() {
    if (currentUser?.role === 'Admin' || currentUser?.role === 'Resident') showSection('resident-profile');
}

function cancelWalkInRequest() {
    const panel = document.getElementById('walkin-request-panel');
    const overlay = document.getElementById('walkin-panel-overlay');
    if (!panel || !overlay || panel.hidden || panel.classList.contains('is-closing')) return;
    panel.classList.add('is-closing');
    overlay.classList.add('is-closing');
    const finishClose = () => {
        if (!panel.classList.contains('is-closing')) return;
        window.clearTimeout(walkInPanelCloseTimer);
        panel.hidden = true;
        overlay.hidden = true;
        panel.classList.remove('is-closing');
        overlay.classList.remove('is-closing');
        document.body.classList.remove('walkin-panel-open');
        walkInPanelReturnFocus?.focus?.();
        walkInPanelReturnFocus = null;
        walkInPanelCloseTimer = null;
    };
    panel.addEventListener('animationend', event => {
        if (event.target === panel) finishClose();
    }, { once: true });
    walkInPanelCloseTimer = window.setTimeout(finishClose, 300);
}

document.addEventListener('keydown', event => {
    const panel = document.getElementById('walkin-request-panel');
    if (!panel || panel.hidden) return;
    if (event.key === 'Escape') {
        event.preventDefault();
        cancelWalkInRequest();
        return;
    }
    if (event.key !== 'Tab') return;
    const focusable = Array.from(panel.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled])'))
        .filter(element => !element.hidden && element.getAttribute('type') !== 'hidden');
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
});

async function submitManualRequest(event) {
    event.preventDefault();
    const form = event.currentTarget;
    const phoneField = document.getElementById('walkin-phone');
    if (phoneField && !setPhoneValidation(phoneField)) { phoneField.reportValidity(); return; }
    const payload = Object.fromEntries(new FormData(form));
    try {
        const response = await fetch('api/manual_request.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Could not save the walk-in request.');
        form.reset();
        showToast(`Walk-in request saved. Tracking number: ${result.trackingCode}`, 'success');
        adminRequestPage = 1;
        loadAdminData().catch(() => showToast('The request was saved, but the queue could not refresh.', 'error'));
        invalidateAdminPanelCache('overview', 'activity');
    } catch (error) { showToast(error.message || 'Could not save the walk-in request.', 'error'); }
}

/* ============================================================
   RESIDENT: LOAD MY REQUESTS
   ============================================================ */
async function loadResidentData() {
    document.getElementById('resident-name').textContent = currentUser.name;
    const verifyBanner = document.getElementById('email-verification-banner');
    if (verifyBanner) verifyBanner.hidden = currentUser.emailVerified === true;
    renderResidentProfile();
    const res = await fetch('api/requests.php');
    const requests = await res.json();
    residentRequestItems = Array.isArray(requests) ? requests : [];
    renderResidentRequestList(residentRequestItems);
}

function renderResidentRequestList(requests = residentRequestItems) {
    const list = document.getElementById('request-list');
    if (!list) return;
    clearNode(list);
    const sortedRequests = [...(Array.isArray(requests) ? requests : [])].sort((a, b) => {
        const timeA = new Date(a.created_at).getTime();
        const timeB = new Date(b.created_at).getTime();
        const normalizedA = Number.isNaN(timeA) ? 0 : timeA;
        const normalizedB = Number.isNaN(timeB) ? 0 : timeB;
        return normalizedB - normalizedA || Number(b.request_id || 0) - Number(a.request_id || 0);
    });
    const visibleRequests = sortedRequests.filter(request => {
        const terminal = ['Completed', 'Rejected'].includes(String(request.current_status || ''));
        return residentRequestStatusFilter === 'completed' ? terminal : !terminal;
    });

    if (!visibleRequests.length) {
        list.append(
            el('div', { className: 'request-empty-state' }, [
                el('p', { text: residentRequestStatusFilter === 'completed' ? 'No completed requests' : 'No active requests' })
            ])
        );
        return;
    }

    visibleRequests.forEach(r => {
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
        document.getElementById('overview-needs-action-caption').textContent = `${approvedWaiting} approved ${approvedWaiting === 1 ? 'request' : 'requests'}`;
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
        tbody.appendChild(el('tr', { className: 'table-empty-row' }, [el('td', { className: 'table-empty-state', text: 'No requests here yet.', attrs: { colspan: '8' } })]));
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
        const safeProofPath = typeof r.proof_url === 'string' && /^api\/request_proof\.php\?id=\d+$/.test(r.proof_url);
        const proofCell = el('td', { attrs: { 'data-label': 'Proof' } }, [safeProofPath
            ? el('button', {
                className: 'link-button request-proof-view',
                text: 'View file',
                attrs: { type: 'button', 'aria-haspopup': 'dialog', 'aria-label': `View submitted proof for request ${tracking}` },
                on: { click: () => openRequestProofPreview(r) }
            })
            : el('span', { className: 'unavailable-label', text: '—' })
        ]);
        const actionGroup = el('div', { className: 'table-action-group' }, [
            el('button', { className: 'link-button request-row-view', text: 'View', attrs: { type: 'button', 'aria-label': `View request ${tracking}` }, on: { click: () => openAdminRequestDetail(requestId) } })
        ]);
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
            el('td', { attrs: { 'data-label': 'Tracking' } }, [
                el('span', { className: 'request-tracking-cell' }, [
                    el('strong', { className: 'request-tracking-code', text: tracking }),
                    el('button', { className: 'request-tracking-copy', attrs: { type: 'button', 'aria-label': `Copy tracking number ${tracking}`, title: 'Copy tracking number' }, on: { click: () => copyRequestTracking(tracking) } }, [el('i', { className: 'fas fa-copy', attrs: { 'aria-hidden': 'true' } })])
                ])
            ]),
            el('td', { attrs: { 'data-label': 'Resident' } }, [
                el('span', { text: r.residentName || 'Resident' }),
                r.request_source === 'walk_in' ? el('small', { className: 'request-source-label', text: 'Walk-in / face-to-face' }) : null
            ]),
            el('td', { text: r.documentType, attrs: { 'data-label': 'Document' } }),
            el('td', { text: formatDate(r.created_at), attrs: { 'data-label': 'Requested', title: String(r.created_at ?? '') } }),
            el('td', { attrs: { 'data-label': 'Status' } }, [el('span', { className: statusClass(r.current_status), text: r.current_status })]),
            el('td', { attrs: { 'data-label': 'Payment' } }, [el('span', {
                className: `payment-status-chip ${fee === 0 ? 'payment-free' : `payment-${paymentStatus.toLowerCase()}`}`,
                text: fee === 0 ? 'No payment required' : paymentStatus
            })]),
            proofCell,
            actionsCell
        ]);
        tbody.appendChild(row);

        if (mobileList) {
            const openRequest = () => openAdminRequestDetail(requestId);
            const paymentPill = el('span', {
                className: `payment-status-chip ${fee === 0 ? 'payment-free' : `payment-${paymentStatus.toLowerCase()}`}`,
                text: fee === 0 ? 'No payment required' : paymentStatus
            });
            const mobileProofActions = el('div', { className: 'admin-request-mobile-actions' }, [
                safeProofPath ? el('button', {
                    className: 'admin-request-mobile-proof',
                    text: 'View file',
                    attrs: { type: 'button', 'aria-haspopup': 'dialog', 'aria-label': `View submitted proof for request ${tracking}` },
                    on: { click: () => openRequestProofPreview(r) }
                }) : null,
                el('button', { className: 'admin-request-mobile-view', text: 'View', attrs: { type: 'button', 'aria-label': `View request ${tracking}` }, on: { click: openRequest } })
            ]);
            const mobileTracking = el('div', { className: 'admin-request-mobile-tracking-wrap' }, [
                el('strong', { className: 'admin-request-mobile-tracking', text: tracking }),
                el('button', { className: 'request-tracking-copy', attrs: { type: 'button', 'aria-label': `Copy tracking number ${tracking}`, title: 'Copy tracking number' }, on: { click: () => copyRequestTracking(tracking) } }, [el('i', { className: 'fas fa-copy', attrs: { 'aria-hidden': 'true' } })])
            ]);
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
                    mobileTracking,
                    el('span', { className: statusClass(r.current_status), text: r.current_status })
                ]),
                el('div', { className: 'admin-request-mobile-resident' }, [
                    el('span', { text: r.residentName || 'Resident' }),
                    r.request_source === 'walk_in' ? el('small', { className: 'request-source-label', text: 'Walk-in / face-to-face' }) : null
                ]),
                el('div', { className: 'admin-request-mobile-document', text: r.documentType || 'Document' }),
                el('div', { className: 'admin-request-mobile-date', text: `Requested ${formatDate(r.created_at)}` }),
                el('div', { className: 'admin-request-mobile-bottom' }, [
                    paymentPill,
                    mobileProofActions
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
        const residentName = titleCaseName([request.first_name, request.middle_name, request.last_name].filter(Boolean).join(' ')) || request.walk_in_name || 'Resident';
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
            el('div', {}, [el('strong', { text: 'Submitted' }), el('p', { text: `${formatDateTime(request.created_at)} · ${request.request_source === 'walk_in' ? 'Recorded by staff in person' : 'Online resident submission'}` })])
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
            el('div', {}, [el('p', { className: 'eyebrow', text: request.request_source === 'walk_in' ? 'Walk-in / face-to-face request' : 'Online request' }), el('h2', { id: 'request-detail-title', text: request.document_type }), el('p', { text: `Requested ${formatDateTime(request.created_at)}` })]),
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
                request.request_source === 'online' && typeof request.proof_url === 'string'
                    ? el('p', {}, [el('button', { className: 'link-button request-proof-view', text: `View submitted proof${request.proof_type ? ` (${request.proof_type})` : ''}`, attrs: { type: 'button', 'aria-haspopup': 'dialog' }, on: { click: () => openRequestProofPreview(request) } })])
                    : request.request_source === 'walk_in' ? el('p', { className: 'field-help', text: 'Resident identity checked in person by staff.' }) : null,
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
   ADMIN: RESIDENT ACCOUNTS
   ============================================================ */
async function loadResidentAccounts() {
    const res = await fetch(`api/resident_accounts.php?page=${residentAccountPage}`);
    const data = await res.json();
    if (!res.ok || !data.success) throw new Error(data.message || 'Could not load resident accounts.');
    const residents = Array.isArray(data.residents) ? data.residents : [];
    residentAccountPage = Number(data.page) || 1;
    residentAccountPages = Number(data.pages) || 0;
    markAdminPanelLoaded('residents');

    const tbody = document.getElementById('admin-resident-list');
    const mobileList = document.getElementById('resident-accounts-mobile-list');
    clearNode(tbody);
    if (mobileList) clearNode(mobileList);
    const total = Number(data.total) || 0;
    const pageStatus = document.getElementById('resident-page-status');
    const previous = document.getElementById('resident-prev');
    const next = document.getElementById('resident-next');
    if (pageStatus) pageStatus.textContent = total ? `Page ${residentAccountPage} of ${residentAccountPages} · ${total} accounts` : 'No resident accounts';
    if (previous) previous.disabled = residentAccountPage <= 1 || residentAccountPages === 0;
    if (next) next.disabled = residentAccountPage >= residentAccountPages || residentAccountPages === 0;

    if (!residents.length) {
        if (tbody) tbody.appendChild(el('tr', { className: 'table-empty-row' }, [el('td', { className: 'table-empty-state', text: 'No resident accounts have been registered.', attrs: { colspan: '7' } })]));
        if (mobileList) mobileList.appendChild(el('p', { className: 'resident-mobile-empty', text: 'No resident accounts have been registered.' }));
        return;
    }

    residents.forEach(r => {
        const id = Number(r.resident_id);
        const name = titleCaseName(`${r.first_name ?? ''} ${r.middle_name ?? ''} ${r.last_name ?? ''}`);
        const archived = Boolean(r.archived_at);
        const status = el('span', { className: `resident-account-status${archived ? ' is-archived' : ' is-active'}`, text: archived ? 'Archived' : 'Active' });
        const actionButtons = (surface) => {
            return el('div', { className: 'table-action-group resident-account-actions' }, [
                el('button', {
                    className: 'action-btn btn-delete resident-account-archive-delete',
                    text: 'Archive & Delete',
                    attrs: { type: 'button', 'aria-label': `Archive and delete resident account ${name}` },
                    on: { click: () => confirmResidentAccountAction(id, 'archive_delete', name, archived) }
                })
            ]);
        };
        if (tbody) tbody.appendChild(el('tr', {}, [
            el('td', { attrs: { 'data-label': 'Name', title: name } }, [el('strong', { className: 'resident-admin-name', text: name || 'Name not provided' })]),
            el('td', { text: r.email || '—', attrs: { 'data-label': 'Email', title: r.email || '' } }),
            el('td', { text: r.phone_number || '—', attrs: { 'data-label': 'Phone' } }),
            el('td', { text: r.address || '—', attrs: { 'data-label': 'Address', title: r.address || '' } }),
            el('td', { text: formatDate(r.created_at), attrs: { 'data-label': 'Registered' } }),
            el('td', { attrs: { 'data-label': 'Status' } }, [status]),
            el('td', { attrs: { 'data-label': 'Actions' } }, [actionButtons('desktop')])
        ]));
        if (mobileList) mobileList.appendChild(el('article', { className: 'resident-mobile-card' }, [
            el('div', { className: 'resident-mobile-person' }, [
                el('strong', { className: 'resident-mobile-name', text: name || 'Name not provided' }),
                el('span', { className: 'resident-mobile-email', text: r.email || 'No email provided', attrs: { title: r.email || '' } })
            ]),
            el('p', { className: 'resident-mobile-registered', text: `Phone: ${r.phone_number || 'Not provided'}` }),
            el('p', { className: 'resident-mobile-registered', text: `Address: ${r.address || 'Not provided'}` }),
            el('div', { className: 'resident-mobile-status-row' }, [status.cloneNode(true), el('span', { className: 'resident-mobile-registered', text: `Registered ${formatDate(r.created_at)}` })]),
            actionButtons('mobile')
        ]));
    });
}

function changeResidentAccountPage(direction) {
    const nextPage = residentAccountPage + Number(direction || 0);
    if (nextPage < 1 || nextPage > residentAccountPages || nextPage === residentAccountPage) return;
    residentAccountPage = nextPage;
    loadResidentAccounts().catch(() => showToast('Could not load this resident page.', 'error'));
}

function confirmResidentAccountAction(residentId, action, residentName, archived = false) {
    const copy = action === 'archive_delete'
        ? `${archived ? 'Permanently delete' : 'Archive and permanently delete'} ${residentName}'s account? Related request records will be kept, but the account cannot be restored.`
        : action === 'delete'
        ? `Permanently delete ${residentName}'s account? Related request records will be kept, but the account cannot be restored.`
        : action === 'archive'
            ? `Archive ${residentName}'s account? They will no longer be able to sign in.`
            : `Restore ${residentName}'s account and allow them to sign in again?`;
    openModal(`${action === 'archive_delete' ? 'Archive & Delete' : action === 'delete' ? 'Delete' : action === 'archive' ? 'Archive' : 'Restore'} resident account`, copy, '', '', () => manageResidentAccount(residentId, action), 'text', {
        showInput: false,
        lockScroll: false,
        confirmLabel: action === 'archive_delete' ? 'Archive & Delete' : action === 'delete' ? 'Delete account' : action === 'archive' ? 'Archive account' : 'Restore account',
        confirmClass: ['delete', 'archive_delete'].includes(action) ? 'btn-outline btn-reject' : 'btn-solid'
    });
}

async function manageResidentAccount(residentId, action) {
    try {
        const res = await fetch('api/manage_resident_account.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ residentId, action })
        });
        const result = await res.json();
        if (!res.ok || !result.success) {
            showToast(result.message || 'The resident account could not be updated.', 'error');
            return false;
        }
        showToast(result.message || 'Resident account updated.', 'success');
        invalidateAdminPanelCache('overview', 'activity');
        await loadResidentAccounts();
        return true;
    } catch (error) {
        showToast('Could not reach the server. Check your connection and try again.', 'error');
        return false;
    }
}

function titleCaseName(name) {
    return String(name || '').trim().replace(/\s+/g, ' ').toLocaleLowerCase('en-PH').replace(/(^|[\s'-])([\p{L}])/gu, (_, boundary, letter) => boundary + letter.toLocaleUpperCase('en-PH'));
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
    if (currentUser?.role === 'Admin' && (document.body.classList.contains('admin-profile-view') || document.querySelector('.section.active')?.id !== 'admin-dashboard')) {
        showSection('admin-dashboard', false, tab);
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
        // This attribute only exists so the directly requested tab can paint
        // before session startup. Once the app has synchronized panel state,
        // remove it so its first-paint CSS cannot pin that tab during navigation.
        document.body.removeAttribute('data-initial-admin-section');
        currentAdminDetailId = null;
        const detailPanel = document.getElementById('admin-request-detail-panel');
        if (detailPanel) detailPanel.hidden = true;
        if (tab === 'activity' && adminPanelNeedsRefresh('activity')) loadAdminActivity(1);
        if (tab === 'overview' && adminPanelNeedsRefresh('overview')) loadAdminOverview();
        if (tab === 'requests' && adminPanelNeedsRefresh('requests')) loadAdminData();
        if (tab === 'residents' && adminPanelNeedsRefresh('residents')) loadResidentAccounts();
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
    if (!validateDisclosureForm()) return;
    const categoryId = document.getElementById('disc-category').value;
    const title = document.getElementById('disc-title').value.trim();
    const content = document.getElementById('disc-content').value.trim();
    const fileInput = document.getElementById('disc-file');
    if (!editingDisclosureId && !window.confirm('This will be visible to all residents. Publish this disclosure?')) return;

    const submitButton = document.getElementById('publish-disclosure-button');
    submitButton.disabled = true;
    submitButton.setAttribute('aria-busy', 'true');

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
        updateDisclosureComposerPreview();
        resizeDisclosureDescription();
        updateDisclosureComposerState();
        try { sessionStorage.removeItem('disclosure-draft'); } catch { /* Ignore unavailable browser storage. */ }
        if (wasEditing) {
            setDisclosureStatus('Disclosure updated successfully.');
            showToast('Disclosure updated successfully.', 'success');
        } else {
            setDisclosureStatus('Disclosure posted successfully.', `#bulletin?category=${encodeURIComponent(categoryId)}`);
            showToast('Disclosure posted successfully.', 'success');
        }
        updateDisclosureSavedSnapshot();
        invalidateAdminPanelCache('activity');
        await Promise.all([loadRecentDisclosures(), loadDisclosures(true), loadLatestAnnouncements()]);
    } catch (error) {
        setDisclosureStatus(error.message || 'Disclosure could not be saved.', null, true);
        showToast(error.message || 'Disclosure could not be saved.', 'error');
    } finally {
        submitButton.removeAttribute('aria-busy');
        updateDisclosureComposerState();
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
    resizeDisclosureDescription();
    updateDisclosureComposerPreview();
    updateDisclosureComposerState();
    updateDisclosureSavedSnapshot();
    [document.getElementById('disc-category'), title, description].forEach(field => {
        field.addEventListener('input', () => {
            updateDisclosureCharacterCount();
            updateDisclosureComposerPreview();
            resizeDisclosureDescription();
            updateDisclosureComposerState();
            if (field.value.trim()) clearDisclosureFieldError(field.id);
            clearDisclosureStatus();
        });
        field.addEventListener('change', () => {
            updateDisclosureCharacterCount();
            updateDisclosureComposerPreview();
            updateDisclosureComposerState();
            if (field.value.trim()) clearDisclosureFieldError(field.id);
            clearDisclosureStatus();
        });
        field.addEventListener('blur', () => validateDisclosureField(field));
    });
    document.getElementById('save-disclosure-draft').addEventListener('click', saveDisclosureDraft);
    document.getElementById('cancel-disclosure-composer').addEventListener('click', cancelDisclosureComposer);
    document.getElementById('cancel-disclosure-edit').addEventListener('click', () => cancelDisclosureEdit());
    document.querySelectorAll('[data-disclosure-mobile-tab]').forEach(button => {
        button.addEventListener('click', () => setDisclosureMobileTab(button.dataset.disclosureMobileTab));
    });

    input.addEventListener('change', () => {
        setDisclosureFile(input.files?.[0]);
        updateDisclosureComposerPreview();
        updateDisclosureComposerState();
        clearDisclosureStatus();
    });
    zone.addEventListener('click', event => {
        if (event.target.closest('.disclosure-browse')) return;
        input.click();
    });
    zone.addEventListener('keydown', event => {
        if (event.target !== zone || !['Enter', ' '].includes(event.key)) return;
        event.preventDefault();
        input.click();
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
        updateDisclosureComposerPreview();
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
    const updateCounter = (input, node, max) => {
        if (!input || !node) return;
        const length = input.value.length;
        node.textContent = `${length} / ${max}`;
        node.classList.toggle('is-warning', length >= Math.ceil(max * .9) && length < max);
        node.classList.toggle('is-limit', length >= max);
    };
    updateCounter(title, titleCounter, 120);
    updateCounter(field, counter, 2000);
}

function setDisclosureMobileTab(tab) {
    const layout = document.getElementById('disclosure-composer-layout');
    if (!layout || !['edit', 'preview'].includes(tab)) return;
    layout.dataset.mobileTab = tab;
    document.querySelectorAll('[data-disclosure-mobile-tab]').forEach(button => {
        const selected = button.dataset.disclosureMobileTab === tab;
        button.classList.toggle('active', selected);
        button.setAttribute('aria-selected', String(selected));
    });
}

function resizeDisclosureDescription() {
    const field = document.getElementById('disc-content');
    if (!field) return;
    field.style.height = 'auto';
    const height = Math.min(400, Math.max(220, field.scrollHeight));
    field.style.height = `${height}px`;
    field.style.overflowY = field.scrollHeight > 400 ? 'auto' : 'hidden';
}

function formatDisclosureFileSize(size) {
    return size < 1024 * 1024 ? `${Math.max(1, Math.round(size / 1024))} KB` : `${(size / (1024 * 1024)).toFixed(1)} MB`;
}

function updateDisclosureComposerPreview() {
    const category = document.getElementById('disc-category');
    const title = document.getElementById('disc-title');
    const description = document.getElementById('disc-content');
    const categoryNode = document.getElementById('disclosure-live-category');
    const titleNode = document.getElementById('disclosure-live-title');
    const descriptionNode = document.getElementById('disclosure-live-description');
    const attachmentNode = document.getElementById('disclosure-live-attachment');
    const dateNode = document.getElementById('disclosure-live-date');
    if (!category || !title || !description || !categoryNode || !titleNode || !descriptionNode || !attachmentNode) return;

    const categoryText = category.value ? category.selectedOptions[0]?.textContent.trim() : '';
    categoryNode.textContent = categoryText || 'Select a category';
    categoryNode.classList.toggle('is-placeholder', !categoryText);
    titleNode.textContent = title.value.trim() || 'Your title appears here';
    titleNode.classList.toggle('is-placeholder', !title.value.trim());
    descriptionNode.textContent = description.value.trim() || 'Your description appears here';
    descriptionNode.classList.toggle('is-placeholder', !description.value.trim());
    const file = document.getElementById('disc-file')?.files?.[0];
    const existingName = editingDisclosureAttachment?.name || '';
    const fileName = file?.name || existingName;
    attachmentNode.replaceChildren();
    attachmentNode.classList.toggle('is-placeholder', !fileName);
    const fileIcon = document.createElement('i');
    fileIcon.className = 'fas fa-file-lines';
    fileIcon.setAttribute('aria-hidden', 'true');
    const fileCopy = document.createElement('span');
    fileCopy.className = 'disclosure-live-attachment-name';
    fileCopy.textContent = fileName || 'No attachment selected';
    attachmentNode.append(fileIcon, fileCopy);
    if (file) {
        const fileSize = document.createElement('small');
        fileSize.textContent = formatDisclosureFileSize(file.size);
        attachmentNode.append(fileSize);
    } else if (existingName) {
        const fileSize = document.createElement('small');
        fileSize.textContent = 'Existing attachment';
        attachmentNode.append(fileSize);
    }
    if (dateNode) {
        const today = new Date();
        dateNode.dateTime = today.toISOString();
        dateNode.textContent = formatDate(today);
    }
}

function updateDisclosureComposerState() {
    const button = document.getElementById('publish-disclosure-button');
    if (!button) return;
    const category = document.getElementById('disc-category')?.value;
    const title = document.getElementById('disc-title')?.value.trim();
    const description = document.getElementById('disc-content')?.value.trim();
    button.disabled = !(category && title && description) || button.getAttribute('aria-busy') === 'true';
}

function validateDisclosureField(field) {
    if (!field) return;
    if (field.id === 'disc-category' && !field.value) setDisclosureFieldError(field.id, 'Category is required');
    else if (field.id === 'disc-title' && !field.value.trim()) setDisclosureFieldError(field.id, 'Title is required');
    else if (field.id === 'disc-content' && !field.value.trim()) setDisclosureFieldError(field.id, 'Description is required');
    else clearDisclosureFieldError(field.id);
}

function cancelDisclosureComposer() {
    if (!confirmLeavingDisclosureComposer()) return;
    if (window.history.length > 1) window.history.back();
    else switchAdminTab('overview');
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
            : 'File is too large. Maximum is 5 MB.';
        error.hidden = false;
        zone.classList.add('has-error');
        return;
    }
    clearDisclosureFile();
    const currentInput = document.getElementById('disc-file');
    const transfer = new DataTransfer();
    transfer.items.add(file);
    currentInput.files = transfer.files;
    document.getElementById('disc-file-name').textContent = file.name;
    document.getElementById('disc-file-size').textContent = formatDisclosureFileSize(file.size);
    const isImage = ['jpg', 'jpeg', 'png'].includes(extension);
    const thumbnail = document.getElementById('disc-file-thumbnail');
    document.getElementById('disc-file-icon').classList.toggle('hidden', isImage);
    thumbnail.classList.toggle('hidden', !isImage);
    if (isImage) {
        disclosureFileObjectUrl = URL.createObjectURL(file);
        thumbnail.src = disclosureFileObjectUrl;
    }
    document.getElementById('disc-file-error').hidden = true;
    zone.classList.remove('has-error');
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
        zone?.classList.remove('has-error');
    }
    updateDisclosureComposerPreview();
    updateDisclosureComposerState();
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
    if (!category.value) { setDisclosureFieldError('disc-category', 'Category is required'); invalid.push(category); }
    if (!title.value.trim()) { setDisclosureFieldError('disc-title', 'Title is required'); invalid.push(title); }
    else if (title.value.length > 120) { setDisclosureFieldError('disc-title', 'Title must be 120 characters or fewer.'); invalid.push(title); }
    if (!content.value.trim()) { setDisclosureFieldError('disc-content', 'Description is required'); invalid.push(content); }
    else if (content.value.length > 2000) { setDisclosureFieldError('disc-content', 'Description must be 2,000 characters or fewer.'); invalid.push(content); }
    if (invalid.length) { invalid[0].focus(); return false; }
    return true;
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
        const savedLabel = document.getElementById('disclosure-draft-saved');
        if (savedLabel) { savedLabel.textContent = `Draft saved · ${savedTime}`; savedLabel.hidden = false; }
        clearDisclosureStatus();
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
    resizeDisclosureDescription();
    updateDisclosureComposerPreview();
    updateDisclosureComposerState();
    document.getElementById('publish-disclosure-button').textContent = 'Save changes';
    document.getElementById('disclosure-edit-actions').classList.remove('hidden');
    clearDisclosureStatus();
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
    if (actions) actions.classList.add('hidden');
    document.getElementById('disclosure-form')?.reset();
    clearDisclosureFile();
    document.getElementById('disclosure-attachment-edit-note')?.classList.add('hidden');
    updateDisclosureCharacterCount();
    ['disc-category', 'disc-title', 'disc-content'].forEach(clearDisclosureFieldError);
    resizeDisclosureDescription();
    updateDisclosureComposerPreview();
    updateDisclosureComposerState();
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
    if (publicDisclosuresLoaded && !force && Date.now() - publicDisclosuresLoadedAt < PUBLIC_DISCLOSURES_CACHE_MS) return publicDisclosuresItems;
    if (publicDisclosuresLoadPromise) {
        if (!force) return publicDisclosuresLoadPromise;
        await publicDisclosuresLoadPromise;
    }
    const lists = {
        1: document.getElementById('list-1'),
        2: document.getElementById('list-2'),
        3: document.getElementById('list-3'),
        4: document.getElementById('list-4')
    };
    const load = async () => {
        publicDisclosuresLoadError = false;
        const currentQuery = document.getElementById('public-records-search-input')?.value || '';
        renderBulletinArchive(currentQuery);
        renderBulletinLatest(currentQuery);
        Object.values(lists).forEach(list => {
            if (!list) return;
            list.setAttribute('aria-busy', 'true');
            clearNode(list);
            list.appendChild(el('li', {}, [
                el('p', { className: 'bulletin-loading bulletin-loading-compact', attrs: { role: 'status' } }, [
                    el('span', { className: 'bulletin-loading-spinner', attrs: { 'aria-hidden': 'true' } }),
                    'Loading…'
                ])
            ]));
        });
        if (activeBulletinCategory) renderBulletinCategory(activeBulletinCategory);

        try {
            const res = await fetch('api/disclosures.php', { cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error('Public records could not be loaded.');
            const items = await res.json();
            if (!Array.isArray(items)) throw new Error('Public records returned an invalid response.');
            publicDisclosuresItems = items;

            Object.values(lists).forEach(list => {
                if (!list) return;
                const categoryId = Number(list.id.replace('list-', ''));
                list.removeAttribute('aria-busy');
                clearNode(list);
                list.appendChild(el('li', {}, [el('p', { className: 'empty-state', text: getBulletinEmptyMessage(categoryId) })]));
            });

            items.forEach(item => {
                const list = lists[item.category_id];
                if (!list) return;
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

                const safeFilePath = typeof item.file_path === 'string'
                    && /^api\/public_attachment\.php\?id=\d+$/.test(item.file_path)
                    ? item.file_path
                    : null;
                if (safeFilePath) {
                    const label = el('span', { className: 'attachment-label' }, [
                        el('i', { className: 'fas fa-paperclip', attrs: { 'aria-hidden': 'true' } }),
                        ' Attachment'
                    ]);
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
            publicDisclosuresLoadedAt = Date.now();
            publicDisclosuresLoadError = false;
            if (activeBulletinCategory) renderBulletinCategory(activeBulletinCategory);
            const searchQuery = document.getElementById('public-records-search-input')?.value || '';
            renderBulletinArchive(searchQuery);
            renderBulletinLatest(searchQuery);
            requestAnimationFrame(() => {
                document.querySelectorAll('#bulletin .bulletin-list li').forEach(item => {
                    const content = item.querySelector('.bulletin-item-content');
                    const link = item.querySelector('.bulletin-read-more');
                    if (!content || !link) return;
                    link.hidden = !window.matchMedia('(max-width: 767px)').matches || content.scrollHeight <= content.clientHeight + 1;
                });
            });
            return items;
        } catch (error) {
            publicDisclosuresLoaded = false;
            publicDisclosuresLoadError = true;
            const searchQuery = document.getElementById('public-records-search-input')?.value || '';
            renderBulletinArchive(searchQuery);
            renderBulletinLatest(searchQuery);
            Object.values(lists).forEach(list => {
                if (!list) return;
                list.setAttribute('aria-busy', 'false');
                clearNode(list);
                list.appendChild(el('li', {}, [
                    el('p', { className: 'empty-state', text: 'Unable to load records.' }),
                    el('button', {
                        className: 'bulletin-inline-retry',
                        text: 'Try again',
                        attrs: { type: 'button' },
                        on: { click: () => loadDisclosures(true) }
                    })
                ]));
            });
            if (activeBulletinCategory) renderBulletinCategory(activeBulletinCategory);
            return null;
        }
    };

    const request = load();
    publicDisclosuresLoadPromise = request;
    try {
        return await request;
    } finally {
        if (publicDisclosuresLoadPromise === request) publicDisclosuresLoadPromise = null;
    }
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
            container.appendChild(el('div', { className: 'empty-state', attrs: { role: 'status' } }, [
                el('strong', { text: 'No announcements yet' }),
                el('span', { text: 'New public updates will appear here once they are published.' })
            ]));
            setupMobileCarousel(container, document.getElementById('announcement-carousel-dots'));
            return;
        }

        latest.forEach(item => {
            const categoryName = String(item.category_name || 'Notice');
            const categoryClass = categoryName.toLocaleLowerCase().includes('advisory')
                ? 'announcement-category announcement-category-advisory'
                : 'announcement-category';
            const card = el('button', {
                className: 'announcement-card',
                attrs: { type: 'button' }
            }, [
                el('span', { className: categoryClass, text: categoryName }),
                el('strong', { className: 'announcement-title', text: item.title }),
                el('p', { className: 'announcement-excerpt', text: item.content }),
                el('time', {
                    className: 'announcement-date',
                    text: item.timestamp_posted ? formatDate(item.timestamp_posted) : '',
                    attrs: { datetime: String(item.timestamp_posted ?? '') }
                }),
                el('span', { className: 'announcement-read-more', text: 'Read more →' })
            ]);
            card.addEventListener('click', () => showSection('bulletin'));
            container.appendChild(card);
        });
        setupMobileCarousel(container, document.getElementById('announcement-carousel-dots'));
    } catch (error) {
        clearNode(container);
        container.setAttribute('aria-busy', 'false');
        container.appendChild(el('p', {
            className: 'empty-state load-error-state',
            attrs: { role: 'status' },
            text: 'Announcements could not be loaded. Please try again later.'
        }));
        setupMobileCarousel(container, document.getElementById('announcement-carousel-dots'));
    }
}

/* ============================================================
   INITIALIZATION
   ============================================================ */
document.addEventListener('DOMContentLoaded', async () => {
    setPortalLanguage(getPortalLanguagePreference());
    updateRequestPurposeFields();
    const publicRecordsSearchInput = document.getElementById('public-records-search-input');
    if (publicRecordsSearchInput) {
        updatePublicRecordsSearchPlaceholder();
        syncPublicRecordsSearchControls();
        publicRecordsSearchInput.addEventListener('input', syncPublicRecordsSearchControls);
        window.addEventListener('resize', updatePublicRecordsSearchPlaceholder, { passive: true });
    }
    const mainNav = document.getElementById('main-nav');
    if (mainNav) {
        updateStickyHeader();
        window.addEventListener('scroll', updateStickyHeader, { passive: true });
        window.addEventListener('resize', updateStickyHeader, { passive: true });
    }

    const updateUnavailableMobileAddress = () => {
        if (!window.matchMedia('(max-width: 600px)').matches) return;
        document.querySelectorAll('.unavailable-address-mobile').forEach(address => {
            const attemptedPath = address.textContent.trim() || `${location.pathname || '/'}${location.search}`;
            const pathWithFragment = attemptedPath.includes('#') ? attemptedPath : `${attemptedPath}${location.hash}`;
            address.textContent = pathWithFragment.replace(/([?&](?:token|secret|password|key)=)[^&#]*/gi, '$1[redacted]');
        });
    };
    updateUnavailableMobileAddress();

    const footerYear = document.getElementById('footer-year');
    if (footerYear) footerYear.textContent = String(new Date().getFullYear());
    initAdminTableScrollFades();
    setupMobileCarousel(document.querySelector('.doc-strip'), document.getElementById('doc-carousel-dots'));

    window.addEventListener('resize', () => {
        if (window.innerWidth > 720) closeMobileMenu();
    });

    const navMenu = document.getElementById('nav-menu');
    document.addEventListener('click', event => {
        if (!(event.target instanceof Element) || !event.target.closest('#user-info')) closeUserMenu();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !document.getElementById('user-account-menu')?.hidden) {
            closeUserMenu();
            document.getElementById('welcome-user')?.focus();
        }
    });
    navMenu.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            if (!document.getElementById('user-account-menu')?.hidden) closeUserMenu();
            closeMobileMenu();
            document.getElementById('nav-toggle').focus();
        }
    });
    navMenu.addEventListener('click', event => {
        if (navMenu.classList.contains('open') && event.target.closest('a, button') && !event.target.closest('.account-menu-toggle')) closeMobileMenu();
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
    ['year-1', 'year-2', 'year-admin', 'resident-change-email-year', 'resident-change-password-year'].forEach(id => {
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
                if (response.ok && result.success && currentUser?.role === 'Resident') {
                    currentUser.emailVerified = true;
                    updateNavBar();
                }
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
    let sessionData = null;
    if (sessionRes.ok) {
        sessionData = await sessionRes.json();
        if (sessionData.loggedIn) {
            currentUser = { id: sessionData.id, name: sessionData.name, role: sessionData.role, email: sessionData.email || '', phoneNumber: sessionData.phoneNumber || '', emailVerified: sessionData.role === 'Admin' ? true : sessionData.emailVerified === true };
            if (sessionData.role === 'Admin') startAdminSessionExpiry(sessionData.createdAt);
            updateNavBar();
            if (location.hash === '#admin-login') {
                history.replaceState({ section: 'admin-login' }, '', '#admin-login');
                showSection('admin-login', true);
                return;
            }
            // Admins can browse the public home and records in the same SPA.
            // Honor those index.php hashes on direct loads instead of always
            // forcing the dashboard route after a refresh.
            const [publicSection, publicQuery = ''] = location.hash.slice(1).split('?', 2);
            const canOpenProfile = publicSection === 'resident-profile' && ['Resident', 'Admin'].includes(sessionData.role);
            const canOpenResidentSecurityPage = ['resident-change-email', 'resident-change-password'].includes(publicSection) && ['Resident', 'Admin'].includes(sessionData.role);
            if (canOpenProfile || canOpenResidentSecurityPage || (!window.INITIAL_ADMIN_SECTION && ['landing', 'bulletin'].includes(publicSection))) {
                const requestedParams = new URLSearchParams(publicQuery);
                const routeParams = new URLSearchParams();
                const categoryParam = requestedParams.get('category');
                const categoryId = publicSection === 'bulletin' && /^[1-4]$/.test(categoryParam || '') ? Number(categoryParam) : null;
                const recordParam = requestedParams.get('record');
                const recordValue = Number(recordParam);
                const recordId = categoryId && /^[1-9]\d*$/.test(recordParam || '') && Number.isSafeInteger(recordValue) ? recordValue : null;
                if (categoryId) routeParams.set('category', String(categoryId));
                if (recordId) routeParams.set('record', String(recordId));
                const searchParam = publicSection === 'bulletin' ? requestedParams.get('search') : null;
                if (searchParam !== null) routeParams.set('search', searchParam.slice(0, 300));
                const portalFocus = publicSection === 'landing' ? requestedParams.get('portalFocus') : null;
                if (['services', 'contact-info'].includes(portalFocus)) routeParams.set('portalFocus', portalFocus);
                const publicHash = `#${publicSection}${routeParams.size ? `?${routeParams}` : ''}`;
                history.replaceState({ section: publicSection, ...(categoryId ? { bulletinCategory: categoryId } : {}) }, '', `${location.pathname}${publicHash}`);
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
    if (sessionData?.sessionExpired) {
        history.replaceState({ section: 'landing' }, '', `${location.pathname}#landing`);
        showSection('landing', true);
        return;
    }
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
    const validHashRoutes = ['landing', 'bulletin', 'login', 'admin-login', 'forgot-password', 'register', 'resident-dashboard', 'resident-profile', 'resident-change-email', 'resident-change-password', 'admin-dashboard', 'privacy-policy', 'terms', 'moderation', 'copyright', 'page-unavailable'];
    const initialResetRoute = initialRoute === 'password-reset' || initialRoute === 'forgot-password';
    const initialHasResetToken = initialParams.has('token');
    const initialResetTokenValid = initialHasResetToken && /^[a-f0-9]{64}$/i.test(initialParams.get('token') || '');
    const invalidInitialResetToken = initialResetRoute && (initialRoute === 'password-reset' ? !initialResetTokenValid : initialHasResetToken && !initialResetTokenValid);
    const initialIsKnown = validHashRoutes.includes(initialRoute) || (initialRoute.startsWith('admin-') && ['overview', 'requests', 'residents', 'disclosures', 'activity'].includes(initialRoute.slice(6)));
    const initialIsAdminRoute = initialRoute === 'admin-dashboard' || (initialRoute.startsWith('admin-') && ['overview', 'requests', 'residents', 'disclosures', 'activity'].includes(initialRoute.slice(6)));
    const start = (passwordResetToken && /^[a-f0-9]{64}$/i.test(passwordResetToken)) || initialResetTokenValid
        ? 'forgot-password'
        : (initialIsAdminRoute
            ? (document.body.dataset.adminEntryAuthorized === '1' ? 'admin-login' : 'page-unavailable')
            : (invalidInitialResetToken || (rawInitialHash && (!initialIsKnown || !initialRoute))
            ? 'page-unavailable'
            : (initialIsKnown ? initialRoute : 'landing')));
    const resolvedStart = start === 'admin-login' && document.body.dataset.adminEntryAuthorized === '0' ? 'page-unavailable' : start;
    const initialBulletinCategory = initialRoute === 'bulletin' && /^[1-4]$/.test(initialParams.get('category') || '')
        ? Number(initialParams.get('category'))
        : null;
    const initialRecordParam = initialParams.get('record');
    const initialRecordValue = Number(initialRecordParam);
    const initialBulletinRecord = initialBulletinCategory && /^[1-9]\d*$/.test(initialRecordParam || '') && Number.isSafeInteger(initialRecordValue)
        ? initialRecordValue
        : null;
    const initialRouteParams = new URLSearchParams();
    if (resolvedStart === 'bulletin' && initialBulletinCategory) {
        initialRouteParams.set('category', String(initialBulletinCategory));
        if (initialBulletinRecord) initialRouteParams.set('record', String(initialBulletinRecord));
    }
    const initialSearch = resolvedStart === 'bulletin' ? initialParams.get('search') : null;
    if (initialSearch !== null) initialRouteParams.set('search', initialSearch.slice(0, 300));
    const initialPortalFocus = resolvedStart === 'landing' ? initialParams.get('portalFocus') : null;
    if (['services', 'contact-info'].includes(initialPortalFocus)) initialRouteParams.set('portalFocus', initialPortalFocus);
    const initialHash = `#${resolvedStart}${initialRouteParams.size ? `?${initialRouteParams}` : ''}`;
    history.replaceState({ section: resolvedStart, ...(resolvedStart === 'bulletin' && initialBulletinCategory ? { bulletinCategory: initialBulletinCategory } : {}) }, '', initialHash);
    showSection(resolvedStart, true);
});
