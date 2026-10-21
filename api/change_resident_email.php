<?php
require_once 'db.php';
require_once 'email.php';
require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['success'=>false, 'message'=>'Method not allowed']); exit; }
$isAdmin = ($_SESSION['role'] ?? '') === 'Admin' && !empty($_SESSION['user_id']);
$accountId = $isAdmin ? (int)$_SESSION['user_id'] : (int)($_SESSION['resident_id'] ?? 0);
if ($accountId < 1 || !in_array(($_SESSION['role'] ?? ''), ['Resident','Admin'], true)) { http_response_code(401); echo json_encode(['success'=>false, 'message'=>'Sign in to continue.']); exit; }
if (!rateLimit($pdo, 'change-profile-email', 5, 3600, ($isAdmin ? 'admin-' : 'resident-') . $accountId)) { http_response_code(429); echo json_encode(['success'=>false, 'message'=>'Too many attempts. Try again later.']); exit; }
$data = jsonBody(8192);
$email = strtolower(trim(is_string($data['email'] ?? null) ? $data['email'] : ''));
$password = is_string($data['password'] ?? null) ? $data['password'] : '';
if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) > 256) { http_response_code(400); echo json_encode(['success'=>false, 'message'=>'Enter a valid email address and your password.']); exit; }
if (!$isAdmin && !isAllowedEmailProvider($email)) { http_response_code(400); echo json_encode(['success'=>false, 'message'=>'Please use a known email provider, like Gmail, Yahoo, or Outlook.']); exit; }
$accountTable = $isAdmin ? '`user`' : 'resident'; $accountColumn = $isAdmin ? 'user_id' : 'resident_id';
$find = $pdo->prepare("SELECT first_name, email, password_hash" . ($isAdmin ? '' : ', archived_at') . " FROM $accountTable WHERE $accountColumn = ?");
$find->execute([$accountId]);
$account = $find->fetch();
if (!$account || (!$isAdmin && $account['archived_at']) || !password_verify($password, (string) $account['password_hash'])) { http_response_code(403); echo json_encode(['success'=>false, 'message'=>'The password is incorrect.']); exit; }
$exists = $pdo->prepare('SELECT email FROM resident WHERE email = ? AND resident_id <> ? UNION SELECT email FROM user WHERE email = ? AND user_id <> ? LIMIT 1');
$exists->execute([$email, $isAdmin ? -1 : $accountId, $email, $isAdmin ? $accountId : -1]);
if ($exists->fetch()) { http_response_code(409); echo json_encode(['success'=>false, 'message'=>'That email is already in use.']); exit; }
if ($isAdmin) {
    $pdo->prepare('UPDATE user SET email = ? WHERE user_id = ? AND role = ?')->execute([$email, $accountId, 'Admin']);
    $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, 'admin_email_changed', 'Updated administrator email address')")->execute([$accountId]);
    $_SESSION['email'] = $email;
    echo json_encode(['success'=>true,'email'=>$email,'emailVerified'=>true,'emailVerificationSent'=>false,'message'=>'Your email address has been updated.']);
    exit;
}
$appUrl = portalConfig()['app_url'];
if (!isAllowedPortalUrl($appUrl)) { http_response_code(503); echo json_encode(['success'=>false, 'message'=>'Email changes are temporarily unavailable.']); exit; }
$resident = $account;
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE resident SET email = ?, email_verified_at = NULL, archived_at = NULL, is_active = 1 WHERE resident_id = ?')->execute([$email, (int) $_SESSION['resident_id']]);
    $pdo->prepare('DELETE FROM email_verification_token WHERE resident_id = ?')->execute([(int) $_SESSION['resident_id']]);
    $rawToken = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO email_verification_token (token_hash, resident_id, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))')->execute([hash('sha256', $rawToken), (int) $_SESSION['resident_id']]);
    $pdo->commit();
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
$url = rtrim((string) $appUrl, '/') . '/index.php?verify=' . rawurlencode($rawToken);
$name = trim((string) $resident['first_name']) ?: 'there';
$plain = "Hello $name,\n\nVerify this new email address to continue using your Barangay San Jose account:\n$url\n\nThis link expires in 24 hours.\n\nBarangay San Jose";
$html = portalEmailTemplate('Account email change', 'Verify your new email address', ["Hello $name,", 'Confirm this address to enable document request submissions.'], 'Verify email', $url, 'This link expires in 24 hours.');
$error = null;
$sent = queuePortalEmail($pdo, $email, 'Verify your new Barangay San Jose email', $plain, $html, $error);
$_SESSION['fullName'] = trim((string) ($_SESSION['fullName'] ?? $name));
echo json_encode(['success'=>true, 'email'=>$email, 'emailVerified'=>false, 'emailVerificationSent'=>$sent, 'message'=>$sent ? 'Email changed. Check the new inbox to verify it.' : 'Email changed, but the verification email could not be queued. Use Resend verification to try again.']);
?>
