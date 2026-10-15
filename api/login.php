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
if ($context === 'admin' && !isAllowedAdminIp()) {
    denyAdminIpAccess(true);
}
if (strlen($email) > 190 || strlen($password) > 256 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
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
        $stmt = $pdo->prepare("SELECT user_id, first_name, last_name, email, password_hash, role FROM user WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && $user['role'] === 'Admin' && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION = ['user_id' => $user['user_id'], 'role' => 'Admin', 'fullName' => $user['first_name'] . ' ' . $user['last_name'], '_created_at' => time(), '_last_activity' => time()];
            $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)');
            $audit->execute([$user['user_id'], 'admin_login', 'Admin signed in']);
            echo json_encode(['success' => true, 'id' => $user['user_id'], 'name' => $_SESSION['fullName'], 'role' => 'Admin']);
            exit;
        }
        password_verify($password, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, details) VALUES (NULL, ?, ?)');
        $audit->execute(['failed_admin_login', 'Failed admin sign-in from IP hash ' . hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))]);
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT resident_id, first_name, last_name, email, password_hash, is_active FROM resident WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $resident = $stmt->fetch();
    if ($resident && password_verify($password, $resident['password_hash']) && (int) $resident['is_active'] === 1) {
        session_regenerate_id(true);
        $_SESSION = ['resident_id' => $resident['resident_id'], 'role' => 'Resident', 'fullName' => $resident['first_name'] . ' ' . $resident['last_name'], '_created_at' => time(), '_last_activity' => time()];
        echo json_encode(['success' => true, 'id' => $resident['resident_id'], 'name' => $_SESSION['fullName'], 'role' => 'Resident']);
        exit;
    }
    if ($resident) password_verify($password, $resident['password_hash']);
    else password_verify($password, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
    $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, details) VALUES (NULL, ?, ?)');
    $audit->execute(['failed_resident_login', 'Failed resident sign-in from IP hash ' . hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))]);
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
?>
