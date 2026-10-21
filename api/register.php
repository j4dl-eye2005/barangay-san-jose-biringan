<?php
require_once 'db.php';
require_once 'email.php';
require_once 'config.php';
require_once 'recaptcha.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST request required']);
    exit;
}

try {
    $email = strtolower(trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : ''));
    $website = trim(is_string($_POST['website'] ?? null) ? $_POST['website'] : '');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $firstName = trim(is_string($_POST['firstName'] ?? null) ? $_POST['firstName'] : '');
    $middleName = trim(is_string($_POST['middleName'] ?? null) ? $_POST['middleName'] : '');
    $lastName = trim(is_string($_POST['lastName'] ?? null) ? $_POST['lastName'] : '');
    $phone = trim(is_string($_POST['phone'] ?? null) ? $_POST['phone'] : '');
    $address = trim(is_string($_POST['address'] ?? null) ? $_POST['address'] : '');
    $termsAccepted = ($_POST['termsAccepted'] ?? '') === '1';
    if (!$email || !$password || !$firstName || !$lastName || !$address || !$termsAccepted) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Registration could not be completed. Check your details and try again.']);
        exit;
    }

    // Verify reCAPTCHA before any other processing
    $recaptchaToken = is_string($_POST['g-recaptcha-response'] ?? null) ? $_POST['g-recaptcha-response'] : '';
    if (!verifyRecaptcha($recaptchaToken)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please complete the CAPTCHA and try again.']);
        exit;
    }
    $validName = static fn(string $name): bool => (bool) preg_match('/^[\p{L}\p{M} .\'-]{1,100}$/u', $name);
    if (strlen($email) > 190 || !isAllowedEmailProvider($email)
        || !passwordMeetsPolicy($password)
        || !$validName($firstName) || !$validName($lastName) || ($middleName !== '' && !$validName($middleName))
        || strlen($address) > 255 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $address)
        || ($phone !== '' && !isValidPhilippineMobile($phone))) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Registration could not be completed. Check your details and try again.']);
        exit;
    }

    $appUrl = portalConfig()['app_url'];
    if (!isAllowedPortalUrl($appUrl)) {
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Registration is temporarily unavailable. Please try again later.']);
        exit;
    }

    // Version the bucket so stale counters from earlier deployment/testing can
    // be left behind without blocking users after a configuration correction.
    if (!rateLimit($pdo, 'registration-v2', 5, 3600)) {
        http_response_code(429);
        header('Retry-After: 3600');
        echo json_encode(['success' => false, 'message' => 'Too many registration attempts. Try again later.']);
        exit;
    }
    if ($website !== '') {
        echo json_encode(['success' => true, 'message' => 'Registration request received.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT email FROM resident WHERE email = ? UNION SELECT email FROM user WHERE email = ?");
    $stmt->execute([$email, $email]);
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'This email already exists. Sign in to the existing account or use another email.']);
        exit;
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare(
        "INSERT INTO resident (first_name, middle_name, last_name, email, phone_number, address, password_hash, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)"
    );
    $stmt->execute([$firstName, $middleName, $lastName, $email, $phone, $address, $passwordHash]);
    $residentId = (int) $pdo->lastInsertId();

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $token = $pdo->prepare('INSERT INTO email_verification_token (token_hash, resident_id, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))');
    $token->execute([$tokenHash, $residentId]);
    $verificationUrl = $appUrl . '/index.php?verify=' . rawurlencode($rawToken);
    $firstNameForEmail = trim($firstName) ?: 'there';
    $plainMessage = "Hello $firstNameForEmail,\n\nPlease verify your email address for your Barangay San Jose account:\n$verificationUrl\n\nThis link expires in 24 hours. You can log in and browse the portal now; email verification is required before you submit a document request.\n\nBarangay San Jose";
    $htmlMessage = portalEmailTemplate('Resident registration', 'Verify your email address', [
        "Hello $firstNameForEmail,",
        'Please verify your email address. You can log in and browse the portal now; email verification is required before submitting a document request.'
    ], 'Verify email', $verificationUrl, 'This link expires in 24 hours.');
    $mailError = null;
    $mailQueued = queuePortalEmail($pdo, $email, 'Verify your Barangay San Jose account', $plainMessage, $htmlMessage, $mailError);
    if (!$mailQueued) {
        // Keep a credential-redacted diagnostic in the database so shared
        // hosting without accessible PHP error logs can still be diagnosed.
        $mailDiagnostic = (string) ($mailError ?? 'Unknown mail error');
        $mailDiagnostic = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $mailDiagnostic) ?? 'Mail delivery failed';
        $mailDiagnostic = preg_replace('/(?i)\b(?:password|token)\b\s*[:=]\s*\S+/', '[credential redacted]', $mailDiagnostic) ?? 'Mail delivery failed';
        $mailDiagnostic = trim(preg_replace('/[\r\n\t]+/', ' ', $mailDiagnostic) ?? 'Mail delivery failed');
        $mailDiagnostic = substr($mailDiagnostic, 0, 500);
        try {
            $audit = $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (NULL, 'registration_email_failed', ?)");
            $audit->execute([$mailDiagnostic]);
        } catch (Throwable $diagnosticError) {
            error_log('Could not save registration mail diagnostic.');
        }

        error_log('Registration verification email could not be queued: ' . ($mailError ?? 'unknown queue error'));
    }

    echo json_encode(['success' => true, 'emailVerificationSent' => $mailQueued, 'message' => $mailQueued
        ? 'Your account is ready. You can log in now. Verify your email before submitting a document request.'
        : 'Your account is ready and you can log in now. We could not send the verification email, but you can resend it from your account.']);
} catch (Throwable $e) {
    error_log('Registration processing failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Registration failed. Please try again.']);
}
?>
