-- Existing installations: run this once in the barangay_portal database.
-- request_status_log and audit_log already provide status history and activity storage.
CREATE TABLE IF NOT EXISTS request_internal_note (
    request_id         INT PRIMARY KEY,
    note_text          TEXT         NOT NULL,
    updated_by_user_id INT          DEFAULT NULL,
    updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_request_note_request FOREIGN KEY (request_id)
        REFERENCES document_request (request_id) ON DELETE CASCADE,
    CONSTRAINT fk_request_note_user FOREIGN KEY (updated_by_user_id)
        REFERENCES user (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;
