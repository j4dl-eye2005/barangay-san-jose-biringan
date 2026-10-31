-- LOCAL DEMO DATA ONLY. Run after schema.sql and request_enhancements_migration.sql.
-- Creates a non-login placeholder resident and one request for each workflow state.
-- Do not run this file against production data.

START TRANSACTION;

INSERT INTO resident (first_name, last_name, email, password_hash, email_verified_at)
VALUES ('Demo', 'Workflow', 'workflow-demo@invalid.example', CONCAT('DISABLED-DEMO-LOGIN-', UUID()), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE resident_id = LAST_INSERT_ID(resident_id);
SET @workflow_demo_resident_id = LAST_INSERT_ID();
SELECT document_type_id INTO @workflow_demo_document_type_id
FROM document_type WHERE is_active = 1 ORDER BY document_type_id LIMIT 1;
SET @workflow_demo_pickup_date = CASE
    WHEN WEEKDAY(CURDATE()) = 4 THEN DATE_ADD(CURDATE(), INTERVAL 3 DAY)
    WHEN WEEKDAY(CURDATE()) = 5 THEN DATE_ADD(CURDATE(), INTERVAL 2 DAY)
    WHEN WEEKDAY(CURDATE()) = 6 THEN DATE_ADD(CURDATE(), INTERVAL 1 DAY)
    ELSE DATE_ADD(CURDATE(), INTERVAL 1 DAY)
END;
SET @workflow_demo_reschedule_date = CASE
    WHEN WEEKDAY(@workflow_demo_pickup_date) = 2 THEN DATE_ADD(@workflow_demo_pickup_date, INTERVAL 2 DAY)
    WHEN WEEKDAY(@workflow_demo_pickup_date) = 3 THEN DATE_ADD(@workflow_demo_pickup_date, INTERVAL 4 DAY)
    ELSE DATE_ADD(@workflow_demo_pickup_date, INTERVAL 3 DAY)
END;

INSERT INTO document_request (tracking_code, resident_id, document_type_id, purpose, request_source, current_status, payment_status, assessed_fee, created_at)
VALUES ('DEMO-WF-PENDING', @workflow_demo_resident_id, @workflow_demo_document_type_id, 'Local workflow demonstration', 'online', 'Pending', 'Not Required', 0, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE request_id = LAST_INSERT_ID(request_id);
SET @workflow_demo_pending_id = LAST_INSERT_ID();

INSERT INTO document_request (tracking_code, resident_id, document_type_id, purpose, request_source, current_status, payment_status, assessed_fee, created_at)
VALUES ('DEMO-WF-APPROVED', @workflow_demo_resident_id, @workflow_demo_document_type_id, 'Local workflow demonstration', 'online', 'Approved', 'Not Required', 0, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE request_id = LAST_INSERT_ID(request_id);
SET @workflow_demo_approved_id = LAST_INSERT_ID();

INSERT INTO document_request (tracking_code, resident_id, document_type_id, purpose, request_source, current_status, payment_status, assessed_fee, pickup_date, pickup_time, pickup_deadline_at, request_for_other, owner_first_name, owner_last_name, owner_date_of_birth, owner_address, owner_relationship, representation_consent_at, created_at)
VALUES ('DEMO-WF-READY', @workflow_demo_resident_id, @workflow_demo_document_type_id, 'Local workflow demonstration', 'online', 'Ready for Pickup', 'Not Required', 0, @workflow_demo_pickup_date, '09:00:00', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY), 1, 'Demo', 'Owner', '1990-01-01', '10 Rizal Street, Purok 1, Barangay San Jose', 'other', UTC_TIMESTAMP(), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE request_id = LAST_INSERT_ID(request_id);
SET @workflow_demo_ready_id = LAST_INSERT_ID();

INSERT INTO document_request (tracking_code, resident_id, document_type_id, purpose, request_source, current_status, payment_status, assessed_fee, pickup_date, pickup_time, pickup_deadline_at, created_at)
VALUES ('DEMO-WF-COMPLETED', @workflow_demo_resident_id, @workflow_demo_document_type_id, 'Local workflow demonstration', 'online', 'Completed', 'Not Required', 0, @workflow_demo_pickup_date, '09:00:00', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE request_id = LAST_INSERT_ID(request_id);
SET @workflow_demo_completed_id = LAST_INSERT_ID();

INSERT INTO document_request (tracking_code, resident_id, document_type_id, purpose, request_source, current_status, payment_status, assessed_fee, created_at)
VALUES ('DEMO-WF-CANCELLED', @workflow_demo_resident_id, @workflow_demo_document_type_id, 'Local workflow demonstration', 'online', 'Cancelled', 'Not Required', 0, UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE request_id = LAST_INSERT_ID(request_id);
SET @workflow_demo_cancelled_id = LAST_INSERT_ID();

INSERT INTO document_request (tracking_code, resident_id, document_type_id, purpose, request_source, current_status, payment_status, assessed_fee, rejection_reason, created_at)
VALUES ('DEMO-WF-REJECTED', @workflow_demo_resident_id, @workflow_demo_document_type_id, 'Local workflow demonstration', 'online', 'Rejected', 'Not Required', 0, 'Demo rejection reason', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE request_id = LAST_INSERT_ID(request_id);
SET @workflow_demo_rejected_id = LAST_INSERT_ID();

INSERT INTO document_request (tracking_code, resident_id, document_type_id, purpose, request_source, current_status, payment_status, assessed_fee, pickup_date, pickup_time, pickup_deadline_at, created_at)
VALUES ('DEMO-WF-RESCHEDULE', @workflow_demo_resident_id, @workflow_demo_document_type_id, 'Local workflow demonstration', 'online', 'Reschedule Requested', 'Not Required', 0, @workflow_demo_pickup_date, '09:00:00', DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE request_id = LAST_INSERT_ID(request_id);
SET @workflow_demo_reschedule_id = LAST_INSERT_ID();

INSERT INTO document_request (tracking_code, resident_id, document_type_id, purpose, request_source, current_status, payment_status, assessed_fee, pickup_date, pickup_time, pickup_deadline_at, created_at)
VALUES ('DEMO-WF-EXPIRED', @workflow_demo_resident_id, @workflow_demo_document_type_id, 'Local workflow demonstration', 'online', 'Expired', 'Not Required', 0, DATE_SUB(CURDATE(), INTERVAL 8 DAY), '09:00:00', DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE request_id = LAST_INSERT_ID(request_id);
SET @workflow_demo_expired_id = LAST_INSERT_ID();

INSERT INTO request_status_log (request_id, status, notes)
SELECT sample.request_id, sample.status, sample.notes
FROM (
    SELECT @workflow_demo_pending_id AS request_id, 'Pending' AS status, 'Demo request submitted.' AS notes
    UNION ALL SELECT @workflow_demo_approved_id, 'Pending', 'Demo request submitted.'
    UNION ALL SELECT @workflow_demo_approved_id, 'Approved', 'Demo request approved.'
    UNION ALL SELECT @workflow_demo_ready_id, 'Pending', 'Demo request submitted.'
    UNION ALL SELECT @workflow_demo_ready_id, 'Approved', 'Demo request approved.'
    UNION ALL SELECT @workflow_demo_ready_id, 'Ready for Pickup', CONCAT('Pickup: ', DATE_FORMAT(@workflow_demo_pickup_date, '%Y-%m-%d'), ' at 9:00 AM')
    UNION ALL SELECT @workflow_demo_completed_id, 'Pending', 'Demo request submitted.'
    UNION ALL SELECT @workflow_demo_completed_id, 'Approved', 'Demo request approved.'
    UNION ALL SELECT @workflow_demo_completed_id, 'Ready for Pickup', CONCAT('Pickup: ', DATE_FORMAT(@workflow_demo_pickup_date, '%Y-%m-%d'), ' at 9:00 AM')
    UNION ALL SELECT @workflow_demo_completed_id, 'Completed', 'Document collected in demo.'
    UNION ALL SELECT @workflow_demo_cancelled_id, 'Pending', 'Demo request submitted.'
    UNION ALL SELECT @workflow_demo_cancelled_id, 'Cancelled', 'Demo cancellation.'
    UNION ALL SELECT @workflow_demo_rejected_id, 'Pending', 'Demo request submitted.'
    UNION ALL SELECT @workflow_demo_rejected_id, 'Rejected', 'Demo rejection reason.'
    UNION ALL SELECT @workflow_demo_reschedule_id, 'Pending', 'Demo request submitted.'
    UNION ALL SELECT @workflow_demo_reschedule_id, 'Approved', 'Demo request approved.'
    UNION ALL SELECT @workflow_demo_reschedule_id, 'Ready for Pickup', CONCAT('Pickup: ', DATE_FORMAT(@workflow_demo_pickup_date, '%Y-%m-%d'), ' at 9:00 AM')
    UNION ALL SELECT @workflow_demo_reschedule_id, 'Reschedule Requested', 'Demo pickup reschedule needs review.'
    UNION ALL SELECT @workflow_demo_expired_id, 'Pending', 'Demo request submitted.'
    UNION ALL SELECT @workflow_demo_expired_id, 'Approved', 'Demo request approved.'
    UNION ALL SELECT @workflow_demo_expired_id, 'Ready for Pickup', 'Demo pickup deadline passed.'
    UNION ALL SELECT @workflow_demo_expired_id, 'Expired', 'Demo request expired.'
) AS sample
WHERE NOT EXISTS (
    SELECT 1 FROM request_status_log old_log
    WHERE old_log.request_id = sample.request_id AND old_log.status = sample.status
);

INSERT INTO pickup_reschedule_request (request_id, resident_id, original_pickup_date, requested_pickup_date, reason, status, created_at)
SELECT @workflow_demo_reschedule_id, @workflow_demo_resident_id, @workflow_demo_pickup_date,
       @workflow_demo_reschedule_date, 'Demo reason: schedule conflict.', 'Pending', UTC_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM pickup_reschedule_request WHERE request_id = @workflow_demo_reschedule_id AND status = 'Pending');

INSERT INTO request_cancellation_log (request_id, resident_id, reason, notes, cooldown_seconds, cooldown_until, cancelled_at)
SELECT @workflow_demo_cancelled_id, @workflow_demo_resident_id, 'No longer needed', NULL, 180,
       DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 MINUTE), UTC_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM request_cancellation_log WHERE request_id = @workflow_demo_cancelled_id);

INSERT INTO resident_request_cooldown (resident_id, latest_cancellation_id, cooldown_until, lifted_at, lifted_by_user_id)
SELECT resident_id, cancellation_id, cooldown_until, NULL, NULL
FROM request_cancellation_log WHERE request_id = @workflow_demo_cancelled_id
ON DUPLICATE KEY UPDATE latest_cancellation_id = VALUES(latest_cancellation_id), cooldown_until = VALUES(cooldown_until), lifted_at = NULL, lifted_by_user_id = NULL;

INSERT INTO audit_log (user_id, subject_resident_id, action, details)
SELECT NULL, @workflow_demo_resident_id, 'resident_request_cancelled',
       CONCAT('request_id=', @workflow_demo_cancelled_id, '; resident_id=', @workflow_demo_resident_id, '; reason=No longer needed; demo fixture')
WHERE NOT EXISTS (SELECT 1 FROM audit_log WHERE action = 'resident_request_cancelled' AND details LIKE CONCAT('%request_id=', @workflow_demo_cancelled_id, ';%'));

COMMIT;
