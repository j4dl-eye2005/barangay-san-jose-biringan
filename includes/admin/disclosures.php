<div id="admin-disclosures-panel" class="admin-panel" role="tabpanel" aria-labelledby="tab-disclosures" <?= $adminInitialSection !== 'disclosures' ? 'hidden' : '' ?>>
    <div class="panel-header">
        <div><p class="eyebrow">Transparency publishing</p><h2>Post a disclosure</h2><p>Publish an official SK record to the public bulletin board.</p></div>
    </div>
    <div class="disclosure-composer-tabs" role="tablist" aria-label="Disclosure composer view">
        <button type="button" class="active" id="disclosure-edit-tab" role="tab" aria-selected="true" data-disclosure-mobile-tab="edit">Edit</button>
        <button type="button" id="disclosure-preview-tab" role="tab" aria-selected="false" data-disclosure-mobile-tab="preview">Preview</button>
    </div>
    <div class="disclosure-composer-layout" id="disclosure-composer-layout" data-mobile-tab="edit">
        <div class="card full-width-card composer-card">
            <form id="disclosure-form" novalidate data-action-submit="handlePostDisclosure(event)">
                <div class="disclosure-form-fields">
                    <div class="input-group">
                        <label for="disc-category">Category <span class="req-star" aria-hidden="true">*</span></label>
                        <select id="disc-category" required aria-describedby="disc-category-error">
                            <option value="" selected disabled>Select a category</option>
                            <option value="1">Announcements</option><option value="2">Financial Reports</option><option value="3">Projects &amp; Budget</option><option value="4">Meeting Minutes</option>
                        </select>
                        <span class="disclosure-field-error" id="disc-category-error" role="alert" hidden></span>
                    </div>
                    <div class="input-group">
                        <label for="disc-title">Title <span class="req-star" aria-hidden="true">*</span></label>
                        <input type="text" id="disc-title" maxlength="120" required placeholder="Enter a clear title" aria-describedby="disc-title-counter disc-title-error">
                        <span class="disclosure-char-count" id="disc-title-counter" aria-live="polite">0 / 120</span>
                        <span class="disclosure-field-error" id="disc-title-error" role="alert" hidden></span>
                    </div>
                    <div class="input-group">
                        <label for="disc-content">Description <span class="req-star" aria-hidden="true">*</span></label>
                        <textarea id="disc-content" maxlength="2000" required rows="4" placeholder="Share the details residents need to know." aria-describedby="disc-content-counter disc-content-error"></textarea>
                        <span class="disclosure-char-count" id="disc-content-counter" aria-live="polite">0 / 2000</span>
                        <span class="disclosure-field-error" id="disc-content-error" role="alert" hidden></span>
                    </div>
                    <div class="input-group disclosure-file-group">
                        <label for="disc-file">Attachment <span class="disclosure-label-optional">(optional)</span></label>
                        <div class="disclosure-dropzone" id="disc-dropzone" role="button" tabindex="0" aria-controls="disc-file">
                            <i class="disclosure-upload-icon fas fa-cloud-arrow-up" aria-hidden="true"></i>
                            <p class="disclosure-dropzone-desktop-copy">Drag and drop a file here, or <button type="button" class="disclosure-browse" data-action-click="openFilePicker('disc-file')">browse</button></p>
                            <p class="disclosure-dropzone-mobile-copy">Tap to choose a file</p>
                            <span>PDF, DOCX, JPG, or PNG · max 5 MB</span>
                        </div>
                        <input type="file" id="disc-file" accept=".pdf,.docx,.jpg,.jpeg,.png" aria-label="Add an optional disclosure attachment" hidden>
                        <div class="disclosure-file-preview hidden" id="disc-file-preview">
                            <i class="fas fa-file-lines disclosure-file-type-icon" id="disc-file-icon" aria-hidden="true"></i>
                            <img class="disclosure-file-thumbnail hidden" id="disc-file-thumbnail" alt="Attachment thumbnail">
                            <span id="disc-file-name"></span>
                            <small id="disc-file-size"></small>
                            <button type="button" class="disclosure-file-action" data-action-click="openFilePicker('disc-file')">Replace</button>
                            <button type="button" class="disclosure-file-action" data-action-click="clearDisclosureFile()">Remove</button>
                        </div>
                        <p class="disclosure-file-error" id="disc-file-error" role="alert" hidden></p>
                        <p class="disclosure-attachment-note hidden" id="disclosure-attachment-edit-note">The existing attachment stays unchanged while editing.</p>
                    </div>
                </div>
                <p class="disclosure-status" id="disclosure-status" role="status" aria-live="polite" hidden></p>
            </form>
        </div>
        <div class="disclosure-form-actions" aria-label="Disclosure actions">
            <button type="button" class="disclosure-cancel-link" id="cancel-disclosure-composer">Cancel</button>
            <span class="disclosure-draft-saved" id="disclosure-draft-saved" role="status" hidden></span>
            <span class="disclosure-edit-actions hidden" id="disclosure-edit-actions"><button type="button" class="btn-outline" id="cancel-disclosure-edit">Cancel edit</button></span>
            <button type="button" class="btn-outline" id="save-disclosure-draft">Save as draft</button>
            <button type="submit" class="btn-solid" id="publish-disclosure-button" form="disclosure-form" disabled>Publish disclosure</button>
        </div>
        <aside class="disclosure-live-preview card" id="disclosure-live-preview" aria-labelledby="disclosure-live-preview-title">
            <h3 id="disclosure-live-preview-title">Preview · how residents will see this</h3>
            <article class="disclosure-live-post">
                <span class="recent-disclosure-category disclosure-live-category" id="disclosure-live-category">Select a category</span>
                <h4 class="is-placeholder" id="disclosure-live-title">Your title appears here</h4>
                <p class="disclosure-live-description is-placeholder" id="disclosure-live-description">Your description appears here</p>
                <div class="disclosure-live-attachment is-placeholder" id="disclosure-live-attachment">
                    <i class="fas fa-paperclip" aria-hidden="true"></i>
                    <span>No attachment selected</span>
                </div>
                <time id="disclosure-live-date"></time>
            </article>
        </aside>
    </div>
    <section class="recent-disclosures" aria-labelledby="recent-disclosures-title">
        <div class="recent-disclosures-header"><div><p class="eyebrow">Published records</p><h3 id="recent-disclosures-title">Recent disclosures</h3></div></div>
        <div class="card recent-disclosures-card"><div class="recent-disclosures-list" id="recent-disclosures-list" aria-live="polite"><p class="empty-state">Loading disclosures…</p></div></div>
    </section>
</div>
