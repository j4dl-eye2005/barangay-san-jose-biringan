<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
requireAdminAccess('residents.view');

$page = max(1, min(1000, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;
$total = (int) $pdo->query('SELECT COUNT(*) FROM resident')->fetchColumn();
$pages = (int) ceil($total / $perPage);
if ($pages > 0) $page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare('SELECT resident_id, first_name, middle_name, last_name, suffix, email, phone_number, address, date_of_birth, civil_status, gender,
        email_verified_at, archived_at, created_at
    FROM resident
    ORDER BY (archived_at IS NOT NULL) ASC, created_at DESC, resident_id DESC
    LIMIT ' . $perPage . ' OFFSET ' . $offset);
$stmt->execute();

echo json_encode([
    'success' => true,
    'residents' => $stmt->fetchAll(),
    'page' => $page,
    'per_page' => $perPage,
    'total' => $total,
    'pages' => $pages,
]);
?>
