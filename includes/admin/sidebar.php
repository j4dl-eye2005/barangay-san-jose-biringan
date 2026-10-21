<aside class="admin-rail" aria-label="Administration sections">
                    <div class="rail-heading"><p class="eyebrow">Barangay operations</p><h1 id="admin-dashboard-title">Admin workspace</h1><p>Manage service queues and public records.</p></div>
                    <nav class="admin-tabs" aria-label="Admin workspace sections">
                        <button class="admin-tab <?= $adminInitialSection === null || $adminInitialSection === 'overview' ? 'active' : '' ?>" type="button" data-action-click="switchAdminTab('overview')" id="tab-overview" aria-current="<?= $adminInitialSection === null || $adminInitialSection === 'overview' ? 'page' : 'false' ?>"><i class="fas fa-chart-pie" aria-hidden="true"></i><span>Overview</span></button>
                        <button class="admin-tab <?= $adminInitialSection === 'requests' ? 'active' : '' ?>" type="button" data-action-click="switchAdminTab('requests')" id="tab-requests" aria-current="<?= $adminInitialSection === 'requests' ? 'page' : 'false' ?>"><i class="fas fa-file-lines" aria-hidden="true"></i><span>Requests queue</span><span class="admin-tab-count" id="requests-queue-count" aria-label="0 pending requests" hidden>0</span></button>
                        <button class="admin-tab <?= $adminInitialSection === 'residents' ? 'active' : '' ?>" type="button" data-action-click="switchAdminTab('residents')" id="tab-residents" aria-current="<?= $adminInitialSection === 'residents' ? 'page' : 'false' ?>"><i class="fas fa-user-check" aria-hidden="true"></i><span>Resident accounts</span></button>
                        <button class="admin-tab <?= $adminInitialSection === 'disclosures' ? 'active' : '' ?>" type="button" data-action-click="switchAdminTab('disclosures')" id="tab-disclosures" aria-current="<?= $adminInitialSection === 'disclosures' ? 'page' : 'false' ?>"><i class="fas fa-paperclip" aria-hidden="true"></i><span>Post disclosure</span></button>
                        <button class="admin-tab <?= $adminInitialSection === 'activity' ? 'active' : '' ?>" type="button" data-action-click="switchAdminTab('activity')" id="tab-activity" aria-current="<?= $adminInitialSection === 'activity' ? 'page' : 'false' ?>"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i><span>Activity log</span></button>
                    </nav>
                    <div class="admin-sidebar-account" id="admin-sidebar-account" hidden>
                        <div class="admin-sidebar-identity">
                            <span class="admin-sidebar-avatar" id="admin-sidebar-avatar" aria-hidden="true"></span>
                            <div class="admin-sidebar-account-copy">
                                <strong class="admin-sidebar-account-name" id="admin-sidebar-account-name"></strong>
                                <div class="admin-sidebar-account-meta"><span class="admin-sidebar-role">Administrator</span><button class="admin-sidebar-profile" type="button" data-action-click="handleViewProfile()">View profile</button></div>
                            </div>
                        </div>
                        <button class="admin-sidebar-logout" type="button" data-action-click="logout()"><i class="fas fa-arrow-right-from-bracket" aria-hidden="true"></i><span>Log out</span></button>
                    </div>
                </aside>
