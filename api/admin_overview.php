<?php
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Administrator access required']);
    exit;
}

$needsActionRequests = (int) $pdo->query("SELECT COUNT(*) FROM document_request WHERE current_status = 'Approved'")->fetchColumn();
$pendingResidents = (int) $pdo->query('SELECT COUNT(*) FROM resident WHERE is_active = 0')->fetchColumn();
$readyForPickup = (int) $pdo->query("SELECT COUNT(*) FROM document_request WHERE current_status = 'Ready for Pickup'")->fetchColumn();
$completedThisWeek = (int) $pdo->query("SELECT COUNT(*) FROM request_status_log WHERE status = 'Completed' AND YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)")->fetchColumn();
$newResidentsThisWeek = (int) $pdo->query("SELECT COUNT(*) FROM resident WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)")->fetchColumn();

$byDocument = $pdo->query("SELECT dt.name AS document_type, COUNT(dr.request_id) AS request_count
    FROM document_type dt LEFT JOIN document_request dr ON dr.document_type_id = dt.document_type_id
    GROUP BY dt.document_type_id, dt.name ORDER BY request_count DESC, dt.name ASC")->fetchAll();

$oldest = $pdo->query("SELECT dr.request_id, dr.tracking_code, dr.current_status, dr.created_at,
        dt.name AS document_type, CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name) AS resident_name
    FROM document_request dr
    JOIN document_type dt ON dt.document_type_id = dr.document_type_id
    JOIN resident r ON r.resident_id = dr.resident_id
    WHERE dr.current_status IN ('Pending', 'Approved')
    ORDER BY dr.created_at ASC LIMIT 5")->fetchAll();

echo json_encode([
    'success' => true,
    'summary' => [
        'needs_action' => $needsActionRequests + $pendingResidents,
        'approved_waiting' => $needsActionRequests,
        'pending_residents' => $pendingResidents,
        'ready_for_pickup' => $readyForPickup,
        'completed_this_week' => $completedThisWeek,
        'new_residents_this_week' => $newResidentsThisWeek,
    ],
    'requests_by_document' => $byDocument,
    'oldest_waiting' => $oldest,
]);
?>
