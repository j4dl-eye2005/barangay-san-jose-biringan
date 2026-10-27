<div id="admin-collections-panel" class="admin-panel" role="tabpanel" aria-labelledby="tab-collections" hidden>
    <div class="panel-header"><div><p class="eyebrow">Request-linked payments</p><h2>Collections</h2><p>Review payment proofs, record counter payments, and see the request and amount attached to each entry.</p></div><button class="btn-outline" type="button" data-action-click="loadAdminCollections()">Refresh</button></div>
    <div class="collection-summary-grid">
        <article class="card"><span>Collected today</span><strong id="collection-total-today">₱0.00</strong></article>
        <article class="card"><span>Collected this month</span><strong id="collection-total-month">₱0.00</strong></article>
        <article class="card"><span>Open payments</span><strong id="collection-open-count">0</strong></article>
    </div>
    <section class="card full-width-card table-card collection-table-card">
        <div class="card-heading"><h3>Requests needing payment action</h3><p>Fee assessments must match the approved barangay schedule. A pending fee blocks payment and document preparation.</p></div>
        <div class="admin-table-scroll"><table class="admin-table"><caption class="visually-hidden">Requests needing payment action</caption><thead><tr><th>Tracking / resident</th><th>Document</th><th>Assessed fee</th><th>Payment status</th><th>Actions</th></tr></thead><tbody id="admin-collection-open-list"></tbody></table></div>
    </section>
    <section class="card full-width-card table-card collection-table-card">
        <div class="card-heading"><h3>Recent payment history</h3><p>Latest 100 confirmed and reversed request-linked payments.</p></div>
        <div class="admin-table-scroll"><table class="admin-table"><caption class="visually-hidden">Recent payment history</caption><thead><tr><th>Tracking / resident</th><th>Document</th><th>Amount / method</th><th>Reference</th><th>Recorded</th><th>Status</th></tr></thead><tbody id="admin-collection-history"></tbody></table></div>
    </section>
</div>
