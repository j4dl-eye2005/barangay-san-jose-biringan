<?php
require_once 'db.php';
require_once 'email.php';
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['success'=>false, 'message'=>'Method not allowed']); exit; }
if (($_SESSION['role'] ?? '') !== 'Resident' || empty($_SESSION['resident_id'])) { http_response_code(401); echo json_encode(['success'=>false, 'message'=>'Sign in to continue.']); exit; }
if (!rateLimit($pdo, 'resend-verification', 5, 3600, (string) $_SESSION['resident_id'])) { http_response_code(429); echo json_encode(['success'=>false, 'message'=>'Too many attempts. Try again later.']); exit; }
$stmt = $pdo->prepare('SELECT first_name, email, email_verified_at, archived_at FROM resident WHERE resident_id = ?');
$stmt->execute([(int) $_SESSION['resident_id']]);
$resident = $stmt->fetch();
if (!$resident || $resident['archived_at']) { http_response_code(403); echo json_encode(['success'=>false, 'message'=>'This account is unavailable.']); exit; }
if ($resident['email_verified_at']) { echo json_encode(['success'=>true, 'alreadyVerified'=>true, 'message'=>'Your email is already verified.']); exit; }
$appUrl = portalConfig()['app_url'];
if (!isAllowedPortalUrl($appUrl)) { http_response_code(503); echo json_encode(['success'=>false, 'message'=>'Verification email is temporarily unavailable.']); exit; }
$pdo->prepare('DELETE FROM email_verification_token WHERE resident_id = ?')->execute([(int) $_SESSION['resident_id']]);
$rawToken = bin2hex(random_bytes(32));
$pdo->prepare('INSERT INTO email_verification_token (token_hash, resident_id, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))')->execute([hash('sha256', $rawToken), (int) $_SESSION['resident_id']]);
$url = $appUrl . '/index.php?verify=' . rawurlencode($rawToken);
$name = trim((string) $resident['first_name']) ?: 'there';
$plain = "Hello $name,\n\nVerify your email address to submit document requests:\n$url\n\nThis link expires in 24 hours.\n\nBarangay San Jose";
$html = portalEmailTemplate('Account verification', 'Verify your email address', ["Hello $name,", 'Verify this email address to submit document requests.'], 'Verify email', $url, 'This link expires in 24 hours.');
$error = null;
$sent = queuePortalEmail($pdo, (string) $resident['email'], 'Verify your Barangay San Jose account', $plain, $html, $error);
if (!$sent) { error_log('Verification email could not be queued: ' . ($error ?? 'unknown queue error')); http_response_code(503); echo json_encode(['success'=>false, 'message'=>'Could not send the verification email. Try again later.']); exit; }
echo json_encode(['success'=>true, 'message'=>'Verification email sent.']);
?>
