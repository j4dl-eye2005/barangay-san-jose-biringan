<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$data = jsonBody(8192);
$token = strtolower(trim(is_string($data['token'] ?? null) ? $data['token'] : ''));
$password = is_string($data['password'] ?? null) ? $data['password'] : '';
$returnToProfile = ($data['returnToProfile'] ?? false) === true;
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'pageUnavailable' => true, 'message' => 'This page isn’t available. Check the address or return to the home page.']);
    exit;
}
if (!passwordMeetsPolicy($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Use at least 8 characters with uppercase and lowercase letters and a number.']);
    exit;
}
if (!rateLimit($pdo, 'password-reset-complete', 10, 3600)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many attempts. Try again later.']);
    exit;
}

try {
    $pdo->beginTransaction();
    $find = $pdo->prepare('SELECT account_type, account_id FROM password_reset_token WHERE token_hash = ? AND expires_at > NOW() FOR UPDATE');
    $find->execute([hash('sha256', $token)]);
    $reset = $find->fetch();
    if (!$reset || !in_array($reset['account_type'], ['resident', 'admin'], true)) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'pageUnavailable' => true, 'message' => 'This page isn’t available. Check the address or return to the home page.']);
        exit;
    }
    if ($reset['account_type'] === 'admin' && !hasAdminEntryAuthorization()) {
        $pdo->rollBack();
        denyAdminEntryAccess(true);
    }

    $table = $reset['account_type'] === 'admin' ? 'user' : 'resident';
    $idColumn = $reset['account_type'] === 'admin' ? 'user_id' : 'resident_id';
    $update = $pdo->prepare("UPDATE $table SET password_hash = ?, password_changed_at = UTC_TIMESTAMP(), auth_version = auth_version + 1 WHERE $idColumn = ?");
    $update->execute([password_hash($password, PASSWORD_DEFAULT), $reset['account_id']]);
    if ($update->rowCount() !== 1) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'This account could not be found. Request a new reset link.']);
        exit;
    }
    $profileResident = null;
    if ($returnToProfile && $reset['account_type'] === 'resident') {
        $profile = $pdo->prepare('SELECT resident_id, first_name, last_name, email, phone_number, email_verified_at, archived_at, auth_version FROM resident WHERE resident_id = ? LIMIT 1');
        $profile->execute([(int) $reset['account_id']]);
        $profileResident = $profile->fetch();
        if ($profileResident && $profileResident['archived_at']) $profileResident = null;
    }
    $delete = $pdo->prepare('DELETE FROM password_reset_token WHERE account_type = ? AND account_id = ?');
    $delete->execute([$reset['account_type'], $reset['account_id']]);
    $audit = $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (NULL, 'password_reset_completed', ?)");
    $audit->execute(['Account type ' . $reset['account_type'] . '; account ID ' . (int) $reset['account_id']]);
    $pdo->commit();

    $sessionRestored = is_array($profileResident);
    $response = ['success' => true, 'message' => 'Your password has been changed. You can now log in with your new password.', 'sessionRestored' => false];
    if ($sessionRestored) {
        session_regenerate_id(true);
        $_SESSION = [
            'resident_id' => (int) $profileResident['resident_id'],
            'role' => 'Resident',
            'fullName' => trim($profileResident['first_name'] . ' ' . $profileResident['last_name']),
            '_auth_version' => (int) $profileResident['auth_version'],
            '_created_at' => time(),
            '_last_activity' => time()
        ];
        $response['csrfToken'] = rotateCsrfToken();
        $response['sessionRestored'] = true;
        $response['message'] = 'Your password has been changed.';
    }
    echo json_encode($response);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Password reset completion error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'We could not update your password right now. Please try again.']);
}
