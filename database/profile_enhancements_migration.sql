-- Apply once before deploying the expanded shared profile features.
ALTER TABLE `user`
    ADD COLUMN `profile_photo` VARCHAR(80) DEFAULT NULL,
    ADD COLUMN `position_title` VARCHAR(120) DEFAULT NULL,
    ADD COLUMN `contact_number` VARCHAR(30) DEFAULT NULL,
    ADD COLUMN `notification_new_requests` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN `notification_payment_verification` TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN `password_changed_at` DATETIME DEFAULT NULL;

ALTER TABLE `resident`
    ADD COLUMN `profile_photo` VARCHAR(80) DEFAULT NULL,
    ADD COLUMN `password_changed_at` DATETIME DEFAULT NULL;

CREATE TABLE IF NOT EXISTS account_session (
    session_key CHAR(64) NOT NULL PRIMARY KEY,
    account_type VARCHAR(10) NOT NULL,
    account_id INT NOT NULL,
    user_agent VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    last_active_at DATETIME NOT NULL,
    INDEX idx_account_session_lookup (account_type, account_id, last_active_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_mfa_recovery_code (
    recovery_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used_at DATETIME DEFAULT NULL,
    INDEX idx_admin_recovery_user (user_id, used_at),
    CONSTRAINT fk_admin_recovery_user FOREIGN KEY (user_id)
        REFERENCES user (user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

UPDATE `user` SET password_changed_at = created_at WHERE password_changed_at IS NULL;
UPDATE `resident` SET password_changed_at = created_at WHERE password_changed_at IS NULL;

ALTER TABLE `user`
    MODIFY COLUMN `password_changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE `resident`
    MODIFY COLUMN `password_changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
