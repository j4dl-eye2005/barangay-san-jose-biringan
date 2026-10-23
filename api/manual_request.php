<?php
require_once 'db.php';
require_once __DIR__ . '/email.php';
header('Content-Type: application/json; charset=utf-8');
requireAdminAccess();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['success'=>false, 'message'=>'Method not allowed']); exit; }
if (!rateLimit($pdo, 'walk-in-request', 30, 3600)) { http_response_code(429); echo json_encode(['success'=>false, 'message'=>'Too many walk-in entries. Try again later.']); exit; }
$data = jsonBody(12000);
$name = trim(is_string($data['name'] ?? null) ? $data['name'] : '');
$phone = trim(is_string($data['phone'] ?? null) ? $data['phone'] : '');
$address = trim(is_string($data['address'] ?? null) ? $data['address'] : '');
$purpose = trim(is_string($data['purpose'] ?? null) ? $data['purpose'] : '');
$documentTypeId = filter_var($data['documentTypeId'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$purposeLength = function_exists('mb_strlen') ? mb_strlen($purpose, 'UTF-8') : strlen($purpose);
if ($name === '' || strlen($name) > 200 || !preg_match('/^[\p{L}\p{M} .\'-]{1,200}$/u', $name)
    || ($phone !== '' && !isValidPhilippineMobile($phone)) || strlen($address) > 255
    || !$documentTypeId || $purpose === '' || $purposeLength > 2000) {
    http_response_code(400); echo json_encode(['success'=>false, 'message'=>'Enter the resident name, document type, and purpose.']); exit;
}
$type = $pdo->prepare('SELECT document_type_id, name, fee_amount FROM document_type WHERE document_type_id = ? AND is_active = 1');
$type->execute([$documentTypeId]);
$documentType = $type->fetch();
if (!$documentType) { http_response_code(400); echo json_encode(['success'=>false, 'message'=>'Choose an available document type.']); exit; }
$trackingCode = 'REQ-' . strtoupper(bin2hex(random_bytes(8)));
$paymentStatus = $documentType['fee_amount'] !== null && (float) $documentType['fee_amount'] === 0.0 ? 'Not Required' : 'Unpaid';
$insert = $pdo->prepare("INSERT INTO document_request (tracking_code, resident_id, document_type_id, purpose, request_source, walk_in_name, walk_in_phone, walk_in_address, payment_status) VALUES (?, NULL, ?, ?, 'walk_in', ?, ?, ?, ?)");
$insert->execute([$trackingCode, $documentTypeId, $purpose, $name, $phone ?: null, $address ?: null, $paymentStatus]);
$requestId = (int) $pdo->lastInsertId();
$audit = $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, 'walk_in_request_created', ?)");
$audit->execute([(int) $_SESSION['user_id'], 'Request ID ' . $requestId . '; Tracking ' . $trackingCode]);
notifyAdminsOfNewRequest($pdo, $trackingCode, (string)$documentType['name'] . ' (walk-in)');
echo json_encode(['success'=>true, 'trackingCode'=>$trackingCode]);
?>
