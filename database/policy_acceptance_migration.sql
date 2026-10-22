ALTER TABLE `resident`
    ADD COLUMN IF NOT EXISTS `terms_accepted_at` DATETIME DEFAULT NULL AFTER `archived_at`,
    ADD COLUMN IF NOT EXISTS `terms_version` VARCHAR(32) DEFAULT NULL AFTER `terms_accepted_at`,
    ADD COLUMN IF NOT EXISTS `privacy_notice_acknowledged_at` DATETIME DEFAULT NULL AFTER `terms_version`,
    ADD COLUMN IF NOT EXISTS `privacy_notice_version` VARCHAR(32) DEFAULT NULL AFTER `privacy_notice_acknowledged_at`;
