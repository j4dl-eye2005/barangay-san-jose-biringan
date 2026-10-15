<?php
require_once 'db.php';
require_once 'email.php';
require_once 'config.php';
header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 0);

// Only Admins can approve/reject
requireAdminAccess();

$data = jsonBody(12000);
$residentId = filter_var($data['residentId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = strtolower(is_string($data['action'] ?? null) ? $data['action'] : '');
$rejectionReason = trim(is_string($data['reason'] ?? null) ? $data['reason'] : '');

if (!$residentId || !$action) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

if (!in_array($action, ['approve', 'reject'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid resident action']);
    exit;
}
if (strlen($rejectionReason) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $rejectionReason)) { http_response_code(400); echo json_encode(['success'=>false, 'message'=>'Invalid rejection reason.']); exit; }

$resident = $pdo->prepare("SELECT first_name, middle_name, last_name, email, proof_path, is_active, email_verified_at FROM resident WHERE resident_id = ?");
$resident->execute([$residentId]);
$residentInfo = $resident->fetch();
if (!$residentInfo) {
    echo json_encode(['success' => false, 'message' => 'Resident not found']);
    exit;
}
if ((int) $residentInfo['is_active'] === 1) {
    echo json_encode(['success' => false, 'message' => 'This resident has already been verified.']);
    exit;
}
if (empty($residentInfo['email_verified_at'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Email verification is required before residency review.']);
    exit;
}

try {
    if ($action === 'approve') {
        // Activate the account so it drops out of the pending list.
        $stmt = $pdo->prepare("UPDATE resident SET is_active = 1 WHERE resident_id = ?");
        $stmt->execute([$residentId]);
    } else {
        // Reject: remove the pending resident entirely so it no longer appears
        // in the pending list (pending and rejected would otherwise both be is_active = 0).
        $stmt = $pdo->prepare("DELETE FROM resident WHERE resident_id = ? AND is_active = 0 AND email_verified_at IS NOT NULL");
        $stmt->execute([$residentId]);

        // Clean up the stored proof file for the rejected resident.
        $absoluteProof = resolveStoredUpload((string) ($residentInfo['proof_path'] ?? ''));
        if ($absoluteProof && is_file($absoluteProof)) @unlink($absoluteProof);
    }

    $log = $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)");
    $residentName = trim(implode(' ', array_filter([$residentInfo['first_name'], $residentInfo['middle_name'], $residentInfo['last_name']])));
    $auditDetails = "Resident $residentName (ID $residentId; {$residentInfo['email']})";
    if ($action === 'reject' && $rejectionReason !== '') $auditDetails .= "; reason: $rejectionReason";
    $log->execute([$_SESSION['user_id'], $action . '_resident', $auditDetails]);

    $firstName = trim((string) ($residentInfo['first_name'] ?? '')) ?: 'there';
    $appUrl = portalConfig()['app_url'];
    $emailSubject = $action === 'approve'
        ? 'Your Barangay San Jose account is verified'
        : 'Update on your Barangay San Jose account verification';
    if ($action === 'approve') {
        $emailMessage = "Hello $firstName,\n\nYour resident account has been verified by the Barangay San Jose administrator. You can now log in and submit document requests.\n\nThank you,\nBarangay San Jose";
        $htmlMessage = portalEmailTemplate('Resident account', 'Your account is verified', [
            "Hello $firstName,",
            'Your resident account has been verified by the Barangay San Jose administrator. You can now log in and submit document requests.'
        ], 'Log in to the portal', $appUrl . '/#login');
    } else {
        $emailMessage = "Hello $firstName,\n\nYour resident account could not be verified at this time. Please contact the barangay office if you need help.\n";
        $rejectionNote = $rejectionReason !== '' ? "Reason provided by the administrator:\n$rejectionReason" : null;
        if ($rejectionReason !== '') $emailMessage .= "\nReason: $rejectionReason\n";
        $emailMessage .= "\nThank you,\nBarangay San Jose";
        $htmlMessage = portalEmailTemplate('Resident account', 'Verification was not approved', [
            "Hello $firstName,",
            'Your resident account could not be verified at this time. Please contact the barangay office if you need help.'
        ], null, null, $rejectionNote);
    }
    $emailError = null;
    $recipientEmail = $residentInfo['email'] ?? '';
    $emailSent = queuePortalEmail($pdo, $recipientEmail, $emailSubject, $emailMessage, $htmlMessage, $emailError);
    if (!$emailSent) $emailSent = sendPortalEmail($recipientEmail, $emailSubject, $emailMessage, $emailError, $htmlMessage);
    if (!$emailSent) {
        error_log("approve_resident.php: email FAILED — " . ($emailError ?? 'no error detail'));
    }

    echo json_encode([
        'success' => true,
        'message' => $emailSent
            ? "Resident $action successfully. Notification email queued."
            : "Resident $action successfully, but the notification email could not be sent. Check PHP mail configuration.",
        'emailSent' => $emailSent,
    ]);
} catch (PDOException $e) {
    error_log('Resident approval database error: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'The database is unavailable. Start MySQL in XAMPP and try again.',
    ]);
}
?>
