<?php
require_once 'db.php';
require_once __DIR__ . '/request_workflow.php';
header('Content-Type: application/json');

requireAdminAccess('requests.view');
expireReadyRequests($pdo, (int) $_SESSION['user_id']);

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$requestId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid request ID is required']);
    exit;
}

$paymentStatusColumn = $pdo->query("SHOW COLUMNS FROM document_request LIKE 'payment_status'")->fetch() ? 'dr.payment_status' : "'Unpaid' AS payment_status";
$stmt = $pdo->prepare("SELECT dr.request_id, dr.tracking_code, dr.purpose, dr.current_status, " . $paymentStatusColumn . ", dr.payment_reference, dr.payment_submitted_at, dr.payment_rejection_reason,
        CASE WHEN dr.payment_proof_path IS NULL THEN NULL ELSE CONCAT('api/payment_proof.php?id=', dr.request_id) END AS payment_proof_url,
        dr.rejection_reason, dr.created_at, dr.request_source, dr.walk_in_name, dr.walk_in_phone, dr.walk_in_address, dr.proof_type,
        dr.request_for_other, dr.owner_first_name, dr.owner_middle_name, dr.owner_last_name, dr.owner_suffix, dr.owner_date_of_birth,
        dr.owner_address, dr.owner_relationship, dr.owner_id_checked_at, dr.pickup_date, dr.pickup_time, dr.pickup_deadline_at,
        CASE WHEN dr.request_source = 'online' AND dr.proof_path IS NOT NULL THEN CONCAT('api/request_proof.php?id=', dr.request_id) ELSE NULL END AS proof_url,
        CASE WHEN dr.proof_path IS NULL THEN NULL ELSE LOWER(SUBSTRING_INDEX(dr.proof_path, '.', -1)) END AS proof_format,
        dt.name AS document_type, COALESCE(dr.assessed_fee, dt.fee_amount) AS fee_amount, dr.assessed_fee, dr.business_name, dr.business_category, dr.business_address, dr.business_activity, r.resident_id, COALESCE(r.first_name, dr.walk_in_name) AS first_name, r.middle_name, r.last_name, r.suffix, r.date_of_birth, r.civil_status, r.gender, r.email, COALESCE(r.phone_number, dr.walk_in_phone) AS phone_number, COALESCE(r.address, dr.walk_in_address) AS address,
        (SELECT rpt.method FROM request_payment_transaction rpt WHERE rpt.request_id = dr.request_id AND rpt.status = 'confirmed' ORDER BY rpt.recorded_at DESC, rpt.payment_id DESC LIMIT 1) AS latest_payment_method,
        rrc.cooldown_until AS resident_request_cooldown_until,
        CASE WHEN rrc.cooldown_until > UTC_TIMESTAMP() AND rrc.lifted_at IS NULL THEN 1 ELSE 0 END AS resident_request_cooldown_active
    FROM document_request dr
    JOIN document_type dt ON dt.document_type_id = dr.document_type_id
    LEFT JOIN resident r ON r.resident_id = dr.resident_id
    LEFT JOIN resident_request_cooldown rrc ON rrc.resident_id = r.resident_id
    WHERE dr.request_id = ?");
$stmt->execute([$requestId]);
$request = $stmt->fetch();
if (!$request) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Request not found']);
    exit;
}

$staffRoleStmt = $pdo->prepare("SELECT staff_role FROM `user` WHERE user_id = ? AND role = 'Admin' LIMIT 1");
$staffRoleStmt->execute([(int) $_SESSION['user_id']]);
$staffRole = (string) ($staffRoleStmt->fetchColumn() ?: '');
if (!in_array($staffRole, ['super_admin', 'cashier'], true)) {
    foreach (['payment_reference', 'payment_submitted_at', 'payment_rejection_reason', 'payment_proof_url', 'latest_payment_method'] as $financialField) {
        $request[$financialField] = null;
    }
}
if ($staffRole === 'document_issuer') {
    foreach (['resident_id', 'walk_in_name', 'walk_in_phone', 'walk_in_address', 'purpose', 'proof_type', 'proof_url', 'proof_format', 'email', 'phone_number', 'address', 'date_of_birth', 'civil_status', 'gender', 'business_name', 'business_category', 'business_address', 'business_activity', 'rejection_reason', 'request_for_other', 'owner_first_name', 'owner_middle_name', 'owner_last_name', 'owner_suffix', 'owner_date_of_birth', 'owner_address', 'owner_relationship', 'owner_id_checked_at', 'resident_request_cooldown_until', 'resident_request_cooldown_active'] as $privateField) {
        $request[$privateField] = null;
    }
}

$pendingReschedule = null;
if (in_array($staffRole, ['super_admin', 'service_clerk'], true)) {
    $reschedule = $pdo->prepare("SELECT reschedule_id, original_pickup_date, requested_pickup_date, reason, created_at
        FROM pickup_reschedule_request WHERE request_id = ? AND status = 'Pending'
        ORDER BY created_at DESC, reschedule_id DESC LIMIT 1");
    $reschedule->execute([(int) $requestId]);
    $pendingReschedule = $reschedule->fetch(PDO::FETCH_ASSOC) ?: null;
}

$viewAudit = $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, 'request_viewed', ?)");
$viewAudit->execute([$_SESSION['user_id'], 'Request ID ' . $requestId]);

$history = $pdo->prepare("SELECT rsl.status, rsl.notes, rsl.created_at, rsl.updated_by_user_id,
        CASE WHEN u.user_id IS NULL THEN NULL ELSE CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name) END AS admin_name
    FROM request_status_log rsl
    LEFT JOIN user u ON u.user_id = rsl.updated_by_user_id
    WHERE rsl.request_id = ? ORDER BY rsl.created_at ASC, rsl.log_id ASC");
$history->execute([$requestId]);
$timeline = $history->fetchAll();

$note = $pdo->prepare('SELECT note_text, updated_at FROM request_internal_note WHERE request_id = ?');
$note->execute([$requestId]);
$internalNote = $note->fetch() ?: null;

if ($staffRole === 'document_issuer') {
    foreach ($timeline as &$step) {
        $step['notes'] = null;
        $step['admin_name'] = null;
    }
    unset($step);
    $internalNote = null;
}

echo json_encode(['success' => true, 'request' => $request, 'timeline' => $timeline, 'internal_note' => $internalNote, 'pending_reschedule' => $pendingReschedule]);
?>
