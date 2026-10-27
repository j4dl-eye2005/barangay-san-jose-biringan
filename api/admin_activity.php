<?php
require_once 'db.php';
header('Content-Type: application/json');

requireAdminAccess('staff.manage');

$page = max(1, min(1000, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
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
    $tokens = preg_split('/[^\\p{L}\\p{N}]+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $tokens = array_values(array_filter($tokens, static function (string $token): bool {
        $length = function_exists('mb_strlen') ? mb_strlen($token, 'UTF-8') : strlen($token);
        return $length >= 4;
    }));
    if ($tokens) {
        $booleanSearch = implode(' ', array_map(static fn(string $token): string => '+' . $token . '*', $tokens));
        $where[] = '(MATCH(a.action, a.details) AGAINST (? IN BOOLEAN MODE)
            OR MATCH(u.first_name, u.last_name) AGAINST (? IN BOOLEAN MODE)
            OR CONCAT_WS(\' \', r.first_name, r.last_name) LIKE ?)';
        array_push($params, $booleanSearch, $booleanSearch, '%' . $search . '%');
    } else {
        $where[] = '(a.action LIKE ? OR a.details LIKE ? OR CONCAT_WS(\' \', u.first_name, u.last_name) LIKE ? OR CONCAT_WS(\' \', r.first_name, r.last_name) LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
}
$sort = is_string($_GET['sort'] ?? null) ? $_GET['sort'] : 'date';
$direction = strtoupper(is_string($_GET['direction'] ?? null) ? $_GET['direction'] : 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$sortColumn = ['date' => 'a.created_at', 'admin' => 'admin_name', 'action' => 'a.action'][$sort] ?? 'a.created_at';
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$userJoin = 'LEFT JOIN user u ON u.user_id = a.user_id';
$joins = "$userJoin LEFT JOIN resident r ON r.resident_id = a.subject_resident_id";
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
        a.created_at,
        CASE
            WHEN a.action IN ('resident_login_success', 'resident_email_verified', 'password_reset_completed') AND r.resident_id IS NOT NULL
                THEN CONCAT_WS(' ', r.first_name, r.last_name)
            WHEN a.action = 'failed_resident_login' THEN 'Unknown resident'
            WHEN a.action = 'failed_admin_login' AND a.user_id IS NULL THEN 'Unknown administrator'
            WHEN a.user_id IS NULL THEN 'System'
            WHEN u.user_id IS NULL THEN 'Former admin'
            ELSE CONCAT_WS(' ', u.first_name, u.middle_name, u.last_name)
        END AS admin_name,
        CASE
            WHEN a.action IN ('resident_login_success', 'resident_email_verified', 'password_reset_completed') AND r.resident_id IS NOT NULL THEN 'Resident'
            WHEN a.action = 'failed_resident_login' THEN 'Unknown account'
            WHEN a.action = 'failed_admin_login' AND a.user_id IS NULL THEN 'Unknown account'
            WHEN a.user_id IS NULL THEN 'System'
            ELSE COALESCE(u.role, 'Admin')
        END AS admin_role,
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
