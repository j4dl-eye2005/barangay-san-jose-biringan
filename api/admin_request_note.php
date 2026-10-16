<?php
require_once 'db.php';
header('Content-Type: application/json');

requireAdminAccess();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = jsonBody(12000);
$requestId = filter_var($data['request_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$note = trim(is_string($data['note'] ?? null) ? $data['note'] : '');
if (!$requestId || strlen($note) > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $note)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid request ID is required']);
    exit;
}
$exists = $pdo->prepare('SELECT request_id FROM document_request WHERE request_id = ?');
$exists->execute([$requestId]);
if (!$exists->fetch()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Request not found']);
    exit;
}

$save = $pdo->prepare("INSERT INTO request_internal_note (request_id, note_text, updated_by_user_id)
    VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE note_text = VALUES(note_text), updated_by_user_id = VALUES(updated_by_user_id), updated_at = CURRENT_TIMESTAMP");
$save->execute([$requestId, $note, $_SESSION['user_id']]);
$audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)');
$audit->execute([$_SESSION['user_id'], 'request_note_updated', 'Internal note updated for request ID ' . $requestId]);
echo json_encode(['success' => true, 'message' => 'Internal note saved.']);
?>
