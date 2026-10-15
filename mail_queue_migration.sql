-- Apply once to an existing production database before relying on queued mail.
CREATE TABLE IF NOT EXISTS portal_email_queue (
    queue_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient VARCHAR(254) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    text_body LONGTEXT NOT NULL,
    html_body LONGTEXT DEFAULT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'queued',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    locked_at DATETIME DEFAULT NULL,
    last_error VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME DEFAULT NULL,
    INDEX idx_mail_queue_due (status, available_at, queue_id),
    INDEX idx_mail_queue_lock (status, locked_at)
) ENGINE=InnoDB;

-- Indexes for frequent filters and date-ordered lists.
CREATE INDEX idx_requests_status_created ON document_request (current_status, created_at);
CREATE INDEX idx_requests_resident_created ON document_request (resident_id, created_at);
CREATE INDEX idx_status_request_status_date ON request_status_log (request_id, status, created_at, log_id);
CREATE INDEX idx_residents_active_verified_created ON resident (is_active, email_verified_at, created_at);
CREATE INDEX idx_audit_action_created ON audit_log (action, created_at);
CREATE INDEX idx_audit_created ON audit_log (created_at, audit_id);
CREATE INDEX idx_disclosure_archived_posted ON disclosure (is_archived, timestamp_posted);
