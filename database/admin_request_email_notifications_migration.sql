-- Enable email alerts for resident-submitted requests for existing admins.
-- Admins can still turn this off later in Profile > Email notifications.
ALTER TABLE `user`
    MODIFY COLUMN `notification_new_requests` TINYINT(1) NOT NULL DEFAULT 1;

UPDATE `user`
SET `notification_new_requests` = 1
WHERE `role` = 'Admin';
