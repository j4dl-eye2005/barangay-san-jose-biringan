<?php
require_once 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Administrator access required']);
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$action = trim((string) ($_GET['action'] ?? ''));
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$where = [];
$params = [];
if ($action !== '') { $where[] = 'a.action = ?'; $params[] = $action; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'a.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'a.created_at < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $to; }
if ($search !== '') {
    $where[] = '(a.action LIKE ? OR a.details LIKE ? OR CONCAT_WS(\' \', u.first_name, u.last_name) LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$count = $pdo->prepare("SELECT COUNT(*) FROM audit_log a LEFT JOIN user u ON u.user_id = a.user_id $whereSql");
$count->execute($params);
$total = (int) $count->fetchColumn();
$list = $pdo->prepare("SELECT a.audit_id, a.action, a.details, a.created_at,
        COALESCE(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name), 'Former admin') AS admin_name
    FROM audit_log a LEFT JOIN user u ON u.user_id = a.user_id $whereSql
    ORDER BY a.created_at DESC, a.audit_id DESC LIMIT $perPage OFFSET $offset");
$list->execute($params);
echo json_encode(['success' => true, 'entries' => $list->fetchAll(), 'page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => (int) ceil($total / $perPage)]);
?>
