<?php
declare(strict_types=1);

$isWebDispatch = PHP_SAPI !== 'cli';
if ($isWebDispatch) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'POST request required.']);
        exit;
    }
} else {
    // Local runs can pass --local; production cron omits it and uses DB_* settings.
    if (in_array('--local', $argv ?? [], true)) {
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email.php';

if ($isWebDispatch) {
    $dispatch = jsonBody(1024);
    $permission = is_string($dispatch['permission'] ?? null) ? $dispatch['permission'] : '';
    $workerQueueId = filter_var($dispatch['queue_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $workerRecipient = null;
    if (!$workerQueueId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A valid queued email is required.']);
        exit;
    }
    if (in_array($permission, ['requests.manage', 'payments.manage'], true)) {
        requireAdminAccess($permission);
    } elseif ($permission === 'resident.payment' && ($_SESSION['role'] ?? '') === 'Resident' && (int) ($_SESSION['resident_id'] ?? 0) > 0) {
        $residentEmail = $pdo->prepare('SELECT email FROM resident WHERE resident_id = ? AND archived_at IS NULL LIMIT 1');
        $residentEmail->execute([(int) $_SESSION['resident_id']]);
        $workerRecipient = $residentEmail->fetchColumn();
        if (!is_string($workerRecipient) || !filter_var($workerRecipient, FILTER_VALIDATE_EMAIL)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Resident email is unavailable.']);
            exit;
        }
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid mail queue dispatch.']);
        exit;
    }
}

$maxJobs = $isWebDispatch ? 1 : 20;
$processed = 0;
$sentCount = 0;
$retryCount = 0;
$failedCount = 0;
$retryDelays = [60, 300, 900, 3600];
$pdo->exec("UPDATE portal_email_queue SET status = 'failed', text_body = NULL, html_body = NULL, locked_at = NULL, last_error = COALESCE(last_error, 'Worker stopped before delivery') WHERE status = 'processing' AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) AND attempts >= 5");
$pdo->exec("DELETE FROM portal_email_queue WHERE status = 'sent' AND sent_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)");

while ($processed < $maxJobs) {
    try {
        $pdo->beginTransaction();
        $query = "SELECT queue_id, recipient, subject, text_body, html_body, attempts
            FROM portal_email_queue
            WHERE ((status = 'queued' AND available_at <= UTC_TIMESTAMP() AND attempts < 5)
                OR (status = 'processing' AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) AND attempts < 5))
            " . ($isWebDispatch ? 'AND queue_id = ? ' : '')
            . ($isWebDispatch && $workerRecipient !== null ? 'AND recipient = ? ' : '') . "
            ORDER BY queue_id ASC LIMIT 1 FOR UPDATE";
        $select = $pdo->prepare($query);
        $selectParams = $isWebDispatch ? [$workerQueueId] : [];
        if ($isWebDispatch && $workerRecipient !== null) $selectParams[] = $workerRecipient;
        $select->execute($selectParams);
        $job = $select->fetch();
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
            $sentCount++;
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
            if ($status === 'failed') $failedCount++; else $retryCount++;
        }
        $processed++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Mail queue worker stopped after a processing error.');
        if ($isWebDispatch) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'The queued email could not be processed.']);
        } else {
            fwrite(STDERR, "Mail queue worker stopped with an error. See the PHP error log.\n");
        }
        exit(1);
    }
}

if ($isWebDispatch) {
    $statusQuery = $pdo->prepare('SELECT status, attempts FROM portal_email_queue WHERE queue_id = ? LIMIT 1');
    $statusQuery->execute([$workerQueueId]);
    $queueState = $statusQuery->fetch();
    $delivery = match ($queueState['status'] ?? null) {
        'sent' => 'sent',
        'failed' => 'failed',
        'processing' => 'processing',
        'queued' => $retryCount > 0 || (int) ($queueState['attempts'] ?? 0) > 0 ? 'retrying' : 'queued',
        default => 'missing',
    };
    echo json_encode(['success' => true, 'processed' => $processed, 'delivery' => $delivery]);
} else {
    fwrite(STDOUT, "Processed $processed queued email(s).\n");
}
