<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
requireAdminAccess('payments.manage');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST request required.']);
    exit;
}
$data = jsonBody(4096);
$requestId = filter_var($data['request_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$amountInput = is_string($data['amount'] ?? null) ? trim($data['amount']) : (is_numeric($data['amount'] ?? null) ? (string) $data['amount'] : '');
if (!$requestId || !preg_match('/^(?:0|[1-9]\d{0,5})(?:\.\d{1,2})?$/', $amountInput)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Enter a valid fee from the approved barangay schedule.']);
    exit;
}
$amount = number_format((float) $amountInput, 2, '.', '');

try {
    $pdo->beginTransaction();
    $lookup = $pdo->prepare("SELECT dr.payment_status, dr.current_status, dr.assessed_fee,
                                    COALESCE(dr.assessed_fee, dt.fee_amount) AS current_fee,
                                    dt.name AS document_name, dr.tracking_code,
                                    r.email, COALESCE(r.first_name, dr.walk_in_name) AS first_name
                             FROM document_request dr
                             JOIN document_type dt ON dt.document_type_id = dr.document_type_id
                             LEFT JOIN resident r ON r.resident_id = dr.resident_id
                             WHERE dr.request_id = ?
                             FOR UPDATE");
    $lookup->execute([$requestId]);
    $request = $lookup->fetch(PDO::FETCH_ASSOC);
    if (!$request) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Request not found.']);
        exit;
    }
    if (stripos((string) $request['document_name'], 'business') === false) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Manual assessment is only enabled for business clearance requests.']);
        exit;
    }
    $paymentLookup = $pdo->prepare("SELECT payment_id FROM request_payment_transaction
                                    WHERE request_id = ? AND status = 'confirmed'
                                    LIMIT 1 FOR UPDATE");
    $paymentLookup->execute([$requestId]);
    $hasConfirmedPayment = $paymentLookup->fetchColumn() !== false;
    if (!in_array($request['current_status'], ['Pending', 'Approved'], true)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Fee can only be set while the request is pending or approved.']);
        exit;
    }
    if (in_array($request['payment_status'], ['Paid', 'Pending Verification'], true) || $hasConfirmedPayment) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Payment has already been submitted or recorded; the fee cannot be changed.']);
        exit;
    }
    $paymentStatus = (float) $amount === 0.0 ? 'Not Required' : 'Unpaid';
    $feeChanged = $request['current_fee'] === null || (float) $request['current_fee'] !== (float) $amount;
    $update = $pdo->prepare('UPDATE document_request SET assessed_fee = ?, payment_status = ?, payment_reference = NULL, payment_rejection_reason = NULL WHERE request_id = ?');
    $update->execute([$amount, $paymentStatus, $requestId]);
    $audit = $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, 'request_fee_assessed', ?)");
    $audit->execute([(int) $_SESSION['user_id'], 'Request ID ' . $requestId . '; approved fee set to PHP ' . $amount]);
    $pdo->commit();

    $emailDelivery = $feeChanged ? 'failed' : 'not_needed';
    if ($feeChanged && !empty($request['email'])) {
        $firstName = trim((string) ($request['first_name'] ?? '')) ?: 'there';
        $trackingCode = (string) $request['tracking_code'];
        $appUrl = rtrim((string) portalConfig()['app_url'], '/');
        $formattedAmount = 'PHP ' . number_format((float) $amount, 2);
        if ((float) $amount <= 0) {
            $paragraphs = [
                "Hello $firstName,",
                "The fee for your {$request['document_name']} request has been assessed as PHP 0.00.",
                "Tracking code: $trackingCode",
                'No payment is required for this request.'
            ];
            $plainBody = implode("\n\n", $paragraphs) . "\n\nThank you,\nBarangay San Jose";
            $emailNote = 'This is a request status update, not an official payment receipt.';
        } else {
            $paragraphs = [
                "Hello $firstName,",
                "The fee for your {$request['document_name']} request has been assessed as $formattedAmount.",
                "Tracking code: $trackingCode",
                'Please pay in person at the Barangay Hall cashier in Barangay San Jose, Biringan City. Show your tracking code and confirm the accepted payment method with the cashier before paying.',
                'Keep the official receipt. This portal does not process online payments.'
            ];
            $plainBody = implode("\n\n", $paragraphs) . "\n\nThank you,\nBarangay San Jose";
            $emailNote = 'Do not send payment through this portal. Follow the cashier’s instructions and keep the official receipt.';
        }
        $html = portalEmailTemplate(
            'Business clearance fee',
            'Your request fee has been assessed',
            $paragraphs,
            'View your request',
            $appUrl . '/#dashboard',
            $emailNote
        );
        $mailError = null;
        $emailDelivery = queuePortalNotification(
            $pdo,
            (string) $request['email'],
            'Fee assessed for request ' . $trackingCode,
            $plainBody,
            $html,
            $mailError
        );
        if ($emailDelivery === 'failed') {
            error_log("admin_assess_fee.php email failed for request $requestId: " . ($mailError ?? 'unknown error'));
        }
    }
    echo json_encode(['success' => true, 'fee' => $amount, 'payment_status' => $paymentStatus, 'emailDelivery' => $emailDelivery]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $sqlState = $error instanceof PDOException ? (string) $error->getCode() : 'n/a';
    error_log('Request fee assessment failed (' . get_class($error) . '; SQLSTATE/code ' . $sqlState . ').');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Fee assessment could not be saved. Try again.']);
}
?>
