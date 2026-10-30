<?php
require_once 'db.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/payment_receipt_number.php';
header('Content-Type: application/json; charset=utf-8');
requireAdminAccess('payments.manage');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = jsonBody(8192);
$requestId = filter_var($data['request_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$status = is_string($data['payment_status'] ?? null) ? $data['payment_status'] : '';
 $reference = trim(is_string($data['reference'] ?? null) ? $data['reference'] : '');
if (!$requestId || !in_array($status, ['Paid', 'Unpaid'], true) || strlen($reference) > 120 || preg_match('/[\x00-\x1F\x7F]/', $reference)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid request and payment status are required']);
    exit;
}

try {
    $pdo->beginTransaction();
    $receiptNumber = null;
    $lookup = $pdo->prepare("SELECT dr.payment_status, dr.current_status, dr.tracking_code, dt.name AS document_name,
                                    COALESCE((SELECT r.first_name FROM resident r WHERE r.resident_id = dr.resident_id), dr.walk_in_name) AS resident_name,
                                    (SELECT r.email FROM resident r WHERE r.resident_id = dr.resident_id) AS email,
                                    COALESCE(dr.assessed_fee, dt.fee_amount) AS fee_amount,
                                    (SELECT rpt.payment_id FROM request_payment_transaction rpt WHERE rpt.request_id = dr.request_id AND rpt.status = 'confirmed' ORDER BY rpt.recorded_at DESC, rpt.payment_id DESC LIMIT 1) AS latest_payment_id,
                                    (SELECT rpt.method FROM request_payment_transaction rpt WHERE rpt.request_id = dr.request_id AND rpt.status = 'confirmed' ORDER BY rpt.recorded_at DESC, rpt.payment_id DESC LIMIT 1) AS latest_payment_method
                             FROM document_request dr JOIN document_type dt ON dt.document_type_id = dr.document_type_id
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
        echo json_encode(['success' => false, 'message' => 'A positive assessed fee is required before recording payment.']);
        exit;
    }
    if ($request['payment_status'] === 'Pending Verification') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Review the submitted proof before changing this payment status']);
        exit;
    }
    if (!in_array($request['current_status'], ['Approved', 'Ready for Pickup'], true)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Payment can only be recorded for an approved request']);
        exit;
    }

    if ($status === 'Paid') {
        if ($request['payment_status'] === 'Paid') {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'This request is already recorded as paid.']);
            exit;
        }
        $record = $pdo->prepare("INSERT INTO request_payment_transaction (request_id, amount, method, reference, recorded_by_user_id, recorded_at) VALUES (?, ?, 'cash', ?, ?, UTC_TIMESTAMP())");
        $record->execute([$requestId, $request['fee_amount'], $reference !== '' ? $reference : null, (int) $_SESSION['user_id']]);
        $receiptNumber = portalPaymentReceiptNumber((int) $pdo->lastInsertId());
        $update = $pdo->prepare('UPDATE document_request SET payment_status = \'Paid\', payment_reference = ?, payment_reviewed_at = UTC_TIMESTAMP(), payment_reviewed_by_user_id = ?, payment_rejection_reason = NULL WHERE request_id = ?');
        $update->execute([$reference !== '' ? $reference : null, (int) $_SESSION['user_id'], $requestId]);
        $actionText = 'cash payment recorded';
    } else {
        if ($request['payment_status'] !== 'Paid' || $request['latest_payment_method'] !== 'cash' || !$request['latest_payment_id']) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Only an in-person cash payment can be reversed here. Online proof decisions must be corrected by the payment reviewer.']);
            exit;
        }
        $reverse = $pdo->prepare("UPDATE request_payment_transaction SET status = 'reversed', reversed_by_user_id = ?, reversed_at = UTC_TIMESTAMP() WHERE payment_id = ? AND status = 'confirmed'");
        $reverse->execute([(int) $_SESSION['user_id'], (int) $request['latest_payment_id']]);
        if ($reverse->rowCount() !== 1) throw new RuntimeException('Payment transaction changed before reversal.');
        $update = $pdo->prepare("UPDATE document_request SET payment_status = 'Unpaid', payment_reference = NULL, payment_reviewed_at = UTC_TIMESTAMP(), payment_reviewed_by_user_id = ?, payment_rejection_reason = NULL WHERE request_id = ?");
        $update->execute([(int) $_SESSION['user_id'], $requestId]);
        $actionText = 'cash payment reversed';
    }
    $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)');
    $audit->execute([(int) $_SESSION['user_id'], 'request_payment_updated', 'Request ID ' . $requestId . ' ' . $actionText . ($reference !== '' ? '; reference recorded' : '')]);
    $pdo->commit();
    $emailDelivery = 'failed';
    $emailQueueId = null;
    if (is_string($request['email']) && filter_var($request['email'], FILTER_VALIDATE_EMAIL)) {
        $residentName = trim((string) ($request['resident_name'] ?? '')) ?: 'Resident';
        $amount = 'PHP ' . number_format((float) $request['fee_amount'], 2);
        if ($status === 'Paid') {
            $recordedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('F j, Y g:i A') . ' (Philippine time)';
            $referenceLine = $reference !== '' ? $reference : 'Not recorded';
            $message = "Hello $residentName,\n\nThe Barangay Hall cashier received your in-person cash payment, and staff recorded it as paid.\nRequest: {$request['tracking_code']}\nDocument: {$request['document_name']}\nAmount: $amount\nCashier receipt reference: $referenceLine\nPortal receipt no.: $receiptNumber\nRecorded: $recordedAt\n\nKeep the official receipt issued by the Barangay Hall cashier. The portal receipt number is a status reference, not an official receipt.\n";
            $paragraphs = [
                "Hello $residentName,",
                "The Barangay Hall cashier received your in-person cash payment, and staff recorded it as paid for {$request['document_name']}.",
                "Request: {$request['tracking_code']}\nAmount: $amount\nCashier receipt reference: $referenceLine\nPortal receipt no.: $receiptNumber\nRecorded: $recordedAt",
                'Keep the official receipt issued by the Barangay Hall cashier.'
            ];
            $emailTitle = 'Cash payment received';
            $emailNote = 'This email confirms the portal payment record. The cashier-issued official receipt is your payment receipt; the portal number is for status reference.';
        } else {
            $message = "Hello $residentName,\n\nThe in-person payment of $amount for {$request['document_name']} (tracking code: {$request['tracking_code']}) was reversed. Please contact the barangay office if you have questions.";
            $paragraphs = ["Hello $residentName,", "The in-person payment of $amount for {$request['document_name']} (tracking code: {$request['tracking_code']}) was reversed.", 'Please contact the barangay office if you have questions.'];
            $emailTitle = 'Payment reversed';
            $emailNote = null;
        }
        $config = portalConfig();
        $html = portalEmailTemplate('Payment update', $emailTitle, $paragraphs, 'View your request', rtrim((string) $config['app_url'], '/') . '/#dashboard', $emailNote);
        $mailError = null;
        $emailDelivery = queuePortalNotification($pdo, (string) $request['email'], $emailTitle . ' for request ' . $request['tracking_code'], $message, $html, $mailError, $emailQueueId);
        if ($emailDelivery === 'failed') error_log("admin_request_payment.php email failed for request $requestId: " . ($mailError ?? 'unknown error'));
    }
    echo json_encode(['success' => true, 'payment_status' => $status, 'receipt_number' => $receiptNumber, 'emailDelivery' => $emailDelivery, 'emailQueueId' => $emailQueueId]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Manual request payment update failed.');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Payment status could not be updated. Try again.']);
}
?>
