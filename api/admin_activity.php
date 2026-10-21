<?php
require_once 'db.php';
header('Content-Type: application/json');

requireAdminAccess();

$page = max(1, min(100000, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;
$action = trim(is_string($_GET['action'] ?? null) ? $_GET['action'] : '');
$from = trim(is_string($_GET['from'] ?? null) ? $_GET['from'] : '');
$to = trim(is_string($_GET['to'] ?? null) ? $_GET['to'] : '');
$search = trim(is_string($_GET['search'] ?? null) ? $_GET['search'] : '');
if (strlen($action) > 80 || strlen($search) > 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid filter value']);
    exit;
}
$where = [];
$params = [];
if ($action !== '') { $where[] = 'a.action = ?'; $params[] = $action; }
if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $from, $date) && checkdate((int) $date[2], (int) $date[3], (int) $date[1])) { $where[] = 'a.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $to, $date) && checkdate((int) $date[2], (int) $date[3], (int) $date[1])) { $where[] = 'a.created_at < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $to; }
if ($search !== '') {
    $where[] = '(a.action LIKE ? OR a.details LIKE ? OR CONCAT_WS(\' \', u.first_name, u.last_name) LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
$sort = is_string($_GET['sort'] ?? null) ? $_GET['sort'] : 'date';
$direction = strtoupper(is_string($_GET['direction'] ?? null) ? $_GET['direction'] : 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$sortColumn = ['date' => 'a.created_at', 'admin' => 'admin_name', 'action' => 'a.action'][$sort] ?? 'a.created_at';
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$joins = "LEFT JOIN user u ON u.user_id = a.user_id
    LEFT JOIN resident r ON r.resident_id = CASE
        WHEN LOCATE('(ID ', a.details) > 0 THEN CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(a.details, '(ID ', -1), ';', 1) AS UNSIGNED)
        WHEN LOCATE('Resident ID ', a.details) > 0 THEN CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(a.details, 'Resident ID ', -1), ';', 1) AS UNSIGNED)
        ELSE NULL
    END";
$count = $pdo->prepare("SELECT COUNT(*) FROM audit_log a $joins $whereSql");
$count->execute($params);
$total = (int) $count->fetchColumn();
$list = $pdo->prepare("SELECT a.audit_id, a.action,
        CASE
            WHEN r.resident_id IS NOT NULL AND a.details LIKE 'Resident ID %'
                THEN CONCAT('Resident: ', CONCAT_WS(' ', r.first_name, r.last_name))
            WHEN a.details LIKE 'Resident % (ID %'
                THEN CONCAT('Resident: ', SUBSTRING_INDEX(SUBSTRING(a.details, 10), ' (ID ', 1))
            WHEN a.details LIKE 'Resident ID %'
                THEN 'Name not recorded'
            ELSE a.details
        END AS details,
        a.created_at, COALESCE(CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name), 'Former admin') AS admin_name,
        COALESCE(u.role, 'Admin') AS admin_role,
        CASE WHEN a.action = 'reject_resident' AND LOCATE('; reason: ', a.details) > 0
            THEN SUBSTRING_INDEX(a.details, '; reason: ', -1) ELSE NULL END AS reason,
        CASE WHEN a.action LIKE 'request_%' OR a.action = 'walk_in_request_created' THEN (SELECT request_id FROM document_request WHERE tracking_code = SUBSTRING_INDEX(SUBSTRING_INDEX(a.details, 'Tracking ', -1), ';', 1) LIMIT 1) ELSE NULL END AS request_id,
        CASE WHEN a.action = 'disclosure_edited' THEN a.details ELSE NULL END AS edit_details
    FROM audit_log a $joins $whereSql
    ORDER BY $sortColumn $direction, a.audit_id DESC LIMIT $perPage OFFSET $offset");
$list->execute($params);
$entries = $list->fetchAll();
foreach ($entries as &$entry) {
    if (preg_match('/^Tracking ([^;]+); resident (.*?); document (.+)$/', (string) $entry['details'], $matches)) {
        $entry['details'] = "Tracking number: {$matches[1]} · Resident: {$matches[2]} · Document: {$matches[3]}";
    }
}
unset($entry);
echo json_encode(['success' => true, 'entries' => $entries, 'page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => (int) ceil($total / $perPage)]);
?>
