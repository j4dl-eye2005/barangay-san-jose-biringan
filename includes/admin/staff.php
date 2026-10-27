<div id="admin-staff-panel" class="admin-panel" role="tabpanel" aria-labelledby="tab-staff" hidden>
    <div class="panel-header staff-page-header">
        <div><p class="eyebrow">Barangay operations</p><h2>Officials and staff</h2><p>Maintain the internal directory and assign access to existing portal administrator accounts.</p></div>
    </div>
    <aside class="staff-directory-notice" aria-label="Staff access information">
        <i class="fas fa-circle-info" aria-hidden="true"></i>
        <p>A directory entry <strong>does not create a login</strong>. Only link an account after it has been securely provisioned for an authorized person. Marking a linked staff record inactive suspends its portal access; restoring it re-enables access. Unlinking an account removes its portal permissions until a super-admin assigns a role again.</p>
    </aside>
    <form id="staff-directory-form" class="card staff-directory-form" data-action-submit="saveStaffDirectoryEntry(event)">
        <input id="staff-record-id" type="hidden">
        <div class="staff-directory-form-heading"><h3>Add or edit a staff record</h3></div>
        <div class="staff-directory-fields">
            <div class="staff-directory-field"><label for="staff-full-name">Full name</label><input id="staff-full-name" maxlength="180" required autocomplete="name"></div>
            <div class="staff-directory-field"><label for="staff-position-title">Position / office</label><input id="staff-position-title" maxlength="120" required placeholder="e.g. Barangay Secretary"></div>
            <div class="staff-directory-field"><label for="staff-kind">Category</label><select id="staff-kind" required><option value="staff">Staff</option><option value="official">Elected / appointed official</option></select></div>
            <div class="staff-directory-field"><label for="staff-contact-number">Contact number</label><input id="staff-contact-number" maxlength="30" type="tel"></div>
            <div class="staff-directory-field"><label for="staff-email">Work email</label><input id="staff-email" maxlength="190" type="email" autocomplete="email"></div>
            <div class="staff-directory-field"><label for="staff-portal-user">Linked portal account</label><select id="staff-portal-user"><option value="">No portal login</option></select></div>
            <div class="staff-directory-field staff-directory-role-field"><label for="staff-portal-role">Portal role</label><select id="staff-portal-role"><option value="service_clerk">Service clerk</option><option value="cashier">Cashier</option><option value="document_issuer">Document issuer</option><option value="super_admin">Super-admin</option></select><small>Link a portal account to assign a role.</small></div>
        </div>
        <div class="staff-directory-actions"><button class="btn-solid" type="submit">Save staff record</button><button class="btn-outline" type="button" data-action-click="resetStaffDirectoryForm()">Clear</button><span id="staff-directory-feedback" role="status" aria-live="polite"></span></div>
    </form>
    <section class="card full-width-card table-card staff-directory-table-card" aria-labelledby="staff-directory-heading">
        <div class="staff-directory-table-heading"><h3 id="staff-directory-heading">Directory</h3></div>
        <div class="staff-directory-table-scroll admin-table-scroll">
            <table class="admin-table staff-directory-table"><caption class="visually-hidden">Barangay officials and staff directory</caption><thead><tr><th scope="col">Name</th><th scope="col">Position</th><th scope="col">Category</th><th scope="col">Contact</th><th scope="col">Portal access</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody id="admin-staff-list"></tbody></table>
        </div>
    </section>
</div>
