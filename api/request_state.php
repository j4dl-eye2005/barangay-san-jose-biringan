<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/request_workflow.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || ($_SESSION['role'] ?? '') !== 'Resident' || empty($_SESSION['resident_id'])) {
    http_response_code(($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' ? 401 : 405);
    echo json_encode(['success' => false, 'message' => 'Sign in as a resident to continue.']);
    exit;
}

expireReadyRequests($pdo);
$residentId = (int) $_SESSION['resident_id'];
$state = $pdo->prepare("SELECT DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%dT%H:%i:%sZ') AS server_now,
        (SELECT DATE_FORMAT(cooldown_until, '%Y-%m-%dT%H:%i:%sZ') FROM resident_request_cooldown
         WHERE resident_id = ? AND lifted_at IS NULL AND cooldown_until > UTC_TIMESTAMP() LIMIT 1) AS cooldown_until,
        (SELECT COUNT(*) FROM document_request WHERE resident_id = ? AND current_status = 'Pending') AS pending_requests");
$state->execute([$residentId, $residentId]);
$result = $state->fetch(PDO::FETCH_ASSOC) ?: [];
echo json_encode(['success' => true, 'serverNow' => $result['server_now'] ?? null,
    'cooldownUntil' => $result['cooldown_until'] ?: null, 'pendingRequests' => (int) ($result['pending_requests'] ?? 0),
    'pendingRequestLimit' => (int) requestWorkflowConfig()['pending_request_limit'],
    'demoMode' => !empty(requestWorkflowConfig()['demo_mode'])]);
?>
