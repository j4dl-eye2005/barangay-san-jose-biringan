-- Run once in the barangay_portal database before deploying payment review.
ALTER TABLE document_type
    ADD COLUMN fee_amount DECIMAL(10,2) DEFAULT NULL;

UPDATE document_type
SET fee_amount = CASE
    WHEN LOWER(TRIM(name)) = 'certificate of indigency' THEN 0.00
    WHEN LOWER(TRIM(name)) = 'business permit' THEN 200.00
    WHEN LOWER(TRIM(name)) IN ('barangay clearance', 'certificate of residency') THEN 50.00
    ELSE fee_amount
END;

ALTER TABLE document_request
    MODIFY COLUMN payment_status VARCHAR(24) NOT NULL DEFAULT 'Unpaid',
    ADD COLUMN payment_reference VARCHAR(120) DEFAULT NULL,
    ADD COLUMN payment_proof_path VARCHAR(500) DEFAULT NULL,
    ADD COLUMN payment_submitted_at DATETIME DEFAULT NULL,
    ADD COLUMN payment_reviewed_at DATETIME DEFAULT NULL,
    ADD COLUMN payment_reviewed_by_user_id INT DEFAULT NULL,
    ADD COLUMN payment_rejection_reason VARCHAR(1000) DEFAULT NULL,
    ADD INDEX idx_requests_payment_status_created (payment_status, created_at, request_id),
    ADD CONSTRAINT fk_request_payment_reviewer FOREIGN KEY (payment_reviewed_by_user_id)
        REFERENCES user (user_id) ON DELETE SET NULL;

UPDATE document_request dr
JOIN document_type dt ON dt.document_type_id = dr.document_type_id
SET dr.payment_status = 'Not Required',
    dr.payment_reference = NULL,
    dr.payment_proof_path = NULL,
    dr.payment_submitted_at = NULL,
    dr.payment_reviewed_at = NULL,
    dr.payment_reviewed_by_user_id = NULL,
    dr.payment_rejection_reason = NULL
WHERE dt.fee_amount = 0;
