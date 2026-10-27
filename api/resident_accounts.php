<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
requireAdminAccess('residents.view');

$page = max(1, min(1000, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;
$total = (int) $pdo->query('SELECT COUNT(*) FROM resident')->fetchColumn();
$pages = (int) ceil($total / $perPage);
if ($pages > 0) $page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare('SELECT resident_id, first_name, middle_name, last_name, suffix, email, phone_number, address, date_of_birth, civil_status, gender,
        proof_path, email_verified_at, archived_at, created_at
    FROM resident
    ORDER BY (archived_at IS NOT NULL) ASC, created_at DESC, resident_id DESC
    LIMIT ' . $perPage . ' OFFSET ' . $offset);
$stmt->execute();
$residents = array_map(static function (array $resident): array {
    $proofPath = $resident['proof_path'] ?? null;
    $normalizedProofPath = is_string($proofPath) ? str_replace('\\', '/', $proofPath) : '';
    $safeProofPath = preg_match('#^(?:private/)?proofs/[a-f0-9]{32}\.(?:pdf|docx|jpg|jpeg|png)$#i', $normalizedProofPath)
        || preg_match('#^uploads/proofs/[^/\x00-\x1f\x7f]+\.(?:pdf|docx|jpg|jpeg|png)$#i', $normalizedProofPath);
    $proofFile = $safeProofPath ? resolveStoredUpload((string) $proofPath) : null;
    $proofType = strtolower(pathinfo($normalizedProofPath, PATHINFO_EXTENSION));
    if ($proofFile !== null && in_array($proofType, ['pdf', 'jpg', 'jpeg', 'png', 'docx'], true)) {
        $resident['proof_url'] = 'api/resident_proof.php?id=' . (int) $resident['resident_id'];
        $resident['proof_type'] = $proofType;
    } else {
        $resident['proof_url'] = null;
        $resident['proof_type'] = null;
    }
    unset($resident['proof_path']);
    return $resident;
}, $stmt->fetchAll());

echo json_encode([
    'success' => true,
    'residents' => $residents,
    'page' => $page,
    'per_page' => $perPage,
    'total' => $total,
    'pages' => $pages,
]);
?>
