<div id="admin-collections-panel" class="admin-panel" role="tabpanel" aria-labelledby="tab-collections" hidden>
    <div class="panel-header collection-page-header">
        <div class="collection-page-heading">
            <p class="eyebrow">Request-linked payments</p>
            <div class="collection-title-row">
                <h2>Collections</h2>
                <button class="btn-outline" type="button" data-action-click="loadAdminCollections()">Refresh</button>
            </div>
            <p>Review payment proofs, record counter payments, and see the request and amount attached to each entry.</p>
        </div>
    </div>
    <div class="collection-summary-grid">
        <article class="card collection-summary-card"><span>Collected today</span><strong id="collection-total-today">₱0.00</strong></article>
        <article class="card collection-summary-card"><span>Collected this month</span><strong id="collection-total-month">₱0.00</strong></article>
        <article class="card collection-summary-card"><span>Open payments</span><strong id="collection-open-count">0</strong></article>
    </div>
    <section class="card full-width-card table-card collection-table-card">
        <div class="collection-card-heading"><h3>Requests needing payment action</h3><p>Fee assessments must match the approved barangay schedule. A pending fee blocks payment and document preparation.</p></div>
        <div class="admin-table-scroll collection-table-scroll"><table class="admin-table collection-admin-table collection-open-table"><caption class="visually-hidden">Requests needing payment action</caption><thead><tr><th scope="col">Tracking</th><th scope="col">Resident</th><th scope="col">Document</th><th scope="col">Assessed fee</th><th scope="col">Payment status</th><th scope="col">Actions</th></tr></thead><tbody id="admin-collection-open-list"></tbody></table></div>
        <div id="admin-collection-open-mobile" class="collection-mobile-list collection-mobile-list--requests" role="list" aria-label="Requests needing payment action"></div>
    </section>
    <section class="card full-width-card table-card collection-table-card">
        <div class="collection-card-heading"><h3>Recent payment history</h3><p>Latest 100 confirmed and reversed request-linked payments.</p></div>
        <div class="admin-table-scroll collection-table-scroll"><table class="admin-table collection-admin-table collection-history-table"><caption class="visually-hidden">Recent payment history</caption><thead><tr><th scope="col">Tracking</th><th scope="col">Resident</th><th scope="col">Document</th><th scope="col">Amount / method</th><th scope="col">Reference</th><th scope="col">Recorded</th><th scope="col">Status</th></tr></thead><tbody id="admin-collection-history"></tbody></table></div>
        <div id="admin-collection-history-mobile" class="collection-mobile-list collection-mobile-list--history" role="list" aria-label="Recent payment history"></div>
    </section>
</div>
