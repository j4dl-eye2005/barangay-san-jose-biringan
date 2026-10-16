-- Apply once to existing databases before deploying the security changes.
ALTER TABLE resident ADD COLUMN email_verified_at DATETIME NULL DEFAULT NULL;

-- Treat previously approved and pending accounts as verified so existing residents
-- are not locked out; new registrations must verify their email.
UPDATE resident SET email_verified_at = created_at WHERE email_verified_at IS NULL;

CREATE TABLE IF NOT EXISTS app_rate_limit (
    bucket_hash CHAR(64) NOT NULL PRIMARY KEY,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    INDEX idx_rate_window (window_started_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS email_verification_token (
    token_hash CHAR(64) NOT NULL PRIMARY KEY,
    resident_id INT NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_verify_resident (resident_id),
    INDEX idx_email_verify_expiry (expires_at),
    CONSTRAINT fk_email_verify_resident FOREIGN KEY (resident_id)
        REFERENCES resident (resident_id) ON DELETE CASCADE
) ENGINE=InnoDB;
