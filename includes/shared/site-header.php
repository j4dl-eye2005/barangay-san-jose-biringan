    <nav class="navbar" id="main-nav" aria-label="Primary navigation">
        <div class="portal-utility-bar" aria-label="Portal utility links">
            <div class="portal-utility-inner">
                <time class="portal-current-date" id="portal-current-date" aria-live="polite"></time>
                <div class="portal-utility-links">
                    <button class="portal-utility-link" type="button" data-action-click="focusContactInfo()" data-portal-i18n="utilityContact">Contact</button>
                    <span class="portal-utility-divider" aria-hidden="true"></span>
                    <label class="portal-language-control">
                        <span class="visually-hidden" data-portal-i18n="languageLabel">Language</span>
                        <select id="portal-language-select" aria-label="Select language" data-portal-i18n-aria-label="languageSelectLabel" data-action-change="setPortalLanguage(event)">
                            <option value="en">English</option>
                            <option value="tl">Tagalog</option>
                        </select>
                    </label>
                </div>
            </div>
        </div>
        <div class="nav-inner">
        <div class="portal-main-row">
        <a class="logo-container brand-link" href="#landing" data-action-click="showSection('landing'); return false" aria-label="Go to Barangay San Jose home" data-portal-i18n-aria-label="brandLabel">
            <img src="Barangay San Jose, Biringan City.png" alt="Barangay San Jose seal" class="logo">
            <span class="logo-text">
                <strong>Barangay San Jose</strong>
                <span data-portal-i18n="tagline">Digital services and public records</span>
            </span>
            <span class="portal-brand-divider" aria-hidden="true"></span>
            <span class="portal-brand-statement">DIGITAL DISCLOSURE &amp;<br>DOCUMENT REQUEST PORTAL</span>
        </a>
        <button class="nav-toggle" id="nav-toggle" type="button" aria-expanded="false" aria-controls="nav-menu" data-action-click="toggleMobileMenu()">
            <span class="nav-toggle-label" data-portal-i18n="menu">Menu</span><i class="fas fa-bars" aria-hidden="true"></i>
        </button>
        </div>
        <div class="portal-header-actions">
            <form class="portal-header-search" role="search" data-action-submit="portalSearch(this); return false">
                <label class="visually-hidden" for="portal-header-search-input" data-portal-i18n="headerSearchLabel">Search documents and public records</label>
                <i class="fas fa-search" aria-hidden="true"></i>
                <input id="portal-header-search-input" name="query" type="search" autocomplete="off" placeholder="Search documents and public records" data-portal-i18n-placeholder="headerSearchPlaceholder">
            </form>
            <button class="portal-contact-button" type="button" data-action-click="focusContactInfo()"><i class="fas fa-envelope" aria-hidden="true"></i><span data-portal-i18n="contactUs">Contact Us</span></button>
        </div>
        <button class="btn-solid compact-button nav-register-button" id="nav-register-cta" type="button" data-action-click="showSection('register')" data-portal-i18n="register">Register</button>
        <div class="nav-menu" id="nav-menu">
            <ul class="nav-links">
                <li class="nav-home-item"><button type="button" data-section="landing" aria-current="page" data-action-click="showSection('landing')" data-portal-i18n="home">Home</button></li>
                <li class="portal-services-nav-item"><button type="button" data-section="services" data-action-click="showHomeAnchor('services')" data-portal-i18n="services">Services</button></li>
                <li><button type="button" data-section="bulletin" data-action-click="showSection('bulletin')" data-portal-i18n="publicRecords">Public Records</button></li>
                <li class="portal-track-nav-item"><button type="button" data-section="resident-dashboard" data-action-click="openResidentProfileQuickLink('track')" data-portal-i18n="trackRequests">Track Request</button></li>
                <li class="portal-contact-nav-item"><button type="button" data-section="contact" data-action-click="focusContactInfo()" data-portal-i18n="contact">Contact</button></li>
                <li><button type="button" class="hidden" id="dashboard-nav-link" data-section="dashboard" data-action-click="openDashboard()">Dashboard</button></li>
                <li class="mobile-profile-nav-item" id="mobile-profile-nav-item" hidden><button type="button" data-section="resident-profile" data-action-click="showSection('resident-profile')">My profile</button></li>
            </ul>
            <!-- Admin workspace tabs — shown only inside the hamburger on mobile when admin is logged in -->
            <div class="admin-nav-section" id="admin-nav-section" hidden>
                <div class="admin-nav-label">Workspace</div>
                <ul class="admin-nav-tabs-list">
                    <li><button class="admin-nav-tab <?= $adminInitialSection === null || $adminInitialSection === 'overview' ? 'active' : '' ?>" type="button" id="nav-tab-overview" data-action-click="switchAdminTab('overview'); closeMobileMenu();">Overview</button></li>
                    <li><button class="admin-nav-tab <?= $adminInitialSection === 'requests' ? 'active' : '' ?>" type="button" id="nav-tab-requests" data-action-click="switchAdminTab('requests'); closeMobileMenu();">Requests queue<span class="admin-tab-count" id="nav-requests-queue-count" aria-label="0 pending requests" hidden>0</span></button></li>
                    <li><button class="admin-nav-tab <?= $adminInitialSection === 'residents' ? 'active' : '' ?>" type="button" id="nav-tab-residents" data-action-click="switchAdminTab('residents'); closeMobileMenu();">Resident accounts</button></li>
                    <li><button class="admin-nav-tab <?= $adminInitialSection === 'disclosures' ? 'active' : '' ?>" type="button" id="nav-tab-disclosures" data-action-click="switchAdminTab('disclosures'); closeMobileMenu();">Post disclosure</button></li>
                    <li><button class="admin-nav-tab <?= $adminInitialSection === 'activity' ? 'active' : '' ?>" type="button" id="nav-tab-activity" data-action-click="switchAdminTab('activity'); closeMobileMenu();">Activity log</button></li>
                </ul>
            </div>
            <div class="user-info hidden" id="user-info">
                <button id="welcome-user" class="account-menu-toggle" type="button" aria-haspopup="menu" aria-controls="user-account-menu" aria-expanded="false" data-action-click="toggleUserMenu(this)"><span id="welcome-user-name" class="account-greeting"></span><span id="welcome-user-avatar" class="account-avatar" aria-hidden="true"></span><i class="fas fa-chevron-down" aria-hidden="true"></i></button>
                <div class="user-account-menu" id="user-account-menu" role="menu" hidden>
                    <div class="account-menu-summary"><strong id="account-menu-full-name"></strong><span id="account-menu-email"></span></div>
                    <div class="account-menu-divider" id="account-profile-divider" aria-hidden="true"></div>
                    <button class="account-menu-item hidden" id="user-profile-link" type="button" role="menuitem" data-action-click="showSection('resident-profile')"><i class="fas fa-user" aria-hidden="true"></i><span>View profile</span></button>
                    <div class="account-menu-divider account-menu-logout-divider" aria-hidden="true"></div>
                    <button class="account-menu-item account-menu-logout" type="button" role="menuitem" data-action-click="logout()"><i class="fas fa-right-from-bracket" aria-hidden="true"></i><span>Log out</span></button>
                </div>
            </div>
            <div class="mobile-account-actions" id="mobile-account-actions" hidden>
                <button class="mobile-menu-logout" type="button" data-action-click="logout()">Log out</button>
            </div>
            <div class="nav-auth-actions" id="guest-auth-actions">
                <button class="btn-solid nav-register-menu-btn" type="button" data-action-click="showSection('register')" data-portal-i18n="register">Register</button>
                <button class="btn-outline nav-login-button" type="button" data-action-click="showSection('login')" data-portal-i18n="login">Log in</button>
            </div>
        </div>
        <div class="nav-backdrop" id="nav-backdrop" hidden></div>
        </div><!-- /.nav-inner -->
    </nav>
