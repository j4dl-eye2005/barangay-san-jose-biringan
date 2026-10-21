<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Local runs can pass --local; production cron omits it and uses DB_* settings.
if (in_array('--local', $argv ?? [], true)) {
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email.php';

$maxJobs = 20;
$processed = 0;
$retryDelays = [60, 300, 900, 3600];
$pdo->exec("UPDATE portal_email_queue SET status = 'failed', text_body = NULL, html_body = NULL, locked_at = NULL, last_error = COALESCE(last_error, 'Worker stopped before delivery') WHERE status = 'processing' AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) AND attempts >= 5");
$pdo->exec("DELETE FROM portal_email_queue WHERE status = 'sent' AND sent_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)");

while ($processed < $maxJobs) {
    try {
        $pdo->beginTransaction();
        $job = $pdo->query("SELECT queue_id, recipient, subject, text_body, html_body, attempts
            FROM portal_email_queue
            WHERE ((status = 'queued' AND available_at <= UTC_TIMESTAMP() AND attempts < 5)
                OR (status = 'processing' AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) AND attempts < 5))
            ORDER BY queue_id ASC LIMIT 1 FOR UPDATE")->fetch();
        if (!$job) {
            $pdo->commit();
            break;
        }
        $attempt = (int) $job['attempts'] + 1;
        $claim = $pdo->prepare("UPDATE portal_email_queue SET status = 'processing', attempts = ?, locked_at = UTC_TIMESTAMP() WHERE queue_id = ?");
        $claim->execute([$attempt, $job['queue_id']]);
        $pdo->commit();

        $mailError = null;
        $sent = sendPortalEmail((string) $job['recipient'], (string) $job['subject'], (string) $job['text_body'], $mailError, $job['html_body'] !== null ? (string) $job['html_body'] : null);
        if ($sent) {
            $done = $pdo->prepare("UPDATE portal_email_queue SET status = 'sent', text_body = NULL, html_body = NULL, sent_at = UTC_TIMESTAMP(), locked_at = NULL, last_error = NULL WHERE queue_id = ?");
            $done->execute([$job['queue_id']]);
        } else {
            $safeError = (string) ($mailError ?? 'SMTP delivery failed');
            $safeError = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $safeError) ?? 'SMTP delivery failed';
            $safeError = preg_replace('/(?i)\b(?:password|token)\b\s*[:=]\s*\S+/', '[credential redacted]', $safeError) ?? 'SMTP delivery failed';
            $safeError = trim(preg_replace('/[\r\n\t]+/', ' ', $safeError) ?? 'SMTP delivery failed');
            $safeError = substr($safeError, 0, 500);
            $status = $attempt >= 5 ? 'failed' : 'queued';
            $delay = $retryDelays[min($attempt - 1, count($retryDelays) - 1)];
            $retry = $pdo->prepare('UPDATE portal_email_queue SET status = ?, text_body = IF(? = \'failed\', NULL, text_body), html_body = IF(? = \'failed\', NULL, html_body), available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), locked_at = NULL, last_error = ? WHERE queue_id = ?');
            $retry->execute([$status, $status, $status, $delay, $safeError, $job['queue_id']]);
        }
        $processed++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Mail queue worker stopped after a processing error.');
        fwrite(STDERR, "Mail queue worker stopped with an error. See the PHP error log.\n");
        exit(1);
    }
}

fwrite(STDOUT, "Processed $processed queued email(s).\n");
