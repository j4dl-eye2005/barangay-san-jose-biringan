<?php
require_once 'db.php';
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['success'=>false, 'message'=>'Method not allowed']); exit; }

requireAdminAccess();

$data = jsonBody(8192);
$details = 'Disclosure draft saved';

try {
    $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)');
    $audit->execute([$_SESSION['user_id'], 'disclosure_draft_saved', $details]);
    echo json_encode(['success' => true]);
} catch (PDOException $error) {
    error_log('Disclosure draft audit error: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Draft saved, but its activity could not be recorded']);
}
?>
