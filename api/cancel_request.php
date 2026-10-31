<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/request_workflow.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['success'=>false, 'message'=>'POST request required.']); exit; }
if (($_SESSION['role'] ?? '') !== 'Resident' || empty($_SESSION['resident_id'])) { http_response_code(401); echo json_encode(['success'=>false, 'message'=>'Sign in to continue.']); exit; }
if (!rateLimit($pdo, 'request-cancellation', 10, 3600)) { http_response_code(429); echo json_encode(['success'=>false, 'message'=>'Too many cancellation attempts. Try again later.']); exit; }

$data = jsonBody(8192);
$requestId = filter_var($data['requestId'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$reason = is_string($data['reason'] ?? null) ? trim($data['reason']) : '';
$notes = is_string($data['notes'] ?? null) ? trim($data['notes']) : '';
$reasons = ['Wrong document type', 'Wrong details', 'No longer needed', 'Other'];
$noteLength = function_exists('mb_strlen') ? mb_strlen($notes, 'UTF-8') : strlen($notes);
if (!$requestId || !in_array($reason, $reasons, true) || ($reason === 'Other' && $noteLength < 10) || $noteLength > 1000) {
    http_response_code(400); echo json_encode(['success'=>false, 'message'=>'Choose a cancellation reason. If you choose Other, enter at least 10 characters.']); exit;
}
if ($reason !== 'Other') $notes = '';

$residentId = (int) $_SESSION['resident_id'];
$config = requestWorkflowConfig();
$pdo->beginTransaction();
try {
    $residentLock = $pdo->prepare('SELECT resident_id FROM resident WHERE resident_id = ? AND archived_at IS NULL FOR UPDATE');
    $residentLock->execute([$residentId]);
    if (!$residentLock->fetchColumn()) throw new RuntimeException('This resident account is unavailable.');
    $find = $pdo->prepare("SELECT dr.current_status, dr.tracking_code, dr.request_for_other, dt.name AS document_name,
            r.first_name, r.email
        FROM document_request dr JOIN document_type dt ON dt.document_type_id = dr.document_type_id
        JOIN resident r ON r.resident_id = dr.resident_id
        WHERE dr.request_id = ? AND dr.resident_id = ? FOR UPDATE");
    $find->execute([$requestId, $residentId]);
    $request = $find->fetch(PDO::FETCH_ASSOC);
    if (!$request) { $pdo->rollBack(); http_response_code(404); echo json_encode(['success'=>false, 'message'=>'Request not found.']); exit; }
    $newStatus = requestTransitionTarget((string) $request['current_status'], 'Cancel');
    if ($newStatus === null) { $pdo->rollBack(); http_response_code(409); echo json_encode(['success'=>false, 'message'=>'Only pending requests can be cancelled. Staff has started processing this request.']); exit; }

    $count = $pdo->prepare('SELECT COUNT(*) FROM request_cancellation_log WHERE resident_id = ? AND cancelled_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)');
    $count->execute([$residentId]);
    $tier = (int) $count->fetchColumn() + 1;
    $cooldownSeconds = !empty($config['demo_mode']) ? 10 : (int) ($config['cooldowns_seconds'][min($tier, 4)] ?? 86400);
    $applied = applyRequestTransition($pdo, (int) $requestId, 'Pending', 'Cancel', null,
        'Resident cancellation; reason: ' . $reason . ($notes !== '' ? '; notes: ' . $notes : ''));
    if ($applied === null) throw new RuntimeException('The request changed before cancellation could be saved. Refresh and try again.');

    $insert = $pdo->prepare('INSERT INTO request_cancellation_log (request_id, resident_id, reason, notes, cooldown_seconds, cooldown_until, cancelled_at)
        VALUES (?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), UTC_TIMESTAMP())');
    $insert->execute([(int) $requestId, $residentId, $reason, $notes !== '' ? $notes : null, $cooldownSeconds, $cooldownSeconds]);
    $cancellationId = (int) $pdo->lastInsertId();
    $cooldownSave = $pdo->prepare('INSERT INTO resident_request_cooldown (resident_id, latest_cancellation_id, cooldown_until, lifted_at, lifted_by_user_id)
        SELECT ?, cancellation_id, cooldown_until, NULL, NULL FROM request_cancellation_log WHERE cancellation_id = ?
        ON DUPLICATE KEY UPDATE latest_cancellation_id = VALUES(latest_cancellation_id), cooldown_until = VALUES(cooldown_until), lifted_at = NULL, lifted_by_user_id = NULL');
    $cooldownSave->execute([$residentId, $cancellationId]);
    $cooldownDetails = $pdo->prepare("SELECT DATE_FORMAT(cooldown_until, '%Y-%m-%dT%H:%i:%sZ') FROM resident_request_cooldown WHERE resident_id = ?");
    $cooldownDetails->execute([$residentId]);
    $cooldownUntil = (string) $cooldownDetails->fetchColumn();
    $serverNow = (string) $pdo->query("SELECT DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%dT%H:%i:%sZ')")->fetchColumn();
    $auditDetails = 'request_id=' . (int) $requestId . '; resident_id=' . $residentId . '; reason=' . $reason
        . '; notes=' . $notes . '; server_timestamp=' . str_replace('T', ' ', rtrim($serverNow, 'Z'))
        . '; cooldown_seconds=' . $cooldownSeconds . '; cooldown_until=' . $cooldownUntil;
    $audit = $pdo->prepare("INSERT INTO audit_log (user_id, subject_resident_id, action, details) VALUES (NULL, ?, 'resident_request_cancelled', ?)");
    $audit->execute([$residentId, $auditDetails]);
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Resident request cancellation failed: ' . $error->getMessage());
    http_response_code(409);
    echo json_encode(['success'=>false, 'message'=>$error instanceof RuntimeException ? $error->getMessage() : 'The request could not be cancelled. Please try again.']);
    exit;
}

$emailDelivery = 'failed';
try {
    $tierText = $config['demo_mode'] ? '10 seconds' : match (min($tier, 4)) { 1 => '3 minutes', 2 => '10 minutes', 3 => '1 hour', default => '1 day' };
    $firstName = trim((string) ($request['first_name'] ?? '')) ?: 'there';
    $plain = "Hello $firstName,\n\nYour request {$request['tracking_code']} for {$request['document_name']} was cancelled.\nReason: $reason" . ($notes !== '' ? "\nDetails: $notes" : '') . "\nNew document requests are paused for $tierText, until $cooldownUntil (UTC).\n\nBarangay San Jose";
    $appUrl = rtrim((string) portalConfig()['app_url'], '/');
    $html = portalEmailTemplate('Document request update', 'Request cancelled', [
        "Hello $firstName,",
        "Your request {$request['tracking_code']} for {$request['document_name']} was cancelled.",
        'Reason: ' . $reason . ($notes !== '' ? "\nDetails: $notes" : ''),
        "New document requests are paused for $tierText, until $cooldownUntil (UTC)."
    ], 'View your requests', $appUrl . '/index.php#resident-dashboard');
    $mailError = null;
    $emailDelivery = !empty($request['email']) ? queuePortalNotification($pdo, (string) $request['email'], 'Request cancelled — ' . $request['tracking_code'], $plain, $html, $mailError) : 'failed';
} catch (Throwable $error) {
    error_log('Cancellation was saved but its email notification could not be prepared for request ' . (int) $requestId . '.');
}
if ($emailDelivery === 'failed') error_log('Cancellation notice could not be queued for request ' . (int) $requestId . '.');
echo json_encode(['success'=>true, 'status'=>'Cancelled', 'cooldownSeconds'=>$cooldownSeconds,
    'cooldownUntil'=>$cooldownUntil, 'serverNow'=>$serverNow, 'emailDelivery'=>$emailDelivery]);
?>
