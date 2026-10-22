ALTER TABLE `disclosure`
    ADD INDEX IF NOT EXISTS `idx_disclosure_public_listing` (`is_archived`, `category_id`, `timestamp_posted`, `disclosure_id`);

ALTER TABLE `disclosure_attachment`
    ADD INDEX IF NOT EXISTS `idx_attachment_disclosure_id` (`disclosure_id`, `attachment_id`);
