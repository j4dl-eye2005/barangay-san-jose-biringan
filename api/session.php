<?php
require_once __DIR__ . '/security.php';
enforceRequestSafety();
startAppSession();
header('Content-Type: application/json');
header('Cache-Control: no-store, private');

if (isset($_SESSION['user_id'])) {
    echo json_encode(['loggedIn' => true, 'id' => (int) $_SESSION['user_id'], 'name' => (string) ($_SESSION['fullName'] ?? ''), 'role' => 'Admin']);
} elseif (isset($_SESSION['resident_id'])) {
    echo json_encode(['loggedIn' => true, 'id' => (int) $_SESSION['resident_id'], 'name' => (string) ($_SESSION['fullName'] ?? ''), 'role' => 'Resident']);
} else {
    echo json_encode(['loggedIn' => false]);
}
?>
