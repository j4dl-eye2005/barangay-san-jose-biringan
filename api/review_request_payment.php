<?php
require_once 'db.php';
require_once __DIR__ . '/email.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
requireAdminAccess('payments.manage');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = jsonBody(8192);
$requestId = filter_var($data['request_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$action = is_string($data['action'] ?? null) ? $data['action'] : '';
$reason = trim(is_string($data['reason'] ?? null) ? $data['reason'] : '');
$reasonLength = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
if (!$requestId || !in_array($action, ['verify', 'reject'], true)
    || ($action === 'reject' && ($reasonLength < 3 || $reasonLength > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Choose a valid review action and provide a rejection reason when rejecting proof.']);
    exit;
}

try {
    $pdo->beginTransaction();
    $lookup = $pdo->prepare("SELECT dr.payment_status, dr.payment_proof_path, dr.tracking_code, dr.current_status,
                                    dt.name AS document_name, COALESCE(dr.assessed_fee, dt.fee_amount) AS fee_amount, dr.payment_reference, r.email, r.first_name
                             FROM document_request dr
                             JOIN document_type dt ON dt.document_type_id = dr.document_type_id
                             LEFT JOIN resident r ON r.resident_id = dr.resident_id
                             WHERE dr.request_id = ? FOR UPDATE");
    $lookup->execute([$requestId]);
    $request = $lookup->fetch();
    if (!$request) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Request not found']);
        exit;
    }
    if ($request['fee_amount'] === null || (float) $request['fee_amount'] <= 0) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This document does not require payment']);
        exit;
    }
    if ($request['payment_status'] !== 'Pending Verification'
        || !is_string($request['payment_proof_path'])
        || !preg_match('#^proofs/[a-f0-9]{32}\.(?:pdf|jpg|jpeg|png)$#', $request['payment_proof_path'])) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'There is no pending payment proof to review']);
        exit;
    }
    if (!in_array($request['current_status'], ['Approved', 'Ready for Pickup'], true)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'The request is no longer eligible for payment review']);
        exit;
    }

    $newStatus = $action === 'verify' ? 'Paid' : 'Rejected';
    $reviewReason = $action === 'reject' ? $reason : null;
    if ($action === 'verify') {
        $record = $pdo->prepare("INSERT INTO request_payment_transaction (request_id, amount, method, reference, recorded_by_user_id, recorded_at) VALUES (?, ?, 'online_proof', ?, ?, UTC_TIMESTAMP())");
        $record->execute([$requestId, $request['fee_amount'], $request['payment_reference'], (int) $_SESSION['user_id']]);
    }
    $update = $pdo->prepare('UPDATE document_request SET payment_status = ?, payment_reviewed_at = UTC_TIMESTAMP(), payment_reviewed_by_user_id = ?, payment_rejection_reason = ? WHERE request_id = ? AND payment_status = \'Pending Verification\'');
    $update->execute([$newStatus, (int) $_SESSION['user_id'], $reviewReason, $requestId]);
    if ($update->rowCount() !== 1) throw new RuntimeException('Payment review state changed before the update completed.');

    $auditAction = $action === 'verify' ? 'request_payment_verified' : 'request_payment_rejected';
    $auditDetails = 'Request ID ' . $requestId . ' payment proof ' . strtolower($newStatus);
    if ($reviewReason !== null) $auditDetails .= '; reason: ' . $reviewReason;
    $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)');
    $audit->execute([(int) $_SESSION['user_id'], $auditAction, $auditDetails]);

    $pdo->commit();
    $emailDelivery = 'failed';
    $emailQueueId = null;
    if (is_string($request['email']) && filter_var($request['email'], FILTER_VALIDATE_EMAIL)) {
        $residentName = trim((string) ($request['first_name'] ?? '')) ?: 'Resident';
        $amount = 'PHP ' . number_format((float) $request['fee_amount'], 2);
        $reviewedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('F j, Y g:i A') . ' (Philippine time)';
        if ($action === 'verify') {
            $referenceLine = trim((string) ($request['payment_reference'] ?? ''));
            $message = "Hello $residentName,\n\nYour payment proof has been verified by staff.\nRequest: {$request['tracking_code']}\nDocument: {$request['document_name']}\nAmount: $amount\nPayment reference: " . ($referenceLine !== '' ? $referenceLine : 'Not recorded') . "\nVerified: $reviewedAt\n\nKeep the official receipt from the cashier or payment provider. This email is a payment-status confirmation, not an official receipt.\n";
            $paragraphs = [
                "Hello $residentName,",
                "Your payment proof has been verified by staff for {$request['document_name']}.",
                "Request: {$request['tracking_code']}\nAmount: $amount\nPayment reference: " . ($referenceLine !== '' ? $referenceLine : 'Not recorded') . "\nVerified: $reviewedAt",
                'Keep the official receipt from the cashier or payment provider.'
            ];
            $emailTitle = 'Payment verified';
            $emailNote = 'This message confirms the portal status after staff reviewed your proof. It is not an official receipt.';
        } else {
            $message = "Hello $residentName,\n\nYour payment proof for request {$request['tracking_code']} ($amount) could not be verified.\nReason: $reason\nPlease submit a corrected receipt or contact the barangay office.\n";
            $paragraphs = ["Hello $residentName,", "Your payment proof for request {$request['tracking_code']} ($amount) could not be verified.", "Reason: $reason", 'Submit a corrected receipt or contact the barangay office.'];
            $emailTitle = 'Payment proof needs an update';
            $emailNote = null;
        }
        $config = portalConfig();
        $html = portalEmailTemplate('Payment update', $emailTitle, $paragraphs, 'View your request', rtrim((string) $config['app_url'], '/') . '/#dashboard', $emailNote);
        $mailError = null;
        $emailDelivery = queuePortalNotification($pdo, (string) $request['email'], $emailTitle . ' for request ' . $request['tracking_code'], $message, $html, $mailError, $emailQueueId);
        if ($emailDelivery === 'failed') error_log("review_request_payment.php email failed for request $requestId: " . ($mailError ?? 'unknown error'));
    }
    echo json_encode(['success' => true, 'payment_status' => $newStatus, 'emailDelivery' => $emailDelivery, 'emailQueueId' => $emailQueueId]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Request payment review failed.');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The payment review could not be saved. Try again.']);
}
?>
