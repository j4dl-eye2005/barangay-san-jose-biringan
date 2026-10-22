<?php
require_once __DIR__ . '/db.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { http_response_code(405); exit; }
requireAdminAccess();
$residentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$residentId || !rateLimit($pdo, 'resident-proof', 60, 60)) { http_response_code($residentId ? 429 : 404); exit; }

$stmt = $pdo->prepare('SELECT proof_path FROM resident WHERE resident_id = ? AND is_active = 0');
$stmt->execute([$residentId]);
$storedPath = (string) ($stmt->fetchColumn() ?: '');
$file = resolveStoredUpload($storedPath);
if (!$file) { http_response_code(404); exit; }

$audit = $pdo->prepare("INSERT INTO audit_log (user_id, subject_resident_id, action, details) VALUES (?, ?, 'proof_viewed', ?)");
$audit->execute([$_SESSION['user_id'], $residentId, 'Resident ID ' . $residentId]);
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream';
if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) $mime = 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="residency-proof.' . pathinfo($file, PATHINFO_EXTENSION) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('Content-Length: ' . filesize($file));
readfile($file);
