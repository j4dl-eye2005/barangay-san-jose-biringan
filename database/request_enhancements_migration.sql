-- Apply once after keep_but_change_migration.sql and the other request/payment migrations, before deploying the request workflow updates.
ALTER TABLE document_request
    ADD COLUMN request_for_other TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN owner_first_name VARCHAR(100) DEFAULT NULL,
    ADD COLUMN owner_middle_name VARCHAR(100) DEFAULT NULL,
    ADD COLUMN owner_last_name VARCHAR(100) DEFAULT NULL,
    ADD COLUMN owner_suffix VARCHAR(20) DEFAULT NULL,
    ADD COLUMN owner_date_of_birth DATE DEFAULT NULL,
    ADD COLUMN owner_address VARCHAR(255) DEFAULT NULL,
    ADD COLUMN owner_relationship VARCHAR(30) DEFAULT NULL,
    ADD COLUMN representation_consent_at DATETIME DEFAULT NULL,
    ADD COLUMN owner_id_checked_at DATETIME DEFAULT NULL,
    ADD COLUMN owner_id_checked_by_user_id INT DEFAULT NULL,
    ADD COLUMN pickup_date DATE DEFAULT NULL,
    ADD COLUMN pickup_time TIME DEFAULT NULL,
    ADD COLUMN pickup_deadline_at DATETIME DEFAULT NULL,
    ADD INDEX idx_request_pickup_deadline (current_status, pickup_deadline_at),
    ADD CONSTRAINT fk_request_owner_id_checker FOREIGN KEY (owner_id_checked_by_user_id)
        REFERENCES `user` (user_id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS request_cancellation_log (
    cancellation_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL UNIQUE,
    resident_id INT NOT NULL,
    reason VARCHAR(40) NOT NULL,
    notes TEXT DEFAULT NULL,
    cooldown_seconds INT UNSIGNED NOT NULL,
    cooldown_until DATETIME NOT NULL,
    cancelled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cancellation_resident_created (resident_id, cancelled_at),
    CONSTRAINT fk_cancellation_request FOREIGN KEY (request_id) REFERENCES document_request (request_id) ON DELETE CASCADE,
    CONSTRAINT fk_cancellation_resident FOREIGN KEY (resident_id) REFERENCES resident (resident_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS resident_request_cooldown (
    resident_id INT PRIMARY KEY,
    latest_cancellation_id BIGINT DEFAULT NULL,
    cooldown_until DATETIME NOT NULL,
    lifted_at DATETIME DEFAULT NULL,
    lifted_by_user_id INT DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_request_cooldown_resident FOREIGN KEY (resident_id) REFERENCES resident (resident_id) ON DELETE CASCADE,
    CONSTRAINT fk_request_cooldown_cancellation FOREIGN KEY (latest_cancellation_id) REFERENCES request_cancellation_log (cancellation_id) ON DELETE SET NULL,
    CONSTRAINT fk_request_cooldown_lifter FOREIGN KEY (lifted_by_user_id) REFERENCES `user` (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pickup_reschedule_request (
    reschedule_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    resident_id INT NOT NULL,
    original_pickup_date DATE NOT NULL,
    requested_pickup_date DATE NOT NULL,
    reason VARCHAR(2000) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'Pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME DEFAULT NULL,
    reviewed_by_user_id INT DEFAULT NULL,
    review_notes VARCHAR(1000) DEFAULT NULL,
    INDEX idx_reschedule_status_created (status, created_at),
    INDEX idx_reschedule_request_created (request_id, created_at),
    CONSTRAINT fk_reschedule_request FOREIGN KEY (request_id) REFERENCES document_request (request_id) ON DELETE CASCADE,
    CONSTRAINT fk_reschedule_resident FOREIGN KEY (resident_id) REFERENCES resident (resident_id) ON DELETE CASCADE,
    CONSTRAINT fk_reschedule_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES `user` (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Backfill pickup dates from prior status notes where possible. Older records
-- without a recognizable date remain null and do not expire automatically.
UPDATE document_request dr
JOIN request_status_log rsl ON rsl.request_id = dr.request_id AND rsl.status = 'Ready for Pickup'
SET dr.pickup_date = COALESCE(
    STR_TO_DATE(REGEXP_SUBSTR(rsl.notes, '[0-9]{4}-[0-9]{2}-[0-9]{2}'), '%Y-%m-%d'),
    STR_TO_DATE(REGEXP_SUBSTR(rsl.notes, '[A-Za-z]+ [0-9]{1,2}, [0-9]{4}'), '%M %e, %Y'),
    DATE(rsl.created_at)
)
WHERE dr.pickup_date IS NULL
  AND rsl.log_id = (SELECT MAX(last_log.log_id) FROM request_status_log last_log WHERE last_log.request_id = dr.request_id AND last_log.status = 'Ready for Pickup');

UPDATE document_request dr
JOIN request_status_log rsl ON rsl.request_id = dr.request_id AND rsl.status = 'Ready for Pickup'
SET dr.pickup_deadline_at = DATE_ADD(rsl.created_at, INTERVAL 7 DAY)
WHERE dr.current_status = 'Ready for Pickup'
  AND dr.pickup_deadline_at IS NULL
  AND rsl.log_id = (SELECT MAX(last_log.log_id) FROM request_status_log last_log WHERE last_log.request_id = dr.request_id AND last_log.status = 'Ready for Pickup');
