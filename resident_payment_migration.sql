-- Run once in the barangay_portal database for an existing installation.
ALTER TABLE document_request
    ADD COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'Unpaid';
