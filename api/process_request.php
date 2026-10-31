<?php
require_once 'db.php';
require_once 'email.php';
require_once 'config.php';
require_once __DIR__ . '/request_workflow.php';
header('Content-Type: application/json');

requireAdminAccess('requests.manage');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['success'=>false, 'message'=>'Method not allowed']); exit; }

$data = jsonBody(12000);
$requestId = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = is_string($data['action'] ?? null) ? $data['action'] : '';
$details = is_string($data['details'] ?? null) ? trim($data['details']) : '';
if (!$requestId || strlen($details) > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $details)) { http_response_code(400); echo json_encode(['success'=>false, 'message'=>'Invalid request details.']); exit; }
$allowedActions = ['Approve', 'Reject', 'Ready', 'Pickup', 'RescheduleApprove', 'RescheduleReject'];
if (!in_array($action, $allowedActions, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request action']);
    exit;
}
$pickupDate = is_string($data['pickupDate'] ?? null) ? trim($data['pickupDate']) : '';
$pickupTime = is_string($data['pickupTime'] ?? null) ? trim($data['pickupTime']) : '';
$rescheduleId = filter_var($data['rescheduleId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$ownerIdChecked = ($data['ownerIdChecked'] ?? false) === true;
if ($action === 'Ready') {
    $scheduledDate = DateTimeImmutable::createFromFormat('!Y-m-d', $pickupDate, new DateTimeZone('Asia/Manila'));
    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
    if (!$scheduledDate || $scheduledDate->format('Y-m-d') !== $pickupDate || $scheduledDate < $today
        || (int) $scheduledDate->format('N') > 5 || !preg_match('/^(?:(?:08|09|1[0-6]):(?:00|30)|17:00)$/', $pickupTime)) {
        http_response_code(400); echo json_encode(['success'=>false, 'message'=>'Choose a valid future weekday and pickup time between 8:00 AM and 5:00 PM.']); exit;
    }
}
if ($action === 'Reject' && $details === '') { http_response_code(400); echo json_encode(['success'=>false, 'message'=>'Enter a reason for rejecting this request.']); exit; }
if ($action === 'RescheduleReject' && $details === '') { http_response_code(400); echo json_encode(['success'=>false, 'message'=>'Enter a reason for declining the reschedule.']); exit; }

$pdo->beginTransaction();
try {
    $lock = $pdo->prepare('SELECT dr.current_status, dr.payment_status, dr.request_for_other, dr.pickup_date, dr.pickup_time,
                                  COALESCE(dr.assessed_fee, dt.fee_amount) AS fee_amount
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

    if ($action === 'Pickup' && !empty($currentRequest['request_for_other']) && !$ownerIdChecked) {
        $pdo->rollBack(); http_response_code(400); echo json_encode(['success'=>false, 'message'=>'Confirm that you checked the document owner’s valid ID before marking pickup complete.']); exit;
    }

    $changes = [];
    if ($action === 'Ready') {
        $changes = ['pickup_date' => $pickupDate, 'pickup_time' => $pickupTime, 'pickup_deadline_days' => (int) requestWorkflowConfig()['pickup_deadline_days']];
    } elseif ($action === 'Pickup' && !empty($currentRequest['request_for_other'])) {
        $changes = ['owner_id_checked_at' => gmdate('Y-m-d H:i:s'), 'owner_id_checked_by_user_id' => (int) $_SESSION['user_id']];
    }

    $pendingReschedule = null;
    if (in_array($action, ['RescheduleApprove', 'RescheduleReject'], true)) {
        $rescheduleQuery = 'SELECT reschedule_id, original_pickup_date, requested_pickup_date, reason FROM pickup_reschedule_request WHERE request_id = ? AND status = \'Pending\'';
        $rescheduleParams = [(int) $requestId];
        if ($rescheduleId) { $rescheduleQuery .= ' AND reschedule_id = ?'; $rescheduleParams[] = (int) $rescheduleId; }
        $rescheduleQuery .= ' ORDER BY created_at DESC, reschedule_id DESC LIMIT 1 FOR UPDATE';
        $rescheduleLock = $pdo->prepare($rescheduleQuery);
        $rescheduleLock->execute($rescheduleParams);
        $pendingReschedule = $rescheduleLock->fetch(PDO::FETCH_ASSOC);
        if (!$pendingReschedule) {
            $pdo->rollBack(); http_response_code(409); echo json_encode(['success'=>false, 'message'=>'There is no pending reschedule request to review.']); exit;
        }
        if ($action === 'RescheduleApprove') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $pendingReschedule['requested_pickup_date'], new DateTimeZone('Asia/Manila'));
            $original = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $pendingReschedule['original_pickup_date'], new DateTimeZone('Asia/Manila'));
            $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
            if (!$date || !$original || $date <= $today || $date <= $original || (int) $date->format('N') > 5 || $date->diff($original)->days > 7) {
                $pdo->rollBack(); http_response_code(409); echo json_encode(['success'=>false, 'message'=>'The requested pickup date has passed or is no longer valid. Decline it and ask the resident to submit a new request.']); exit;
            }
            $changes = ['pickup_date' => $date->format('Y-m-d'), 'pickup_deadline_days' => (int) requestWorkflowConfig()['pickup_deadline_days']];
            $details = 'Pickup reschedule approved for ' . $date->format('Y-m-d') . '.';
        } else {
            $details = 'Pickup reschedule declined: ' . $details;
        }
        $updateReschedule = $pdo->prepare('UPDATE pickup_reschedule_request SET status = ?, reviewed_at = UTC_TIMESTAMP(), reviewed_by_user_id = ?, review_notes = ? WHERE reschedule_id = ? AND status = \'Pending\'');
        $updateReschedule->execute([$action === 'RescheduleApprove' ? 'Approved' : 'Rejected', (int) $_SESSION['user_id'], $details, (int) $pendingReschedule['reschedule_id']]);
        if ($updateReschedule->rowCount() !== 1) throw new RuntimeException('The reschedule request has already been reviewed.');
    }

    $newStatus = applyRequestTransition($pdo, (int) $requestId, $currentStatus, $action, (int) $_SESSION['user_id'], $details, $changes);
    if ($newStatus === null) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This request has already changed or cannot take that action. Refresh the requests list and try again.']);
        exit;
    }
    if ($action === 'Reject') {
        $pdo->prepare('UPDATE document_request SET rejection_reason = ? WHERE request_id = ?')->execute([$details, (int) $requestId]);
    }

    $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)');
    $auditAction = match ($action) {
        'Approve' => 'request_approved',
        'Reject' => 'request_rejected',
        'Ready' => 'request_ready_for_pickup',
        'Pickup' => 'request_completed',
        'RescheduleApprove' => 'pickup_reschedule_approved',
        'RescheduleReject' => 'pickup_reschedule_rejected',
    };
    $audit->execute([$_SESSION['user_id'], $auditAction, "Request ID $requestId; new status $newStatus"]);

    $request = $pdo->prepare("SELECT dr.tracking_code, r.email, COALESCE(r.first_name, dr.walk_in_name) AS first_name,
                                     r.middle_name, r.last_name, dr.request_source, dt.name AS document_name,
                                     COALESCE(dr.assessed_fee, dt.fee_amount) AS fee_amount, dr.request_for_other,
                                     dr.owner_first_name, dr.pickup_date, dr.pickup_time
                              FROM document_request dr
                              JOIN document_type dt ON dt.document_type_id = dr.document_type_id
                              LEFT JOIN resident r ON r.resident_id = dr.resident_id
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

$emailDelivery = 'failed';
$emailQueueId = null;
try {
$appUrl   = portalConfig()['app_url'];

$firstName = trim((string) ($requestInfo['first_name'] ?? '')) ?: 'there';
$isBusinessClearance = stripos((string) ($requestInfo['document_name'] ?? ''), 'business') !== false;
$isBusinessApproval = $action === 'Approve' && $isBusinessClearance;
$approvedFee = $requestInfo['fee_amount'] === null ? null : (float) $requestInfo['fee_amount'];
$includeGcashQr = false;

$statusLabels = [
    'Approved'        => 'Approved',
    'Rejected'        => 'Rejected',
    'Ready for Pickup' => 'Ready for Pickup',
    'Completed'       => 'Completed',
];
$statusLabel = match ($action) {
    'RescheduleApprove' => 'Pickup reschedule approved',
    'RescheduleReject' => 'Pickup reschedule declined',
    default => $statusLabels[$newStatus] ?? $newStatus,
};

$emailSubject = $isBusinessApproval
    ? "Business clearance request approved — {$requestInfo['document_name']}"
    : match ($action) {
    'Approve' => "Your document request has been approved — {$requestInfo['document_name']}",
    'Reject'  => "Update on your document request — {$requestInfo['document_name']}",
    'Ready'   => "Your document is ready for pickup — {$requestInfo['document_name']}",
    'Pickup'  => "Your document request is complete — {$requestInfo['document_name']}",
    'RescheduleApprove' => "Pickup reschedule approved — {$requestInfo['document_name']}",
    'RescheduleReject' => "Pickup reschedule declined — {$requestInfo['document_name']}",
    default   => "Barangay request update — {$requestInfo['document_name']}",
};
$emailSubject .= ' — ' . (string) $requestInfo['tracking_code'];

$plainBody = "Hello $firstName,\n\n"
    . "Your request for {$requestInfo['document_name']} has been updated.\n"
    . "Tracking code: {$requestInfo['tracking_code']}\n"
    . "Status: $statusLabel\n";
if ($isBusinessApproval) {
    if ($approvedFee === null) {
        $plainBody .= "\nThe business clearance fee has not been assessed yet. Please wait for a follow-up email with the amount and payment instructions before paying.\n";
    }
}
if ($action === 'Approve' && $approvedFee !== null && $approvedFee > 0) {
    $plainBody .= "\nPayment instructions for the assessed fee of PHP " . number_format($approvedFee, 2) . ": pay in person at the Barangay Hall cashier in Barangay San Jose, Biringan City, or online through GCash using the QR code in this email. After paying through GCash, enter the receipt number as the payment reference in your request and upload a screenshot of the receipt. Verify the recipient before confirming; transfer fees may apply.\n";
    $includeGcashQr = true;
}
if ($details) {
    $plainBody .= "\nNote: {$details}\n";
}
$plainBody .= "\nPlease log in to the Barangay Digital Portal for more information.\n\nThank you,\nBarangay San Jose";

$paragraphs = ["Hello $firstName,", "Your request for {$requestInfo['document_name']} (tracking code: {$requestInfo['tracking_code']}) has been updated to: $statusLabel."];
if ($isBusinessApproval) {
    if ($approvedFee === null) {
        $paragraphs[] = 'The business clearance fee has not been assessed yet. Please wait for a follow-up email with the amount and payment instructions before paying.';
    }
}
if ($action === 'Approve' && $approvedFee !== null && $approvedFee > 0) {
    $paragraphs[] = 'Payment instructions for the assessed fee of PHP ' . number_format($approvedFee, 2) . ': pay in person at the Barangay Hall cashier in Barangay San Jose, Biringan City, or online through GCash using the QR code below.';
}
if ($includeGcashQr) {
    $paragraphs[] = 'After paying through GCash, enter the receipt number as the payment reference in your request and upload a screenshot of the receipt. Verify the recipient before confirming; transfer fees may apply.';
}
if ($details) $paragraphs[] = "Note from the administrator: $details";
$note = match ($action) {
    'Approve' => 'Your request is now being processed. You will be notified again once it is ready for pickup.',
    'Ready'   => 'Please visit the barangay office to claim your document. Bring a valid ID.',
    'Pickup'  => 'Thank you for using Barangay San Jose digital services.',
    'RescheduleApprove' => 'The updated pickup date is now shown in your request history.',
    'RescheduleReject' => 'Your original pickup date remains scheduled. Contact the barangay office if you need help.',
    default   => null,
};
if ($isBusinessApproval) {
    $note = $approvedFee === null
        ? 'This message confirms approval, not payment. Wait for the follow-up email with the assessed fee before sending money.'
        : null;
}
$emailNote = $note;
$emailNote = $action === 'Approve' && $approvedFee !== null && $approvedFee <= 0
    ? 'No payment is required for this request.'
    : $emailNote;
$htmlBody = portalEmailTemplate(
    'Document request',
    "Request $statusLabel",
    $paragraphs,
    'View your request',
    $appUrl . '/#dashboard',
    $emailNote,
    $includeGcashQr
);

$emailError = null;
if (!empty($requestInfo['email'])) {
    $emailDelivery = queuePortalNotification($pdo, (string) $requestInfo['email'], $emailSubject, $plainBody, $htmlBody, $emailError, $emailQueueId);
    if ($emailDelivery === 'failed') error_log("process_request.php email failed for request $requestId: " . ($emailError ?? 'unknown error'));
}
} catch (Throwable $error) {
    error_log("Request $requestId was saved but its email notification could not be prepared.");
}

echo json_encode(['success' => true, 'emailDelivery' => $emailDelivery, 'emailQueueId' => $emailQueueId]);
?>
