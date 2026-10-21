-- Run once on an existing barangay_portal database if resident.archived_at is missing.
-- This adds the nullable archive marker expected by the current login and account APIs.
ALTER TABLE resident
    ADD COLUMN archived_at DATETIME DEFAULT NULL;
