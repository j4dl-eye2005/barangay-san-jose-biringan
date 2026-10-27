<?php
require_once 'db.php';
require_once __DIR__ . '/email.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
if (($_SESSION['role'] ?? '') !== 'Resident' || empty($_SESSION['resident_id'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Resident access required.']);
    exit;
}

$residentId = (int) $_SESSION['resident_id'];
if (!rateLimit($pdo, 'payment-proof-ip', 8, 3600) || !rateLimit($pdo, 'payment-proof-resident-' . $residentId, 5, 3600)) {
    http_response_code(429);
    header('Retry-After: 3600');
    echo json_encode(['success' => false, 'message' => 'Too many payment submissions. Try again later.']);
    exit;
}

$requestId = filter_var($_POST['requestId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$reference = trim(is_string($_POST['paymentReference'] ?? null) ? $_POST['paymentReference'] : '');
$referenceLength = function_exists('mb_strlen') ? mb_strlen($reference, 'UTF-8') : strlen($reference);
$proof = $_FILES['proof'] ?? null;
$extension = $proof ? validateUpload($proof, ['pdf' => true, 'jpg' => true, 'jpeg' => true, 'png' => true], 5 * 1024 * 1024) : null;
if (!$requestId || $referenceLength < 3 || $referenceLength > 120 || preg_match('/[\x00-\x1F\x7F]/', $reference)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Enter a valid payment reference, up to 120 characters.']);
    exit;
}
if ($extension === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Attach a valid receipt as PDF, JPG, or PNG, up to 5 MB.']);
    exit;
}

$proofDirectory = privateStorageDirectory('proofs');
$storedPath = null;
$oldStoredPath = null;
try {
    $pdo->beginTransaction();
    $lookup = $pdo->prepare("SELECT dr.request_id, dr.tracking_code, dr.current_status, dr.payment_status, dr.payment_proof_path,
                                    dt.name AS document_name, COALESCE(dr.assessed_fee, dt.fee_amount) AS fee_amount, r.email
                             FROM document_request dr
                             JOIN document_type dt ON dt.document_type_id = dr.document_type_id
                             JOIN resident r ON r.resident_id = dr.resident_id
                             WHERE dr.request_id = ? AND dr.resident_id = ? AND dr.request_source = 'online'
                             FOR UPDATE");
    $lookup->execute([$requestId, $residentId]);
    $request = $lookup->fetch();
    if (!$request) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Request not found.']);
        exit;
    }
    if ($request['fee_amount'] === null || (float) $request['fee_amount'] <= 0) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This document does not require payment.']);
        exit;
    }
    if (!in_array($request['current_status'], ['Approved', 'Ready for Pickup'], true)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Payment proof can be submitted after the request is approved.']);
        exit;
    }
    if (!in_array($request['payment_status'], ['Unpaid', 'Rejected'], true)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This payment is already submitted or verified.']);
        exit;
    }

    $proofName = bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file((string) $proof['tmp_name'], $proofDirectory . DIRECTORY_SEPARATOR . $proofName)) {
        throw new RuntimeException('Payment proof upload failed.');
    }
    $storedPath = 'proofs/' . $proofName;
    $oldStoredPath = is_string($request['payment_proof_path']) ? $request['payment_proof_path'] : null;

    $update = $pdo->prepare("UPDATE document_request
                             SET payment_status = 'Pending Verification', payment_reference = ?, payment_proof_path = ?,
                                 payment_submitted_at = UTC_TIMESTAMP(), payment_reviewed_at = NULL,
                                 payment_reviewed_by_user_id = NULL, payment_rejection_reason = NULL
                             WHERE request_id = ? AND resident_id = ? AND payment_status IN ('Unpaid', 'Rejected')");
    $update->execute([$reference, $storedPath, $requestId, $residentId]);
    if ($update->rowCount() !== 1) throw new RuntimeException('Payment state changed before the submission completed.');

    $audit = $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (NULL, 'resident_payment_submitted', ?)");
    $audit->execute(['Request ID ' . $requestId . '; payment proof submitted for ' . $request['tracking_code']]);
    notifyAdminsOfPaymentSubmission(
        $pdo,
        (string) $request['tracking_code'],
        (string) $request['document_name'],
        (float) $request['fee_amount']
    );
    $pdo->commit();

    if ($oldStoredPath && preg_match('#^proofs/[a-f0-9]{32}\.(?:pdf|jpg|jpeg|png)$#', $oldStoredPath)) {
        $oldPath = $proofDirectory . DIRECTORY_SEPARATOR . basename($oldStoredPath);
        if (is_file($oldPath)) @unlink($oldPath);
    }
    echo json_encode(['success' => true, 'paymentStatus' => 'Pending Verification']);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($storedPath) {
        $newPath = $proofDirectory . DIRECTORY_SEPARATOR . basename($storedPath);
        if (is_file($newPath)) @unlink($newPath);
    }
    error_log('Resident payment-proof submission failed.');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Payment proof could not be submitted. Try again.']);
}
?>
