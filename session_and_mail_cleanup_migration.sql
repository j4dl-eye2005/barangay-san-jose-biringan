-- Apply once to an existing database before deploying the matching PHP changes.
-- Password resets increment auth_version to revoke all current sessions.
ALTER TABLE `user`
    ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 0;

ALTER TABLE resident
    ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 0;

-- Mail bodies contain verification/reset links. Retain them only while a job
-- may still be retried; completed and exhausted jobs no longer need the body.
ALTER TABLE portal_email_queue
    MODIFY text_body LONGTEXT DEFAULT NULL;

UPDATE portal_email_queue
SET text_body = NULL, html_body = NULL
WHERE status IN ('sent', 'failed');
