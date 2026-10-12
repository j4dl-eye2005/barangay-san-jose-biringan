<?php
require_once 'db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST request required']);
    exit;
}

try {
    // The browser submits this form as multipart/form-data because it contains a file.
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $firstName = trim($_POST['firstName'] ?? '');
    $middleName = trim($_POST['middleName'] ?? '');
    $lastName = trim($_POST['lastName'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $termsAccepted = ($_POST['termsAccepted'] ?? '') === '1';
    $proof = $_FILES['proof'] ?? null;

    if (!$email || !$password || !$firstName || !$lastName || !$address || !$proof || !$termsAccepted) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Enter a valid email address']);
        exit;
    }

    if ($proof['error'] !== UPLOAD_ERR_OK || $proof['size'] > 5 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'Proof must be a file no larger than 5MB']);
        exit;
    }

    $allowedExtensions = ['pdf', 'docx', 'jpg', 'jpeg', 'png'];
    $extension = strtolower(pathinfo($proof['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) {
        echo json_encode(['success' => false, 'message' => 'Unsupported proof file type']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT email FROM resident WHERE email = ? UNION SELECT email FROM user WHERE email = ?");
    $stmt->execute([$email, $email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Email already exists']);
        exit;
    }

    // Save the proof file first so a failed upload never leaves an orphan
    // resident row without an attached document.
    $proofDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'proofs';
    if (!is_dir($proofDirectory) && !mkdir($proofDirectory, 0755, true)) {
        throw new RuntimeException('Unable to create proof upload directory');
    }

    $proofName = bin2hex(random_bytes(16)) . '.' . $extension;
    if (!move_uploaded_file($proof['tmp_name'], $proofDirectory . DIRECTORY_SEPARATOR . $proofName)) {
        throw new RuntimeException('Unable to save proof file');
    }
    $proofPath = 'uploads/proofs/' . $proofName;

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO resident (first_name, middle_name, last_name, email, phone_number, address, proof_path, password_hash, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)"
        );
        $stmt->execute([$firstName, $middleName, $lastName, $email, $phone, $address, $proofPath, $passwordHash]);
    } catch (Throwable $e) {
        // Roll back the stored file if the insert fails.
        $storedProof = $proofDirectory . DIRECTORY_SEPARATOR . $proofName;
        if (is_file($storedProof)) unlink($storedProof);
        throw $e;
    }

    echo json_encode(['success' => true, 'message' => 'Registration successful. Awaiting admin approval.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Registration failed. Please try again.']);
}
?>
