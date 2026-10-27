<?php
require_once __DIR__ . '/db.php';
if (!enforceAuthenticatedSessionVersion($pdo)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['loggedIn' => false, 'message' => 'Session status is temporarily unavailable.']);
    exit;
}
header('Content-Type: application/json');
header('Cache-Control: no-store, private');

if (isset($_SESSION['user_id'])) {
    $adminProfile = $pdo->prepare('SELECT email, staff_role FROM user WHERE user_id = ? AND role = ? LIMIT 1');
    $adminProfile->execute([(int) $_SESSION['user_id'], 'Admin']);
    $adminRecord = $adminProfile->fetch(PDO::FETCH_ASSOC) ?: [];
    $adminEmail = (string) ($adminRecord['email'] ?? ($_SESSION['email'] ?? ''));
    $staffRole = (string) ($adminRecord['staff_role'] ?? '');
    $_SESSION['email'] = $adminEmail;
    echo json_encode(['loggedIn' => true, 'id' => (int) $_SESSION['user_id'], 'name' => (string) ($_SESSION['fullName'] ?? ''), 'email' => $adminEmail, 'role' => 'Admin', 'staffRole' => $staffRole, 'createdAt' => (int) ($_SESSION['_created_at'] ?? time())]);
} elseif (isset($_SESSION['resident_id'])) {
    $profile = $pdo->prepare('SELECT email, phone_number, email_verified_at, archived_at FROM resident WHERE resident_id = ?');
    $profile->execute([(int) $_SESSION['resident_id']]);
    $resident = $profile->fetch();
    if (!$resident || $resident['archived_at']) {
        $_SESSION = [];
        echo json_encode(['loggedIn' => false, 'sessionExpired' => !empty($GLOBALS['sessionExpiredThisRequest'])]);
    } else {
        echo json_encode(['loggedIn' => true, 'id' => (int) $_SESSION['resident_id'], 'name' => (string) ($_SESSION['fullName'] ?? ''), 'email' => (string) $resident['email'], 'phoneNumber' => (string) ($resident['phone_number'] ?? ''), 'emailVerified' => !empty($resident['email_verified_at']), 'role' => 'Resident']);
    }
} else {
    echo json_encode(['loggedIn' => false, 'sessionExpired' => !empty($GLOBALS['sessionExpiredThisRequest'])]);
}
?>
