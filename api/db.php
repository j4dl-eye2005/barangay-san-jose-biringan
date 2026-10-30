<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/config.php';
$config = portalConfig();
enforceRequestSafety();
if (empty($GLOBALS['skipAppSessionForPublicRead'])) startAppSession();

$requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$localHost = preg_replace('/:\d+$/', '', $requestHost);
$isLocal = in_array($localHost, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
$hasConfiguredDatabase = $config['db_host'] !== '' && $config['db_name'] !== '' && $config['db_user'] !== '';
if ($isLocal && !$hasConfiguredDatabase) {
    // Keep the XAMPP defaults for a plain local checkout with no DB settings.
    $host = '127.0.0.1';
    $port = '3306';
    $db = 'barangay_portal';
    $user = 'root';
    $pass = '';
} else {
    $host = $config['db_host'];
    $port = $config['db_port'];
    $db = $config['db_name'];
    $user = $config['db_user'];
    $pass = $config['db_password'];
}
$charset = 'utf8mb4';

if ($host === '' || $db === '' || $user === '' || ($pass === '' && !$isLocal)) {
    error_log('Database configuration is incomplete. Set DB_HOST, DB_NAME, DB_USER, and DB_PASS.');
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'The service is temporarily unavailable.']);
    exit;
}

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_TIMEOUT            => 5,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    error_log('Database connection failed. SQLSTATE: ' . (string) $e->getCode());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'The service is temporarily unavailable.']);
    exit;
}

if (!rateLimit($pdo, 'api-global', 300, 60)) {
    http_response_code(429);
    header('Content-Type: application/json; charset=utf-8');
    header('Retry-After: 60');
    echo json_encode(['success' => false, 'message' => 'Too many requests. Try again shortly.']);
    exit;
}
