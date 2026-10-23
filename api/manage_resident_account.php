<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
requireAdminAccess();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$data = jsonBody(8192);
$residentId = filter_var($data['residentId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = is_string($data['action'] ?? null) ? $data['action'] : '';
if (!$residentId || !in_array($action, ['archive', 'restore', 'delete', 'archive_delete'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Choose a valid resident account action.']);
    exit;
}

try {
    $pdo->beginTransaction();
    $find = $pdo->prepare('SELECT resident_id, first_name, middle_name, last_name, email, archived_at, proof_path, profile_photo FROM resident WHERE resident_id = ? FOR UPDATE');
    $find->execute([$residentId]);
    $resident = $find->fetch();
    if (!$resident) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Resident account not found.']);
        exit;
    }

    $name = trim(implode(' ', array_filter([
        (string) ($resident['first_name'] ?? ''),
        (string) ($resident['middle_name'] ?? ''),
        (string) ($resident['last_name'] ?? ''),
    ], static fn(string $part): bool => $part !== '')));

    $filesToDelete = [];
    if ($action === 'archive') {
        if (empty($resident['archived_at'])) {
            $pdo->prepare('UPDATE resident SET archived_at = UTC_TIMESTAMP(), is_active = 0, auth_version = auth_version + 1 WHERE resident_id = ?')->execute([$residentId]);
            $pdo->prepare('DELETE FROM email_verification_token WHERE resident_id = ?')->execute([$residentId]);
        }
        $auditAction = 'resident_archived';
        $message = 'Resident account archived.';
    } elseif ($action === 'restore') {
        if (!empty($resident['archived_at'])) {
            $pdo->prepare('UPDATE resident SET archived_at = NULL, is_active = 1, auth_version = auth_version + 1 WHERE resident_id = ?')->execute([$residentId]);
        }
        $auditAction = 'resident_restored';
        $message = 'Resident account restored.';
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM password_reset_token WHERE account_type = 'resident' AND account_id = ?")->execute([$residentId]);
        $requests = $pdo->prepare('SELECT request_id, tracking_code, proof_path, payment_proof_path FROM document_request WHERE resident_id = ? FOR UPDATE');
        $requests->execute([$residentId]);
        foreach ($requests->fetchAll(PDO::FETCH_ASSOC) as $requestRow) {
            if (is_string($requestRow['proof_path'] ?? null) && $requestRow['proof_path'] !== '') $filesToDelete[] = $requestRow['proof_path'];
            if (is_string($requestRow['payment_proof_path'] ?? null) && $requestRow['payment_proof_path'] !== '') $filesToDelete[] = $requestRow['payment_proof_path'];
            if (is_string($requestRow['tracking_code'] ?? null) && $requestRow['tracking_code'] !== '') {
                $pdo->prepare('DELETE FROM portal_email_queue WHERE text_body LIKE ? OR html_body LIKE ?')->execute(['%' . $requestRow['tracking_code'] . '%', '%' . $requestRow['tracking_code'] . '%']);
            }
            $requestRecordId = (int) ($requestRow['request_id'] ?? 0);
            if ($requestRecordId > 0) {
                $redactRequestAudit = $pdo->prepare("UPDATE audit_log SET details = 'Related request details erased with resident account' WHERE details = ? OR details LIKE ? OR details LIKE ? OR details = ? OR details LIKE ?");
                $redactRequestAudit->execute([
                    'Request ID ' . $requestRecordId,
                    'Request ID ' . $requestRecordId . ';%',
                    'Request ID ' . $requestRecordId . ' %',
                    'Internal note updated for request ID ' . $requestRecordId,
                    'Payment status changed for request ID ' . $requestRecordId . ' to %',
                ]);
            }
        }
        $pdo->prepare('DELETE FROM document_request WHERE resident_id = ?')->execute([$residentId]);
        $pdo->prepare("DELETE FROM account_session WHERE account_type = 'resident' AND account_id = ?")->execute([$residentId]);
        $pdo->prepare('DELETE FROM portal_email_queue WHERE recipient = ?')->execute([(string) $resident['email']]);
        $pdo->prepare("UPDATE audit_log SET subject_resident_id = NULL, details = 'Resident data erased with account' WHERE subject_resident_id = ?")->execute([$residentId]);
        $pdo->prepare('DELETE FROM resident WHERE resident_id = ?')->execute([$residentId]);
        if (is_string($resident['proof_path'] ?? null) && $resident['proof_path'] !== '') $filesToDelete[] = $resident['proof_path'];
        $auditAction = 'resident_deleted';
        $message = 'Resident account, linked requests, and proof files permanently deleted.';
    } else {
        if (empty($resident['archived_at'])) {
            $pdo->prepare('UPDATE resident SET archived_at = UTC_TIMESTAMP(), is_active = 0, auth_version = auth_version + 1 WHERE resident_id = ?')->execute([$residentId]);
            $pdo->prepare('DELETE FROM email_verification_token WHERE resident_id = ?')->execute([$residentId]);
        }
        $pdo->prepare("DELETE FROM password_reset_token WHERE account_type = 'resident' AND account_id = ?")->execute([$residentId]);
        $requests = $pdo->prepare('SELECT request_id, tracking_code, proof_path, payment_proof_path FROM document_request WHERE resident_id = ? FOR UPDATE');
        $requests->execute([$residentId]);
        foreach ($requests->fetchAll(PDO::FETCH_ASSOC) as $requestRow) {
            if (is_string($requestRow['proof_path'] ?? null) && $requestRow['proof_path'] !== '') $filesToDelete[] = $requestRow['proof_path'];
            if (is_string($requestRow['payment_proof_path'] ?? null) && $requestRow['payment_proof_path'] !== '') $filesToDelete[] = $requestRow['payment_proof_path'];
            if (is_string($requestRow['tracking_code'] ?? null) && $requestRow['tracking_code'] !== '') {
                $pdo->prepare('DELETE FROM portal_email_queue WHERE text_body LIKE ? OR html_body LIKE ?')->execute(['%' . $requestRow['tracking_code'] . '%', '%' . $requestRow['tracking_code'] . '%']);
            }
            $requestRecordId = (int) ($requestRow['request_id'] ?? 0);
            if ($requestRecordId > 0) {
                $redactRequestAudit = $pdo->prepare("UPDATE audit_log SET details = 'Related request details erased with resident account' WHERE details = ? OR details LIKE ? OR details LIKE ? OR details = ? OR details LIKE ?");
                $redactRequestAudit->execute([
                    'Request ID ' . $requestRecordId,
                    'Request ID ' . $requestRecordId . ';%',
                    'Request ID ' . $requestRecordId . ' %',
                    'Internal note updated for request ID ' . $requestRecordId,
                    'Payment status changed for request ID ' . $requestRecordId . ' to %',
                ]);
            }
        }
        $pdo->prepare('DELETE FROM document_request WHERE resident_id = ?')->execute([$residentId]);
        $pdo->prepare("DELETE FROM account_session WHERE account_type = 'resident' AND account_id = ?")->execute([$residentId]);
        $pdo->prepare('DELETE FROM portal_email_queue WHERE recipient = ?')->execute([(string) $resident['email']]);
        $pdo->prepare("UPDATE audit_log SET subject_resident_id = NULL, details = 'Resident data erased with account' WHERE subject_resident_id = ?")->execute([$residentId]);
        $pdo->prepare('DELETE FROM resident WHERE resident_id = ?')->execute([$residentId]);
        if (is_string($resident['proof_path'] ?? null) && $resident['proof_path'] !== '') $filesToDelete[] = $resident['proof_path'];
        $auditAction = 'resident_archived_and_deleted';
        $message = 'Resident account, linked requests, and proof files permanently deleted.';
    }

    if (in_array($action, ['delete', 'archive_delete'], true)) {
        $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)');
        $audit->execute([(int) $_SESSION['user_id'], $auditAction, 'Resident account and linked request data permanently erased']);
    } else {
        $audit = $pdo->prepare('INSERT INTO audit_log (user_id, subject_resident_id, action, details) VALUES (?, ?, ?, ?)');
        $audit->execute([
            (int) $_SESSION['user_id'],
            (int) $residentId,
            $auditAction,
            'Resident: ' . ($name !== '' ? $name : 'Name not provided') . ' (ID ' . (int) $residentId . '; email ' . (string) $resident['email'] . ')',
        ]);
    }
    $pdo->commit();

    if (in_array($action, ['delete', 'archive_delete'], true)) {
        if (is_string($resident['profile_photo'] ?? null) && preg_match('/^[a-f0-9]{32}\.(?:webp|jpg)$/', $resident['profile_photo'])) {
            try {
                $photoDirectory = realpath(privateStorageDirectory('profile-photos'));
                $photoPath = $photoDirectory === false ? false : realpath($photoDirectory . DIRECTORY_SEPARATOR . $resident['profile_photo']);
                if ($photoPath !== false && is_file($photoPath)
                    && str_starts_with(strtolower($photoPath), strtolower(rtrim($photoDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
                    $filesToDelete[] = $photoPath;
                }
            } catch (Throwable $cleanupError) {
                error_log('Could not access private storage while deleting a resident account.');
                $message .= ' Some stored files could not be reached for cleanup.';
            }
        }
        $cleanupFailed = false;
        foreach (array_unique($filesToDelete) as $storedPath) {
            try {
                $file = str_starts_with($storedPath, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $storedPath)
                    ? $storedPath
                    : resolveStoredUpload($storedPath);
                if (is_string($file) && is_file($file) && !@unlink($file)) $cleanupFailed = true;
            } catch (Throwable $cleanupError) {
                $cleanupFailed = true;
            }
        }
        if ($cleanupFailed) {
            error_log('One or more resident proof files could not be deleted from private storage.');
            $message .= ' Some stored files could not be erased and need administrator cleanup.';
        }
    }

    echo json_encode(['success' => true, 'message' => $message]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Resident account action failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The resident account action could not be completed.']);
}
?>
