<?php
require_once 'db.php';
header('Content-Type: application/json; charset=utf-8');
requireAdminAccess();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = jsonBody(8192);
$requestId = filter_var($data['request_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$status = is_string($data['payment_status'] ?? null) ? $data['payment_status'] : '';
if (!$requestId || !in_array($status, ['Paid', 'Unpaid'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid request and payment status are required']);
    exit;
}

try {
    $pdo->beginTransaction();
    $lookup = $pdo->prepare('SELECT dr.payment_status, dr.current_status, dt.fee_amount FROM document_request dr JOIN document_type dt ON dt.document_type_id = dr.document_type_id WHERE dr.request_id = ? FOR UPDATE');
    $lookup->execute([$requestId]);
    $request = $lookup->fetch();
    if (!$request) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Request not found']);
        exit;
    }
    if ($request['fee_amount'] !== null && (float) $request['fee_amount'] === 0.0) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This document does not require payment']);
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

    $update = $pdo->prepare('UPDATE document_request SET payment_status = ?, payment_reviewed_at = UTC_TIMESTAMP(), payment_reviewed_by_user_id = ?, payment_rejection_reason = NULL WHERE request_id = ?');
    $update->execute([$status, (int) $_SESSION['user_id'], $requestId]);
    $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)');
    $audit->execute([(int) $_SESSION['user_id'], 'request_payment_updated', 'Request ID ' . $requestId . ' payment manually marked ' . strtolower($status)]);
    $pdo->commit();
    echo json_encode(['success' => true, 'payment_status' => $status]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Manual request payment update failed.');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Payment status could not be updated. Try again.']);
}
?>
