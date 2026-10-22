<?php
require_once 'db.php';
header('Content-Type: application/json');

requireAdminAccess();

$needsActionRequests = (int) $pdo->query("SELECT COUNT(*) FROM document_request WHERE current_status = 'Approved'")->fetchColumn();
$readyForPickup = (int) $pdo->query("SELECT COUNT(*) FROM document_request WHERE current_status = 'Ready for Pickup'")->fetchColumn();
$weekStart = 'DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)';
$weekEnd = "DATE_ADD($weekStart, INTERVAL 7 DAY)";
$completedThisWeek = (int) $pdo->query("SELECT COUNT(*) FROM request_status_log WHERE status = 'Completed' AND created_at >= $weekStart AND created_at < $weekEnd")->fetchColumn();
$newResidentsThisWeek = (int) $pdo->query("SELECT COUNT(*) FROM resident WHERE created_at >= $weekStart AND created_at < $weekEnd")->fetchColumn();

$byDocument = $pdo->query("SELECT dt.name AS document_type, COUNT(dr.request_id) AS request_count
    FROM document_type dt LEFT JOIN document_request dr ON dr.document_type_id = dt.document_type_id
    GROUP BY dt.document_type_id, dt.name ORDER BY request_count DESC, dt.name ASC")->fetchAll();

$oldest = $pdo->query("SELECT dr.request_id, dr.tracking_code, dr.current_status, dr.created_at,
        dt.name AS document_type, COALESCE(NULLIF(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name), ''), dr.walk_in_name) AS resident_name
    FROM document_request dr
    JOIN document_type dt ON dt.document_type_id = dr.document_type_id
    LEFT JOIN resident r ON r.resident_id = dr.resident_id
    WHERE dr.current_status IN ('Pending', 'Approved')
    ORDER BY dr.created_at ASC LIMIT 5")->fetchAll();

echo json_encode([
    'success' => true,
    'summary' => [
        'needs_action' => $needsActionRequests,
        'approved_waiting' => $needsActionRequests,
        'ready_for_pickup' => $readyForPickup,
        'completed_this_week' => $completedThisWeek,
        'new_residents_this_week' => $newResidentsThisWeek,
    ],
    'requests_by_document' => $byDocument,
    'oldest_waiting' => $oldest,
]);
?>
