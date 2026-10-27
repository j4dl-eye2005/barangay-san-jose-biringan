<div id="admin-staff-panel" class="admin-panel" role="tabpanel" aria-labelledby="tab-staff" hidden>
    <div class="panel-header">
        <div><p class="eyebrow">Barangay operations</p><h2>Officials and staff</h2><p>Maintain the internal directory and assign access to existing portal administrator accounts.</p></div>
    </div>
    <p class="field-help">A directory entry does not create a login. Only link an account after it has been securely provisioned for an authorized person. Marking a linked staff record inactive suspends its portal access; restoring it re-enables access. Unlinking an account removes its portal permissions until a super-admin assigns a role again.</p>
    <form id="staff-directory-form" class="card staff-directory-form" data-action-submit="saveStaffDirectoryEntry(event)">
        <input id="staff-record-id" type="hidden">
        <div class="staff-directory-fields">
            <label>Full name<input id="staff-full-name" maxlength="180" required autocomplete="name"></label>
            <label>Position / office<input id="staff-position-title" maxlength="120" required placeholder="e.g. Barangay Secretary"></label>
            <label>Category<select id="staff-kind" required><option value="staff">Staff</option><option value="official">Elected / appointed official</option></select></label>
            <label>Contact number<input id="staff-contact-number" maxlength="30" type="tel"></label>
            <label>Work email<input id="staff-email" maxlength="190" type="email" autocomplete="email"></label>
            <label>Linked portal account<select id="staff-portal-user"><option value="">No portal login</option></select></label>
            <label>Portal role<select id="staff-portal-role"><option value="service_clerk">Service clerk</option><option value="cashier">Cashier</option><option value="document_issuer">Document issuer</option><option value="super_admin">Super-admin</option></select></label>
        </div>
        <div class="staff-directory-actions"><button class="btn-solid" type="submit">Save staff record</button><button class="btn-outline" type="button" data-action-click="resetStaffDirectoryForm()">Clear</button><span id="staff-directory-feedback" role="status" aria-live="polite"></span></div>
    </form>
    <div class="card full-width-card table-card admin-table-scroll">
        <table class="admin-table"><caption class="visually-hidden">Barangay officials and staff directory</caption><thead><tr><th>Name</th><th>Position</th><th>Category</th><th>Contact</th><th>Portal access</th><th>Status</th><th>Actions</th></tr></thead><tbody id="admin-staff-list"></tbody></table>
    </div>
</div>
