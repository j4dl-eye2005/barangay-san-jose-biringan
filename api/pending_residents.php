<?php
require_once 'db.php';
header('Content-Type: application/json');

requireAdminAccess();

$page = max(1, min(100000, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;
$total = (int) $pdo->query('SELECT COUNT(*) FROM resident WHERE is_active = FALSE AND email_verified_at IS NOT NULL')->fetchColumn();
$pages = (int) ceil($total / $perPage);
if ($pages > 0 && $page > $pages) { $page = $pages; $offset = ($page - 1) * $perPage; }
$stmt = $pdo->query("SELECT resident_id, first_name, middle_name, last_name, email, phone_number, address,
    CASE WHEN proof_path IS NULL OR proof_path = '' THEN NULL ELSE CONCAT('api/resident_proof.php?id=', resident_id) END AS proof_url,
    LOWER(SUBSTRING_INDEX(proof_path, '.', -1)) AS proof_type, is_active, created_at
    FROM resident WHERE is_active = FALSE AND email_verified_at IS NOT NULL
    ORDER BY created_at DESC, resident_id DESC LIMIT $perPage OFFSET $offset");
echo json_encode(['success' => true, 'residents' => $stmt->fetchAll(), 'page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => $pages]);
?>
