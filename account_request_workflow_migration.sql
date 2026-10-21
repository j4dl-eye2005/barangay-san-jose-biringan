-- Run once on an existing portal database before deploying the updated PHP.
CREATE TABLE IF NOT EXISTS portal_maintenance (
    maintenance_key VARCHAR(80) PRIMARY KEY,
    last_run_at DATETIME NOT NULL
) ENGINE=InnoDB;

-- The supplied cPanel export already has resident.archived_at,
-- idx_resident_archive, is_active DEFAULT 1, and portal_maintenance.
-- Do not repeat those setup steps against that database.

-- Drop and recreate this foreign key in separate statements. MySQL/MariaDB can
-- report errno 121 if a constraint is dropped and re-added under the same name
-- within one ALTER TABLE statement.
ALTER TABLE document_request
    DROP FOREIGN KEY fk_request_resident;

ALTER TABLE document_request
    MODIFY resident_id INT DEFAULT NULL,
    ADD COLUMN request_source VARCHAR(20) NOT NULL DEFAULT 'online' AFTER purpose,
    ADD COLUMN walk_in_name VARCHAR(200) DEFAULT NULL AFTER request_source,
    ADD COLUMN walk_in_phone VARCHAR(30) DEFAULT NULL AFTER walk_in_name,
    ADD COLUMN walk_in_address VARCHAR(255) DEFAULT NULL AFTER walk_in_phone,
    ADD COLUMN proof_path VARCHAR(500) DEFAULT NULL AFTER walk_in_address,
    ADD COLUMN proof_type VARCHAR(40) DEFAULT NULL AFTER proof_path,
    ADD CONSTRAINT fk_request_resident FOREIGN KEY (resident_id)
        REFERENCES resident (resident_id) ON DELETE SET NULL;

-- Existing inactive accounts were awaiting admin residency approval. Activate
-- them for sign-in; email verification remains a separate request-submission gate.
UPDATE resident SET is_active = 1 WHERE is_active = 0 AND archived_at IS NULL;
