<?php
require_once __DIR__ . '/db.php';
requireAdminAccess('residents.view');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    http_response_code(405);
    exit('Method not allowed.');
}

$residentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$residentId || !rateLimit($pdo, 'resident-proof', 60, 60)) {
    http_response_code($residentId ? 429 : 404);
    exit('Proof not found.');
}

$stmt = $pdo->prepare('SELECT proof_path FROM resident WHERE resident_id = ?');
$stmt->execute([$residentId]);
$storedPath = $stmt->fetchColumn();
if (!is_string($storedPath) || $storedPath === '') {
    http_response_code(404);
    exit('Proof not found.');
}

$normalizedProofPath = str_replace('\\', '/', $storedPath);
$safeProofPath = preg_match('#^(?:private/)?proofs/[a-f0-9]{32}\.(?:pdf|docx|jpg|jpeg|png)$#i', $normalizedProofPath)
    || preg_match('#^uploads/proofs/[^/\x00-\x1f\x7f]+\.(?:pdf|docx|jpg|jpeg|png)$#i', $normalizedProofPath);
$extension = strtolower(pathinfo($normalizedProofPath, PATHINFO_EXTENSION));
if (!$safeProofPath || !in_array($extension, ['pdf', 'docx', 'jpg', 'jpeg', 'png'], true)) {
    http_response_code(404);
    exit('Proof not found.');
}

$proofFile = resolveStoredUpload($storedPath);
if ($proofFile === null || !is_file($proofFile)) {
    http_response_code(404);
    exit('Proof not found.');
}

$detectedMime = (new finfo(FILEINFO_MIME_TYPE))->file($proofFile) ?: 'application/octet-stream';
$mimeTypes = [
    'pdf' => ['application/pdf'],
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
    'docx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/octet-stream'],
];
if (!in_array($detectedMime, $mimeTypes[$extension] ?? [], true)) {
    http_response_code(415);
    exit('Unsupported file.');
}

$audit = $pdo->prepare("INSERT INTO audit_log (user_id, subject_resident_id, action, details) VALUES (?, ?, 'proof_viewed', ?)");
$audit->execute([(int) $_SESSION['user_id'], (int) $residentId, 'Resident ID ' . (int) $residentId]);

$mime = $extension === 'docx'
    ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    : $detectedMime;
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($proofFile));
header('Content-Disposition: ' . ($extension === 'docx' ? 'attachment' : 'inline') . '; filename="resident-proof-' . (int) $residentId . '.' . $extension . '"');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
readfile($proofFile);
?>
