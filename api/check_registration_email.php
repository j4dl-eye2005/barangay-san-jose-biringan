<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST request required.']);
    exit;
}

$email = strtolower(trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : ''));
if (strlen($email) > 190 || !isAllowedEmailProvider($email)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Enter a valid email address.']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT email FROM resident WHERE LOWER(email) = ? UNION SELECT email FROM user WHERE LOWER(email) = ? LIMIT 1'
    );
    $stmt->execute([$email, $email]);
    $exists = (bool) $stmt->fetchColumn();
    echo json_encode(['success' => true, 'available' => !$exists]);
} catch (Throwable $e) {
    error_log('Registration email availability check failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Email check is temporarily unavailable.']);
}
