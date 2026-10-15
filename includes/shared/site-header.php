    <nav class="navbar" id="main-nav" aria-label="Primary navigation">
        <div class="nav-inner">
        <a class="logo-container brand-link" href="#landing" data-action-click="showSection('landing'); return false" aria-label="Go to Barangay San Jose home">
            <img src="Barangay San Jose, Biringan City.png" alt="Barangay San Jose seal" class="logo">
            <span class="logo-text">
                <strong>Barangay San Jose</strong>
                <span>Digital services and public records</span>
            </span>
        </a>
        <button class="btn-solid compact-button nav-register-button" id="nav-register-cta" type="button" data-action-click="showSection('register')">Register</button>
        <button class="nav-toggle" id="nav-toggle" type="button" aria-expanded="false" aria-controls="nav-menu" data-action-click="toggleMobileMenu()">
            <span class="nav-toggle-label">Menu</span><i class="fas fa-bars" aria-hidden="true"></i>
        </button>
        <div class="nav-menu" id="nav-menu">
            <ul class="nav-links">
                <li class="nav-home-item"><button type="button" data-section="landing" aria-current="page" data-action-click="showSection('landing')">Home</button></li>
                <li><button type="button" class="hidden" id="dashboard-nav-link" data-section="dashboard" data-action-click="openDashboard()">Dashboard</button></li>
                <li><button type="button" data-section="bulletin" data-action-click="showSection('bulletin')">Public Records</button></li>
            </ul>
            <!-- Admin workspace tabs — shown only inside the hamburger on mobile when admin is logged in -->
            <div class="admin-nav-section" id="admin-nav-section" hidden>
                <div class="admin-nav-label">Workspace</div>
                <ul class="admin-nav-tabs-list">
                    <li><button class="admin-nav-tab <?= $adminInitialSection === null || $adminInitialSection === 'overview' ? 'active' : '' ?>" type="button" id="nav-tab-overview" data-action-click="switchAdminTab('overview'); closeMobileMenu();">Overview</button></li>
                    <li><button class="admin-nav-tab <?= $adminInitialSection === 'requests' ? 'active' : '' ?>" type="button" id="nav-tab-requests" data-action-click="switchAdminTab('requests'); closeMobileMenu();">Requests queue<span class="admin-tab-count" id="nav-requests-queue-count" aria-label="0 pending requests" hidden>0</span></button></li>
                    <li><button class="admin-nav-tab <?= $adminInitialSection === 'residents' ? 'active' : '' ?>" type="button" id="nav-tab-residents" data-action-click="switchAdminTab('residents'); closeMobileMenu();">Pending residents<span class="admin-tab-count" id="nav-pending-residents-count" aria-label="0 pending residents" hidden>0</span></button></li>
                    <li><button class="admin-nav-tab <?= $adminInitialSection === 'disclosures' ? 'active' : '' ?>" type="button" id="nav-tab-disclosures" data-action-click="switchAdminTab('disclosures'); closeMobileMenu();">Post disclosure</button></li>
                    <li><button class="admin-nav-tab <?= $adminInitialSection === 'activity' ? 'active' : '' ?>" type="button" id="nav-tab-activity" data-action-click="switchAdminTab('activity'); closeMobileMenu();">Activity log</button></li>
                </ul>
            </div>
            <div class="user-info hidden" id="user-info">
                <span id="welcome-user"></span>
                <button class="btn-outline compact-button" type="button" data-action-click="logout()">Log out</button>
            </div>
            <div class="nav-auth-actions" id="guest-auth-actions">
                <button class="btn-solid nav-register-menu-btn" type="button" data-action-click="showSection('register')">Register</button>
                <button class="btn-outline nav-login-button" type="button" data-action-click="showSection('login')">Log in</button>
            </div>
        </div>
        <div class="nav-backdrop" id="nav-backdrop" hidden></div>
        </div><!-- /.nav-inner -->
    </nav>
