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
    // The browser submits this form as multipart/form-data because it contains a file.
    $email = strtolower(trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : ''));
    $website = trim(is_string($_POST['website'] ?? null) ? $_POST['website'] : '');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $firstName = trim(is_string($_POST['firstName'] ?? null) ? $_POST['firstName'] : '');
    $middleName = trim(is_string($_POST['middleName'] ?? null) ? $_POST['middleName'] : '');
    $lastName = trim(is_string($_POST['lastName'] ?? null) ? $_POST['lastName'] : '');
    $phone = trim(is_string($_POST['phone'] ?? null) ? $_POST['phone'] : '');
    $address = trim(is_string($_POST['address'] ?? null) ? $_POST['address'] : '');
    $termsAccepted = ($_POST['termsAccepted'] ?? '') === '1';
    $proof = $_FILES['proof'] ?? null;

    if (!$email || !$password || !$firstName || !$lastName || !$address || !$proof || !$termsAccepted) {
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
    if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || !passwordMeetsPolicy($password)
        || !$validName($firstName) || !$validName($lastName) || ($middleName !== '' && !$validName($middleName))
        || strlen($address) > 255 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $address)
        || ($phone !== '' && !preg_match('/^\+?[0-9().\s-]{7,30}$/', $phone))) {
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

    $extension = validateUpload($proof, ['pdf' => true, 'docx' => true, 'jpg' => true, 'jpeg' => true, 'png' => true], 5 * 1024 * 1024);
    if ($extension === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Registration could not be completed. Check the proof file and try again.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT email FROM resident WHERE email = ? UNION SELECT email FROM user WHERE email = ?");
    $stmt->execute([$email, $email]);
    if ($stmt->fetch()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Registration could not be completed. Check your details and try again.']);
        exit;
    }

    // Save the proof file first so a failed upload never leaves an orphan
    // resident row without an attached document.
    $proofDirectory = privateStorageDirectory('proofs');

    $proofName = bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($proof['tmp_name'], $proofDirectory . DIRECTORY_SEPARATOR . $proofName)) {
        throw new RuntimeException('Unable to save proof file');
    }
    $proofPath = 'proofs/' . $proofName;

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO resident (first_name, middle_name, last_name, email, phone_number, address, proof_path, password_hash, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)"
        );
        $stmt->execute([$firstName, $middleName, $lastName, $email, $phone, $address, $proofPath, $passwordHash]);
        $residentId = (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        // Roll back the stored file if the insert fails.
        $storedProof = $proofDirectory . DIRECTORY_SEPARATOR . $proofName;
        if (is_file($storedProof)) unlink($storedProof);
        throw $e;
    }

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $token = $pdo->prepare('INSERT INTO email_verification_token (token_hash, resident_id, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))');
    $token->execute([$tokenHash, $residentId]);
    $verificationUrl = $appUrl . '/index.php?verify=' . rawurlencode($rawToken);
    $firstNameForEmail = trim($firstName) ?: 'there';
    $plainMessage = "Hello $firstNameForEmail,\n\nPlease verify your email address to complete your Barangay San Jose resident registration:\n$verificationUrl\n\nThis link expires in 24 hours. After verification, a barangay administrator will review your residency proof.\n\nBarangay San Jose";
    $htmlMessage = portalEmailTemplate('Resident registration', 'Verify your email address', [
        "Hello $firstNameForEmail,",
        'Please verify your email address to complete your resident registration. A barangay administrator will review your residency proof after verification.'
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

        $pdo->prepare('DELETE FROM resident WHERE resident_id = ?')->execute([$residentId]);
        if (is_file($proofDirectory . DIRECTORY_SEPARATOR . $proofName)) unlink($proofDirectory . DIRECTORY_SEPARATOR . $proofName);
        error_log('Registration verification email could not be queued: ' . ($mailError ?? 'unknown queue error'));
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Registration could not be completed right now. Please try again later.']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Check your email for a verification link. Your residency will be reviewed after you verify.']);
} catch (Throwable $e) {
    error_log('Registration processing failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Registration failed. Please try again.']);
}
?>
