<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function appIsHttps(): bool
{
    return isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off' && $_SERVER['HTTPS'] !== '';
}

/** Return true only when the request came directly from an approved admin IP. */
function isAllowedAdminIp(): bool
{
    static $allowedIps = null;
    if ($allowedIps === null) {
        $allowlistFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private_storage' . DIRECTORY_SEPARATOR . 'admin_ip_allowlist.php';
        $configuredIps = is_file($allowlistFile) && is_readable($allowlistFile) ? require $allowlistFile : [];
        $allowedIps = is_array($configuredIps)
            ? array_values(array_filter(array_map(static fn($ip): string => trim((string) $ip), $configuredIps), static fn(string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false))
            : [];
    }
    $remoteIp = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return $remoteIp !== '' && in_array($remoteIp, $allowedIps, true);
}

function unavailablePageMarkup(string $titleId = 'unavailable-title'): string
{
    $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $scriptDirectory = rtrim(dirname($scriptPath), '/.');
    $homePath = ($scriptDirectory !== '' ? $scriptDirectory : '') . '/index.php';
    $logoPath = ($scriptDirectory !== '' ? $scriptDirectory : '') . '/Barangay San Jose, Biringan City.png';
    $originalPath = (string) ($_SERVER['REDIRECT_URL'] ?? $_SERVER['REQUEST_URI'] ?? '/');
    $originalQuery = (string) ($_SERVER['REDIRECT_QUERY_STRING'] ?? '');
    $attemptedAddress = trim($originalPath . ($originalQuery !== '' && !str_contains($originalPath, '?') ? '?' . $originalQuery : ''));
    $attemptedAddress = preg_replace('/[\x00-\x1F\x7F]/u', '', $attemptedAddress) ?? '/';
    $attemptedAddress = preg_replace('/([?&](?:token|secret|password|key)=)[^&#]*/i', '$1[redacted]', $attemptedAddress) ?? $attemptedAddress;
    $attemptedAddress = substr($attemptedAddress, 0, 500);
    $publicHome = 'https://barangaysanjose.site/index.php#landing';
    $escapedHome = htmlspecialchars($publicHome, ENT_QUOTES, 'UTF-8');
    $escapedAddress = htmlspecialchars($attemptedAddress, ENT_QUOTES, 'UTF-8');
    $reportHref = 'mailto:deleonjuliusakeem@gmail.com?subject=Report%20a%20broken%20link&body=' . rawurlencode('Broken link: ' . $attemptedAddress);
    return '<div class="unavailable-page-content">'
        . '<a class="unavailable-brand" href="' . $escapedHome . '"><img src="' . htmlspecialchars($logoPath, ENT_QUOTES, 'UTF-8') . '" alt="" width="40" height="40"><span class="unavailable-brand-text"><strong>Barangay San Jose</strong><span>Digital services and public records</span></span></a>'
        . '<section class="unavailable-card" aria-labelledby="' . htmlspecialchars($titleId, ENT_QUOTES, 'UTF-8') . '">'
        . '<div class="unavailable-icon-wrap"><svg class="unavailable-icon" viewBox="0 0 48 48" aria-hidden="true" focusable="false"><circle cx="24" cy="24" r="21" fill="none" stroke="currentColor" stroke-width="2.5"/><path d="M24 13v13m0 8h.02" fill="none" stroke="currentColor" stroke-linecap="round" stroke-width="3.5"/></svg></div>'
        . '<p class="unavailable-code">Error 404</p><h1 id="' . htmlspecialchars($titleId, ENT_QUOTES, 'UTF-8') . '">This page isn’t available.</h1>'
        . '<p class="unavailable-description">The page you&#39;re looking for may have been moved or removed.</p>'
        . '<p class="unavailable-address"><span>Address you tried:</span> <code>' . $escapedAddress . '</code></p>'
        . '<div class="unavailable-actions"><a class="unavailable-home" href="' . $escapedHome . '">Return to home</a>'
        . '<a class="unavailable-back" href="' . $escapedHome . '" onclick="if (history.length > 1) { event.preventDefault(); history.back(); }">Go back</a></div>'
        . '<nav class="unavailable-quick-links" aria-label="Quick links"><span>Quick links</span><a href="' . htmlspecialchars($homePath, ENT_QUOTES, 'UTF-8') . '#bulletin">Disclosures</a><a href="' . htmlspecialchars($homePath, ENT_QUOTES, 'UTF-8') . '#login">Request a document</a><a href="' . htmlspecialchars($homePath, ENT_QUOTES, 'UTF-8') . '#login">Login</a></nav>'
        . '<div class="unavailable-card-footer"><p>Still stuck? <a href="mailto:deleonjuliusakeem@gmail.com">Contact the barangay office.</a></p><a class="unavailable-report" href="' . htmlspecialchars($reportHref, ENT_QUOTES, 'UTF-8') . '">Report a broken link</a></div>'
        . '</section></div>';
}

function unavailablePageStyles(): string
{
    return 'html,body{min-height:100%;margin:0;border:0}html,body{scrollbar-width:none;-ms-overflow-style:none}html::-webkit-scrollbar,body::-webkit-scrollbar{display:none;width:0;height:0}body{min-height:100vh;padding:12px;box-sizing:border-box;background:#f4f7f2;color:#20231f;font:16px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}main.unavailable-shell{width:min(100%,760px);margin:0 auto}.unavailable-page-content{display:grid;gap:14px;width:100%;margin:0 auto}'
        . '.unavailable-brand{display:inline-flex;min-height:44px;align-items:center;gap:10px;width:max-content;color:#20231f;text-decoration:none}.unavailable-brand img{display:block;width:40px;height:40px;object-fit:cover;border-radius:50%}.unavailable-brand-text{display:flex;flex-direction:column;line-height:1.25}.unavailable-brand-text strong{font-size:16px;font-weight:800}.unavailable-brand-text span{color:#747a71;font-size:13px;font-weight:400}'
        . '.unavailable-card{box-sizing:border-box;width:min(100%,760px);padding:clamp(22px,4vw,34px);border:1px solid #dce4d8;border-radius:20px;background:#fff;box-shadow:0 10px 28px #163d170e}'
        . '.unavailable-icon-wrap{display:grid;width:52px;height:52px;place-items:center;margin:0 0 14px;border-radius:50%;background:#edf4e9;color:#12600b}.unavailable-icon{display:block;width:30px;height:30px}'
        . '.unavailable-code{margin:0 0 10px;color:#12600b;font-size:13px;font-weight:800;letter-spacing:.13em;text-transform:uppercase}'
        . '.unavailable-card h1{max-width:22ch;margin:0 0 12px;font-size:clamp(30px,5.6vw,44px);line-height:1.08;letter-spacing:-.018em;text-wrap:balance}'
        . '.unavailable-description{margin:0 0 8px;color:#444b42;font-size:17px}.unavailable-address{display:block;margin:0 0 22px;color:#62685f;font-size:13px}.unavailable-address code{overflow-wrap:anywhere;color:#62685f;font:inherit}'
        . '.unavailable-actions{display:flex;flex-wrap:wrap;align-items:center;gap:12px}.unavailable-home,.unavailable-back,.unavailable-quick-links a,.unavailable-card-footer a{display:inline-flex;align-items:center;justify-content:center;min-height:44px}'
        . '.unavailable-home,.unavailable-back{padding:0 20px;border:1px solid #086000;border-radius:10px;font:inherit;font-weight:700;text-decoration:none;cursor:pointer;transition:background-color .18s ease,color .18s ease,box-shadow .18s ease}'
        . '.unavailable-home{background:#086000;color:#fff}.unavailable-home:hover{background:#064b00;color:#fff}.unavailable-back{background:#fff;color:#086000}.unavailable-back:hover{background:#edf4e9;color:#064b00}'
        . '.unavailable-quick-links{display:flex;flex-wrap:wrap;align-items:center;gap:0 16px;margin-top:24px;padding-top:8px;border-top:1px solid #edf0eb}.unavailable-quick-links span{width:100%;color:#5d625a;font-size:13px;font-weight:700}.unavailable-quick-links a,.unavailable-card-footer a{color:#086000;font-size:14px;font-weight:500;text-decoration:none;text-underline-offset:3px}.unavailable-brand:hover,.unavailable-quick-links a:hover,.unavailable-card-footer a:hover{color:#064b00}.unavailable-quick-links a:hover,.unavailable-card-footer a:hover{text-decoration:underline}.unavailable-quick-links a:hover{padding:0 6px;margin-right:-12px;margin-left:-6px;border-radius:6px;background:#edf4e9}'
        . '.unavailable-card-footer{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:4px 16px;margin-top:6px;padding-top:6px;border-top:1px solid #edf0eb}.unavailable-card-footer p{margin:0;color:#5d625a;font-size:13px}.unavailable-card-footer a{min-height:44px}.unavailable-card-footer .unavailable-report{margin-left:auto;font-size:13px}'
        . '.unavailable-home:focus-visible,.unavailable-back:focus-visible,.unavailable-quick-links a:focus-visible,.unavailable-brand:focus-visible,.unavailable-card-footer a:focus-visible{outline:3px solid #12600b;outline-offset:3px;border-radius:4px}'
        . '@media(max-width:600px){body{padding:24px 16px 16px}.unavailable-page-content{gap:10px}.unavailable-card{padding:20px;border-radius:16px}.unavailable-code{font-weight:800;letter-spacing:.13em}.unavailable-address,.unavailable-address code{color:#62685f;font-size:12px;overflow-wrap:anywhere}.unavailable-actions{display:grid;grid-template-columns:1fr}.unavailable-home,.unavailable-back{width:100%;box-sizing:border-box}.unavailable-quick-links{gap:12px}.unavailable-card-footer{align-items:flex-start;gap:12px}.unavailable-card-footer .unavailable-report{margin-left:0}}';
}

function renderUnavailablePage(int $statusCode = 404): void
{
    http_response_code($statusCode);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Page unavailable</title><style>'
        . unavailablePageStyles()
        . '</style></head><body><main class="unavailable-shell">' . unavailablePageMarkup() . '</main></body></html>';
    exit;
}

/** Use one generic response for blocked admin access and unavailable URLs. */
function denyAdminIpAccess(?bool $asJson = null): void
{
    if ($asJson === null) {
        $asJson = str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/api/');
    }
    if (!$asJson) renderUnavailablePage(404);

    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode(['success' => false, 'pageUnavailable' => true, 'message' => 'This page isn’t available. Check the address or return to the home page.']);
    exit;
}

/** Enforce the IP rule and then the normal authenticated-admin requirement. */
function requireAdminAccess(): void
{
    if (!isAllowedAdminIp()) denyAdminIpAccess(true);
    if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'Admin') return;

    if (function_exists('logPermissionFailure')) logPermissionFailure();
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Administrator access required']);
    exit;
}

function sendAppSecurityHeaders(): void
{
    if (headers_sent()) return;
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com data:; img-src 'self' data: blob: https:; connect-src 'self' https://www.google.com/recaptcha/; frame-src 'self' https://www.google.com/recaptcha/ https://recaptcha.google.com/recaptcha/; upgrade-insecure-requests");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (appIsHttps()) header('Strict-Transport-Security: max-age=31536000');
}

function startAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    $secureCookie = appIsHttps() || strtolower((string) (getenv('APP_ENV') ?: '')) === 'production';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // Admin sessions are usable only from the allowlisted public IP. Clear an
    // existing session that arrives from any other address before the request
    // reaches shared pages or admin-protected API endpoints.
    if (($_SESSION['role'] ?? '') === 'Admin' && !isAllowedAdminIp()) {
        $GLOBALS['adminIpDeniedThisRequest'] = true;
        $_SESSION = [];
        session_regenerate_id(true);
    }

    $now = time();
    $idleTimeout = 30 * 60;
    $absoluteTimeout = 8 * 60 * 60;
    $last = (int) ($_SESSION['_last_activity'] ?? $now);
    $created = (int) ($_SESSION['_created_at'] ?? $now);
    if (($now - $last) > $idleTimeout || ($now - $created) > $absoluteTimeout) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => $now - 42000,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool) $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
        session_id('');
        session_start();
        session_regenerate_id(true);
        $created = $now;
    }
    $_SESSION['_created_at'] = $created;
    $_SESSION['_last_activity'] = $now;
}

function enforceRequestSafety(): void
{
    sendAppSecurityHeaders();
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    static $handlerInstalled = false;
    if (!$handlerInstalled) {
        set_exception_handler(static function (Throwable $error): void {
            error_log('Unhandled application error [' . get_class($error) . ']');
            if (headers_sent()) return;
            http_response_code(500);
            $path = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
            if (str_contains($path, '/api/')) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'The request could not be completed.']);
            } else {
                header('Content-Type: text/plain; charset=utf-8');
                echo 'The request could not be completed.';
            }
        });
        $handlerInstalled = true;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $limit = str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') ? 6 * 1024 * 1024 : 1024 * 1024;
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > $limit) {
        http_response_code(413);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Request is too large.']);
        exit;
    }
    if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        $fetchSite = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
        if ($fetchSite === 'cross-site' || !isSameOriginRequest()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Request could not be verified. Reload the page and try again.']);
            exit;
        }
    }
}

function isSameOriginRequest(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
        $originPort = parse_url($origin, PHP_URL_PORT);
        $hostOnly = preg_replace('/:\d+$/', '', $host);
        $hostPort = preg_match('/:\d+$/', $host) ? (int) substr($host, strrpos($host, ':') + 1) : null;
        return $originHost !== '' && $originHost === $hostOnly && ($originPort === null || $hostPort === null || (int) $originPort === $hostPort);
    }
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($referer === '') return false;
    return strtolower((string) parse_url($referer, PHP_URL_HOST)) === preg_replace('/:\d+$/', '', $host);
}

function rateLimit(PDO $pdo, string $scope, int $maxAttempts, int $windowSeconds, ?string $identifier = null): bool
{
    static $tableReady = false;
    try {
        if (!$tableReady) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS app_rate_limit (
                bucket_hash CHAR(64) NOT NULL PRIMARY KEY,
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                window_started_at DATETIME NOT NULL,
                INDEX idx_rate_window (window_started_at)
            ) ENGINE=InnoDB");
            $tableReady = true;
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $window = max(1, min(86400, $windowSeconds));
        $sql = "INSERT INTO app_rate_limit (bucket_hash, attempts, window_started_at) VALUES (?, 1, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE
              attempts = IF(window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL $window SECOND), 1, attempts + 1),
              window_started_at = IF(window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL $window SECOND), UTC_TIMESTAMP(), window_started_at)";
        $identities = [$scope . '|ip|' . $ip];
        if (isset($_SESSION['user_id'])) $identities[] = $scope . '|admin|' . (int) $_SESSION['user_id'];
        elseif (isset($_SESSION['resident_id'])) $identities[] = $scope . '|resident|' . (int) $_SESSION['resident_id'];
        if ($identifier !== null && $identifier !== '') $identities[] = $scope . '|target|' . $ip . '|' . hash('sha256', strtolower($identifier));
        $stmt = $pdo->prepare($sql);
        $read = $pdo->prepare('SELECT attempts FROM app_rate_limit WHERE bucket_hash = ?');
        foreach ($identities as $identity) {
            $bucket = hash('sha256', $identity);
            $stmt->execute([$bucket]);
            $read->execute([$bucket]);
            if ((int) $read->fetchColumn() > $maxAttempts) return false;
        }
        if (random_int(1, 100) === 1) $pdo->exec('DELETE FROM app_rate_limit WHERE window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)');
        return true;
    } catch (Throwable $e) {
        error_log('Rate limit check failed: ' . $e->getMessage());
        return false;
    }
}

function passwordMeetsPolicy(string $password): bool
{
    return strlen($password) >= 12
        && strlen($password) <= 256
        && preg_match('/[a-z]/', $password)
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[0-9]/', $password);
}

function isAllowedPortalUrl(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($scheme === 'https') return true;
    return $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function validateUpload(array $file, array $types, int $maxBytes): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) return null;
    if ((int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > $maxBytes) return null;
    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!isset($types[$extension])) return null;
    $tmp = (string) $file['tmp_name'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
    $valid = match ($extension) {
        'jpg', 'jpeg' => $mime === 'image/jpeg' && @getimagesize($tmp) !== false,
        'png' => $mime === 'image/png' && @getimagesize($tmp) !== false,
        'pdf' => $mime === 'application/pdf' && str_starts_with((string) file_get_contents($tmp, false, null, 0, 5), '%PDF-'),
        'docx' => validateDocxPackage($tmp, $mime),
        default => false,
    };
    return $valid ? $extension : null;
}

function validateDocxPackage(string $path, string $mime): bool
{
    if (!in_array($mime, ['application/zip', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/octet-stream'], true)
        || !str_starts_with((string) file_get_contents($path, false, null, 0, 2), 'PK')) return false;
    if (!class_exists(ZipArchive::class)) {
        // ZIP central directories contain the package member names; this fallback
        // still requires both Office document entries when PHP's ZIP extension is absent.
        $package = file_get_contents($path);
        return is_string($package)
            && strpos($package, '[Content_Types].xml') !== false
            && strpos($package, 'word/document.xml') !== false;
    }
    $archive = new ZipArchive();
    if ($archive->open($path) !== true) return false;
    $valid = $archive->locateName('[Content_Types].xml') !== false && $archive->locateName('word/document.xml') !== false;
    $archive->close();
    return $valid;
}

function privateStorageDirectory(string $kind): string
{
    if (!in_array($kind, ['proofs', 'disclosures'], true)) throw new InvalidArgumentException('Invalid private storage type.');
    $base = portalConfig()['private_storage'];
    $directory = $base . DIRECTORY_SEPARATOR . $kind;
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Private upload storage is unavailable.');
    }
    $realDirectory = realpath($directory);
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $appPrivateRoot = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'private_storage');
    $isProtectedAppStorage = $realDirectory !== false
        && $appPrivateRoot !== false
        && strcasecmp($realDirectory, $appPrivateRoot . DIRECTORY_SEPARATOR . $kind) === 0
        && is_file($appPrivateRoot . DIRECTORY_SEPARATOR . '.htaccess');
    $isInsideDocumentRoot = $realDirectory !== false && $documentRoot !== false
        && ($realDirectory === $documentRoot || str_starts_with(strtolower($realDirectory), strtolower($documentRoot . DIRECTORY_SEPARATOR)));
    if ($realDirectory === false || ($isInsideDocumentRoot && !$isProtectedAppStorage)) {
        throw new RuntimeException('Private upload storage is not configured in a protected location.');
    }
    return $directory;
}

function logPermissionFailure(): void
{
    $route = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'unknown'));
    $ipHash = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    error_log("Permission denied on $route from IP hash $ipHash");
}

function resolveStoredUpload(string $storedPath): ?string
{
    $normalized = str_replace('\\', '/', $storedPath);
    if (preg_match('#^uploads/(proofs|disclosures)/([^/]+)$#i', $normalized, $legacy)
        && preg_match('/\.(?:pdf|docx|jpg|jpeg|png)$/i', $legacy[2])
        && !preg_match('/[\x00-\x1f\x7f]/', $legacy[2])
        && $legacy[2] !== '.' && $legacy[2] !== '..') {
        $directory = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $legacy[1]);
        $candidate = $directory === false ? false : realpath($directory . DIRECTORY_SEPARATOR . $legacy[2]);
        if ($candidate !== false && is_file($candidate)
            && str_starts_with(strtolower($candidate), strtolower(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
            return $candidate;
        }
        return null;
    }

    if (!preg_match('#^(?:private/)?(?:proofs|disclosures)/[a-f0-9]{32}\.(?:pdf|docx|jpg|jpeg|png)$#i', $normalized)) return null;
    $relative = str_starts_with($normalized, 'private/') ? substr($normalized, 8) : $normalized;
    $candidate = portalConfig()['private_storage'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    return is_file($candidate) ? $candidate : null;
}

function jsonBody(int $maxBytes = 1024 * 1024): array
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > $maxBytes) {
        http_response_code(413);
        echo json_encode(['success' => false, 'message' => 'Request is too large.']);
        exit;
    }
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || strlen($raw) > $maxBytes) {
        http_response_code(413);
        echo json_encode(['success' => false, 'message' => 'Request is too large.']);
        exit;
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid request data.']);
        exit;
    }
    return $data;
}
