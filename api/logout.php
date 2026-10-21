<?php
require_once __DIR__ . '/security.php';
enforceRequestSafety();
startAppSession();
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}
$role = (string) ($_SESSION['role'] ?? '');
$accountId = $role === 'Admin' ? (int) ($_SESSION['user_id'] ?? 0)
    : ($role === 'Resident' ? (int) ($_SESSION['resident_id'] ?? 0) : 0);
if ($accountId > 0 && in_array($role, ['Admin', 'Resident'], true)) {
    try {
        $config = portalConfig();
        if ($config['db_host'] !== '' && $config['db_name'] !== '' && $config['db_user'] !== '') {
            $dsn = 'mysql:host=' . $config['db_host'] . ';port=' . $config['db_port'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4';
            $logoutPdo = new PDO($dsn, $config['db_user'], $config['db_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]);
            $removeSession = $logoutPdo->prepare('DELETE FROM account_session WHERE session_key = ?');
            $removeSession->execute([hash('sha256', session_id())]);
        }
    } catch (Throwable $e) {
        error_log('Logged-out session could not be removed from the device list.');
    }
}
$_SESSION = [];
session_regenerate_id(true);
$_SESSION = ['_created_at' => time(), '_last_activity' => time()];
$csrfToken = rotateCsrfToken();
header('X-CSRF-Token: ' . $csrfToken);
echo json_encode(['success' => true, 'csrfToken' => $csrfToken]);
?>
