<div id="admin-overview-panel" class="admin-panel" role="tabpanel" aria-labelledby="tab-overview" <?= $adminInitialSection !== null && $adminInitialSection !== 'overview' ? 'hidden' : '' ?>>
                        <div class="panel-header"><div><p class="eyebrow">At a glance</p><h2>Overview</h2><p>Current service activity and items that need attention.</p></div></div>
                        <div class="overview-summary-grid" id="admin-overview-summary">
                            <article class="overview-summary-card overview-summary-card-composite"><span>Needs action</span><strong id="overview-needs-action">—</strong><small id="overview-needs-action-caption">0 approved requests</small><div class="overview-summary-links"><button type="button" data-action-click="openOverviewDestination('approved')">Approved requests</button><button type="button" data-action-click="openOverviewDestination('new-residents')">Resident accounts</button></div></article>
                            <div class="overview-summary-swipe">
                                <button class="overview-summary-card" type="button" data-action-click="openOverviewDestination('ready')"><span>Ready for pickup</span><strong id="overview-ready-count">—</strong><small>Requests awaiting collection</small></button>
                                <button class="overview-summary-card" type="button" data-action-click="openOverviewDestination('completed')"><span>Completed this week</span><strong id="overview-completed-count">—</strong><small>Since Monday</small></button>
                                <button class="overview-summary-card" type="button" data-action-click="openOverviewDestination('new-residents')"><span>New residents this week</span><strong id="overview-new-residents-count">—</strong><small>Registered since Monday</small></button>
                            </div>
                        </div>
                        <div class="overview-lower-grid">
                            <section class="card overview-widget" aria-labelledby="requests-by-document-title"><div class="overview-widget-heading"><p class="eyebrow">Requests</p><h3 id="requests-by-document-title">By document type</h3></div><div id="overview-document-chart" class="overview-document-chart"><p class="empty-state">Loading request totals…</p></div></section>
                            <section class="card overview-widget" aria-labelledby="oldest-waiting-title"><div class="overview-widget-heading"><div><p class="eyebrow">Needs attention</p><h3 id="oldest-waiting-title">Oldest waiting</h3></div><button class="overview-view-all" type="button" data-action-click="openOverviewDestination('all-requests')">View all</button></div><div id="overview-oldest-list" class="overview-oldest-list"><p class="empty-state">Loading requests…</p></div></section>
                        </div>
                    </div>
                    
