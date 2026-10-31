<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/request_workflow.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['success'=>false, 'message'=>'POST request required.']); exit; }
if (($_SESSION['role'] ?? '') !== 'Resident' || empty($_SESSION['resident_id'])) { http_response_code(401); echo json_encode(['success'=>false, 'message'=>'Sign in to continue.']); exit; }
if (!rateLimit($pdo, 'pickup-reschedule', 5, 3600)) { http_response_code(429); echo json_encode(['success'=>false, 'message'=>'Too many reschedule requests. Try again later.']); exit; }

$data = jsonBody(8192);
$requestId = filter_var($data['requestId'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$requestedDate = trim(is_string($data['requestedDate'] ?? null) ? $data['requestedDate'] : '');
$reason = trim(is_string($data['reason'] ?? null) ? $data['reason'] : '');
$reasonLength = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
if (!$requestId || $reasonLength < 1 || $reasonLength > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $reason)) {
    http_response_code(400); echo json_encode(['success'=>false, 'message'=>'Enter why you need to reschedule and choose a date.']); exit;
}

expireReadyRequests($pdo);
$residentId = (int) $_SESSION['resident_id'];
$maxReschedules = (int) requestWorkflowConfig()['max_pickup_reschedules'];
$date = DateTimeImmutable::createFromFormat('!Y-m-d', $requestedDate, new DateTimeZone('Asia/Manila'));
$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
if (!$date || $date->format('Y-m-d') !== $requestedDate || $date <= $today || (int) $date->format('N') > 5) {
    http_response_code(400); echo json_encode(['success'=>false, 'message'=>'Choose a future working day. Saturdays and Sundays are not available.']); exit;
}

$pdo->beginTransaction();
try {
    $find = $pdo->prepare('SELECT dr.current_status, dr.pickup_date, dr.tracking_code, dt.name AS document_name, r.first_name, r.email
        FROM document_request dr JOIN document_type dt ON dt.document_type_id = dr.document_type_id
        JOIN resident r ON r.resident_id = dr.resident_id WHERE dr.request_id = ? AND dr.resident_id = ? FOR UPDATE');
    $find->execute([$requestId, $residentId]);
    $request = $find->fetch(PDO::FETCH_ASSOC);
    if (!$request) { $pdo->rollBack(); http_response_code(404); echo json_encode(['success'=>false, 'message'=>'Request not found.']); exit; }
    if ($request['current_status'] !== 'Ready for Pickup' || empty($request['pickup_date'])) {
        $pdo->rollBack(); http_response_code(409); echo json_encode(['success'=>false, 'message'=>'Only a request with a scheduled pickup can be rescheduled.']); exit;
    }
    $originalDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request['pickup_date'], new DateTimeZone('Asia/Manila'));
    if (!$originalDate || $date <= $originalDate || $date->diff($originalDate)->days > 7) {
        $pdo->rollBack(); http_response_code(400); echo json_encode(['success'=>false, 'message'=>'Choose a different working day no more than 7 days from the original pickup date.']); exit;
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM pickup_reschedule_request WHERE request_id = ?');
    $count->execute([$requestId]);
    if ((int) $count->fetchColumn() >= $maxReschedules) {
        $pdo->rollBack(); http_response_code(409); echo json_encode(['success'=>false, 'message'=>'This request has reached its limit of 2 pickup reschedules.']); exit;
    }
    $pending = $pdo->prepare("SELECT COUNT(*) FROM pickup_reschedule_request WHERE request_id = ? AND status = 'Pending'");
    $pending->execute([$requestId]);
    if ((int) $pending->fetchColumn() > 0) {
        $pdo->rollBack(); http_response_code(409); echo json_encode(['success'=>false, 'message'=>'A reschedule request is already awaiting staff review.']); exit;
    }
    $insert = $pdo->prepare("INSERT INTO pickup_reschedule_request (request_id, resident_id, original_pickup_date, requested_pickup_date, reason, status, created_at)
        VALUES (?, ?, ?, ?, ?, 'Pending', UTC_TIMESTAMP())");
    $insert->execute([(int) $requestId, $residentId, $originalDate->format('Y-m-d'), $date->format('Y-m-d'), $reason]);
    $rescheduleId = (int) $pdo->lastInsertId();
    $newStatus = applyRequestTransition($pdo, (int) $requestId, 'Ready for Pickup', 'Reschedule', null,
        'Pickup reschedule requested for ' . $date->format('Y-m-d') . ': ' . $reason);
    if ($newStatus === null) throw new RuntimeException('This request changed before the reschedule could be saved.');
    $audit = $pdo->prepare("INSERT INTO audit_log (user_id, subject_resident_id, action, details) VALUES (NULL, ?, 'pickup_reschedule_requested', ?)");
    $audit->execute([$residentId, 'request_id=' . (int) $requestId . '; reschedule_id=' . $rescheduleId . '; requested_date=' . $date->format('Y-m-d') . '; reason=' . $reason]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Pickup reschedule request failed: ' . $error->getMessage());
    http_response_code(409);
    echo json_encode(['success'=>false, 'message'=>$error instanceof RuntimeException ? $error->getMessage() : 'The reschedule request could not be saved. Please try again.']);
    exit;
}

try {
    $appUrl = rtrim((string) portalConfig()['app_url'], '/');
    $reviewUrl = $appUrl . '/admin_requests.php#admin-requests';
    $admins = $pdo->query("SELECT email FROM user WHERE role = 'Admin' AND staff_role IN ('super_admin', 'service_clerk') AND notification_new_requests = 1")->fetchAll(PDO::FETCH_COLUMN);
    $adminText = "A resident requested a pickup reschedule.\n\nRequest: {$request['tracking_code']}\nDocument: {$request['document_name']}\nNew date requested: " . $date->format('F j, Y') . "\nReason: $reason\n\nReview the reschedule queue: $reviewUrl";
    $adminHtml = portalEmailTemplate('Admin notification', 'Pickup reschedule needs review', [
        'A resident requested a pickup reschedule.',
        "Request: {$request['tracking_code']}\nDocument: {$request['document_name']}\nNew date requested: " . $date->format('F j, Y'),
        'Reason: ' . $reason
    ], 'Review request', $reviewUrl);
    foreach ($admins as $adminEmail) {
        $mailError = null;
        if (!queuePortalEmail($pdo, (string) $adminEmail, 'Pickup reschedule needs review — ' . $request['tracking_code'], $adminText, $adminHtml, $mailError)) {
            error_log('Pickup reschedule admin notification could not be queued for request ' . (int) $requestId . '.');
        }
    }
} catch (Throwable $error) {
    error_log('Pickup reschedule saved but admin notification queueing failed for request ' . (int) $requestId . '.');
}
echo json_encode(['success'=>true, 'rescheduleId'=>$rescheduleId, 'status'=>'Reschedule Requested']);
?>
