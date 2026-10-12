<?php
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    echo json_encode([]); exit;
}

$stmt = $pdo->query("SELECT resident_id, first_name, middle_name, last_name, email, phone_number, address, proof_path, is_active, created_at FROM resident WHERE is_active = FALSE ORDER BY created_at DESC");
echo json_encode($stmt->fetchAll());
?>