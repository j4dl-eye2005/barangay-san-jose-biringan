<div id="admin-residents-panel" class="admin-panel" role="tabpanel" aria-labelledby="tab-residents" <?= $adminInitialSection !== 'residents' ? 'hidden' : '' ?>>
    <div class="panel-header">
        <div>
            <p class="eyebrow">Resident accounts</p>
            <h2>Resident accounts</h2>
            <p>Review registered accounts. Archive an account to pause access, or permanently delete it when needed.</p>
        </div>
    </div>
    <div class="card full-width-card table-card admin-table-scroll resident-admin-table-wrap">
        <table class="admin-table resident-admin-table">
            <caption class="visually-hidden">Resident accounts</caption>
            <thead><tr>
                <th scope="col">Name</th><th scope="col">Email</th><th scope="col">Phone</th>
                <th scope="col">Address</th><th scope="col">Registered</th><th scope="col">Status</th><th scope="col">Actions</th>
            </tr></thead>
            <tbody id="admin-resident-list"></tbody>
        </table>
    </div>
    <div id="resident-accounts-mobile-list" class="resident-accounts-mobile-list" aria-live="polite"></div>
    <nav class="activity-pagination resident-pagination" id="resident-account-pagination" aria-label="Resident account pages">
        <span id="resident-page-status">Page 1</span>
        <div><button class="btn-outline" type="button" id="resident-prev" data-action-click="changeResidentAccountPage(-1)" disabled>Previous</button><button class="btn-outline" type="button" id="resident-next" data-action-click="changeResidentAccountPage(1)" disabled>Next</button></div>
    </nav>
</div>
