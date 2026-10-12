<?php
require_once 'db.php';
require_once 'email.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    echo json_encode(['success' => false]); exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$requestId = $data['id'];
$action = $data['action'];
$details = $data['details'] ?? null;

$newStatus = match($action) {
    'Approve' => 'Approved',
    'Reject'  => 'Rejected',
    'Ready'   => 'Ready for Pickup',
    'Pickup'  => 'Completed',
    default   => 'Pending'
};

if (!in_array($action, ['Approve', 'Reject', 'Ready', 'Pickup'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request action']);
    exit;
}

$request = $pdo->prepare("SELECT dr.tracking_code, r.email, r.first_name, r.middle_name, r.last_name, dt.name AS document_name
                          FROM document_request dr
                          JOIN resident r ON r.resident_id = dr.resident_id
                          JOIN document_type dt ON dt.document_type_id = dr.document_type_id
                          WHERE dr.request_id = ?");
$request->execute([$requestId]);
$requestInfo = $request->fetch();
if (!$requestInfo) {
    echo json_encode(['success' => false, 'message' => 'Request not found']);
    exit;
}

$stmt = $pdo->prepare("UPDATE document_request SET current_status = ?, rejection_reason = ? WHERE request_id = ?");
$stmt->execute([$newStatus, $action === 'Reject' ? $details : null, $requestId]);

$log = $pdo->prepare("INSERT INTO request_status_log (request_id, updated_by_user_id, status, notes) VALUES (?, ?, ?, ?)");
$log->execute([$requestId, $_SESSION['user_id'], $newStatus, $details]);

$audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)');
$auditAction = match ($action) {
    'Approve' => 'request_approved',
    'Reject' => 'request_rejected',
    'Ready' => 'request_ready_for_pickup',
    'Pickup' => 'request_completed',
};
$residentName = trim(implode(' ', array_filter([$requestInfo['first_name'], $requestInfo['middle_name'], $requestInfo['last_name']])));
$auditDetails = "Tracking {$requestInfo['tracking_code']}; resident {$residentName}; document {$requestInfo['document_name']}";
$audit->execute([$_SESSION['user_id'], $auditAction, $auditDetails]);

$emailSubject = "Barangay request {$newStatus}";
$emailMessage = "Hello {$requestInfo['first_name']},\n\n"
    . "Your request for {$requestInfo['document_name']} has been updated.\n"
    . "Tracking code: {$requestInfo['tracking_code']}\n"
    . "Status: {$newStatus}\n";
if ($details) {
    $emailMessage .= "Details: {$details}\n";
}
$emailMessage .= "\nPlease log in to the Barangay Digital Portal for more information.\n";
sendPortalEmail($requestInfo['email'], $emailSubject, $emailMessage);

echo json_encode(['success' => true]);
?>
