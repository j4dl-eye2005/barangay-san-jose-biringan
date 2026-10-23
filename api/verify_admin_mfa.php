<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}
if (!hasAdminEntryAuthorization()) denyAdminEntryAccess(true);

$userId = (int) ($_SESSION['pending_admin_mfa_user_id'] ?? 0);
$expiresAt = (int) ($_SESSION['pending_admin_mfa_expires_at'] ?? 0);
if ($userId < 1 || $expiresAt < time()) {
    unset($_SESSION['pending_admin_mfa_user_id'], $_SESSION['pending_admin_auth_version'], $_SESSION['pending_admin_mfa_expires_at'], $_SESSION['pending_admin_mfa_setup_secret']);
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Your sign-in step expired. Start admin sign-in again.']);
    exit;
}
if (!rateLimit($pdo, 'admin-mfa-verify', 8, 600, 'admin-' . $userId)) {
    http_response_code(429);
    header('Retry-After: 600');
    echo json_encode(['success' => false, 'message' => 'Too many code attempts. Start admin sign-in again later.']);
    exit;
}
$data = jsonBody(4096);
$code = strtoupper(trim(is_string($data['code'] ?? null) ? $data['code'] : ''));
$isRecoveryAttempt = preg_match('/^[A-F0-9]{20}$/', $code) === 1;
if (!preg_match('/^(?:\d{6}|[A-F0-9]{20})$/', $code)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Enter a 6-digit authenticator code or a 20-character recovery code.']);
    exit;
}

$stmt = $pdo->prepare('SELECT user_id, first_name, last_name, email, role, auth_version, admin_mfa_secret_enc, admin_mfa_enabled_at, admin_mfa_last_counter FROM user WHERE user_id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user || $user['role'] !== 'Admin' || !isset($_SESSION['pending_admin_auth_version'])
    || (int) $_SESSION['pending_admin_auth_version'] !== (int) $user['auth_version']) {
    unset($_SESSION['pending_admin_mfa_user_id'], $_SESSION['pending_admin_auth_version'], $_SESSION['pending_admin_mfa_expires_at'], $_SESSION['pending_admin_mfa_setup_secret']);
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Admin sign-in could not be verified.']);
    exit;
}

$isEnrollment = empty($user['admin_mfa_enabled_at']) || empty($user['admin_mfa_secret_enc']);
$usedRecoveryCode = false;
$matchedCounter = null;
$issuedRecoveryCodes = [];
try {
    if (!$isEnrollment && preg_match('/^[A-F0-9]{20}$/', $code)) {
        $consume = $pdo->prepare('UPDATE admin_mfa_recovery_code SET used_at = UTC_TIMESTAMP() WHERE user_id = ? AND code_hash = ? AND used_at IS NULL');
        $consume->execute([$userId, hash('sha256', $code)]);
        $usedRecoveryCode = $consume->rowCount() === 1;
    } else {
        $secret = $isEnrollment
            ? (string) ($_SESSION['pending_admin_mfa_setup_secret'] ?? '')
            : decryptAdminMfaSecret((string) $user['admin_mfa_secret_enc']);
        if ($secret === '') throw new RuntimeException('Admin MFA setup is unavailable.');
        $lastCounter = $isEnrollment || $user['admin_mfa_last_counter'] === null ? null : (int) $user['admin_mfa_last_counter'];
        $matchedCounter = preg_match('/^\d{6}$/', $code) ? verifyAdminTotp($secret, $code, $lastCounter) : null;
    }
    if (!$usedRecoveryCode && $matchedCounter === null) {
        $audit = $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, 'admin_mfa_failed', ?)");
        $audit->execute([$userId, 'Failed authenticator or recovery code; IP hash ' . hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) . '; device ' . substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'), 0, 160)]);
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => $isRecoveryAttempt
            ? 'That recovery code is incorrect or has already been used. Check it and try again.'
            : 'That authenticator code is incorrect or expired. Check your device time and try again.']);
        exit;
    }

    $mfaAccepted = false;
    if ($isEnrollment) {
        $encryptedSecret = encryptAdminMfaSecret($secret);
        for ($i = 0; $i < 10; $i++) $issuedRecoveryCodes[] = strtoupper(bin2hex(random_bytes(5)));
        $pdo->beginTransaction();
        $save = $pdo->prepare('UPDATE user SET admin_mfa_secret_enc = ?, admin_mfa_enabled_at = UTC_TIMESTAMP(), admin_mfa_last_counter = ? WHERE user_id = ? AND admin_mfa_enabled_at IS NULL');
        $save->execute([$encryptedSecret, $matchedCounter, $userId]);
        $mfaAccepted = $save->rowCount() === 1;
        if ($mfaAccepted) {
            $pdo->prepare('DELETE FROM admin_mfa_recovery_code WHERE user_id = ?')->execute([$userId]);
            $insert = $pdo->prepare('INSERT INTO admin_mfa_recovery_code (user_id, code_hash) VALUES (?, ?)');
            foreach ($issuedRecoveryCodes as $recoveryCode) $insert->execute([$userId, hash('sha256', $recoveryCode)]);
            $pdo->commit();
        } else {
            $pdo->rollBack();
            $issuedRecoveryCodes = [];
        }
    } elseif ($usedRecoveryCode) {
        $mfaAccepted = true;
    } else {
        $save = $pdo->prepare('UPDATE user SET admin_mfa_last_counter = ? WHERE user_id = ? AND (admin_mfa_last_counter IS NULL OR admin_mfa_last_counter < ?)');
        $save->execute([$matchedCounter, $userId, $matchedCounter]);
        $mfaAccepted = $save->rowCount() === 1;
    }
    if (!$mfaAccepted) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'That code is invalid or expired. Check your device time and try again.']);
        exit;
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Admin MFA verification failed due to server configuration.');
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Admin verification is temporarily unavailable.']);
    exit;
}

$entryAuthorizedAt = (int) ($_SESSION['admin_entry_authorized_at'] ?? 0);
session_regenerate_id(true);
$_SESSION = [
    'user_id' => (int) $user['user_id'],
    '_auth_version' => (int) $user['auth_version'],
    'role' => 'Admin',
    'fullName' => trim($user['first_name'] . ' ' . $user['last_name']),
    'email' => (string) $user['email'],
    'admin_entry_authorized' => true,
    'admin_entry_authorized_at' => $entryAuthorizedAt,
    'admin_mfa_verified_at' => time(),
    '_created_at' => time(),
    '_last_activity' => time(),
];
$csrfToken = rotateCsrfToken();
$audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)');
$loginDetail = $isEnrollment
    ? 'Admin enrolled authenticator MFA'
    : ($usedRecoveryCode ? 'Admin completed MFA sign-in using a recovery code' : 'Admin completed MFA sign-in');
$audit->execute([$userId, 'admin_login_mfa', $loginDetail . '; device ' . substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'), 0, 160)]);
$response = ['success' => true, 'id' => $userId, 'name' => $_SESSION['fullName'], 'email' => $_SESSION['email'], 'role' => 'Admin', 'createdAt' => (int) $_SESSION['_created_at'], 'csrfToken' => $csrfToken];
if ($isEnrollment) $response['recoveryCodes'] = $issuedRecoveryCodes;
echo json_encode($response);
