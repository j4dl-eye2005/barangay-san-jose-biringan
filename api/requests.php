<?php
require_once 'db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['Admin', 'Resident'], true)) {
        if (!empty($GLOBALS['adminIpDeniedThisRequest'])) denyAdminIpAccess(true);
        http_response_code(401); echo json_encode(['success' => false, 'message' => 'Authentication required']); exit;
    }
    
    if ($_SESSION['role'] === 'Admin') {
        requireAdminAccess();
        $page = max(1, min(100000, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
        $perPage = 25;
        $offset = ($page - 1) * $perPage;
        $status = is_string($_GET['status'] ?? null) ? $_GET['status'] : 'All';
        $allowedStatuses = ['All', 'Pending', 'Approved', 'Ready for Pickup', 'Completed', 'Rejected'];
        if (!in_array($status, $allowedStatuses, true)) $status = 'All';
        $search = trim(is_string($_GET['search'] ?? null) ? $_GET['search'] : '');
        if (strlen($search) > 100) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Search text is too long.']);
            exit;
        }
        $where = [];
        $params = [];
        if ($status !== 'All') { $where[] = 'dr.current_status = ?'; $params[] = $status; }
        if ($search !== '') {
            $where[] = '(dr.tracking_code LIKE ? OR CONCAT_WS(\' \', r.first_name, r.last_name) LIKE ?)';
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
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM document_request dr JOIN resident r ON r.resident_id = dr.resident_id $whereSql");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();
        $stmt = $pdo->prepare("
            SELECT dr.request_id, dr.tracking_code, dr.resident_id, dr.document_type_id, dr.purpose,
                   dr.current_status, dr.payment_status, dr.rejection_reason, dr.created_at,
                   dt.name AS documentType,
                   (SELECT rsl.notes FROM request_status_log rsl
                    WHERE rsl.request_id = dr.request_id AND rsl.status = 'Ready for Pickup'
                    ORDER BY rsl.created_at DESC, rsl.log_id DESC LIMIT 1) AS pickupDetails,
                   CONCAT(r.first_name, ' ', r.last_name) AS residentName
            FROM document_request dr
            JOIN document_type dt ON dr.document_type_id = dt.document_type_id
            JOIN resident r ON dr.resident_id = r.resident_id
            $whereSql
            ORDER BY dr.created_at DESC, dr.request_id DESC
            LIMIT $perPage OFFSET $offset
        ");
        $stmt->execute($params);
    } elseif ($_SESSION['role'] === 'Resident' && isset($_SESSION['resident_id'])) {
        $stmt = $pdo->prepare("
            SELECT dr.request_id, dr.tracking_code, dr.resident_id, dr.document_type_id, dr.purpose,
                   dr.current_status, dr.payment_status, dr.rejection_reason, dr.created_at,
                   dt.name AS documentType,
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
    $data = jsonBody(12000);
    $documentTypeId = filter_var($data['documentTypeId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $purpose = trim(is_string($data['purpose'] ?? null) ? $data['purpose'] : '');
    $purposeLength = function_exists('mb_strlen') ? mb_strlen($purpose, 'UTF-8') : strlen($purpose);
    if (!$documentTypeId || $purpose === '' || $purposeLength > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $purpose)) {
        http_response_code(400); echo json_encode(['success'=>false, 'message'=>'Enter a valid document type and purpose.']); exit;
    }
    $type = $pdo->prepare('SELECT document_type_id FROM document_type WHERE document_type_id = ? AND is_active = 1');
    $type->execute([$documentTypeId]);
    if (!$type->fetch()) { http_response_code(400); echo json_encode(['success'=>false, 'message'=>'Choose an available document type.']); exit; }
    $trackingCode = 'REQ-' . strtoupper(bin2hex(random_bytes(8)));
    
    $stmt = $pdo->prepare("INSERT INTO document_request (tracking_code, resident_id, document_type_id, purpose) VALUES (?, ?, ?, ?)");
    $stmt->execute([$trackingCode, $_SESSION['resident_id'], $documentTypeId, $purpose]);
    echo json_encode(['success' => true, 'trackingCode' => $trackingCode]);
}
?>
