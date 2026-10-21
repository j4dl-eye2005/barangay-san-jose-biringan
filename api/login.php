<?php
require_once 'db.php';
require_once 'recaptcha.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
$data = jsonBody(8192);
$email = strtolower(trim(is_string($data['email'] ?? null) ? $data['email'] : ''));
$password = is_string($data['password'] ?? null) ? $data['password'] : '';
$context = is_string($data['context'] ?? null) && strtolower($data['context']) === 'admin' ? 'admin' : 'resident';
$website = trim(is_string($data['website'] ?? null) ? $data['website'] : '');
if ($context === 'admin' && !hasAdminEntryAuthorization()) {
    denyAdminEntryAccess(true);
}
if (
    strlen($email) > 190
    || strlen($password) > 256
    || ($context === 'admin'
        ? !filter_var($email, FILTER_VALIDATE_EMAIL)
        : !isAllowedEmailProvider($email))
) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
    exit;
}

// Verify reCAPTCHA (only for resident login — admin login skips CAPTCHA)
$recaptchaToken = is_string($data['recaptchaToken'] ?? null) ? $data['recaptchaToken'] : '';
if ($context === 'resident' && !verifyRecaptcha($recaptchaToken)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please complete the CAPTCHA and try again.']);
    exit;
}
if (!rateLimit($pdo, 'login-' . $context, 10, 900, $email)) {
    http_response_code(429);
    header('Retry-After: 900');
    echo json_encode(['success' => false, 'message' => 'Too many sign-in attempts. Try again in 15 minutes.']);
    exit;
}
if ($website !== '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
    exit;
}

    // Each login context can authenticate only the matching account type.
    if ($context === 'admin') {
        $stmt = $pdo->prepare("SELECT user_id, first_name, last_name, email, password_hash, role, auth_version, admin_mfa_secret_enc, admin_mfa_enabled_at FROM user WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && $user['role'] === 'Admin' && password_verify($password, $user['password_hash'])) {
            try {
                adminMfaEncryptionKey();
            } catch (Throwable $e) {
                error_log('Admin MFA encryption is unavailable.');
                http_response_code(503);
                echo json_encode(['success' => false, 'message' => 'Admin sign-in is temporarily unavailable.']);
                exit;
            }
            $adminEntryAuthorizedAt = (int) ($_SESSION['admin_entry_authorized_at'] ?? 0);
            session_regenerate_id(true);
            $_SESSION = [
                'pending_admin_mfa_user_id' => (int) $user['user_id'],
                'pending_admin_auth_version' => (int) $user['auth_version'],
                'pending_admin_mfa_expires_at' => time() + 300,
                'admin_entry_authorized' => true,
                'admin_entry_authorized_at' => $adminEntryAuthorizedAt,
                '_created_at' => time(),
                '_last_activity' => time(),
            ];
            $csrfToken = rotateCsrfToken();
            if (empty($user['admin_mfa_enabled_at']) || empty($user['admin_mfa_secret_enc'])) {
                $_SESSION['pending_admin_mfa_setup_secret'] = generateAdminMfaSecret();
                echo json_encode([
                    'mfaSetupRequired' => true,
                    'message' => 'Set up an authenticator app to finish admin sign-in.',
                    'secret' => $_SESSION['pending_admin_mfa_setup_secret'],
                    'csrfToken' => $csrfToken,
                ]);
            } else {
                echo json_encode(['mfaRequired' => true, 'message' => 'Enter the current code from your authenticator app.', 'csrfToken' => $csrfToken]);
            }
            exit;
        }
        password_verify($password, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)');
        $audit->execute([$user && $user['role'] === 'Admin' ? (int) $user['user_id'] : null, 'failed_admin_login', 'Failed admin sign-in from IP hash ' . hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) . '; device ' . substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'), 0, 160)]);
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT resident_id, first_name, last_name, email, phone_number, password_hash, email_verified_at, archived_at, auth_version FROM resident WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $resident = $stmt->fetch();
    if ($resident && empty($resident['archived_at']) && password_verify($password, $resident['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION = ['resident_id' => $resident['resident_id'], 'role' => 'Resident', 'fullName' => $resident['first_name'] . ' ' . $resident['last_name'], '_auth_version' => (int) $resident['auth_version'], '_created_at' => time(), '_last_activity' => time()];
        $csrfToken = rotateCsrfToken();
        $audit = $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (NULL, 'resident_login_success', ?)");
        $audit->execute(['Resident ID ' . (int) $resident['resident_id']]);
        echo json_encode(['success' => true, 'id' => $resident['resident_id'], 'name' => $_SESSION['fullName'], 'role' => 'Resident', 'email' => $resident['email'], 'phoneNumber' => $resident['phone_number'] ?? '', 'emailVerified' => !empty($resident['email_verified_at']), 'csrfToken' => $csrfToken]);
        exit;
    }
    if ($resident) password_verify($password, $resident['password_hash']);
    else password_verify($password, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
    $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, details) VALUES (NULL, ?, ?)');
    $audit->execute(['failed_resident_login', 'Failed resident sign-in from IP hash ' . hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))]);
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
?>
