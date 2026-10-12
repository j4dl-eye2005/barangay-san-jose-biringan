// @ts-nocheck
let currentUser = null;
let editingDisclosureId = null;
let adminRequestRows = [];
let adminRequestStatusFilter = 'All';

/** Scroll smoothly to an element by id, accounting for the sticky nav */
function scrollToId(id) {
    const el = document.getElementById(id);
    if (!el) return;
    const offset = el.getBoundingClientRect().top + window.scrollY - (parseInt(getComputedStyle(document.documentElement).getPropertyValue('--topbar-height')) || 56) - 28;
    window.scrollTo({ top: offset, behavior: 'smooth' });
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
        active.querySelectorAll('.policy-toc-btn').forEach(btn => {
            const target = btn.getAttribute('onclick')?.match(/scrollToId\('([^']+)'\)/)?.[1];
            btn.classList.toggle('toc-active', target === activeId);
        });
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

// Remove all children of a node (replacement for `node.innerHTML = ''`).
function clearNode(node) {
    while (node.firstChild) node.removeChild(node.firstChild);
}

function statusClass(status) {
    return 'status-badge status-' + (status || '').toLowerCase().replace(/ /g, '');
}

/* ============================================================
   NAVIGATION
   ============================================================ */
function showSection(id, fromHistory = false) {
    if (id === 'admin-dashboard' && currentUser?.role !== 'Admin') {
        id = currentUser?.role === 'Resident' ? 'resident-dashboard' : 'admin-login';
    } else if (id === 'resident-dashboard' && currentUser?.role !== 'Resident') {
        id = currentUser?.role === 'Admin' ? 'admin-dashboard' : 'login';
    }

    const section = document.getElementById(id);
    if (!section) return;

    document.body.classList.toggle('hide-site-disclaimer', ['login', 'register', 'resident-dashboard', 'admin-dashboard'].includes(id) || (id === 'bulletin' && currentUser?.role === 'Admin'));
    document.body.classList.toggle('admin-dashboard-view', id === 'admin-dashboard');
    document.body.classList.toggle('admin-bulletin-view', id === 'bulletin' && currentUser?.role === 'Admin');

    document.querySelectorAll('.section').forEach(item => {
        const active = item === section;
        item.classList.toggle('active', active);
        item.setAttribute('aria-hidden', active ? 'false' : 'true');
    });

    const nav = document.getElementById('main-nav');
    nav.classList.toggle('hidden-nav', id === 'login' || id === 'register' || id === 'admin-login');
    closeMobileMenu();

    document.querySelectorAll('.nav-links [data-section]').forEach(item => {
        const target = item.dataset.section;
        const active = target === id || (target === 'dashboard' && id.endsWith('-dashboard'));
        if (active) item.setAttribute('aria-current', 'page');
        else item.removeAttribute('aria-current');
    });

    if (id === 'resident-dashboard') { loadResidentData(); loadDocTypes(); }
    if (id === 'admin-dashboard') { switchAdminTab('overview'); loadAdminData(); loadPendingResidents(); initDisclosureComposer(); loadRecentDisclosures(); }
    if (id === 'bulletin') loadDisclosures();
    if (id === 'landing') loadLatestAnnouncements();
    if (id === 'register') setTimeout(regInitDropzone, 50);
    // Reset login context message when navigating to login directly (not via requestDoc)
    if (id === 'login') {
        const msg = document.getElementById('login-context-msg');
        if (msg && !msg.dataset.fromDoc) {
            msg.textContent = 'Enter your credentials to continue to the portal.';
        }
        if (msg) delete msg.dataset.fromDoc;
    }

    if (!fromHistory && history.state?.section !== id) {
        history.pushState({ section: id }, '', `#${id}`);
    }

    window.scrollTo({ top: 0, left: 0, behavior: 'auto' });

    const heading = section.querySelector('h1');
    if (heading) {
        heading.setAttribute('tabindex', '-1');
        requestAnimationFrame(() => heading.focus({ preventScroll: true }));
    }
}

function toggleMobileMenu() {
    const toggle = document.getElementById('nav-toggle');
    const menu = document.getElementById('nav-menu');
    const backdrop = document.getElementById('nav-backdrop');
    const open = !menu.classList.contains('open');
    menu.classList.toggle('open', open);
    toggle.setAttribute('aria-expanded', String(open));
    if (backdrop) {
        backdrop.classList.toggle('open', open);
        backdrop.hidden = !open;
    }
    document.body.classList.toggle('menu-open', open);
}

function closeMobileMenu() {
    const toggle = document.getElementById('nav-toggle');
    const menu = document.getElementById('nav-menu');
    if (!toggle || !menu) return;
    menu.classList.remove('open');
    toggle.setAttribute('aria-expanded', 'false');
    const backdrop = document.getElementById('nav-backdrop');
    if (backdrop) {
        backdrop.classList.remove('open');
        backdrop.hidden = true;
    }
    document.body.classList.remove('menu-open');
}

// Handle browser Back/Forward buttons.
window.addEventListener('popstate', (event) => {
    const id = event.state?.section || 'landing';
    showSection(id, true);
});

window.addEventListener('hashchange', () => {
    if (location.hash !== '#admin-login') return;
    history.replaceState({ section: 'admin-login' }, '', '#admin-login');
    showSection('admin-login', true);
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
    document.body.classList.toggle('admin-user-view', currentUser?.role === 'Admin');
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

    showSection(currentUser.role === 'Admin' ? 'admin-dashboard' : 'resident-dashboard');
}

function showToast(msg, type='info') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.remove('toast-error', 'toast-success');
    if (type === 'error') t.classList.add('toast-error');
    if (type === 'success') t.classList.add('toast-success');
    t.classList.remove('hidden');
    setTimeout(() => t.classList.add('hidden'), 4000);
}

function focusDisclosureCategory(categoryId) {
    document.querySelectorAll('.bulletin-card').forEach(card => {
        card.classList.toggle('selected', card.id === `bulletin-card-${categoryId}`);
    });

    const list = document.getElementById(`list-${categoryId}`);
    if (list) {
        list.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
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

function openModal(title, desc, label, placeholder, callback, inputType = 'textarea') {
    document.getElementById('modal-title').textContent = title;
    document.getElementById('modal-desc').textContent = desc;
    document.getElementById('modal-label').textContent = label;
    const textInput = document.getElementById('modal-input');
    const dateInput = document.getElementById('modal-date-input');
    const input = inputType === 'datetime-local' ? dateInput : textInput;
    textInput.classList.toggle('hidden', input !== textInput);
    dateInput.classList.add('hidden');
    document.getElementById('modal-standard-field').classList.remove('hidden');
    document.getElementById('pickup-fields').classList.add('hidden');
    document.getElementById('pickup-request-context').classList.add('hidden');
    document.getElementById('action-modal-close').classList.add('hidden');
    document.getElementById('action-modal-confirm').textContent = 'Confirm';
    document.getElementById('action-modal-confirm').disabled = false;
    document.getElementById('action-modal-confirm').onclick = submitModal;
    document.getElementById('action-modal').dataset.closeBackdrop = 'false';
    document.getElementById('modal-label').htmlFor = input.id;
    if (input === textInput) input.placeholder = placeholder;
    input.value = '';
    modalInputId = input.id;
    modalReturnFocus = document.activeElement;
    modalCallback = callback;

    const modal = document.getElementById('action-modal');
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
        input.focus();
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
        document.body.classList.remove('modal-open');
        document.body.style.paddingRight = '';
        document.documentElement.classList.remove('modal-open-root');
        document.getElementById('main-nav').inert = false;
        document.getElementById('app-container').inert = false;
        if (returnFocus && document.contains(returnFocus)) returnFocus.focus();
        actionModalCloseTimer = null;
    }, 240);
}

function submitModal() {
    const value = document.getElementById(modalInputId).value.trim();
    if (!value) {
        showToast('Please enter a value.', 'error');
        return;
    }
    if (modalCallback) modalCallback(value);
    closeModal();
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

function openProofPreview(path, title) {
    // Re-validate the path defensively before embedding it.
    if (typeof path !== 'string' || !/^uploads\/[\w./-]+$/.test(path)) {
        showToast('Proof file is unavailable.', 'error');
        return;
    }
    closeProofPreview();

    const isPdf = /\.pdf$/i.test(path);
    const isImage = /\.(jpe?g|png)$/i.test(path);
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
            attrs: { src: path, alt: title, loading: 'lazy' }
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

    const dialog = el('div', {
        className: 'proof-preview-box',
        attrs: { role: 'dialog', 'aria-modal': 'true', 'aria-label': title }
    }, [
        el('div', { className: 'proof-preview-head' }, [
            el('span', { className: 'proof-preview-title', text: title }),
            closeBtn
        ]),
        el('div', { className: 'proof-preview-body' }, [media])
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
        submitButton.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Logging in…';
    }

    try {
        const res = await fetch('api/login.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, password, context })
        });
        if (!res.ok) {
            throw new Error(`Login request failed with HTTP ${res.status}`);
        }
        const data = await res.json();
        if (data.success) {
            const expectedRole = context === 'admin' ? 'Admin' : 'Resident';
            if (data.role !== expectedRole) {
                showToast('This account must use its designated login page.', 'error');
                return;
            }
            currentUser = { id: data.id, name: data.name, role: data.role };
            updateNavBar();
            showToast('Login successful!', 'success');
            showSection(context === 'admin' ? 'admin-dashboard' : 'resident-dashboard');
        } else {
            if (loginError) {
                loginError.textContent = data.message === 'Account Pending Verification'
                    ? "Your account is still under review. We'll notify you once it's approved."
                    : 'Incorrect email or password.';
                loginError.classList.remove('hidden');
            } else {
                showToast(data.message || 'Login failed.', 'error');
            }
        }
    } catch (error) {
        console.error('Login error:', error);
        showToast(error.message.includes('JSON')
            ? 'The server returned an invalid response.'
            : error.message.includes('HTTP')
                ? error.message
                : 'Unable to contact the server. Check Apache and the portal URL.', 'error');
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
    content.innerHTML = source.innerHTML;
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
        }, 210);
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
   REGISTER — two-step flow
   ============================================================ */
let _regScrolled = false;

function regInitStep1() {
    _regScrolled = false;
    const box = document.getElementById('register-terms-scroll');
    const cb  = document.getElementById('register-terms-accepted');
    const btn = document.getElementById('reg-continue-btn');
    const hint = document.getElementById('reg-scroll-hint');
    if (!box) return;

    // Reset state
    if (cb) { cb.checked = false; cb.disabled = true; }
    if (btn) btn.disabled = true;
    if (hint) hint.classList.remove('hidden');

    // Unlock checkbox once scrolled to bottom
    box.onscroll = () => {
        if (box.scrollTop + box.clientHeight >= box.scrollHeight - 10) {
            _regScrolled = true;
            if (cb) cb.disabled = false;
            if (hint) hint.classList.add('hidden');
            box.onscroll = null; // only need it once
        }
        regUpdateContinue();
    };

    // Also update continue on checkbox change
    if (cb) cb.onchange = regUpdateContinue;
}

function regUpdateContinue() {
    const cb  = document.getElementById('register-terms-accepted');
    const btn = document.getElementById('reg-continue-btn');
    if (btn) btn.disabled = !(_regScrolled && cb?.checked);
}

function regGoToStep2() {
    document.getElementById('reg-step-1')?.classList.add('hidden');
    document.getElementById('reg-step-2')?.classList.remove('hidden');
    document.getElementById('reg-dot-1')?.classList.replace('active', 'done');
    document.getElementById('reg-dot-2')?.classList.add('active');
    document.querySelector('#reg-step-2 input')?.focus();
}

function regGoToStep1() {
    document.getElementById('reg-step-2')?.classList.add('hidden');
    document.getElementById('reg-step-1')?.classList.remove('hidden');
    document.getElementById('reg-dot-2')?.classList.remove('active');
    document.getElementById('reg-dot-1')?.classList.replace('done', 'active');
}

async function handleRegister(e) {
    e.preventDefault();
    const form = document.getElementById('register-form');
    const consent = document.getElementById('register-terms-accepted');
    const pw = document.getElementById('register-password');
    const cpw = document.getElementById('register-confirm-password');
    if (pw && cpw && pw.value !== cpw.value) {
        showToast('Passwords do not match.', 'error');
        cpw.focus();
        return;
    }
    if (!consent?.checked) {
        showToast('Please agree to the Terms & Conditions before registering.', 'error');
        consent?.focus();
        return;
    }
    const formData = new FormData(form);

    try {
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
                'Account pending',
                'Your registration is awaiting verification',
                [
                    'Thanks for registering. A barangay administrator still needs to verify your residency details before your account is activated.',
                    'You will not be able to log in until your account is approved. We will review it as soon as possible.'
                ],
                () => showSection('login')
            );
        } else {
            showToast(result.message || `Registration failed (HTTP ${res.status}).`, 'error');
        }
    } catch (error) {
        console.error('Register error:', error);
        showToast('Unable to contact the server. Check Apache and the portal URL.', 'error');
    }
}

/* ============================================================
   LOGOUT
   ============================================================ */
async function logout() {
    const wasAdmin = currentUser?.role === 'Admin' || document.body.classList.contains('admin-user-view');
    try {
        await fetch('api/logout.php', { redirect: 'manual' });
    } catch (error) {
        console.error('Logout error:', error);
    }
    currentUser = null;
    updateNavBar();
    window.location.href = wasAdmin ? 'index.html#admin-login' : 'index.html#landing';
}

/* ============================================================
   LOAD DOCUMENT TYPES
   ============================================================ */
async function loadDocTypes() {
    const res = await fetch('api/document_types.php');
    const types = await res.json();
    const select = document.getElementById('doc-type');
    clearNode(select);

    const placeholder = el('option', { text: 'Select...', attrs: { value: '' } });
    select.appendChild(placeholder);

    types.forEach(t => {
        const fee = getDocumentFee(t);
        const label = fee === null ? t.name : `${t.name} - ${fee === 0 ? 'Free' : `₱${fee}`}`;
        const option = el('option', { text: label });
        option.value = t.document_type_id;
        select.appendChild(option);
    });
}

function getDocumentFee(documentType) {
    if (documentType?.fee != null && documentType.fee !== '') return Number(documentType.fee);
    const name = String(documentType?.name ?? documentType ?? '').trim().toLowerCase();
    if (name.includes('indigency')) return 0;
    if (name.includes('business')) return 200;
    if (name.includes('clearance') || name.includes('residency')) return 50;
    return null;
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
async function handleRequest(e) {
    e.preventDefault();
    const documentTypeId = document.getElementById('doc-type').value;
    const purpose = document.getElementById('doc-purpose').value;
    const res = await fetch('api/requests.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ documentTypeId, purpose })
    });
    const data = await res.json();
    if (data.success) {
        showToast(`Submitted! Tracking: ${data.trackingCode}`, 'success');
        document.getElementById('request-form').reset();
        loadResidentData();
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
        list.appendChild(el('p', { className: 'empty-state', text: 'No requests found.' }));
        return;
    }

    requests.forEach(r => {
        const item = el('article', { className: 'request-item' }, [
            el('h4', { text: r.documentType }),
            el('p', {}, [el('strong', { text: 'Tracking: ' }), String(r.tracking_code ?? '')]),
            el('p', {}, [el('strong', { text: 'Date: ' }), formatDate(r.created_at)]),
            el('span', { className: statusClass(r.current_status), text: r.current_status })
        ]);

        const fee = getDocumentFee(r.documentType);
        if (fee !== null) {
            item.insertBefore(el('p', {}, [el('strong', { text: 'Fee: ' }), fee === 0 ? 'Free' : `₱${fee}`]), item.children[3]);
        }

        if (r.current_status === 'Rejected') {
            item.appendChild(el('div', { className: 'request-note request-note-rejected' }, [
                el('strong', { text: 'Reason: ' }), r.rejection_reason || 'N/A'
            ]));
        }

        if (r.current_status === 'Ready for Pickup') {
            item.appendChild(el('div', { className: 'request-note request-note-ready' }, [
                el('strong', { text: 'Pickup details: ' }), formatPickupDetails(r.pickupDetails) || 'Please proceed to Barangay Hall during office hours.'
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
        request_approved: 'Request approved',
        request_rejected: 'Request rejected',
        request_ready_for_pickup: 'Request set ready',
        request_completed: 'Request completed',
        disclosure_published: 'Disclosure published',
        disclosure_edited: 'Disclosure edited',
        disclosure_unpublished: 'Disclosure unpublished'
    };
    return labels[action] || String(action || '').replaceAll('_', ' ');
}

function debouncedLoadAdminActivity() {
    window.clearTimeout(activitySearchTimer);
    activitySearchTimer = window.setTimeout(() => loadAdminActivity(1), 250);
}

async function loadAdminActivity(page = 1) {
    activityCurrentPage = Math.max(1, Number(page) || 1);
    const tbody = document.getElementById('admin-activity-list');
    if (!tbody) return;
    const query = new URLSearchParams({
        page: String(activityCurrentPage),
        action: document.getElementById('activity-action-filter')?.value || '',
        from: document.getElementById('activity-from-date')?.value || '',
        to: document.getElementById('activity-to-date')?.value || '',
        search: document.getElementById('activity-search')?.value.trim() || ''
    });
    try {
        const response = await fetch(`api/admin_activity.php?${query}`);
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'Could not load activity.');
        clearNode(tbody);
        activityPageCount = data.pages;
        if (!data.entries.length) {
            tbody.appendChild(el('tr', { className: 'table-empty-row' }, [
                el('td', { className: 'table-empty-state', text: 'No activity found.', attrs: { colspan: '4' } })
            ]));
        } else {
            data.entries.forEach(entry => tbody.appendChild(el('tr', {}, [
                el('td', { text: formatDateTime(entry.created_at), attrs: { 'data-label': 'Date and time' } }),
                el('td', { text: entry.admin_name, attrs: { 'data-label': 'Admin' } }),
                el('td', { text: formatActivityAction(entry.action), attrs: { 'data-label': 'Action' } }),
                el('td', { text: entry.details || '—', attrs: { 'data-label': 'Details' } })
            ])));
        }
        document.getElementById('activity-page-status').textContent = data.total ? `Page ${activityCurrentPage} of ${Math.max(1, data.pages)} · ${data.total} entries` : 'No entries';
        document.getElementById('activity-prev').disabled = activityCurrentPage <= 1 || data.pages === 0;
        document.getElementById('activity-next').disabled = activityCurrentPage >= data.pages;
    } catch (error) {
        tbody.replaceChildren(el('tr', { className: 'table-empty-row' }, [
            el('td', { className: 'table-empty-state', text: 'Activity log is unavailable.', attrs: { colspan: '4' } })
        ]));
        document.getElementById('activity-page-status').textContent = 'Activity unavailable';
        document.getElementById('activity-prev').disabled = true;
        document.getElementById('activity-next').disabled = true;
    }
}

/* ============================================================
   ADMIN: LOAD REQUESTS
   ============================================================ */
async function loadAdminData() {
    const res = await fetch('api/requests.php');
    const requests = await res.json();
    adminRequestRows = Array.isArray(requests) ? requests.slice().sort((a, b) => new Date(b.created_at) - new Date(a.created_at)) : [];
    const pendingCount = adminRequestRows.filter(r => ['Pending', 'Approved'].includes(r.current_status)).length;
    const requestBadge = document.getElementById('requests-queue-count');
    if (requestBadge) {
        requestBadge.textContent = String(pendingCount);
        requestBadge.setAttribute('aria-label', `${pendingCount} requests need action`);
        requestBadge.hidden = pendingCount === 0;
    }

    renderAdminRequests();
}

function setAdminRequestFilter(status, button) {
    adminRequestStatusFilter = status;
    document.querySelectorAll('.request-filter').forEach(filterButton => {
        const active = filterButton === button;
        filterButton.classList.toggle('active', active);
        filterButton.setAttribute('aria-pressed', String(active));
    });
    renderAdminRequests();
}

function filterAdminRequests() {
    renderAdminRequests();
}

function renderAdminRequests() {
    const tbody = document.getElementById('admin-request-list');
    if (!tbody) return;
    clearNode(tbody);
    document.querySelectorAll('.request-filter').forEach(filterButton => {
        const status = filterButton.dataset.filterStatus;
        const count = status === 'All'
            ? adminRequestRows.length
            : adminRequestRows.filter(request => request.current_status === status).length;
        filterButton.textContent = `${filterButton.dataset.filterLabel} (${count})`;
    });
    const query = (document.getElementById('admin-request-search')?.value || '').trim().toLocaleLowerCase();
    const filtered = adminRequestRows.filter(request => {
        const statusMatch = adminRequestStatusFilter === 'All' || request.current_status === adminRequestStatusFilter;
        const searchText = `${request.tracking_code ?? ''} ${request.residentName ?? ''}`.toLocaleLowerCase();
        return statusMatch && (!query || searchText.includes(query));
    });

    if (filtered.length === 0) {
        tbody.appendChild(el('tr', { className: 'table-empty-row' }, [el('td', { className: 'table-empty-state', text: 'No requests here yet.', attrs: { colspan: '6' } })]));
        return;
    }

    filtered.forEach(r => {
        const requestId = Number(r.request_id);
        const tracking = String(r.tracking_code ?? '');
        const actionGroup = el('div', { className: 'table-action-group' });
        const actionsCell = el('td', { attrs: { 'data-label': 'Actions' } }, [actionGroup]);
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
            actionsCell
        ]);
        tbody.appendChild(row);
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
        button.disabled = false;
        button.textContent = 'Save note';
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
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'Could not load this request.');
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
        } else {
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
            el('section', { className: 'card request-detail-card request-detail-pickup' }, [
                el('h3', { text: 'Pickup details' }),
                el('p', { className: 'pickup-detail-text', text: readyHistory ? formatPickupDetails(readyHistory.notes) : 'Pickup date and time have not been set.' })
            ]),
            el('section', { className: 'card request-detail-card request-detail-notes' }, [
                el('h3', { text: 'Internal notes' }),
                el('p', { className: 'field-help', text: 'Visible only to administrators.' }),
                el('textarea', { attrs: { id: 'request-internal-note', rows: '4', 'aria-label': 'Internal notes' } }),
                el('button', { className: 'btn-solid', text: 'Save note', attrs: { id: 'save-request-internal-note', type: 'button' }, on: { click: () => saveRequestInternalNote(requestId) } })
            ]),
            el('section', { className: 'card request-detail-card request-detail-action-card' }, [
                el('h3', { text: 'Actions' }), actionGroup
            ])
        ]);
        content.replaceChildren(requestHeading, trackingLine, detailGrid);
        const noteField = document.getElementById('request-internal-note');
        noteField.value = data.internal_note?.note_text || '';
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
    const res = await fetch('api/pending_residents.php');
    const residents = await res.json();
    const tbody = document.getElementById('admin-resident-list');
    clearNode(tbody);
    const pendingCount = Array.isArray(residents) ? residents.length : 0;
    const residentBadge = document.getElementById('pending-residents-count');
    if (residentBadge) {
        residentBadge.textContent = String(pendingCount);
        residentBadge.setAttribute('aria-label', `${pendingCount} pending residents`);
        residentBadge.hidden = pendingCount === 0;
    }

    if (!Array.isArray(residents) || residents.length === 0) {
        const emptyCell = el('td', {
            className: 'table-empty-state',
            text: 'No pending residents.',
            attrs: { colspan: '6' }
        });
        tbody.appendChild(el('tr', { className: 'table-empty-row' }, [emptyCell]));
        return;
    }

    residents.forEach(r => {
        const residentId = Number(r.resident_id);
        const residentName = titleCaseName(`${r.first_name ?? ''} ${r.middle_name ?? ''} ${r.last_name ?? ''}`);
        const safeProofPath = (typeof r.proof_path === 'string'
            && /^uploads\/[\w./-]+$/.test(r.proof_path))
            ? r.proof_path
            : null;

        const proofCell = el('td', { attrs: { 'data-label': 'Proof' } }, [
            safeProofPath
                ? el('button', {
                    className: 'link-button',
                    text: 'View proof',
                    attrs: { type: 'button', 'aria-haspopup': 'dialog', 'aria-label': `View proof of residency for ${residentName}` },
                    on: { click: () => openProofPreview(safeProofPath, `Proof of residency — ${residentName}`) }
                })
                : el('span', { className: 'unavailable-label', text: 'No file' })
        ]);

        const actionGroup = el('div', { className: 'table-action-group' }, [
            el('button', {
                className: 'action-btn btn-approve', text: 'Verify',
                attrs: { type: 'button', 'aria-label': `Verify resident ${residentName}` },
                on: { click: () => verifyResident(residentId, 'verify') }
            }),
            el('button', {
                className: 'action-btn btn-reject', text: 'Reject',
                attrs: { type: 'button', 'aria-label': `Reject resident ${residentName}` },
                on: { click: () => openModal('Reject resident', `Enter a reason for rejecting ${residentName}.`, 'Reason for rejection', 'e.g., The proof does not show a current address.', reason => verifyResident(residentId, 'reject', reason)) }
            })
        ]);

        tbody.appendChild(el('tr', {}, [
            el('td', { text: residentName, attrs: { 'data-label': 'Name', title: residentName } }),
            el('td', { text: r.email, attrs: { 'data-label': 'Email', title: r.email || '' } }),
            el('td', { text: r.address, attrs: { 'data-label': 'Address', title: r.address || '' } }),
            proofCell,
            el('td', { text: formatDate(r.created_at), attrs: { 'data-label': 'Registered' } }),
            el('td', { attrs: { 'data-label': 'Actions' } }, [actionGroup])
        ]));
    });
}

function titleCaseName(name) {
    return String(name || '').trim().replace(/\s+/g, ' ').toLocaleLowerCase('en-PH').replace(/(^|[\s'-])([\p{L}])/gu, (_, boundary, letter) => boundary + letter.toLocaleUpperCase('en-PH'));
}

async function verifyResident(residentId, action, rejectionReason = '') {
    if (action === 'verify' && !window.confirm('Verify this resident and activate their account?')) return;
    const res = await fetch('api/approve_resident.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ residentId, action: action === 'verify' ? 'approve' : 'reject', reason: rejectionReason })
    });
    let result;
    try {
        result = await res.json();
    } catch (error) {
        showToast(`Request failed with HTTP ${res.status}.`, 'error');
        return;
    }
    if (!res.ok || !result.success) {
        showToast(result.message || `Request failed with HTTP ${res.status}.`, 'error');
        return;
    }
    showToast(result.message, 'success');
    await loadPendingResidents();
}

/* ============================================================
   ADMIN TABS
   ============================================================ */
function switchAdminTab(tab) {
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
    Object.keys(panels).forEach(key => {
        const active = key === tab;
        panels[key].hidden = !active;
        btns[key].classList.toggle('active', active);
        btns[key].setAttribute('aria-selected', String(active));
        btns[key].tabIndex = active ? 0 : -1;
    });
    currentAdminDetailId = null;
    const detailPanel = document.getElementById('admin-request-detail-panel');
    if (detailPanel) detailPanel.hidden = true;
    if (tab === 'activity') loadAdminActivity(1);
    if (tab === 'overview') loadAdminOverview();
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
    const categoryId = document.getElementById('disc-category').value;
    const title = document.getElementById('disc-title').value;
    const content = document.getElementById('disc-content').value;
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
        showToast(wasEditing ? 'Disclosure updated successfully.' : 'Disclosure published successfully.', 'success');
        document.getElementById('disclosure-form').reset();
        clearDisclosureFile();
        updateDisclosureCharacterCount();
        cancelDisclosureEdit();
        try { localStorage.removeItem('disclosure-draft'); } catch { /* Ignore unavailable browser storage. */ }
        await loadRecentDisclosures();
    } catch (error) {
        showToast(error.message || 'Disclosure could not be saved.', 'error');
    } finally {
        submitButton.disabled = false;
        submitButton.removeAttribute('aria-busy');
    }
}

function initDisclosureComposer() {
    const form = document.getElementById('disclosure-form');
    if (!form || form.dataset.initialized === 'true') return;
    form.dataset.initialized = 'true';

    const zone = document.getElementById('disc-dropzone');
    const input = document.getElementById('disc-file');
    const description = document.getElementById('disc-content');
    try {
        const savedDraft = localStorage.getItem('disclosure-draft');
        if (savedDraft) {
            const draft = JSON.parse(savedDraft);
            if (draft.categoryId) document.getElementById('disc-category').value = String(draft.categoryId);
            if (draft.title) document.getElementById('disc-title').value = draft.title;
            if (draft.content) description.value = draft.content;
        }
    } catch { /* Draft storage is optional when browser storage is unavailable. */ }
    updateDisclosureCharacterCount();
    description.addEventListener('input', updateDisclosureCharacterCount);
    document.getElementById('save-disclosure-draft').addEventListener('click', saveDisclosureDraft);
    document.getElementById('cancel-disclosure-edit').addEventListener('click', cancelDisclosureEdit);

    input.addEventListener('change', () => setDisclosureFile(input.files?.[0]));
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
    });
}

function updateDisclosureCharacterCount() {
    const field = document.getElementById('disc-content');
    const counter = document.getElementById('disc-content-counter');
    if (field && counter) counter.textContent = `${field.value.length} ${field.value.length === 1 ? 'character' : 'characters'}`;
}

function setDisclosureFile(file) {
    const zone = document.getElementById('disc-dropzone');
    const preview = document.getElementById('disc-file-preview');
    const input = document.getElementById('disc-file');
    if (!file || !zone || !preview || !input) return;
    const extension = file.name.split('.').pop()?.toLowerCase();
    const allowed = ['pdf', 'docx', 'jpg', 'jpeg', 'png'];
    if (!allowed.includes(extension) || file.size > 5 * 1024 * 1024) {
        showToast('Choose a PDF, DOCX, JPG, or PNG file up to 5 MB.', 'error');
        input.value = '';
        return;
    }
    document.getElementById('disc-file-name').textContent = file.name;
    document.getElementById('disc-file-size').textContent = file.size < 1024 * 1024
        ? `${Math.max(1, Math.round(file.size / 1024))} KB`
        : `${(file.size / (1024 * 1024)).toFixed(1)} MB`;
    zone.classList.add('hidden');
    preview.classList.remove('hidden');
}

function clearDisclosureFile() {
    const input = document.getElementById('disc-file');
    const zone = document.getElementById('disc-dropzone');
    const preview = document.getElementById('disc-file-preview');
    if (input) input.value = '';
    if (zone) zone.classList.remove('hidden');
    if (preview) preview.classList.add('hidden');
}

function saveDisclosureDraft() {
    const draft = {
        categoryId: document.getElementById('disc-category').value,
        title: document.getElementById('disc-title').value,
        content: document.getElementById('disc-content').value
    };
    try {
        localStorage.setItem('disclosure-draft', JSON.stringify(draft));
        showToast('Draft saved on this device. Add the attachment again when you publish.', 'success');
    } catch {
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
            list.appendChild(el('article', { className: 'recent-disclosure-item' }, [
                el('div', { className: 'recent-disclosure-copy' }, [
                    el('h4', { text: item.title }),
                    el('div', { className: 'recent-disclosure-meta' }, [
                        el('span', { className: 'recent-disclosure-category', text: item.category_name || 'Disclosure' }),
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
    document.getElementById('disc-category').value = String(item.category_id);
    document.getElementById('disc-title').value = item.title || '';
    document.getElementById('disc-content').value = item.content || '';
    clearDisclosureFile();
    document.getElementById('disc-dropzone').classList.add('hidden');
    document.getElementById('disclosure-attachment-edit-note').classList.remove('hidden');
    updateDisclosureCharacterCount();
    document.getElementById('publish-disclosure-button').textContent = 'Save changes';
    document.getElementById('disclosure-edit-actions').classList.remove('hidden');
    document.getElementById('disc-title').focus();
    document.getElementById('disclosure-form').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function cancelDisclosureEdit() {
    editingDisclosureId = null;
    const button = document.getElementById('publish-disclosure-button');
    const actions = document.getElementById('disclosure-edit-actions');
    if (button) button.textContent = 'Publish disclosure';
    if (actions) actions.classList.add('hidden');
    document.getElementById('disclosure-form')?.reset();
    clearDisclosureFile();
    document.getElementById('disclosure-attachment-edit-note')?.classList.add('hidden');
    updateDisclosureCharacterCount();
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
        await loadRecentDisclosures();
    } catch (error) {
        showToast(error.message || 'Disclosure could not be unpublished.', 'error');
    }
}

async function deleteBulletinDisclosure(id, title) {
    if (currentUser?.role !== 'Admin') return;
    if (!window.confirm(`Delete “${title}” from Public Records?`)) return;
    try {
        const res = await fetch('api/disclosures.php', {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        const data = await res.json();
        if (!res.ok || !data.success) throw new Error(data.message || 'Announcement could not be deleted.');
        showToast('Announcement deleted from Public Records.', 'success');
        await Promise.all([loadDisclosures(), loadLatestAnnouncements(), loadRecentDisclosures()]);
    } catch (error) {
        showToast(error.message || 'Announcement could not be deleted.', 'error');
    }
}

/* ============================================================
   PUBLIC: LOAD DISCLOSURES
   ============================================================ */
async function loadDisclosures() {
    const res = await fetch('api/disclosures.php');
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

        const li = el('li', { className: currentUser?.role === 'Admin' ? 'bulletin-item-admin' : '' }, [
            el('strong', { className: 'bulletin-item-title', text: item.title }),
            el('time', {
                className: 'bulletin-item-date',
                text: formatDate(item.timestamp_posted),
                attrs: { datetime: String(item.timestamp_posted ?? '') }
            }),
            el('p', { className: 'bulletin-item-content', text: item.content })
        ]);

        // Only allow relative upload paths; reject absolute URLs and
        // dangerous schemes (e.g. javascript:) that could be injected.
        const safeFilePath = (typeof item.file_path === 'string'
            && /^uploads\/[\w./-]+$/.test(item.file_path))
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

        if (currentUser?.role === 'Admin') {
            li.appendChild(el('div', { className: 'bulletin-item-actions' }, [
                el('button', {
                    className: 'action-btn btn-reject',
                    text: 'Delete',
                    attrs: { type: 'button', 'aria-label': `Delete ${item.title}` },
                    on: { click: () => deleteBulletinDisclosure(item.disclosure_id, item.title) }
                })
            ]));
        }

        list.appendChild(li);
    });
}

/* ============================================================
   LANDING: LATEST ANNOUNCEMENTS
   ============================================================ */
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
    } catch (error) {
        clearNode(container);
        container.setAttribute('aria-busy', 'false');
        container.appendChild(el('p', { className: 'empty-state', text: 'Unable to load announcements right now.' }));
    }
}

/* ============================================================
   INITIALIZATION
   ============================================================ */
document.addEventListener('DOMContentLoaded', async () => {
    const footerYear = document.getElementById('footer-year');
    if (footerYear) footerYear.textContent = String(new Date().getFullYear());

    window.addEventListener('resize', () => {
        if (window.innerWidth > 720) closeMobileMenu();
    });

    document.getElementById('nav-menu').addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            closeMobileMenu();
            document.getElementById('nav-toggle').focus();
        }
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

    document.querySelector('.admin-tabs').addEventListener('keydown', event => {
        if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return;
        event.preventDefault();
        const tabs = [...document.querySelectorAll('.admin-tab')];
        const current = tabs.indexOf(document.activeElement);
        const direction = ['ArrowRight', 'ArrowDown'].includes(event.key) ? 1 : -1;
        const next = tabs[(current + direction + tabs.length) % tabs.length];
        const tab = next.id.replace('tab-', '');
        switchAdminTab(tab);
        next.focus();
    });

    // Auto-set year
    const yr = new Date().getFullYear();
    ['year-1', 'year-2', 'year-admin'].forEach(id => {
        const yearEl = document.getElementById(id);
        if (yearEl) yearEl.textContent = yr;
    });

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
            const dash = sessionData.role === 'Admin' ? 'admin-dashboard' : 'resident-dashboard';
            history.replaceState({ section: dash }, '', `#${dash}`);
            showSection(dash, true);
            return;
        }
    }
    updateNavBar();
    // Seed the first history entry, honoring any #hash already in the URL.
    const initial = (location.hash || '#landing').slice(1);
    const start = document.getElementById(initial) ? initial : 'landing';
    history.replaceState({ section: start }, '', `#${start}`);
    showSection(start, true);
});
