<?php
// TEMPORARY TOOL — delete this file after use.
require_once __DIR__ . '/api/db.php';
require_once __DIR__ . '/api/email.php';
require_once __DIR__ . '/api/config.php';

$email = $_GET['email'] ?? '';
if (!$email) { die('Usage: resend_verify.php?email=you@example.com'); }

$stmt = $pdo->prepare("SELECT resident_id, first_name FROM resident WHERE email = ? AND is_active = 0 LIMIT 1");
$stmt->execute([$email]);
$resident = $stmt->fetch();
if (!$resident) { die('Resident not found or already active.'); }

$residentId = (int) $resident['resident_id'];
$firstName  = trim($resident['first_name']) ?: 'there';

// Delete old token and create a fresh one
$pdo->prepare('DELETE FROM email_verification_token WHERE resident_id = ?')->execute([$residentId]);
$rawToken  = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $rawToken);
$pdo->prepare('INSERT INTO email_verification_token (token_hash, resident_id, expires_at) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))')
    ->execute([$tokenHash, $residentId]);

$appUrl          = portalConfig()['app_url'];
$verificationUrl = $appUrl . '/index.php?verify=' . rawurlencode($rawToken);

$plain = "Hello $firstName,\n\nVerify your email here:\n$verificationUrl\n\nExpires in 24 hours.\n\nBarangay San Jose";
$html  = portalEmailTemplate('Resident registration', 'Verify your email address', [
    "Hello $firstName,",
    'Please verify your email address to complete your resident registration.'
], 'Verify email', $verificationUrl, 'This link expires in 24 hours.');

$error = null;
if (sendPortalEmail($email, 'Verify your Barangay San Jose account', $plain, $error, $html)) {
    echo "✅ Verification email sent to $email — link: <a href='$verificationUrl'>$verificationUrl</a>";
} else {
    echo "❌ Failed to send: " . htmlspecialchars($error ?? 'unknown error');
}
