<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
} else {
    // The checked-in source copy keeps the existing cPanel copy deployment
    // working when Composer dependencies have not been installed on the host.
    require_once dirname(__DIR__) . '/lib/PHPMailer/Exception.php';
    require_once dirname(__DIR__) . '/lib/PHPMailer/PHPMailer.php';
    require_once dirname(__DIR__) . '/lib/PHPMailer/SMTP.php';
}
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
        if ($documentRoot === false) return;
        $insideDocumentRoot = $realDirectory === $documentRoot
            || str_starts_with(strtolower($realDirectory), strtolower($documentRoot . DIRECTORY_SEPARATOR));
        if ($insideDocumentRoot) return;

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

/** Deliver action notices immediately; retain a queued retry if SMTP is down. */
function deliverOrQueuePortalEmail(PDO $pdo, string $recipient, string $subject, string $message, ?string $htmlMessage = null, ?string &$error = null): string
{
    $error = null;
    if (sendPortalEmail($recipient, $subject, $message, $error, $htmlMessage)) return 'sent';
    $smtpError = $error;
    $queueError = null;
    if (queuePortalEmail($pdo, $recipient, $subject, $message, $htmlMessage, $queueError)) {
        $error = $smtpError;
        return 'queued';
    }
    $error = $queueError ?: $smtpError ?: 'Email delivery failed.';
    return 'failed';
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

/** Queue a payment-proof review alert only for administrators who opted in. */
function notifyAdminsOfPaymentSubmission(PDO $pdo, string $trackingCode, string $documentName, float $fee): void
{
    try {
        $admins = $pdo->query("SELECT DISTINCT email FROM user WHERE role = 'Admin' AND notification_payment_verification = 1")->fetchAll(PDO::FETCH_COLUMN);
        $appUrl = rtrim((string) portalConfig()['app_url'], '/');
        $amount = 'PHP ' . number_format($fee, 2);
        $message = "A resident submitted payment proof for review.\n\nRequest: $trackingCode\nDocument: $documentName\nFee: $amount\n\nReview it in the admin workspace: $appUrl/admin_requests.php#admin-requests\n";
        foreach ($admins as $email) {
            $mailError = null;
            queuePortalEmail($pdo, (string) $email, 'Payment proof needs review', $message, null, $mailError);
        }
    } catch (Throwable $e) {
        error_log('Administrator payment-review notification could not be queued.');
    }
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
        if ($htmlMessage !== null) {
            $sealPath = dirname(__DIR__) . '/Barangay San Jose, Biringan City.png';
            $sealBytes = @file_get_contents($sealPath);
            if ($sealBytes !== false) {
                $mail->addStringEmbeddedImage($sealBytes, 'barangay-seal', 'barangay-san-jose-seal.png', 'base64', 'image/png');
            } else {
                $mail->Body = preg_replace('/<img src="cid:barangay-seal"[^>]*>/i', '', $mail->Body, 1) ?? $mail->Body;
            }
        }
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

/** Build an email-client-safe template matching the Barangay San Jose portal. */
function portalEmailTemplate(string $eyebrow, string $title, array $paragraphs, ?string $buttonLabel = null, ?string $buttonUrl = null, ?string $note = null): string
{
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // Colour tokens matching the portal's shared stylesheet palette
    $green       = '#0c5700';   // --color-logo-green
    $yellow      = '#e5f200';   // --color-logo-yellow
    $canvas      = '#f1f6ec';   // --color-panel-warm
    $surface     = '#ffffff';   // --color-surface
    $ink         = '#1c1917';   // --color-ink
    $textMuted   = '#57534e';   // --color-text
    $quiet       = '#566059';   // --color-muted
    $border      = '#dce6d8';   // soft green-tinted border
    $softGreen   = '#f2f8ef';   // --color-accent-soft
    $softBorder  = '#b8d5b3';   // --color-accent-border
    $eyebrowClr  = $green;

    // Use the website's Plus Jakarta Sans from Google Fonts where supported;
    // preserve the portal's CSS fallback stack for clients that block web fonts.
    $font = "'Plus Jakarta Sans','Segoe UI',system-ui,-apple-system,BlinkMacSystemFont,sans-serif";

    // Body paragraphs
    $body = '';
    foreach ($paragraphs as $paragraph) {
        $body .= '<p style="margin:0 0 11px;color:' . $textMuted . ';font-size:15px;line-height:1.6;font-family:' . $font . '">'
               . nl2br($escape((string) $paragraph))
               . '</p>';
    }

    // CTA button
    $button = '';
    if ($buttonLabel !== null && $buttonUrl !== null && filter_var($buttonUrl, FILTER_VALIDATE_URL)) {
        $button = '<tr><td style="padding:2px 28px 15px">'
                . '<a href="' . $escape($buttonUrl) . '" '
                . 'style="display:inline-block;padding:13px 22px;border-radius:8px;background:' . $green . ';color:#ffffff;'
                . 'font-family:' . $font . ';font-size:14px;font-weight:700;text-decoration:none;letter-spacing:.01em">'
                . $escape($buttonLabel)
                . '</a>'
                . '</td></tr>';
    }

    // Note / info box
    $noteHtml = '';
    if ($note !== null) {
        $noteHtml = '<tr><td style="padding:0 28px 15px">'
                  . '<div style="padding:10px 14px;border-radius:8px;border:1px solid ' . $softBorder . ';background:' . $softGreen . ';'
                  . 'color:' . $textMuted . ';font-family:' . $font . ';font-size:13px;line-height:1.6">'
                  . nl2br($escape($note))
                  . '</div>'
                  . '</td></tr>';
    }

    // Remote font loading stays small, unlike embedding the full TTF in each
    // email. Gmail supports the font-family property; clients that don't load
    // Google Fonts still get a close system sans-serif fallback.
    $fontStyle = '<style type="text/css">@import url("https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap");</style>';

    return '<!doctype html>'
         . '<html lang="en"><head>'
         . '<meta charset="utf-8">'
         . '<meta name="viewport" content="width=device-width,initial-scale=1">'
         . '<meta name="color-scheme" content="light">'
         . '<title>' . $escape($title) . '</title>'
         . $fontStyle
         . '<!--[if mso]><style type="text/css">body,table,td,h1,p,a{font-family:"Segoe UI",Arial,sans-serif!important}</style><![endif]-->'
         . '</head>'
         . '<body style="margin:0;padding:0;background:' . $canvas . ';font-family:' . $font . ';-webkit-text-size-adjust:100%">'

         // Outer wrapper
         . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">'
         . '<tr><td style="padding:16px 12px;background:' . $canvas . '">'

         // Card
         . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" '
         . 'style="max-width:600px;margin:0 auto;border-radius:12px;border:1px solid ' . $border . ';background:' . $surface . ';overflow:hidden">'

         // ── Header ─────────────────────────────────────────────────────────
         . '<tr><td style="padding:0">'
         . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">'
         . '<tr>'
         // Strong green brand band with the portal's bright seal accent
         . '<td style="padding:15px 24px;background:' . $green . ';border-top:4px solid ' . $yellow . ';border-radius:12px 12px 0 0">'
         . '<table role="presentation" cellspacing="0" cellpadding="0" border="0">'
         . '<tr>'
         // Seal logo
         . '<td style="padding-right:14px;vertical-align:middle">'
         . '<img src="cid:barangay-seal" alt="Barangay San Jose seal" width="52" height="52" '
         . 'style="width:52px;height:52px;border-radius:50%;display:block">'
         . '</td>'
         // Brand name + subtitle
         . '<td style="padding-left:14px;vertical-align:middle">'
         . '<div style="font-family:' . $font . ';font-size:17px;font-weight:800;color:#ffffff;letter-spacing:-.02em;line-height:1.2">Barangay San Jose</div>'
         . '<div style="font-family:' . $font . ';font-size:12px;color:#e3efdd;margin-top:4px;letter-spacing:.01em">Digital services and public records</div>'
         . '</td>'
         . '</tr>'
         . '</table>'
         . '</td>'
         . '</tr>'
         . '</table>'
         . '</td></tr>'

         // Yellow accent divider
         . '<tr><td style="height:3px;background:' . $yellow . ';font-size:0;line-height:0">&nbsp;</td></tr>'

         // ── Body ───────────────────────────────────────────────────────────
         . '<tr><td style="padding:20px 28px 4px;background:' . $surface . '">'
         // Eyebrow
         . '<div style="font-family:' . $font . ';font-size:11px;font-weight:800;color:' . $eyebrowClr . ';'
         . 'letter-spacing:.08em;text-transform:uppercase;margin-bottom:10px">' . $escape($eyebrow) . '</div>'
         // Title
         . '<h1 style="margin:0 0 14px;font-family:' . $font . ';font-size:22px;font-weight:800;'
         . 'color:' . $ink . ';line-height:1.25;letter-spacing:-.035em">' . $escape($title) . '</h1>'
         // Paragraphs
         . $body
         . '</td></tr>'

         // CTA button row
         . $button

         // Note / info box row
         . $noteHtml

         // ── Footer ─────────────────────────────────────────────────────────
         . '<tr><td style="height:1px;background:' . $border . ';font-size:0;line-height:0">&nbsp;</td></tr>'
         . '<tr><td style="padding:12px 28px 14px;background:' . $canvas . ';border-radius:0 0 12px 12px">'
         . '<p style="margin:0 0 4px;font-family:' . $font . ';font-size:12px;font-weight:800;color:' . $green . '">'
         . 'Barangay San Jose Digital Portal</p>'
         . '<p style="margin:0;font-family:' . $font . ';font-size:10px;color:' . $quiet . ';line-height:1.4">'
         . 'This is an automated message. Please do not reply to this email.</p>'
         . '</td></tr>'

         . '</table>' // end card
         . '</td></tr>'
         . '</table>' // end outer wrapper
         . '</body></html>';
}
