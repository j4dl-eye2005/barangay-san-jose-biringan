ALTER TABLE `document_request`
    ADD INDEX IF NOT EXISTS `idx_requests_created` (`created_at`, `request_id`);

ALTER TABLE `request_status_log`
    ADD INDEX IF NOT EXISTS `idx_status_created` (`status`, `created_at`, `log_id`);

ALTER TABLE `resident`
    ADD INDEX IF NOT EXISTS `idx_resident_created` (`created_at`);
