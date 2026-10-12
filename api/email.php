<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once dirname(__DIR__) . '/lib/PHPMailer/Exception.php';
require_once dirname(__DIR__) . '/lib/PHPMailer/PHPMailer.php';
require_once dirname(__DIR__) . '/lib/PHPMailer/SMTP.php';

/**
 * Send a plain-text notification email through the configured SMTP server.
 *
 * Returns true if the message was accepted by the SMTP server, false otherwise.
 * Failures are logged via error_log() and never throw, so callers can treat
 * the boolean result as "was the email sent?".
 */
function sendPortalEmail(string $recipient, string $subject, string $message): bool
{
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        error_log('Portal email skipped: invalid recipient address.');
        return false;
    }

    $configPath = __DIR__ . '/mail_config.php';
    if (!is_file($configPath)) {
        error_log('Portal email skipped: mail_config.php not found.');
        return false;
    }
    $config = require $configPath;

    // Guard against unconfigured placeholder values.
    foreach (['username', 'password', 'from_email'] as $key) {
        if (empty($config[$key]) || str_starts_with((string) $config[$key], 'PASTE_')) {
            error_log("Portal email skipped: mail_config.php '$key' is not set.");
            return false;
        }
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $config['host'];
        $mail->Port       = (int) $config['port'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['username'];
        $mail->Password   = $config['password'];

        $encryption = strtolower($config['encryption'] ?? 'tls');
        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom($config['from_email'], $config['from_name'] ?? 'Barangay Digital Portal');
        $mail->addAddress($recipient);

        $mail->Subject = $subject;
        $mail->Body    = $message;
        $mail->CharSet = 'UTF-8';
        // Plain-text email; AltBody keeps it text-only.
        $mail->AltBody = $message;

        $mail->send();
        return true;
    } catch (Exception $e) {
        // PHPMailer's ErrorInfo is more descriptive than the exception message.
        error_log('Portal email failed: ' . $mail->ErrorInfo);
        return false;
    }
}
