ALTER TABLE `user`
    ADD FULLTEXT INDEX IF NOT EXISTS `ft_user_name` (`first_name`, `last_name`);

ALTER TABLE `audit_log`
    ADD COLUMN IF NOT EXISTS `subject_resident_id` INT DEFAULT NULL AFTER `user_id`,
    ADD INDEX IF NOT EXISTS `idx_audit_resident_created` (`subject_resident_id`, `created_at`),
    ADD FULLTEXT INDEX IF NOT EXISTS `ft_audit_search` (`action`, `details`);

UPDATE `audit_log`
SET `subject_resident_id` = CASE
    WHEN LOCATE('(ID ', `details`) > 0
        THEN CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(`details`, '(ID ', -1), ';', 1) AS UNSIGNED)
    WHEN LOCATE('Account type resident; account ID ', `details`) > 0
        THEN CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(`details`, 'Account type resident; account ID ', -1), ';', 1) AS UNSIGNED)
    ELSE CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(`details`, 'Resident ID ', -1), ';', 1) AS UNSIGNED)
END
WHERE `subject_resident_id` IS NULL
  AND (LOCATE('(ID ', `details`) > 0
       OR LOCATE('Resident ID ', `details`) > 0
       OR LOCATE('Account type resident; account ID ', `details`) > 0);
