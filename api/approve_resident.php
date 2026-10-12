<?php
require_once 'db.php';
require_once 'email.php';
header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 0);

// Only Admins can approve/reject
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$residentId = $data['residentId'] ?? null;
$action = strtolower($data['action'] ?? '');
$rejectionReason = trim($data['reason'] ?? '');

if (!$residentId || !$action) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

if (!in_array($action, ['approve', 'reject'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid resident action']);
    exit;
}
if ($action === 'reject' && $rejectionReason === '') {
    echo json_encode(['success' => false, 'message' => 'A reason is required to reject a resident']);
    exit;
}

$resident = $pdo->prepare("SELECT first_name, middle_name, last_name, email, proof_path FROM resident WHERE resident_id = ?");
$resident->execute([$residentId]);
$residentInfo = $resident->fetch();
if (!$residentInfo) {
    echo json_encode(['success' => false, 'message' => 'Resident not found']);
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
        $stmt = $pdo->prepare("DELETE FROM resident WHERE resident_id = ? AND is_active = 0");
        $stmt->execute([$residentId]);

        // Clean up the stored proof file for the rejected resident.
        $storedProof = $residentInfo['proof_path'] ?? '';
        if (is_string($storedProof) && preg_match('#^uploads/[\w./-]+$#', $storedProof)) {
            $absoluteProof = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedProof);
            if (is_file($absoluteProof)) @unlink($absoluteProof);
        }
    }

    $log = $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)");
    $residentName = trim(implode(' ', array_filter([$residentInfo['first_name'], $residentInfo['middle_name'], $residentInfo['last_name']])));
    $auditDetails = "Resident $residentName (ID $residentId; {$residentInfo['email']})";
    if ($action === 'reject') $auditDetails .= "; reason: $rejectionReason";
    $log->execute([$_SESSION['user_id'], $action . '_resident', $auditDetails]);

    $emailSubject = $action === 'approve'
        ? 'Your Barangay Portal email has been verified'
        : 'Barangay Portal account verification update';
    $emailMessage = "Hello {$residentInfo['first_name']},\n\n"
        . ($action === 'approve'
            ? "Your email and resident account have been verified by the barangay administrator.\n"
            : "Your resident account verification was rejected by the barangay administrator.\n");
    $emailMessage .= $action === 'approve'
        ? "You can now log in to the Barangay Digital Portal and submit document requests.\n"
        : "Please contact the barangay office for more information.\n";
    if ($action === 'reject') $emailMessage .= "Reason: {$rejectionReason}\n";
    $emailSent = sendPortalEmail($residentInfo['email'], $emailSubject, $emailMessage);

    echo json_encode([
        'success' => true,
        'message' => $emailSent
            ? "Resident $action successfully. Notification email sent."
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
