<?php
require_once 'db.php';
header('Content-Type: application/json');

requireAdminAccess();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$update = $pdo->prepare('UPDATE document_request SET payment_status = ? WHERE request_id = ?');
$update->execute([$status, $requestId]);
if ($update->rowCount() === 0) {
    $check = $pdo->prepare('SELECT request_id FROM document_request WHERE request_id = ?');
    $check->execute([$requestId]);
    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Request not found']);
        exit;
    }
}

$audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)');
$audit->execute([$_SESSION['user_id'], 'request_payment_updated', 'Payment status changed for request ID ' . $requestId . ' to ' . $status]);

echo json_encode(['success' => true, 'payment_status' => $status]);
?>
