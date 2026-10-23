<?php
require_once 'db.php';
requireAdminAccess();
$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$requestId) { http_response_code(400); exit('Invalid request.'); }
$stmt = $pdo->prepare('SELECT payment_proof_path FROM document_request WHERE request_id = ?');
$stmt->execute([$requestId]);
$relative = $stmt->fetchColumn();
if (!is_string($relative) || !preg_match('#^proofs/[a-f0-9]{32}\.(pdf|jpg|jpeg|png)$#', $relative)) {
    http_response_code(404);
    exit('Payment proof not found.');
}
$directory = privateStorageDirectory('proofs');
$path = $directory . DIRECTORY_SEPARATOR . basename($relative);
if (!is_file($path)) { http_response_code(404); exit('Payment proof not found.'); }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) { http_response_code(415); exit('Unsupported file.'); }
$audit = $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, 'payment_proof_viewed', ?)");
$audit->execute([(int) $_SESSION['user_id'], 'Request ID ' . (int) $requestId . '; payment proof viewed']);
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="payment-proof-' . (int) $requestId . '.' . strtolower(pathinfo($path, PATHINFO_EXTENSION)) . '"');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
readfile($path);
?>
