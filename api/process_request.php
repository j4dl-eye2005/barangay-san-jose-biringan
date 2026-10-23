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

$allowedTransitions = [
    'Pending' => [
        'Approve' => 'Approved',
        'Reject' => 'Rejected',
    ],
    'Approved' => [
        'Ready' => 'Ready for Pickup',
    ],
    'Ready for Pickup' => [
        'Pickup' => 'Completed',
    ],
];

if (!in_array($action, ['Approve', 'Reject', 'Ready', 'Pickup'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request action']);
    exit;
}

$pdo->beginTransaction();
try {
    $lock = $pdo->prepare('SELECT dr.current_status, dr.payment_status, dt.fee_amount
                           FROM document_request dr
                           JOIN document_type dt ON dt.document_type_id = dr.document_type_id
                           WHERE dr.request_id = ? FOR UPDATE');
    $lock->execute([$requestId]);
    $currentRequest = $lock->fetch(PDO::FETCH_ASSOC);
    if (!$currentRequest) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Request not found']);
        exit;
    }
    $currentStatus = (string) $currentRequest['current_status'];

    if (in_array($action, ['Ready', 'Pickup'], true)) {
        $feeAmount = $currentRequest['fee_amount'];
        $paymentRequired = $feeAmount === null
            ? $currentRequest['payment_status'] !== 'Not Required'
            : (float) $feeAmount > 0;
        if ($paymentRequired && $currentRequest['payment_status'] !== 'Paid') {
            $pdo->rollBack();
            http_response_code(409);
            $message = $action === 'Ready'
                ? 'Payment must be verified before the request can be marked ready for pickup.'
                : 'Payment must be verified before the request can be completed.';
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }
    }

    $newStatus = $allowedTransitions[(string) $currentStatus][$action] ?? null;
    if ($newStatus === null) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This request has already changed or cannot take that action. Refresh the requests list and try again.']);
        exit;
    }

    $stmt = $pdo->prepare('UPDATE document_request SET current_status = ?, rejection_reason = ? WHERE request_id = ? AND current_status = ?');
    $stmt->execute([$newStatus, $action === 'Reject' ? $details : null, $requestId, $currentStatus]);
    if ($stmt->rowCount() !== 1) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This request has already changed. Refresh the requests list and try again.']);
        exit;
    }

    $log = $pdo->prepare('INSERT INTO request_status_log (request_id, updated_by_user_id, status, notes) VALUES (?, ?, ?, ?)');
    $log->execute([$requestId, $_SESSION['user_id'], $newStatus, $details]);

    $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)');
    $auditAction = match ($action) {
        'Approve' => 'request_approved',
        'Reject' => 'request_rejected',
        'Ready' => 'request_ready_for_pickup',
        'Pickup' => 'request_completed',
    };
    $audit->execute([$_SESSION['user_id'], $auditAction, "Request ID $requestId; new status $newStatus"]);

    $request = $pdo->prepare("SELECT dr.tracking_code, r.email, COALESCE(r.first_name, dr.walk_in_name) AS first_name, r.middle_name, r.last_name, dr.request_source, dt.name AS document_name
                              FROM document_request dr
                              LEFT JOIN resident r ON r.resident_id = dr.resident_id
                              JOIN document_type dt ON dt.document_type_id = dr.document_type_id
                              WHERE dr.request_id = ?");
    $request->execute([$requestId]);
    $requestInfo = $request->fetch();
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Request status transition failed.');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The request could not be updated. Please try again.']);
    exit;
}

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
$emailSent = false;
if (!empty($requestInfo['email'])) {
    $emailSent = queuePortalEmail($pdo, $requestInfo['email'], $emailSubject, $plainBody, $htmlBody, $emailError);
    if (!$emailSent) $emailSent = sendPortalEmail($requestInfo['email'], $emailSubject, $plainBody, $emailError, $htmlBody);
    if (!$emailSent) error_log("process_request.php email failed for request $requestId: " . ($emailError ?? 'unknown error'));
}

echo json_encode(['success' => true, 'emailSent' => $emailSent]);
?>
