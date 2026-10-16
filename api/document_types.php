<?php
require_once 'db.php';
header('Content-Type: application/json');

$stmt = $pdo->query("SELECT document_type_id, name FROM document_type WHERE is_active = 1 ORDER BY name");
echo json_encode($stmt->fetchAll());
?>
