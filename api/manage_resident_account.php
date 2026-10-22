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
    $find = $pdo->prepare('SELECT resident_id, first_name, middle_name, last_name, email, archived_at, proof_path FROM resident WHERE resident_id = ? FOR UPDATE');
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
        $pdo->prepare('DELETE FROM resident WHERE resident_id = ?')->execute([$residentId]);
        $auditAction = 'resident_deleted';
        $message = 'Resident account permanently deleted. Related request records were kept.';
    } else {
        if (empty($resident['archived_at'])) {
            $pdo->prepare('UPDATE resident SET archived_at = UTC_TIMESTAMP(), is_active = 0, auth_version = auth_version + 1 WHERE resident_id = ?')->execute([$residentId]);
            $pdo->prepare('DELETE FROM email_verification_token WHERE resident_id = ?')->execute([$residentId]);
        }
        $pdo->prepare("DELETE FROM password_reset_token WHERE account_type = 'resident' AND account_id = ?")->execute([$residentId]);
        $pdo->prepare('DELETE FROM resident WHERE resident_id = ?')->execute([$residentId]);
        $auditAction = 'resident_archived_and_deleted';
        $message = 'Resident account archived and permanently deleted. Related request records were kept.';
    }

    $audit = $pdo->prepare('INSERT INTO audit_log (user_id, subject_resident_id, action, details) VALUES (?, ?, ?, ?)');
    $audit->execute([
        (int) $_SESSION['user_id'],
        (int) $residentId,
        $auditAction,
        'Resident: ' . ($name !== '' ? $name : 'Name not provided') . ' (ID ' . (int) $residentId . '; email ' . (string) $resident['email'] . ')',
    ]);
    $pdo->commit();

    if (in_array($action, ['delete', 'archive_delete'], true) && is_string($resident['proof_path'] ?? null)) {
        $proofFile = resolveStoredUpload($resident['proof_path']);
        if ($proofFile) @unlink($proofFile);
    }

    echo json_encode(['success' => true, 'message' => $message]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Resident account action failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The resident account action could not be completed.']);
}
?>
