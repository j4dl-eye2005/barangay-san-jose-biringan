-- Barangay Portal database schema
-- Creates the `barangay_portal` database and all tables the app expects.

CREATE DATABASE IF NOT EXISTS barangay_portal
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE barangay_portal;

-- Admin / staff accounts
CREATE TABLE IF NOT EXISTS user (
    user_id       INT AUTO_INCREMENT PRIMARY KEY,
    first_name    VARCHAR(100) NOT NULL,
    middle_name   VARCHAR(100) DEFAULT NULL,
    last_name     VARCHAR(100) NOT NULL,
    email         VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          VARCHAR(50)  NOT NULL DEFAULT 'Admin',
    admin_mfa_secret_enc TEXT DEFAULT NULL,
    admin_mfa_enabled_at DATETIME DEFAULT NULL,
    admin_mfa_last_counter BIGINT DEFAULT NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Resident accounts
CREATE TABLE IF NOT EXISTS resident (
    resident_id   INT AUTO_INCREMENT PRIMARY KEY,
    first_name    VARCHAR(100) NOT NULL,
    middle_name   VARCHAR(100) DEFAULT NULL,
    last_name     VARCHAR(100) NOT NULL,
    email         VARCHAR(190) NOT NULL UNIQUE,
    phone_number  VARCHAR(30)  DEFAULT NULL,
    address       VARCHAR(255) DEFAULT NULL,
    proof_path    VARCHAR(500) DEFAULT NULL,
    password_hash VARCHAR(255) NOT NULL,
    email_verified_at DATETIME     DEFAULT NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_residents_active_verified_created (is_active, email_verified_at, created_at)
) ENGINE=InnoDB;

-- Types of documents a resident can request
CREATE TABLE IF NOT EXISTS document_type (
    document_type_id INT AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(150) NOT NULL,
    is_active        TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- Document requests submitted by residents
CREATE TABLE IF NOT EXISTS document_request (
    request_id       INT AUTO_INCREMENT PRIMARY KEY,
    tracking_code    VARCHAR(50)  NOT NULL UNIQUE,
    resident_id      INT          NOT NULL,
    document_type_id INT          NOT NULL,
    purpose          TEXT         DEFAULT NULL,
    current_status   VARCHAR(50)  NOT NULL DEFAULT 'Pending',
    payment_status   VARCHAR(20)  NOT NULL DEFAULT 'Unpaid',
    rejection_reason TEXT         DEFAULT NULL,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_requests_status_created (current_status, created_at),
    INDEX idx_requests_resident_created (resident_id, created_at),
    CONSTRAINT fk_request_resident FOREIGN KEY (resident_id)
        REFERENCES resident (resident_id) ON DELETE CASCADE,
    CONSTRAINT fk_request_doctype FOREIGN KEY (document_type_id)
        REFERENCES document_type (document_type_id)
) ENGINE=InnoDB;

-- Status change history for requests
CREATE TABLE IF NOT EXISTS request_status_log (
    log_id             INT AUTO_INCREMENT PRIMARY KEY,
    request_id         INT          NOT NULL,
    updated_by_user_id INT          DEFAULT NULL,
    status             VARCHAR(50)  NOT NULL,
    notes              TEXT         DEFAULT NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status_request_status_date (request_id, status, created_at, log_id),
    CONSTRAINT fk_log_request FOREIGN KEY (request_id)
        REFERENCES document_request (request_id) ON DELETE CASCADE,
    CONSTRAINT fk_log_user FOREIGN KEY (updated_by_user_id)
        REFERENCES user (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Admin-only notes on a request. Status history is stored in request_status_log.
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

-- Disclosure (bulletin) categories
CREATE TABLE IF NOT EXISTS disclosure_category (
    category_id   INT AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

-- Disclosures / bulletin posts
CREATE TABLE IF NOT EXISTS disclosure (
    disclosure_id        INT AUTO_INCREMENT PRIMARY KEY,
    category_id          INT          NOT NULL,
    published_by_user_id INT          DEFAULT NULL,
    title                VARCHAR(255) NOT NULL,
    content              TEXT         NOT NULL,
    is_archived          TINYINT(1)   NOT NULL DEFAULT 0,
    timestamp_posted     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_disclosure_archived_posted (is_archived, timestamp_posted),
    CONSTRAINT fk_disclosure_category FOREIGN KEY (category_id)
        REFERENCES disclosure_category (category_id),
    CONSTRAINT fk_disclosure_user FOREIGN KEY (published_by_user_id)
        REFERENCES user (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Attachments for disclosures
CREATE TABLE IF NOT EXISTS disclosure_attachment (
    attachment_id INT AUTO_INCREMENT PRIMARY KEY,
    disclosure_id INT          NOT NULL,
    file_name     VARCHAR(255) NOT NULL,
    file_path     VARCHAR(500) NOT NULL,
    file_type     VARCHAR(50)  DEFAULT NULL,
    CONSTRAINT fk_attachment_disclosure FOREIGN KEY (disclosure_id)
        REFERENCES disclosure (disclosure_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Audit trail
CREATE TABLE IF NOT EXISTS audit_log (
    audit_id   INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT          DEFAULT NULL,
    action     VARCHAR(100) NOT NULL,
    details    TEXT         DEFAULT NULL,
    created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_action_created (action, created_at),
    INDEX idx_audit_created (created_at, audit_id),
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id)
        REFERENCES user (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Short-lived, single-use password reset tokens (only hashes are stored).
CREATE TABLE IF NOT EXISTS password_reset_token (
    token_hash   CHAR(64)     NOT NULL PRIMARY KEY,
    account_type VARCHAR(10)  NOT NULL,
    account_id   INT          NOT NULL,
    expires_at   DATETIME     NOT NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_password_reset_account (account_type, account_id, created_at),
    INDEX idx_password_reset_expiry (expires_at)
) ENGINE=InnoDB;

-- Shared per-IP and per-account throttling buckets used by the API.
CREATE TABLE IF NOT EXISTS app_rate_limit (
    bucket_hash CHAR(64) NOT NULL PRIMARY KEY,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    INDEX idx_rate_window (window_started_at)
) ENGINE=InnoDB;

-- Single-use email verification tokens for new resident registrations.
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

-- Outbound mail is persisted before the API response and sent by a CLI worker.
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

-- Seed reference data
INSERT INTO document_type (name, is_active) VALUES
    ('Barangay Clearance', 1),
    ('Certificate of Residency', 1),
    ('Certificate of Indigency', 1),
    ('Business Permit', 1);

INSERT INTO disclosure_category (category_id, category_name) VALUES
    (1, 'Announcement'),
    (2, 'Event'),
    (3, 'Advisory'),
    (4, 'Ordinance');
