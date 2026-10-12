<?php
require_once 'db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($_SESSION['role'])) { echo json_encode([]); exit; }
    
    if ($_SESSION['role'] === 'Admin') {
        $stmt = $pdo->query("
            SELECT dr.*, dt.name AS documentType, 
                   CONCAT(r.first_name, ' ', r.last_name) AS residentName
            FROM document_request dr
            JOIN document_type dt ON dr.document_type_id = dt.document_type_id
            JOIN resident r ON dr.resident_id = r.resident_id
            ORDER BY dr.created_at DESC
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT dr.*, dt.name AS documentType,
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
    }
    echo json_encode($stmt->fetchAll());
} 

elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['resident_id'])) { echo json_encode(['success'=>false]); exit; }
    $data = json_decode(file_get_contents('php://input'), true);
    $trackingCode = 'REQ-' . strtoupper(uniqid());
    
    $stmt = $pdo->prepare("INSERT INTO document_request (tracking_code, resident_id, document_type_id, purpose) VALUES (?, ?, ?, ?)");
    $stmt->execute([$trackingCode, $_SESSION['resident_id'], $data['documentTypeId'], $data['purpose']]);
    echo json_encode(['success' => true, 'trackingCode' => $trackingCode]);
}
?>
