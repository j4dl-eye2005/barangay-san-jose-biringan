-- Apply once to an existing barangay_portal database before deploying the
-- staff access, resident profile, business assessment, and document workflow.
ALTER TABLE `user`
    ADD COLUMN staff_role VARCHAR(30) NOT NULL DEFAULT 'service_clerk';

-- Preserve current administrator access while ensuring future accounts start
-- with the least-privileged operational role.
UPDATE `user` SET staff_role = 'super_admin' WHERE role = 'Admin';

CREATE TABLE IF NOT EXISTS barangay_staff (
    staff_id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(180) NOT NULL,
    position_title VARCHAR(120) NOT NULL,
    staff_kind VARCHAR(20) NOT NULL DEFAULT 'staff',
    contact_number VARCHAR(30) DEFAULT NULL,
    email VARCHAR(190) DEFAULT NULL,
    portal_user_id INT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_by_user_id INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_barangay_staff_portal_user (portal_user_id),
    INDEX idx_barangay_staff_active (is_active, staff_kind, full_name),
    CONSTRAINT fk_barangay_staff_portal_user FOREIGN KEY (portal_user_id) REFERENCES `user` (user_id) ON DELETE SET NULL,
    CONSTRAINT fk_barangay_staff_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES `user` (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE resident
    ADD COLUMN suffix VARCHAR(20) DEFAULT NULL,
    ADD COLUMN date_of_birth DATE DEFAULT NULL,
    ADD COLUMN civil_status VARCHAR(30) DEFAULT NULL,
    ADD COLUMN gender VARCHAR(40) DEFAULT NULL;

ALTER TABLE document_request
    ADD COLUMN assessed_fee DECIMAL(10,2) DEFAULT NULL,
    ADD COLUMN business_name VARCHAR(180) DEFAULT NULL,
    ADD COLUMN business_category VARCHAR(20) DEFAULT NULL,
    ADD COLUMN business_address VARCHAR(255) DEFAULT NULL,
    ADD COLUMN business_activity VARCHAR(180) DEFAULT NULL;

-- Preserve the amount already applied to an older paid business request before
-- removing the old catalog default. Unpaid business requests remain unassessed.
UPDATE document_request dr
JOIN document_type dt ON dt.document_type_id = dr.document_type_id
SET dr.assessed_fee = dt.fee_amount
WHERE dr.assessed_fee IS NULL
  AND dr.payment_status = 'Paid'
  AND LOWER(TRIM(dt.name)) IN ('business permit', 'barangay business clearance');

-- Snapshot current non-business rates so later changes to the catalog do not
-- alter an existing resident's request. New business rates are assessed by staff.
UPDATE document_type SET fee_amount = NULL
WHERE LOWER(TRIM(name)) IN ('business permit', 'barangay business clearance');

UPDATE document_request dr
JOIN document_type dt ON dt.document_type_id = dr.document_type_id
SET dr.assessed_fee = dt.fee_amount
WHERE dr.assessed_fee IS NULL
  AND LOWER(TRIM(dt.name)) NOT IN ('business permit', 'barangay business clearance');

CREATE TABLE IF NOT EXISTS request_payment_transaction (
    payment_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    method VARCHAR(24) NOT NULL,
    reference VARCHAR(120) DEFAULT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'confirmed',
    recorded_by_user_id INT DEFAULT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reversed_by_user_id INT DEFAULT NULL,
    reversed_at DATETIME DEFAULT NULL,
    INDEX idx_payment_request_recorded (request_id, recorded_at, payment_id),
    INDEX idx_payment_recorded_at (recorded_at, status),
    CONSTRAINT fk_payment_tx_request FOREIGN KEY (request_id) REFERENCES document_request (request_id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_tx_recorder FOREIGN KEY (recorded_by_user_id) REFERENCES `user` (user_id) ON DELETE SET NULL,
    CONSTRAINT fk_payment_tx_reverser FOREIGN KEY (reversed_by_user_id) REFERENCES `user` (user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Preserve older completed payment statuses in the new ledger without
-- misclassifying their channel. They are visibly marked as legacy entries.
INSERT INTO request_payment_transaction (request_id, amount, method, reference, status, recorded_by_user_id, recorded_at)
SELECT dr.request_id, COALESCE(dr.assessed_fee, dt.fee_amount, 0), 'legacy', dr.payment_reference, 'confirmed', dr.payment_reviewed_by_user_id,
       COALESCE(dr.payment_reviewed_at, dr.created_at)
FROM document_request dr
JOIN document_type dt ON dt.document_type_id = dr.document_type_id
WHERE dr.payment_status = 'Paid'
  AND NOT EXISTS (SELECT 1 FROM request_payment_transaction rpt WHERE rpt.request_id = dr.request_id);

CREATE TABLE IF NOT EXISTS document_issuance (
    issuance_id BIGINT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    document_number VARCHAR(40) NOT NULL UNIQUE,
    content_hash CHAR(64) NOT NULL,
    issued_by_user_id INT NOT NULL,
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_issuance_request (request_id, issued_at),
    CONSTRAINT fk_issuance_request FOREIGN KEY (request_id) REFERENCES document_request (request_id) ON DELETE CASCADE,
    CONSTRAINT fk_issuance_user FOREIGN KEY (issued_by_user_id) REFERENCES `user` (user_id) ON DELETE RESTRICT
) ENGINE=InnoDB;
