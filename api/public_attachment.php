<?php
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') $GLOBALS['skipAppSessionForPublicRead'] = true;
require_once __DIR__ . '/db.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { http_response_code(405); exit; }
$attachmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$attachmentId) { http_response_code(404); exit; }
$stmt = $pdo->prepare("SELECT a.file_path, a.file_name FROM disclosure_attachment a
    JOIN disclosure d ON d.disclosure_id = a.disclosure_id
    WHERE a.attachment_id = ? AND d.is_archived = 0 LIMIT 1");
$stmt->execute([$attachmentId]);
$attachment = $stmt->fetch();
$file = $attachment ? resolveStoredUpload((string) $attachment['file_path']) : null;
if (!$attachment || !$file) { http_response_code(404); exit; }

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream';
if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) $mime = 'application/octet-stream';
$downloadName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string) $attachment['file_name'])) ?: 'attachment';
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('X-Content-Type-Options: nosniff');
// Attachments can be unpublished or deleted while a browser is open. Do not
// let the browser keep serving a stale copy after the database access check.
header('Cache-Control: no-store, private');
header('Content-Length: ' . filesize($file));
readfile($file);
