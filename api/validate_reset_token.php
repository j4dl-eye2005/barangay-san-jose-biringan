<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'This page isn’t available. Check the address or return to the home page.']);
    exit;
}

$data = jsonBody(8192);
$token = strtolower(trim(is_string($data['token'] ?? null) ? $data['token'] : ''));
if (!preg_match('/^[a-f0-9]{64}$/', $token)) denyAdminEntryAccess(true);

$find = $pdo->prepare('SELECT account_type FROM password_reset_token WHERE token_hash = ? AND expires_at > NOW() LIMIT 1');
$find->execute([hash('sha256', $token)]);
$accountType = $find->fetchColumn();
if (!in_array($accountType, ['resident', 'admin'], true)) denyAdminEntryAccess(true);
if ($accountType === 'admin' && !hasAdminEntryAuthorization()) denyAdminEntryAccess(true);

echo json_encode(['success' => true]);
