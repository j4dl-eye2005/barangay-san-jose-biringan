<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/request_workflow.php';
requireAdminAccess('residents.view');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    http_response_code(405);
    exit('Method not allowed.');
}

$documentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$documentId) {
    http_response_code(400);
    exit('A valid document ID is required.');
}

$stmt = $pdo->prepare("SELECT rd.representative_document_id, rd.display_name, rd.file_path, rd.file_format,
        dr.request_id, dr.tracking_code, dr.resident_id,
        CASE WHEN dr.request_for_other = 1
            THEN CONCAT_WS(' ', dr.owner_first_name, dr.owner_middle_name, dr.owner_last_name, dr.owner_suffix)
            ELSE CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name, r.suffix)
        END AS resident_name
    FROM request_representative_document rd
    JOIN document_request dr ON dr.request_id = rd.request_id
    LEFT JOIN resident r ON r.resident_id = dr.resident_id
    WHERE rd.representative_document_id = ? AND dr.request_for_other = 1");
$stmt->execute([$documentId]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$document || !preg_match('#^representative-documents/[a-f0-9]{32}\.(pdf|jpg|jpeg|png)$#', (string) $document['file_path'])) {
    http_response_code(404);
    exit('Document not found.');
}

$directory = realpath(privateStorageDirectory('representative-documents'));
$path = $directory === false ? false : realpath($directory . DIRECTORY_SEPARATOR . basename((string) $document['file_path']));
if ($path === false || !is_file($path)
    || !str_starts_with(strtolower($path), strtolower(rtrim((string) $directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
    http_response_code(404);
    exit('Document not found.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
    http_response_code(415);
    exit('Unsupported file.');
}

$audit = $pdo->prepare("INSERT INTO audit_log (user_id, subject_resident_id, action, details)
    VALUES (?, ?, 'request_representative_document_viewed', ?)");
$audit->execute([
    (int) $_SESSION['user_id'],
    $document['resident_id'] !== null ? (int) $document['resident_id'] : null,
    'Tracking ' . (string) $document['tracking_code'] . '; resident ' . trim((string) $document['resident_name']) . '; document ' . (string) $document['display_name'],
]);

$extension = strtolower(pathinfo((string) $document['file_path'], PATHINFO_EXTENSION));
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="representative-document-' . (int) $documentId . '.' . $extension . '"');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
readfile($path);
?>
