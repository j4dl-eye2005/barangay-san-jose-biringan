<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once dirname(__DIR__) . '/lib/PHPMailer/Exception.php';
require_once dirname(__DIR__) . '/lib/PHPMailer/PHPMailer.php';
require_once dirname(__DIR__) . '/lib/PHPMailer/SMTP.php';
require_once __DIR__ . '/config.php';

/** Write a short, credential-redacted mail failure note to protected storage. */
function logPortalMailFailure(string $reason): void
{
    try {
        $directory = portalConfig()['private_storage'];
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return;
        $realDirectory = realpath($directory);
        if ($realDirectory === false) return;

        $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $protectedRoot = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private_storage');
        $insideDocumentRoot = $documentRoot !== false
            && ($realDirectory === $documentRoot
                || str_starts_with(strtolower($realDirectory), strtolower($documentRoot . DIRECTORY_SEPARATOR)));
        $isProtectedAppFolder = $protectedRoot !== false
            && strcasecmp($realDirectory, $protectedRoot) === 0
            && is_file($protectedRoot . DIRECTORY_SEPARATOR . '.htaccess');
        if ($insideDocumentRoot && !$isProtectedAppFolder) return;

        $reason = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $reason) ?? 'Mail failure';
        $reason = preg_replace('/(?i)\b(?:password|token)\b\s*[:=]\s*\S+/', '[credential redacted]', $reason) ?? 'Mail failure';
        $reason = trim(preg_replace('/[\r\n\t]+/', ' ', $reason) ?? 'Mail failure');
        $reason = substr($reason, 0, 500);

        // Registration writes its proof into this subfolder before sending
        // email, so it is the most reliable writable location for diagnostics.
        $logDirectory = $realDirectory . DIRECTORY_SEPARATOR . 'proofs';
        if (!is_dir($logDirectory) && !@mkdir($logDirectory, 0700, true) && !is_dir($logDirectory)) return;
        if (!is_writable($logDirectory)) {
            error_log('Portal mail diagnostic folder is not writable.');
            return;
        }

        $logFile = $logDirectory . DIRECTORY_SEPARATOR . 'mail-failures.log';
        if (is_file($logFile) && (filesize($logFile) ?: 0) > 16384) {
            @file_put_contents($logFile, "Previous diagnostic log cleared.\n", LOCK_EX);
        }
        if (@file_put_contents($logFile, gmdate('Y-m-d H:i:s') . ' UTC ' . $reason . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            error_log('Portal mail diagnostic log could not be written.');
        }
    } catch (Throwable $ignored) {
        // Diagnostics must never interrupt registration or email sending.
    }
}

/** Persist an email for asynchronous delivery by api/process_mail_queue.php. */
function queuePortalEmail(PDO $pdo, string $recipient, string $subject, string $message, ?string $htmlMessage = null, ?string &$error = null): bool
{
    $error = null;
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || strlen($subject) > 255) {
        $error = 'Invalid email recipient or subject.';
        return false;
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO portal_email_queue (recipient, subject, text_body, html_body, status, attempts, available_at) VALUES (?, ?, ?, ?, 'queued', 0, UTC_TIMESTAMP())");
        $stmt->execute([$recipient, $subject, $message, $htmlMessage]);
        return true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        error_log('Portal email could not be queued.');
        return false;
    }
}

/** Queue a new-request alert only for administrators who opted in. */
function notifyAdminsOfNewRequest(PDO $pdo, string $trackingCode, string $requestSummary): void
{
    try {
        $admins = $pdo->query("SELECT email FROM user WHERE role = 'Admin' AND notification_new_requests = 1")->fetchAll(PDO::FETCH_COLUMN);
        $appUrl = rtrim((string) portalConfig()['app_url'], '/');
        $message = "A new document request was submitted.\n\nRequest: $requestSummary\nTracking number: $trackingCode\n\nReview it in the admin workspace: $appUrl/admin_requests.php#admin-requests\n";
        foreach ($admins as $email) { $mailError = null; queuePortalEmail($pdo, (string)$email, 'New resident document request', $message, null, $mailError); }
    } catch (Throwable $e) { error_log('Administrator new-request notification could not be queued.'); }
}

/**
 * Send a notification email through the configured SMTP server.
 *
 * Returns true if the message was accepted by the SMTP server, false otherwise.
 * Failures are logged via error_log() and never throw, so callers can treat
 * the boolean result as "was the email sent?".
 */
function sendPortalEmail(string $recipient, string $subject, string $message, ?string &$error = null, ?string $htmlMessage = null): bool
{
    $error = null;
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid recipient email address.';
        error_log('Portal email skipped: invalid recipient address.');
        return false;
    }

    $config = portalConfig();

    foreach (['smtp_host', 'smtp_username', 'smtp_password', 'from_email'] as $key) {
        if (empty($config[$key])) {
            $error = "SMTP configuration value '$key' is missing.";
            error_log('Portal email skipped: SMTP configuration is incomplete.');
            logPortalMailFailure($error);
            return false;
        }
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        // Keep admin actions responsive if the SMTP host stalls.
        $mail->Timeout       = 15;  // socket read/write timeout per operation
        $mail->Timelimit     = 25;  // total time limit
        $mail->SMTPKeepAlive = false;
        $mail->Host       = $config['smtp_host'];
        $mail->Port       = (int) $config['smtp_port'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['smtp_username'];
        $mail->Password   = $config['smtp_password'];

        $encryption = $config['smtp_encryption'];
        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($recipient);

        $mail->Subject = $subject;
        $mail->isHTML($htmlMessage !== null);
        $mail->Body    = $htmlMessage ?? $message;
        $mail->CharSet = 'UTF-8';
        // Keep a readable fallback for clients that do not display HTML.
        $mail->AltBody = $message;

        $mail->send();
        return true;
    } catch (Exception $e) {
        // PHPMailer's ErrorInfo is more descriptive than the exception message.
        $error = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
        error_log('Portal email failed during SMTP delivery.');
        logPortalMailFailure($error);
        return false;
    }
}

/** Build an email-client-safe template that matches the Barangay San Jose portal design. */
function portalEmailTemplate(string $eyebrow, string $title, array $paragraphs, ?string $buttonLabel = null, ?string $buttonUrl = null, ?string $note = null): string
{
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // Colour tokens matching the portal's shared stylesheet palette
    $green       = '#0c5700';   // --color-logo-green
    $greenHover  = '#084500';   // --color-accent-hover
    $canvas      = '#f6f7f4';   // --color-canvas
    $surface     = '#ffffff';   // --color-surface
    $ink         = '#1c1917';   // --color-ink
    $textMuted   = '#57534e';   // --color-text
    $quiet       = '#78716c';   // --color-muted
    $border      = '#e7e5e4';   // --color-border
    $softGreen   = '#f2f8ef';   // --color-accent-soft
    $softBorder  = '#b8d5b3';   // --color-accent-border
    $eyebrowClr  = $green;

    // Font stack — Plus Jakarta Sans with safe fallbacks
    $font = "'Plus Jakarta Sans','Segoe UI',system-ui,-apple-system,BlinkMacSystemFont,Arial,sans-serif";

    // Body paragraphs
    $body = '';
    foreach ($paragraphs as $paragraph) {
        $body .= '<p style="margin:0 0 14px;color:' . $textMuted . ';font-size:15px;line-height:1.7;font-family:' . $font . '">'
               . nl2br($escape((string) $paragraph))
               . '</p>';
    }

    // CTA button
    $button = '';
    if ($buttonLabel !== null && $buttonUrl !== null && filter_var($buttonUrl, FILTER_VALIDATE_URL)) {
        $button = '<tr><td style="padding:6px 32px 24px">'
                . '<a href="' . $escape($buttonUrl) . '" '
                . 'style="display:inline-block;padding:13px 24px;border-radius:10px;background:' . $green . ';color:#ffffff;'
                . 'font-family:' . $font . ';font-size:14px;font-weight:700;text-decoration:none;letter-spacing:.01em">'
                . $escape($buttonLabel)
                . '</a>'
                . '</td></tr>';
    }

    // Note / info box
    $noteHtml = '';
    if ($note !== null) {
        $noteHtml = '<tr><td style="padding:0 32px 24px">'
                  . '<div style="padding:14px 18px;border-radius:10px;border:1px solid ' . $softBorder . ';background:' . $softGreen . ';'
                  . 'color:' . $textMuted . ';font-family:' . $font . ';font-size:13px;line-height:1.6">'
                  . nl2br($escape($note))
                  . '</div>'
                  . '</td></tr>';
    }

    // Logo seal as inline SVG circle placeholder (fallback for email clients that block external images)
    // We embed the seal via a hosted URL so it works in external inboxes too.
    $config  = portalConfig();
    $appBase = $config['app_url'];
    $sealUrl = $appBase . '/Barangay%20San%20Jose%2C%20Biringan%20City.png';

    return '<!doctype html>'
         . '<html lang="en"><head>'
         . '<meta charset="utf-8">'
         . '<meta name="viewport" content="width=device-width,initial-scale=1">'
         . '<meta name="color-scheme" content="light">'
         . '<title>' . $escape($title) . '</title>'
         . '</head>'
         . '<body style="margin:0;padding:0;background:' . $canvas . ';-webkit-text-size-adjust:100%">'

         // Outer wrapper
         . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">'
         . '<tr><td style="padding:32px 16px;background:' . $canvas . '">'

         // Card
         . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" '
         . 'style="max-width:600px;margin:0 auto;border-radius:16px;border:1px solid ' . $border . ';background:' . $surface . ';overflow:hidden">'

         // ── Header ─────────────────────────────────────────────────────────
         . '<tr><td style="padding:0">'
         . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">'
         . '<tr>'
         // Green accent strip on the left
         . '<td style="width:6px;background:' . $green . ';border-radius:16px 0 0 0">&nbsp;</td>'
         . '<td style="padding:24px 28px 24px 22px;background:' . $surface . ';border-radius:0 16px 0 0">'
         . '<table role="presentation" cellspacing="0" cellpadding="0" border="0">'
         . '<tr>'
         // Seal logo
         . '<td style="padding-right:14px;vertical-align:middle">'
         . '<img src="' . $escape($sealUrl) . '" alt="Barangay San Jose seal" width="44" height="44" '
         . 'style="width:44px;height:44px;border-radius:50%;display:block;object-fit:cover">'
         . '</td>'
         // Brand name + subtitle
         . '<td style="vertical-align:middle">'
         . '<div style="font-family:' . $font . ';font-size:15px;font-weight:800;color:' . $ink . ';letter-spacing:-.01em;line-height:1.2">Barangay San Jose</div>'
         . '<div style="font-family:' . $font . ';font-size:11px;color:' . $quiet . ';margin-top:2px;letter-spacing:.01em">Digital services and public records</div>'
         . '</td>'
         . '</tr>'
         . '</table>'
         . '</td>'
         . '</tr>'
         . '</table>'
         . '</td></tr>'

         // Divider
         . '<tr><td style="height:1px;background:' . $border . ';font-size:0;line-height:0">&nbsp;</td></tr>'

         // ── Body ───────────────────────────────────────────────────────────
         . '<tr><td style="padding:28px 32px 8px">'
         // Eyebrow
         . '<div style="font-family:' . $font . ';font-size:10px;font-weight:800;color:' . $eyebrowClr . ';'
         . 'letter-spacing:.08em;text-transform:uppercase;margin-bottom:10px">' . $escape($eyebrow) . '</div>'
         // Title
         . '<h1 style="margin:0 0 18px;font-family:' . $font . ';font-size:22px;font-weight:800;'
         . 'color:' . $ink . ';line-height:1.25;letter-spacing:-.025em">' . $escape($title) . '</h1>'
         // Paragraphs
         . $body
         . '</td></tr>'

         // CTA button row
         . $button

         // Note / info box row
         . $noteHtml

         // ── Footer ─────────────────────────────────────────────────────────
         . '<tr><td style="height:1px;background:' . $border . ';font-size:0;line-height:0">&nbsp;</td></tr>'
         . '<tr><td style="padding:18px 32px 20px;background:' . $canvas . ';border-radius:0 0 16px 16px">'
         . '<p style="margin:0 0 2px;font-family:' . $font . ';font-size:12px;font-weight:700;color:' . $textMuted . '">'
         . 'Barangay San Jose Digital Portal</p>'
         . '<p style="margin:0;font-family:' . $font . ';font-size:11px;color:' . $quiet . ';line-height:1.5">'
         . 'This is an automated message. Please do not reply to this email.</p>'
         . '</td></tr>'

         . '</table>' // end card
         . '</td></tr>'
         . '</table>' // end outer wrapper
         . '</body></html>';
}
