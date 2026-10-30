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

/** Start the CLI worker without holding up the page request; cron remains the fallback. */
function startPortalEmailQueueWorker(): void
{
    static $started = false;
    if ($started || PHP_SAPI === 'cli') return;
    $started = true;

    $disabled = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
    $worker = __DIR__ . DIRECTORY_SEPARATOR . 'process_mail_queue.php';
    $phpCandidates = [];
    if (defined('PHP_BINDIR')) {
        $phpCandidates[] = rtrim(PHP_BINDIR, '\\/') . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
    }
    $phpCandidates[] = PHP_BINARY;
    $php = '';
    foreach ($phpCandidates as $candidate) {
        $binaryName = strtolower(basename(str_replace('\\', '/', $candidate)));
        if (str_contains($binaryName, 'fpm') || str_contains($binaryName, 'cgi')) continue;
        if (is_file($candidate) && (PHP_OS_FAMILY === 'Windows' || is_executable($candidate))) {
            $php = $candidate;
            break;
        }
    }
    if ($php === '' || !is_file($worker)) return;

    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\\d+$/', '', $host) ?? $host;
    $localArg = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true) ? ' --local' : '';

    try {
        if (PHP_OS_FAMILY === 'Windows') {
            if (!function_exists('popen') || in_array('popen', $disabled, true)) return;
            $command = 'start "" /B ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . $localArg . ' > NUL 2>&1';
            $process = @popen($command, 'r');
            if (is_resource($process)) @pclose($process);
            return;
        }

        if (!function_exists('exec') || in_array('exec', $disabled, true)) return;
        $command = escapeshellarg($php) . ' ' . escapeshellarg($worker) . $localArg . ' </dev/null >/dev/null 2>&1 &';
        @exec($command);
    } catch (Throwable $error) {
        error_log('Portal mail queue worker could not be started; scheduled worker remains available.');
    }
}

/** Claim and send one specific queued message after the API has returned. */
function deliverPortalQueueItem(PDO $pdo, int $queueId): void
{
    $job = null;
    try {
        $pdo->beginTransaction();
        $claim = $pdo->prepare("SELECT queue_id, recipient, subject, text_body, html_body, attempts
            FROM portal_email_queue
            WHERE queue_id = ? AND status = 'queued' AND available_at <= UTC_TIMESTAMP() AND attempts < 5
            FOR UPDATE");
        $claim->execute([$queueId]);
        $job = $claim->fetch();
        if (!$job) {
            $pdo->commit();
            return;
        }
        $attempt = (int) $job['attempts'] + 1;
        $mark = $pdo->prepare("UPDATE portal_email_queue SET status = 'processing', attempts = ?, locked_at = UTC_TIMESTAMP() WHERE queue_id = ?");
        $mark->execute([$attempt, $queueId]);
        $pdo->commit();

        $mailError = null;
        if (sendPortalEmail((string) $job['recipient'], (string) $job['subject'], (string) $job['text_body'], $mailError, $job['html_body'] !== null ? (string) $job['html_body'] : null)) {
            $sent = $pdo->prepare("UPDATE portal_email_queue SET status = 'sent', text_body = NULL, html_body = NULL, sent_at = UTC_TIMESTAMP(), locked_at = NULL, last_error = NULL WHERE queue_id = ?");
            $sent->execute([$queueId]);
            return;
        }

        $safeError = (string) ($mailError ?? 'SMTP delivery failed');
        $safeError = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\\.[A-Z]{2,}/i', '[email]', $safeError) ?? 'SMTP delivery failed';
        $safeError = preg_replace('/(?i)\\b(?:password|token)\\b\\s*[:=]\\s*\\S+/', '[credential redacted]', $safeError) ?? 'SMTP delivery failed';
        $safeError = substr(trim(preg_replace('/[\\r\\n\\t]+/', ' ', $safeError) ?? 'SMTP delivery failed'), 0, 500);
        $status = $attempt >= 5 ? 'failed' : 'queued';
        $retryDelays = [60, 300, 900, 3600];
        $delay = $retryDelays[min($attempt - 1, count($retryDelays) - 1)];
        $retry = $pdo->prepare('UPDATE portal_email_queue SET status = ?, text_body = IF(? = \'failed\', NULL, text_body), html_body = IF(? = \'failed\', NULL, html_body), available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), locked_at = NULL, last_error = ? WHERE queue_id = ?');
        $retry->execute([$status, $status, $status, $delay, $safeError, $queueId]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Queued email background delivery stopped before completion.');
    }
}

/** Deliver after the HTTP response only when the server can flush it first. */
function schedulePortalEmailQueueDelivery(PDO $pdo, int $queueId): void
{
    // Never fall through to synchronous SMTP during a web request. On servers
    // without FastCGI, the detached CLI worker or scheduled worker handles mail.
    if (!function_exists('fastcgi_finish_request')) return;

    register_shutdown_function(static function () use ($pdo, $queueId): void {
        @fastcgi_finish_request();
        if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
        @ignore_user_abort(true);
        deliverPortalQueueItem($pdo, $queueId);
    });
}

/** Persist an email for asynchronous delivery by api/process_mail_queue.php. */
function queuePortalEmail(PDO $pdo, string $recipient, string $subject, string $message, ?string $htmlMessage = null, ?string &$error = null, ?int &$queueId = null): bool
{
    $error = null;
    $queueId = null;
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || strlen($subject) > 255) {
        $error = 'Invalid email recipient or subject.';
        return false;
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO portal_email_queue (recipient, subject, text_body, html_body, status, attempts, available_at) VALUES (?, ?, ?, ?, 'queued', 0, UTC_TIMESTAMP())");
        $stmt->execute([$recipient, $subject, $message, $htmlMessage]);
        $queueId = (int) $pdo->lastInsertId();
        schedulePortalEmailQueueDelivery($pdo, $queueId);
        startPortalEmailQueueWorker();
        return true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        error_log('Portal email could not be queued.');
        return false;
    }
}

/** Queue action notices for asynchronous delivery by the mail worker. */
function queuePortalNotification(PDO $pdo, string $recipient, string $subject, string $message, ?string $htmlMessage = null, ?string &$error = null, ?int &$queueId = null): string
{
    $error = null;
    $queueId = null;
    return queuePortalEmail($pdo, $recipient, $subject, $message, $htmlMessage, $error, $queueId) ? 'queued' : 'failed';
}

/** Queue a new-request alert only for administrators who opted in. */
function notifyAdminsOfNewRequest(PDO $pdo, string $trackingCode, string $requestSummary): void
{
    try {
        $admins = $pdo->query("SELECT email FROM user WHERE role = 'Admin' AND notification_new_requests = 1")->fetchAll(PDO::FETCH_COLUMN);
        $appUrl = rtrim((string) portalConfig()['app_url'], '/');
        $reviewUrl = $appUrl . '/admin_requests.php#admin-requests';
        $message = "A new document request was submitted.\n\nRequest: $requestSummary\nTracking number: $trackingCode\n\nReview it in the admin workspace: $reviewUrl\n";
        $html = portalEmailTemplate('Admin notification', 'New resident document request', [
            'A new document request was submitted.',
            "Request: $requestSummary\nTracking number: $trackingCode",
            'Open the administrator workspace to review and process this request.'
        ], 'Review request', $reviewUrl);
        foreach ($admins as $email) { $mailError = null; queuePortalEmail($pdo, (string)$email, 'New resident document request', $message, $html, $mailError); }
    } catch (Throwable $e) { error_log('Administrator new-request notification could not be queued.'); }
}

/** Queue a payment-proof review alert only for administrators who opted in. */
function notifyAdminsOfPaymentSubmission(PDO $pdo, string $trackingCode, string $documentName, float $fee): void
{
    try {
        $admins = $pdo->query("SELECT DISTINCT email FROM user WHERE role = 'Admin' AND notification_payment_verification = 1")->fetchAll(PDO::FETCH_COLUMN);
        $appUrl = rtrim((string) portalConfig()['app_url'], '/');
        $amount = 'PHP ' . number_format($fee, 2);
        $reviewUrl = $appUrl . '/admin_requests.php#admin-requests';
        $message = "A resident submitted payment proof for review.\n\nRequest: $trackingCode\nDocument: $documentName\nFee: $amount\n\nReview it in the admin workspace: $reviewUrl\n";
        $html = portalEmailTemplate('Admin notification', 'Payment proof needs review', [
            'A resident submitted payment proof for review.',
            "Request: $trackingCode\nDocument: $documentName\nFee: $amount",
            'Open the administrator workspace to review the submitted proof.'
        ], 'Review payment proof', $reviewUrl);
        foreach ($admins as $email) {
            $mailError = null;
            queuePortalEmail($pdo, (string) $email, 'Payment proof needs review', $message, $html, $mailError);
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
            if (str_contains($mail->Body, 'cid:gcash-payment-qr')) {
                $qrPath = dirname(__DIR__) . '/assets/gcash-payment-qr.png';
                $qrBytes = @file_get_contents($qrPath);
                if ($qrBytes !== false) {
                    $mail->addStringEmbeddedImage($qrBytes, 'gcash-payment-qr', 'gcash-payment-qr.png', 'base64', 'image/png');
                } else {
                    error_log('GCash payment QR could not be embedded in the queued email.');
                    $mail->Body = preg_replace('/<img src="cid:gcash-payment-qr"[^>]*>/i', '<p>GCash QR unavailable in this message. Please use the in-person payment option.</p>', $mail->Body, 1) ?? $mail->Body;
                }
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
function portalEmailTemplate(string $eyebrow, string $title, array $paragraphs, ?string $buttonLabel = null, ?string $buttonUrl = null, ?string $note = null, bool $includeGcashQr = false): string
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

    $gcashQrHtml = $includeGcashQr
        ? '<div style="margin:14px 0 16px;padding:14px;border:1px solid ' . $border . ';border-radius:10px;background:#ffffff;text-align:center">'
            . '<p style="margin:0 0 10px;color:' . $ink . ';font-family:' . $font . ';font-size:14px;font-weight:700">Pay online with GCash</p>'
            . '<img src="cid:gcash-payment-qr" alt="Scan this QR code with GCash to pay the assessed fee" width="280" style="display:block;width:100%;max-width:280px;height:auto;margin:0 auto;border:0">'
            . '<p style="margin:10px 0 0;color:' . $quiet . ';font-family:' . $font . ';font-size:12px;line-height:1.5">Scan in GCash, verify the recipient before confirming, and note the receipt number. Transfer fees may apply.</p>'
            . '</div>'
        : '';

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
         . $gcashQrHtml
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
