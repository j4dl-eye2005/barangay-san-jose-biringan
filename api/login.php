<?php
require_once 'db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';
    // Residents log in through the public portal; admins log in through a
    // separate hidden page. Each context only authenticates against its own
    // table so neither form can be used to sign in as the other role.
    $context = strtolower($data['context'] ?? 'resident') === 'admin' ? 'admin' : 'resident';

    if ($context === 'admin') {
        $stmt = $pdo->prepare("SELECT * FROM user WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['role'] = 'Admin';
            $_SESSION['fullName'] = $user['first_name'] . ' ' . $user['last_name'];
            echo json_encode([
                'success' => true,
                'id' => $user['user_id'],
                'name' => $_SESSION['fullName'],
                'role' => 'Admin'
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
        exit;
    }

    // Resident context (public login).
    $stmt = $pdo->prepare("SELECT * FROM resident WHERE email = ?");
    $stmt->execute([$email]);
    $resident = $stmt->fetch();

    if ($resident && password_verify($password, $resident['password_hash'])) {
        if (!$resident['is_active']) {
            echo json_encode(['success' => false, 'message' => 'Account Pending Verification']);
            exit;
        }
        $_SESSION['resident_id'] = $resident['resident_id'];
        $_SESSION['role'] = 'Resident';
        $_SESSION['fullName'] = $resident['first_name'] . ' ' . $resident['last_name'];
        echo json_encode([
            'success' => true,
            'id' => $resident['resident_id'],
            'name' => $_SESSION['fullName'],
            'role' => 'Resident'
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
}
?>
