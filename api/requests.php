<?php
require_once 'db.php';
require_once __DIR__ . '/email.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['Admin', 'Resident'], true)) {
        if (!empty($GLOBALS['adminEntryDeniedThisRequest'])) denyAdminEntryAccess(true);
        http_response_code(401); echo json_encode(['success' => false, 'message' => 'Authentication required']); exit;
    }
    
    if ($_SESSION['role'] === 'Admin') {
        requireAdminAccess();
        $page = max(1, min(1000, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;
        $status = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'All';
        $allowedStatuses = ['All', 'Pending', 'Approved', 'Ready for Pickup', 'Completed', 'Rejected'];
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
                   CASE WHEN dr.request_source = 'online' AND dr.proof_path IS NOT NULL THEN CONCAT('api/request_proof.php?id=', dr.request_id) ELSE NULL END AS proof_url,
                   CASE WHEN dr.proof_path IS NULL THEN NULL ELSE LOWER(SUBSTRING_INDEX(dr.proof_path, '.', -1)) END AS proof_format,
                   dt.name AS documentType, dt.fee_amount AS fee_amount,
                   (SELECT rsl.notes FROM request_status_log rsl
                    WHERE rsl.request_id = dr.request_id AND rsl.status = 'Ready for Pickup'
                    ORDER BY rsl.created_at DESC, rsl.log_id DESC LIMIT 1) AS pickupDetails,
                   COALESCE(NULLIF(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name), ''), dr.walk_in_name) AS residentName,
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
        $stmt = $pdo->prepare("
            SELECT dr.request_id, dr.tracking_code, dr.resident_id, dr.document_type_id, dr.purpose,
                   dr.current_status, dr.payment_status, dr.payment_reference, dr.payment_submitted_at,
                   dr.payment_rejection_reason, dr.rejection_reason, dr.created_at,
                   dt.name AS documentType, dt.fee_amount AS fee_amount,
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
        echo json_encode(['success' => true, 'requests' => $stmt->fetchAll(), 'page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => (int) ceil($total / $perPage), 'status_counts' => $statusCounts]);
    } else {
        echo json_encode($stmt->fetchAll());
    }
} 

elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['resident_id']) || ($_SESSION['role'] ?? '') !== 'Resident') { logPermissionFailure(); http_response_code(403); echo json_encode(['success'=>false, 'message'=>'Resident access required']); exit; }
    if (!rateLimit($pdo, 'document-request-ip', 10, 3600) || !rateLimit($pdo, 'document-request-resident-' . (int) $_SESSION['resident_id'], 10, 3600)) {
        http_response_code(429); header('Retry-After: 3600'); echo json_encode(['success'=>false, 'message'=>'Too many request submissions. Try again later.']); exit;
    }
    $profile = $pdo->prepare('SELECT email_verified_at FROM resident WHERE resident_id = ? AND archived_at IS NULL');
    $profile->execute([(int) $_SESSION['resident_id']]);
    $emailVerifiedAt = $profile->fetchColumn();
    if (!$emailVerifiedAt) { http_response_code(403); echo json_encode(['success'=>false, 'message'=>'Verify your email before submitting a document request.']); exit; }
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
    $proofDirectory = privateStorageDirectory('proofs');
    $proofName = bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file((string) $proof['tmp_name'], $proofDirectory . DIRECTORY_SEPARATOR . $proofName)) { throw new RuntimeException('Unable to save request proof.'); }
    $proofPath = 'proofs/' . $proofName;
    $trackingCode = 'REQ-' . strtoupper(bin2hex(random_bytes(8)));
    try {
        $paymentStatus = $documentType['fee_amount'] !== null && (float) $documentType['fee_amount'] === 0.0 ? 'Not Required' : 'Unpaid';
        $stmt = $pdo->prepare("INSERT INTO document_request (tracking_code, resident_id, document_type_id, purpose, request_source, proof_path, proof_type, payment_status) VALUES (?, ?, ?, ?, 'online', ?, ?, ?)");
        $stmt->execute([$trackingCode, $_SESSION['resident_id'], $documentTypeId, $purpose, $proofPath, $proofType, $paymentStatus]);
    } catch (Throwable $e) {
        if (is_file($proofDirectory . DIRECTORY_SEPARATOR . $proofName)) unlink($proofDirectory . DIRECTORY_SEPARATOR . $proofName);
        throw $e;
    }
    notifyAdminsOfNewRequest($pdo, $trackingCode, (string)$documentType['name']);
    echo json_encode(['success' => true, 'trackingCode' => $trackingCode]);
}
?>
