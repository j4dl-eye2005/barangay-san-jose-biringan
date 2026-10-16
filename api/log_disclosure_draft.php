<?php
require_once 'db.php';
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['success'=>false, 'message'=>'Method not allowed']); exit; }

requireAdminAccess();

$data = jsonBody(8192);
$title = trim(is_string($data['title'] ?? null) ? $data['title'] : '');
$title = function_exists('mb_substr') ? mb_substr($title, 0, 120, 'UTF-8') : substr($title, 0, 120);
$details = $title === '' ? 'Disclosure draft saved' : "Disclosure draft saved: $title";

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
