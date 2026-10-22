<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}
 $isAdmin = ($_SESSION['role'] ?? '') === 'Admin' && !empty($_SESSION['user_id']);
 $accountId = $isAdmin ? (int)$_SESSION['user_id'] : (int)($_SESSION['resident_id'] ?? 0);
if ($accountId < 1 || !in_array(($_SESSION['role'] ?? ''), ['Resident','Admin'], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sign in to continue.']);
    exit;
}
if (!rateLimit($pdo, 'change-profile-password', 5, 3600, ($isAdmin ? 'admin-' : 'resident-') . $accountId)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many attempts. Try again later.']);
    exit;
}

$data = jsonBody(8192);
$password = is_string($data['password'] ?? null) ? $data['password'] : '';
$confirmation = is_string($data['confirmation'] ?? null) ? $data['confirmation'] : '';
if (!passwordMeetsPolicy($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Use at least 8 characters with uppercase and lowercase letters and a number.']);
    exit;
}
if (!hash_equals($password, $confirmation)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'The passwords do not match.']);
    exit;
}

try {
    $accountTable = $isAdmin ? '`user`' : 'resident';
    $accountColumn = $isAdmin ? 'user_id' : 'resident_id';
    $pdo->beginTransaction();
    $update = $pdo->prepare("UPDATE $accountTable SET password_hash = ?, password_changed_at = UTC_TIMESTAMP(), auth_version = auth_version + 1 WHERE $accountColumn = ?" . ($isAdmin ? '' : ' AND archived_at IS NULL'));
    $update->execute([password_hash($password, PASSWORD_DEFAULT), $accountId]);
    if ($update->rowCount() !== 1) {
        $pdo->rollBack();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Your account could not be found. Sign in again and retry.']);
        exit;
    }

    $version = $pdo->prepare("SELECT auth_version FROM $accountTable WHERE $accountColumn = ?");
    $version->execute([$accountId]);
    $authVersion = $version->fetchColumn();
    if ($authVersion === false) throw new RuntimeException('Account disappeared during password update.');

    $audit = $pdo->prepare("INSERT INTO audit_log (user_id, subject_resident_id, action, details) VALUES (?, ?, ?, ?)");
    $audit->execute([$isAdmin ? $accountId : null, $isAdmin ? null : $accountId, $isAdmin ? 'admin_password_changed' : 'resident_password_changed', $isAdmin ? 'Administrator changed their password' : 'Resident ID ' . $accountId]);
    $pdo->commit();

    $oldSessionKey = hash('sha256', session_id());
    session_regenerate_id(true);
    $_SESSION['_auth_version'] = (int) $authVersion;
    $_SESSION['_last_activity'] = time();
    $pdo->prepare('DELETE FROM account_session WHERE session_key = ?')->execute([$oldSessionKey]);
    $csrfToken = rotateCsrfToken();
    echo json_encode(['success' => true, 'message' => 'Your password has been updated.', 'csrfToken' => $csrfToken]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Resident password change failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not update your password right now. Please try again.']);
}
