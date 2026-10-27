<?php
require_once __DIR__ . '/db.php';
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
    $lookup = $pdo->prepare("SELECT dr.payment_status, dr.current_status, dr.assessed_fee, dt.name AS document_name
                             FROM document_request dr
                             JOIN document_type dt ON dt.document_type_id = dr.document_type_id
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
    $update = $pdo->prepare('UPDATE document_request SET assessed_fee = ?, payment_status = ?, payment_reference = NULL, payment_rejection_reason = NULL WHERE request_id = ?');
    $update->execute([$amount, $paymentStatus, $requestId]);
    $audit = $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, 'request_fee_assessed', ?)");
    $audit->execute([(int) $_SESSION['user_id'], 'Request ID ' . $requestId . '; approved fee set to PHP ' . $amount]);
    $pdo->commit();
    echo json_encode(['success' => true, 'fee' => $amount, 'payment_status' => $paymentStatus]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $sqlState = $error instanceof PDOException ? (string) $error->getCode() : 'n/a';
    error_log('Request fee assessment failed (' . get_class($error) . '; SQLSTATE/code ' . $sqlState . ').');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Fee assessment could not be saved. Try again.']);
}
?>
