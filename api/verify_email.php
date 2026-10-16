<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['success'=>false, 'message'=>'Method not allowed']); exit; }
if (!rateLimit($pdo, 'verify-email', 10, 3600)) { http_response_code(429); echo json_encode(['success'=>false, 'message'=>'Too many attempts. Try again later.']); exit; }
$data = jsonBody(8192);
$token = strtolower(trim(is_string($data['token'] ?? null) ? $data['token'] : ''));
if (!preg_match('/^[a-f0-9]{64}$/', $token)) { http_response_code(404); echo json_encode(['success'=>false, 'pageUnavailable'=>true, 'message'=>'This page isn’t available. Check the address or return to the home page.']); exit; }

try {
    $pdo->beginTransaction();
    $find = $pdo->prepare('SELECT resident_id FROM email_verification_token WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP() FOR UPDATE');
    $find->execute([hash('sha256', $token)]);
    $residentId = $find->fetchColumn();
    if (!$residentId) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success'=>false, 'pageUnavailable'=>true, 'message'=>'This page isn’t available. Check the address or return to the home page.']);
        exit;
    }
    $update = $pdo->prepare('UPDATE resident SET email_verified_at = UTC_TIMESTAMP() WHERE resident_id = ? AND email_verified_at IS NULL');
    $update->execute([(int) $residentId]);
    $pdo->prepare('DELETE FROM email_verification_token WHERE resident_id = ?')->execute([(int) $residentId]);
    $audit = $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (NULL, 'resident_email_verified', ?)");
    $audit->execute(['Resident ID ' . (int) $residentId]);
    $pdo->commit();
    echo json_encode(['success'=>true, 'message'=>'Email verified. Your residency is ready for review.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Email verification failed: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['success'=>false, 'message'=>'Email verification could not be completed right now.']);
}
