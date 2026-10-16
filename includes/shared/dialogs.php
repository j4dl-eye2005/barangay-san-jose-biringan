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

    <div id="register-terms-dialog" class="modal-overlay register-terms-dialog hidden" role="dialog" aria-modal="true" aria-labelledby="register-dialog-title" aria-describedby="register-dialog-instructions">
        <div class="modal-box register-terms-modal">
            <div class="modal-heading"><p class="eyebrow">Before you register</p><h2 id="register-dialog-title">Terms &amp; Conditions</h2><p id="register-dialog-instructions">Please scroll through the terms. Once you reach the end, you can close this window and agree on the registration form.</p></div>
            <div class="register-terms-scroll" id="register-terms-scroll" tabindex="0" role="region" aria-label="Terms and Conditions for registration">
                <p class="policy-updated">Last updated October 3, 2026 · Academic project — no real data collected</p>
                <h3>Acceptance</h3><p>You agree to use this portal only for lawful purposes and in a way that does not harm others or violate these rules.</p>
                <h3>Accounts &amp; services</h3><p>This portal lets residents register accounts, submit proof of residency, and request barangay documents. Access is granted after administrator verification. You are responsible for keeping your login secure and for the accuracy of what you submit.</p>
                <h3>Your responsibilities</h3><p>You agree not to:</p><ul><li>Provide false, misleading, or fraudulent registration details</li><li>Upload forged or altered proof-of-residency documents</li><li>Impersonate another person or submit requests on their behalf without authority</li><li>Use the portal for illegal activity or to harm others</li><li>Upload copyrighted material without permission</li><li>Share sensitive data about other people without their consent</li></ul>
                <h3>Administration rights</h3><p>We may review, approve, reject, or remove any registration, request, or upload that violates these terms or appears fraudulent. Accounts that repeatedly misuse the portal may be suspended.</p>
                <h3>No warranties</h3><p>This portal is provided “as is.” We do not guarantee uninterrupted, secure, or error-free access.</p>
                <h3>Liability</h3><p>We are not liable for damages arising from the use of this portal, including issues from user-submitted content, downtime, or security events.</p>
                <h3>Third-party content</h3><p>We are not responsible for content submitted by users or for third-party links or services that appear on this portal.</p>
                <h3>Changes</h3><p>We may update, change, or remove features at any time without notice.</p>
                <h3>Termination</h3><p>We may suspend or remove access if we believe a user has violated these Terms.</p>
                <h3>Governing law</h3><p>These Terms are governed by the laws of the Republic of the Philippines.</p>
                <h3>Contact</h3><p>Barangay San Jose Digital Portal: <a href="mailto:deleonjuliusakeem@gmail.com">deleonjuliusakeem@gmail.com</a></p>
            </div>
            <p class="register-terms-hint" id="register-dialog-hint" aria-live="polite">Scroll to the bottom to finish reading.</p>
            <div class="modal-actions register-terms-actions"><button class="btn-outline" type="button" id="register-terms-close" disabled>Close</button></div>
        </div>
    </div>

