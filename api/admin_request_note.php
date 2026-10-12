<?php
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Administrator access required']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$requestId = filter_var($data['request_id'] ?? null, FILTER_VALIDATE_INT);
$note = trim((string) ($data['note'] ?? ''));
if (!$requestId) {
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
echo json_encode(['success' => true, 'message' => 'Internal note saved.']);
?>
