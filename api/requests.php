<?php
require_once 'db.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/request_workflow.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['Admin', 'Resident'], true)) {
        if (!empty($GLOBALS['adminEntryDeniedThisRequest'])) denyAdminEntryAccess(true);
        http_response_code(401); echo json_encode(['success' => false, 'message' => 'Authentication required']); exit;
    }
    
    if ($_SESSION['role'] === 'Admin') {
        requireAdminAccess('requests.view');
        expireReadyRequests($pdo, (int) $_SESSION['user_id']);
        $page = max(1, min(1000, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;
        $status = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'All';
        $allowedStatuses = ['All', 'Pending', 'Approved', 'Ready for Pickup', 'Reschedule Requested', 'Completed', 'Rejected', 'Cancelled', 'Expired'];
        if (!in_array($status, $allowedStatuses, true)) $status = 'All';
        $paymentStatus = is_string($_GET['payment'] ?? null) ? $_GET['payment'] : '';
        $allowedPaymentStatuses = ['', 'Unpaid', 'Pending Verification', 'Paid', 'Rejected', 'Not Required'];
        if (!in_array($paymentStatus, $allowedPaymentStatuses, true)) $paymentStatus = '';
        $search = trim(is_string($_GET['search'] ?? null) ? $_GET['search'] : '');
        if (strlen($search) > 100) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Search text is too long.']);
            exit;
        }
        $where = [];
        $params = [];
        if ($status !== 'All') { $where[] = 'dr.current_status = ?'; $params[] = $status; }
        if ($paymentStatus !== '') { $where[] = 'dr.payment_status = ?'; $params[] = $paymentStatus; }
        if ($search !== '') {
            $where[] = '(dr.tracking_code LIKE ? OR COALESCE(NULLIF(CONCAT_WS(\' \', r.first_name, r.last_name), \'\'), dr.walk_in_name, \'\') LIKE ?)';
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $counts = $pdo->query("SELECT current_status, COUNT(*) AS total FROM document_request GROUP BY current_status")->fetchAll();
        $statusCounts = ['All' => 0];
        foreach ($counts as $count) {
            $statusCounts[(string) $count['current_status']] = (int) $count['total'];
            $statusCounts['All'] += (int) $count['total'];
        }
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM document_request dr LEFT JOIN resident r ON r.resident_id = dr.resident_id $whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $stmt = $pdo->prepare("
            SELECT dr.request_id, dr.tracking_code, dr.resident_id, dr.document_type_id, dr.purpose,
                   dr.current_status, dr.payment_status, dr.payment_reference, dr.payment_submitted_at,
                   dr.payment_rejection_reason, dr.rejection_reason, dr.created_at, dr.request_source, dr.proof_type,
                   dr.request_for_other, dr.pickup_date, dr.pickup_time, dr.pickup_deadline_at,
                   CASE WHEN dr.request_for_other = 1 THEN CONCAT_WS(' ', dr.owner_first_name, dr.owner_middle_name, dr.owner_last_name, dr.owner_suffix) ELSE NULL END AS documentOwnerName,
                   CASE WHEN dr.request_source = 'online' AND dr.proof_path IS NOT NULL THEN CONCAT('api/request_proof.php?id=', dr.request_id) ELSE NULL END AS proof_url,
                   CASE WHEN dr.proof_path IS NULL THEN NULL ELSE LOWER(SUBSTRING_INDEX(dr.proof_path, '.', -1)) END AS proof_format,
                   dt.name AS documentType, COALESCE(dr.assessed_fee, dt.fee_amount) AS fee_amount,
                   (SELECT rsl.notes FROM request_status_log rsl
                    WHERE rsl.request_id = dr.request_id AND rsl.status = 'Ready for Pickup'
                    ORDER BY rsl.created_at DESC, rsl.log_id DESC LIMIT 1) AS pickupDetails,
                   COALESCE(NULLIF(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name, r.suffix), ''), dr.walk_in_name) AS residentName,
                   r.email AS residentEmail, COALESCE(r.phone_number, dr.walk_in_phone) AS residentPhone,
                   COALESCE(r.address, dr.walk_in_address) AS residentAddress
            FROM document_request dr
            JOIN document_type dt ON dr.document_type_id = dt.document_type_id
            LEFT JOIN resident r ON dr.resident_id = r.resident_id
            $whereSql
            ORDER BY dr.created_at DESC, dr.request_id DESC
            LIMIT $perPage OFFSET $offset
        ");
        $stmt->execute($params);
    } elseif ($_SESSION['role'] === 'Resident' && isset($_SESSION['resident_id'])) {
        expireReadyRequests($pdo);
        $stmt = $pdo->prepare("
            SELECT dr.request_id, dr.tracking_code, dr.resident_id, dr.document_type_id, dr.purpose,
                   dr.current_status, dr.payment_status, dr.payment_reference, dr.payment_submitted_at,
                   dr.payment_rejection_reason, dr.rejection_reason, dr.created_at, dr.request_for_other,
                   dr.pickup_date, dr.pickup_time, dr.pickup_deadline_at,
                   CASE WHEN dr.request_for_other = 1 THEN CONCAT_WS(' ', dr.owner_first_name, dr.owner_middle_name, dr.owner_last_name, dr.owner_suffix) ELSE NULL END AS documentOwnerName,
                   (SELECT rcl.reason FROM request_cancellation_log rcl WHERE rcl.request_id = dr.request_id LIMIT 1) AS cancellationReason,
                   (SELECT rcl.notes FROM request_cancellation_log rcl WHERE rcl.request_id = dr.request_id LIMIT 1) AS cancellationNotes,
                   (SELECT rcl.cooldown_until FROM request_cancellation_log rcl WHERE rcl.request_id = dr.request_id LIMIT 1) AS cancellationCooldownUntil,
                   (SELECT COUNT(*) FROM pickup_reschedule_request prr WHERE prr.request_id = dr.request_id) AS rescheduleCount,
                   (SELECT prr.status FROM pickup_reschedule_request prr WHERE prr.request_id = dr.request_id ORDER BY prr.created_at DESC, prr.reschedule_id DESC LIMIT 1) AS latestRescheduleStatus,
                   dt.name AS documentType, COALESCE(dr.assessed_fee, dt.fee_amount) AS fee_amount,
                   (SELECT rsl.notes
                    FROM request_status_log rsl
                    WHERE rsl.request_id = dr.request_id
                      AND rsl.status = 'Ready for Pickup'
                    ORDER BY rsl.created_at DESC, rsl.log_id DESC
                    LIMIT 1) AS pickupDetails
            FROM document_request dr
            JOIN document_type dt ON dr.document_type_id = dt.document_type_id
            WHERE dr.resident_id = ?
            ORDER BY dr.created_at DESC
        ");
        $stmt->execute([$_SESSION['resident_id']]);
    } else {
        logPermissionFailure();
        http_response_code(403); echo json_encode(['success' => false, 'message' => 'Access denied']); exit;
    }
    if ($_SESSION['role'] === 'Admin') {
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $staffRoleStmt = $pdo->prepare("SELECT staff_role FROM `user` WHERE user_id = ? AND role = 'Admin' LIMIT 1");
        $staffRoleStmt->execute([(int) $_SESSION['user_id']]);
        $staffRole = (string) ($staffRoleStmt->fetchColumn() ?: '');
        if (!in_array($staffRole, ['super_admin', 'cashier'], true)) {
            foreach ($requests as &$request) {
                foreach (['payment_reference', 'payment_submitted_at', 'payment_rejection_reason'] as $financialField) {
                    $request[$financialField] = null;
                }
            }
            unset($request);
        }
        if ($staffRole === 'document_issuer') {
            foreach ($requests as &$request) {
                foreach (['resident_id', 'purpose', 'payment_reference', 'payment_submitted_at', 'payment_rejection_reason', 'rejection_reason', 'proof_type', 'proof_url', 'proof_format', 'residentEmail', 'residentPhone', 'residentAddress', 'documentOwnerName'] as $privateField) {
                    $request[$privateField] = null;
                }
            }
            unset($request);
        }
        echo json_encode(['success' => true, 'requests' => $requests, 'page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => (int) ceil($total / $perPage), 'status_counts' => $statusCounts]);
    } else {
        echo json_encode($stmt->fetchAll());
    }
} 

elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['resident_id']) || ($_SESSION['role'] ?? '') !== 'Resident') { logPermissionFailure(); http_response_code(403); echo json_encode(['success'=>false, 'message'=>'Resident access required']); exit; }
    $workflowConfig = requestWorkflowConfig();
    if (!rateLimit($pdo, 'document-request-ip', (int) $workflowConfig['request_rate_limit'], (int) $workflowConfig['request_rate_window_seconds'])
        || !rateLimit($pdo, 'document-request-resident', (int) $workflowConfig['request_rate_limit'], (int) $workflowConfig['request_rate_window_seconds'])) {
        http_response_code(429); header('Retry-After: 3600'); echo json_encode(['success'=>false, 'message'=>'Too many request submissions. Try again later.']); exit;
    }
    $isMultipart = str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'multipart/form-data');
    $data = $isMultipart ? $_POST : jsonBody(12000);
    $documentTypeId = filter_var($data['documentTypeId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $purpose = trim(is_string($data['purpose'] ?? null) ? $data['purpose'] : '');
    $purposeLength = function_exists('mb_strlen') ? mb_strlen($purpose, 'UTF-8') : strlen($purpose);
    $proofType = is_string($data['proofType'] ?? null) ? $data['proofType'] : '';
    $proof = $_FILES['proof'] ?? null;
    $extension = $proof ? validateUpload($proof, ['pdf' => true, 'jpg' => true, 'jpeg' => true, 'png' => true], 5 * 1024 * 1024) : null;
    if (!$documentTypeId || $purpose === '' || $purposeLength > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $purpose)) {
        http_response_code(400); echo json_encode(['success'=>false, 'message'=>'Enter a valid document type and purpose.']); exit;
    }
    if (!in_array($proofType, ['National ID', 'Certificate of Residency'], true) || $extension === null) {
        http_response_code(400); echo json_encode(['success'=>false, 'message'=>'Attach a valid National ID or Certificate of Residency (PDF, JPG, or PNG, up to 5 MB).']); exit;
    }
    $type = $pdo->prepare('SELECT document_type_id, name, fee_amount FROM document_type WHERE document_type_id = ? AND is_active = 1');
    $type->execute([$documentTypeId]);
    $documentType = $type->fetch();
    if (!$documentType) { http_response_code(400); echo json_encode(['success'=>false, 'message'=>'Choose an available document type.']); exit; }
    $isBusinessClearance = stripos((string) $documentType['name'], 'business') !== false;
    $businessName = trim(is_string($data['businessName'] ?? null) ? $data['businessName'] : '');
    $businessCategory = is_string($data['businessCategory'] ?? null) ? $data['businessCategory'] : '';
    $businessAddress = trim(is_string($data['businessAddress'] ?? null) ? $data['businessAddress'] : '');
    $businessActivity = trim(is_string($data['businessActivity'] ?? null) ? $data['businessActivity'] : '');
    if ($isBusinessClearance && ($businessName === '' || strlen($businessName) > 180
        || !in_array($businessCategory, ['solo', 'organization'], true)
        || $businessAddress === '' || strlen($businessAddress) > 255
        || $businessActivity === '' || strlen($businessActivity) > 180)) {
        http_response_code(400); echo json_encode(['success'=>false, 'message'=>'Enter the business name, type, address, and activity.']); exit;
    }
    $requestForOther = ($data['requestForOther'] ?? '0') === '1';
    $ownerFirstName = $ownerMiddleName = $ownerLastName = $ownerSuffix = $ownerBirthDate = $ownerAddress = $ownerRelationship = null;
    $representationConsent = false;
    if ($requestForOther) {
        $ownerFirstName = preg_replace('/\s+/u', ' ', trim(is_string($data['ownerFirstName'] ?? null) ? $data['ownerFirstName'] : '')) ?? '';
        $ownerMiddleName = preg_replace('/\s+/u', ' ', trim(is_string($data['ownerMiddleName'] ?? null) ? $data['ownerMiddleName'] : '')) ?? '';
        $ownerLastName = preg_replace('/\s+/u', ' ', trim(is_string($data['ownerLastName'] ?? null) ? $data['ownerLastName'] : '')) ?? '';
        $ownerSuffix = trim(is_string($data['ownerSuffix'] ?? null) ? $data['ownerSuffix'] : '');
        $ownerBirthDate = trim(is_string($data['ownerDateOfBirth'] ?? null) ? $data['ownerDateOfBirth'] : '');
        $ownerAddress = validateStructuredAddress($data, 'owner') ?? '';
        $ownerRelationship = is_string($data['ownerRelationship'] ?? null) ? $data['ownerRelationship'] : '';
        $representationConsent = ($data['representationConsent'] ?? '') === '1';
        $ownerDate = DateTimeImmutable::createFromFormat('!Y-m-d', $ownerBirthDate, new DateTimeZone('Asia/Manila'));
        $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
        $validOwnerBirthDate = $ownerDate instanceof DateTimeImmutable && $ownerDate->format('Y-m-d') === $ownerBirthDate
            && $ownerDate <= $today && $ownerDate >= $today->modify('-120 years');
        if (!validPersonName($ownerFirstName) || !validPersonName($ownerLastName)
            || ($ownerMiddleName !== '' && !validPersonName($ownerMiddleName, 100, 1))
            || !in_array($ownerSuffix, ['', 'Jr.', 'Sr.', 'II', 'III', 'IV'], true)
            || !$validOwnerBirthDate || $ownerAddress === ''
            || !in_array($ownerRelationship, ['parent', 'guardian', 'child', 'spouse', 'other'], true)
            || !$representationConsent) {
            http_response_code(400);
            echo json_encode(['success'=>false, 'message'=>'For a request on someone else’s behalf, enter their valid name, date of birth, address, your relationship, and confirm the accuracy of the information.']);
            exit;
        }
    }
    $proofDirectory = privateStorageDirectory('proofs');
    $proofName = bin2hex(random_bytes(16)) . '.' . $extension;
    $proofPath = 'proofs/' . $proofName;
    $trackingCode = 'REQ-' . strtoupper(bin2hex(random_bytes(8)));
    $residentId = (int) $_SESSION['resident_id'];
    try {
        $pdo->beginTransaction();
        $profile = $pdo->prepare('SELECT email_verified_at FROM resident WHERE resident_id = ? AND archived_at IS NULL FOR UPDATE');
        $profile->execute([$residentId]);
        if (!$profile->fetchColumn()) {
            $pdo->rollBack();
            http_response_code(403); echo json_encode(['success'=>false, 'message'=>'Verify your email before submitting a document request.']); exit;
        }
        $cooldown = $pdo->prepare('SELECT cooldown_until FROM resident_request_cooldown WHERE resident_id = ? AND lifted_at IS NULL AND cooldown_until > UTC_TIMESTAMP() LIMIT 1');
        $cooldown->execute([$residentId]);
        $cooldownUntil = $cooldown->fetchColumn();
        if ($cooldownUntil) {
            $pdo->rollBack();
            http_response_code(429);
            echo json_encode(['success'=>false, 'message'=>'New requests are paused until your cancellation cooldown ends.', 'cooldownUntil'=>$cooldownUntil]);
            exit;
        }
        $pending = $pdo->prepare("SELECT COUNT(*) FROM document_request WHERE resident_id = ? AND current_status = 'Pending'");
        $pending->execute([$residentId]);
        if ((int) $pending->fetchColumn() >= (int) $workflowConfig['pending_request_limit']) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success'=>false, 'message'=>'You can have no more than 3 pending requests at a time. Wait for a decision or cancel a pending request first.']);
            exit;
        }
        if (!move_uploaded_file((string) $proof['tmp_name'], $proofDirectory . DIRECTORY_SEPARATOR . $proofName)) {
            throw new RuntimeException('Unable to save request proof.');
        }
        $assessedFee = $isBusinessClearance ? null : $documentType['fee_amount'];
        $paymentStatus = $assessedFee !== null && (float) $assessedFee === 0.0 ? 'Not Required' : 'Unpaid';
        $stmt = $pdo->prepare("INSERT INTO document_request (tracking_code, resident_id, document_type_id, purpose, request_source, proof_path, proof_type, payment_status, assessed_fee, business_name, business_category, business_address, business_activity, request_for_other, owner_first_name, owner_middle_name, owner_last_name, owner_suffix, owner_date_of_birth, owner_address, owner_relationship, representation_consent_at) VALUES (?, ?, ?, ?, 'online', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(?, UTC_TIMESTAMP(), NULL))");
        $stmt->execute([$trackingCode, $residentId, $documentTypeId, $purpose, $proofPath, $proofType, $paymentStatus, $assessedFee, $isBusinessClearance ? $businessName : null, $isBusinessClearance ? $businessCategory : null, $isBusinessClearance ? $businessAddress : null, $isBusinessClearance ? $businessActivity : null, $requestForOther ? 1 : 0, $ownerFirstName, $ownerMiddleName, $ownerLastName, $ownerSuffix ?: null, $ownerBirthDate ?: null, $ownerAddress ?: null, $ownerRelationship ?: null, $representationConsent ? 1 : 0]);
        $requestId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO request_status_log (request_id, updated_by_user_id, status, notes) VALUES (?, NULL, \'Pending\', ?)')->execute([$requestId, 'Request submitted by resident.']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (is_file($proofDirectory . DIRECTORY_SEPARATOR . $proofName)) unlink($proofDirectory . DIRECTORY_SEPARATOR . $proofName);
        throw $e;
    }
    notifyAdminsOfNewRequest($pdo, $trackingCode, (string)$documentType['name']);
    echo json_encode(['success' => true, 'trackingCode' => $trackingCode]);
}
?>
