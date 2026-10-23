-- Apply once to the production database before uploading MFA-enabled PHP files.
ALTER TABLE `user`
    ADD COLUMN `admin_mfa_secret_enc` TEXT NULL,
    ADD COLUMN `admin_mfa_enabled_at` DATETIME NULL,
    ADD COLUMN `admin_mfa_last_counter` BIGINT NULL;
