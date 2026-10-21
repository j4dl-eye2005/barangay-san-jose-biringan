<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

function resetResponse(string $message): void
{
    echo json_encode(['success' => true, 'message' => $message]);
    exit;
}
$genericMessage = 'If an account matches that email, a password reset link will be sent shortly.';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!rateLimit($pdo, 'password-reset', 5, 3600)) {
    http_response_code(429);
    header('Retry-After: 3600');
    resetResponse($genericMessage);
}

$data = jsonBody(8192);
$email = trim(is_string($data['email'] ?? null) ? $data['email'] : '');
$context = is_string($data['context'] ?? null) && strtolower($data['context']) === 'admin' ? 'admin' : 'resident';
if ($context === 'admin' && !hasAdminEntryAuthorization()) {
    denyAdminEntryAccess(true);
}

if (strlen($email) > 190 || !isAllowedEmailProvider($email)) resetResponse($genericMessage);

try {
    // Keep this idempotent for existing installations; the same table is also
    // included in schema.sql for clean installs.
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_token (
        token_hash CHAR(64) NOT NULL PRIMARY KEY,
        account_type VARCHAR(10) NOT NULL,
        account_id INT NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_password_reset_account (account_type, account_id, created_at),
        INDEX idx_password_reset_expiry (expires_at)
    ) ENGINE=InnoDB");

    $table = $context === 'admin' ? 'user' : 'resident';
    $idColumn = $context === 'admin' ? 'user_id' : 'resident_id';
    $archiveFilter = $context === 'resident' ? ' AND archived_at IS NULL' : '';
    $lookup = $pdo->prepare("SELECT $idColumn AS account_id, first_name FROM $table WHERE email = ?$archiveFilter LIMIT 1");
    $lookup->execute([$email]);
    $account = $lookup->fetch();
    if (!$account) resetResponse($genericMessage);

    $returnToProfile = ($data['returnToProfile'] ?? false) === true
        && $context === 'resident'
        && ($_SESSION['role'] ?? '') === 'Resident'
        && (int) ($_SESSION['resident_id'] ?? 0) === (int) $account['account_id'];

    $appUrl = portalConfig()['app_url'];
    if (!isAllowedPortalUrl($appUrl)) resetResponse($genericMessage);

    // Limit email sends to three per account per hour.
    $rate = $pdo->prepare("SELECT COUNT(*) FROM password_reset_token WHERE account_type = ? AND account_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $rate->execute([$context, $account['account_id']]);
    if ((int) $rate->fetchColumn() >= 3) resetResponse($genericMessage);

    $pdo->prepare('DELETE FROM password_reset_token WHERE expires_at < NOW()')->execute();
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $insert = $pdo->prepare('INSERT INTO password_reset_token (token_hash, account_type, account_id, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))');
    $insert->execute([$tokenHash, $context, $account['account_id']]);

    $resetUrl = $appUrl . '/index.php?reset=' . rawurlencode($rawToken) . '&context=' . rawurlencode($context);
    if ($returnToProfile) $resetUrl .= '&return=profile';
    $firstName = trim((string) ($account['first_name'] ?? '')) ?: 'there';
    $expiryMessage = 'This link expires in one hour. If you did not request a password reset, you can ignore this email.';
    $text = "Hello $firstName,\n\nWe received a request to reset the password for your Barangay San Jose portal account. Use this link to choose a new password:\n$resetUrl\n\n$expiryMessage\n\nBarangay San Jose";
    $html = portalEmailTemplate('Account security', 'Reset your password', [
        "Hello $firstName,",
        'We received a request to reset the password for your Barangay San Jose portal account. Use the button below to choose a new password.'
    ], 'Choose a new password', $resetUrl, $expiryMessage);
    $mailError = null;
    if (!sendPortalEmail($email, 'Reset your Barangay San Jose password', $text, $mailError, $html)) {
        error_log('Password reset email could not be sent.');
        // Keep the response generic so account existence is never disclosed.
        $pdo->prepare('DELETE FROM password_reset_token WHERE token_hash = ?')->execute([$tokenHash]);
    }
    resetResponse($genericMessage);
} catch (Throwable $e) {
    error_log('Password reset request error: ' . $e->getMessage());
    resetResponse($genericMessage);
}
