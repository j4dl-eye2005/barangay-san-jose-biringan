<?php
require_once 'db.php';
header('Content-Type: application/json');

if (isset($_SESSION['user_id'])) {
    echo json_encode(['loggedIn' => true, 'id' => $_SESSION['user_id'], 'name' => $_SESSION['fullName'], 'role' => 'Admin']);
} elseif (isset($_SESSION['resident_id'])) {
    echo json_encode(['loggedIn' => true, 'id' => $_SESSION['resident_id'], 'name' => $_SESSION['fullName'], 'role' => 'Resident']);
} else {
    echo json_encode(['loggedIn' => false]);
}
?>
