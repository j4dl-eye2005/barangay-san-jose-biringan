<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
requireAdminAccess('documents.issue');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST request required.']);
    exit;
}
$data = jsonBody(4096);
$requestId = filter_var($data['request_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$requestId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid request ID is required.']);
    exit;
}
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT dr.request_id, dr.tracking_code, dr.current_status, dr.payment_status,
            COALESCE(dr.assessed_fee, dt.fee_amount) AS fee_amount, dt.name AS document_type,
            COALESCE(r.first_name, dr.walk_in_name) AS first_name, r.middle_name, r.last_name, r.suffix,
            r.address, r.date_of_birth, r.civil_status, r.gender, dr.business_name, dr.business_category,
            dr.business_address, dr.business_activity, dr.purpose
        FROM document_request dr JOIN document_type dt ON dt.document_type_id = dr.document_type_id
        LEFT JOIN resident r ON r.resident_id = dr.resident_id WHERE dr.request_id = ? FOR UPDATE");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    $paid = $request && $request['fee_amount'] !== null
        && (((float) $request['fee_amount'] <= 0 && $request['payment_status'] === 'Not Required')
            || ((float) $request['fee_amount'] > 0 && $request['payment_status'] === 'Paid'));
    if (!$request || !$paid || !in_array($request['current_status'], ['Approved', 'Ready for Pickup', 'Completed'], true)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Confirm request approval and payment before recording a reviewed sample.']);
        exit;
    }
    $issuedAt = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $documentNumber = 'SJ-SAMPLE-' . $issuedAt->format('Y') . '-' . str_pad((string) $requestId, 8, '0', STR_PAD_LEFT) . '-' . strtoupper(bin2hex(random_bytes(3)));
    $payload = json_encode(['template_version' => 1, 'document_number' => $documentNumber, 'prepared_on' => $issuedAt->format('Y-m-d'), 'request' => $request], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $hash = hash('sha256', (string) $payload);
    $insert = $pdo->prepare('INSERT INTO document_issuance (request_id, document_number, content_hash, issued_by_user_id, issued_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())');
    $insert->execute([$requestId, $documentNumber, $hash, (int) $_SESSION['user_id']]);
    $audit = $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, 'sample_document_recorded', ?)");
    $audit->execute([(int) $_SESSION['user_id'], 'Reviewed sample print recorded for request ID ' . $requestId . '; document number ' . $documentNumber]);
    $pdo->commit();
    echo json_encode(['success' => true, 'document_number' => $documentNumber, 'issued_at' => $issuedAt->format(DateTimeInterface::ATOM)]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Reviewed sample document record failed.');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The sample print could not be recorded. Try again.']);
}
?>
