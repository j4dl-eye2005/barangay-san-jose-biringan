    <div id="bulletin-delete-dialog" class="modal-overlay bulletin-delete-dialog hidden" role="dialog" aria-modal="true" aria-labelledby="bulletin-delete-title">
        <div class="modal-box bulletin-delete-sheet">
            <h2 id="bulletin-delete-title">Delete this announcement?</h2>
            <div class="bulletin-delete-actions"><button type="button" class="btn-outline" id="bulletin-delete-cancel" data-action-click="closeBulletinDeleteDialog()">Cancel</button><button type="button" class="bulletin-delete-confirm" data-action-click="confirmBulletinDelete()">Delete</button></div>
        </div>
    </div>

    <div id="action-modal" class="modal-overlay action-modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="modal-title" aria-describedby="modal-desc">
        <div class="modal-box">
            <div class="modal-heading"><p class="eyebrow">Request action</p><h2 id="modal-title">Enter details</h2><p class="pickup-request-context hidden" id="pickup-request-context"></p><p id="modal-desc"></p><button class="request-modal-close hidden" id="action-modal-close" type="button" aria-label="Close dialog" data-action-click="closeModal()"><i class="fas fa-xmark" aria-hidden="true"></i></button></div>
            <div class="input-group" id="modal-standard-field"><label id="modal-label" for="modal-input">Details</label><textarea id="modal-input" rows="3" placeholder="Type here..."></textarea><input id="modal-date-input" type="datetime-local" class="hidden" aria-hidden="true"></div>
            <div class="pickup-fields hidden" id="pickup-fields">
                <div class="pickup-datetime-group">
                    <label class="pickup-field-label" for="pickup-date-display">Pickup date and time</label>
                    <div class="pickup-datetime-row">
                        <div class="pickup-date-control">
                            <input id="pickup-date-display" type="text" placeholder="Choose a date" readonly aria-label="Pickup date" aria-haspopup="dialog" aria-expanded="false" data-action-click="togglePickupCalendar()" data-action-keydown="handlePickupDateKeydown(event)">
                            <button class="pickup-calendar-toggle" type="button" aria-label="Choose pickup date" data-action-click="togglePickupCalendar()"><i class="fas fa-calendar-days" aria-hidden="true"></i></button>
                            <div class="pickup-calendar hidden" id="pickup-calendar" role="dialog" aria-label="Choose pickup date">
                                <div class="pickup-calendar-head"><button type="button" id="pickup-calendar-prev" aria-label="Previous month" data-action-click="changePickupCalendarMonth(-1)"><i class="fas fa-chevron-left" aria-hidden="true"></i></button><strong id="pickup-calendar-month"></strong><button type="button" id="pickup-calendar-next" aria-label="Next month" data-action-click="changePickupCalendarMonth(1)"><i class="fas fa-chevron-right" aria-hidden="true"></i></button></div>
                                <div class="pickup-calendar-grid" id="pickup-calendar-grid" role="grid"></div>
                            </div>
                        </div>
                        <label class="visually-hidden" for="pickup-time-select">Pickup time</label>
                        <select id="pickup-time-select" aria-label="Pickup time" data-action-change="updatePickupReadback()"><option value="">Choose a time</option></select>
                    </div>
                    <p class="pickup-readback hidden" id="pickup-readback" aria-live="polite"></p>
                </div>
                <div class="input-group pickup-note-group"><label for="pickup-note">Note to resident <span>(optional)</span></label><textarea id="pickup-note" rows="2" placeholder="e.g. Bring a valid ID and your tracking number."></textarea></div>
            </div>
            <div class="modal-actions"><button class="btn-outline" id="action-modal-cancel" type="button" data-action-click="closeModal()">Cancel</button><button class="btn-solid" id="action-modal-confirm" type="button" data-action-click="submitModal()">Confirm</button></div>
        </div>
    </div>

    <div id="register-policy-dialog" class="modal-overlay register-policy-dialog hidden" role="dialog" aria-modal="true" aria-labelledby="register-policy-title">
        <div class="modal-box register-policy-modal">
            <div class="modal-heading"><p class="eyebrow">Registration information</p><h2 id="register-policy-title">Terms &amp; Conditions</h2><p class="register-policy-last-updated hidden" id="register-policy-last-updated"></p><p class="register-policy-academic-notice hidden" id="register-policy-academic-notice"><i class="fas fa-circle-info" aria-hidden="true"></i><span id="register-policy-academic-text"></span></p><button class="register-policy-close-x" id="register-policy-close-x" type="button" aria-label="Close dialog"><i class="fas fa-xmark" aria-hidden="true"></i></button></div>
            <div class="register-policy-progress" role="progressbar" aria-label="Reading progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><div class="register-policy-progress-fill" id="register-policy-progress-fill"></div></div>
            <div class="register-policy-content policy-page" id="register-policy-content" tabindex="0" role="region" aria-label="Policy details"></div>
            <div class="modal-actions"><button class="btn-outline" type="button" id="register-policy-close" disabled>I understand</button></div>
        </div>
    </div>

