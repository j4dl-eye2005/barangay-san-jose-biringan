<?php
require_once 'db.php';
require_once 'email.php';
require_once 'config.php';
header('Content-Type: application/json');

requireAdminAccess();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false, 'message'=>'Method not allowed']); exit; }

$data = jsonBody(12000);
$requestId = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = is_string($data['action'] ?? null) ? $data['action'] : '';
$details = is_string($data['details'] ?? null) ? trim($data['details']) : '';
if (!$requestId || strlen($details) > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $details)) { http_response_code(400); echo json_encode(['success'=>false, 'message'=>'Invalid request details.']); exit; }

$newStatus = match($action) {
    'Approve' => 'Approved',
    'Reject'  => 'Rejected',
    'Ready'   => 'Ready for Pickup',
    'Pickup'  => 'Completed',
    default   => 'Pending'
};

if (!in_array($action, ['Approve', 'Reject', 'Ready', 'Pickup'], true)) {
    http_response_code(400);
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
    http_response_code(404);
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
if ($action === 'Reject' && is_string($details) && trim($details) !== '') $auditDetails .= '; reason: ' . trim($details);
$audit->execute([$_SESSION['user_id'], $auditAction, $auditDetails]);

$appUrl   = portalConfig()['app_url'];

$firstName = trim((string) ($requestInfo['first_name'] ?? '')) ?: 'there';

$statusLabels = [
    'Approved'        => 'Approved',
    'Rejected'        => 'Rejected',
    'Ready for Pickup' => 'Ready for Pickup',
    'Completed'       => 'Completed',
];
$statusLabel = $statusLabels[$newStatus] ?? $newStatus;

$emailSubject = match ($action) {
    'Approve' => "Your document request has been approved — {$requestInfo['document_name']}",
    'Reject'  => "Update on your document request — {$requestInfo['document_name']}",
    'Ready'   => "Your document is ready for pickup — {$requestInfo['document_name']}",
    'Pickup'  => "Your document request is complete — {$requestInfo['document_name']}",
    default   => "Barangay request update — {$requestInfo['document_name']}",
};

$plainBody = "Hello $firstName,\n\n"
    . "Your request for {$requestInfo['document_name']} has been updated.\n"
    . "Tracking code: {$requestInfo['tracking_code']}\n"
    . "Status: $statusLabel\n";
if ($details) {
    $plainBody .= "\nNote: {$details}\n";
}
$plainBody .= "\nPlease log in to the Barangay Digital Portal for more information.\n\nThank you,\nBarangay San Jose";

$paragraphs = ["Hello $firstName,", "Your request for {$requestInfo['document_name']} (tracking code: {$requestInfo['tracking_code']}) has been updated to: $statusLabel."];
if ($details) $paragraphs[] = "Note from the administrator: $details";
$note = match ($action) {
    'Approve' => 'Your request is now being processed. You will be notified again once it is ready for pickup.',
    'Ready'   => 'Please visit the barangay office to claim your document. Bring a valid ID.',
    'Pickup'  => 'Thank you for using Barangay San Jose digital services.',
    default   => null,
};
$htmlBody = portalEmailTemplate(
    'Document request',
    "Request $statusLabel",
    $paragraphs,
    'View your request',
    $appUrl . '/#dashboard',
    $note
);

$emailError = null;
$emailSent  = queuePortalEmail($pdo, $requestInfo['email'], $emailSubject, $plainBody, $htmlBody, $emailError);
if (!$emailSent) $emailSent = sendPortalEmail($requestInfo['email'], $emailSubject, $plainBody, $emailError, $htmlBody);
if (!$emailSent) {
    error_log("process_request.php email failed for request $requestId: " . ($emailError ?? 'unknown error'));
}

echo json_encode(['success' => true, 'emailSent' => $emailSent]);
?>
