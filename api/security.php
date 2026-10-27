<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function appIsHttps(): bool
{
    return isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off' && $_SERVER['HTTPS'] !== '';
}

/** Admin route access is granted by submitting the configured key through the private entry form. */
function hasAdminEntryAuthorization(): bool
{
    $authorizedAt = (int) ($_SESSION['admin_entry_authorized_at'] ?? 0);
    if (empty($_SESSION['admin_entry_authorized']) || $authorizedAt < 1 || (time() - $authorizedAt) > 12 * 60 * 60) {
        unset($_SESSION['admin_entry_authorized'], $_SESSION['admin_entry_authorized_at']);
        return false;
    }
    return true;
}

function rotateCsrfToken(): string
{
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf_token'];
}

function csrfToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) startAppSession();
    $token = $_SESSION['_csrf_token'] ?? null;
    return is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token) ? $token : rotateCsrfToken();
}

function csrfTokenIsValid(): bool
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    return is_string($provided) && $provided !== '' && hash_equals(csrfToken(), $provided);
}

/** Validate the unguessable entry key and establish a session gate. */
function authorizeAdminEntry(string $providedKey): bool
{
    $expectedKey = portalConfig()['admin_entry_key'];
    if (strlen($expectedKey) < 32 || strlen($expectedKey) > 128 || strlen($providedKey) !== strlen($expectedKey)) return false;
    if (!hash_equals($expectedKey, $providedKey)) return false;
    session_regenerate_id(true);
    $_SESSION['admin_entry_authorized'] = true;
    $_SESSION['admin_entry_authorized_at'] = time();
    rotateCsrfToken();
    return true;
}

/** Clear an authenticated session if the account's password changed elsewhere. */
function enforceAuthenticatedSessionVersion(PDO $pdo): bool
{
    $role = (string) ($_SESSION['role'] ?? '');
    $id = $role === 'Admin' ? (int) ($_SESSION['user_id'] ?? 0)
        : ($role === 'Resident' ? (int) ($_SESSION['resident_id'] ?? 0) : 0);
    if ($id < 1) return true;

    try {
        $table = $role === 'Admin' ? 'user' : 'resident';
        $idColumn = $role === 'Admin' ? 'user_id' : 'resident_id';
        $stmt = $pdo->prepare("SELECT auth_version FROM $table WHERE $idColumn = ? LIMIT 1");
        $stmt->execute([$id]);
        $currentVersion = $stmt->fetchColumn();
        if ($currentVersion !== false && isset($_SESSION['_auth_version'])
            && (int) $_SESSION['_auth_version'] === (int) $currentVersion) return true;
        if ($currentVersion === false) error_log('Authenticated session references a missing account.');
        elseif (!isset($_SESSION['_auth_version'])) error_log('Authenticated session has no account version and was revoked.');
        else error_log('Authenticated session was revoked after an account version change.');
        $entryAuthorized = !empty($_SESSION['admin_entry_authorized']);
        $entryAuthorizedAt = (int) ($_SESSION['admin_entry_authorized_at'] ?? 0);
        $_SESSION = [];
        if ($role === 'Admin' && $entryAuthorized && $entryAuthorizedAt > 0 && time() - $entryAuthorizedAt <= 12 * 60 * 60) {
            $_SESSION['admin_entry_authorized'] = true;
            $_SESSION['admin_entry_authorized_at'] = $entryAuthorizedAt;
        }
        session_regenerate_id(true);
        rotateCsrfToken();
        return true;
    } catch (Throwable $e) {
        error_log('Could not validate authenticated session version.');
        return false;
    }
}

function adminMfaEncryptionKey(): string
{
    $key = portalConfig()['admin_mfa_encryption_key'];
    if (!preg_match('/^[a-f0-9]{64}$/i', $key)) {
        throw new RuntimeException('Admin MFA encryption key is not configured.');
    }
    return hex2bin($key);
}

function generateAdminMfaSecret(): string
{
    $bytes = random_bytes(20);
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buffer = 0;
    $bits = 0;
    $encoded = '';
    for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
        $buffer = ($buffer << 8) | ord($bytes[$i]);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $encoded .= $alphabet[($buffer >> $bits) & 31];
        }
        $buffer &= (1 << $bits) - 1;
    }
    if ($bits > 0) $encoded .= $alphabet[($buffer << (5 - $bits)) & 31];
    return $encoded;
}

function adminMfaSecretBytes(string $secret): string|false
{
    $secret = strtoupper(preg_replace('/[\s=-]+/', '', $secret) ?? '');
    if ($secret === '' || preg_match('/[^A-Z2-7]/', $secret)) return false;
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buffer = 0;
    $bits = 0;
    $decoded = '';
    for ($i = 0, $length = strlen($secret); $i < $length; $i++) {
        $value = strpos($alphabet, $secret[$i]);
        if ($value === false) return false;
        $buffer = ($buffer << 5) | $value;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $decoded .= chr(($buffer >> $bits) & 255);
        }
        $buffer &= (1 << $bits) - 1;
    }
    return $decoded;
}

function encryptAdminMfaSecret(string $secret): string
{
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', adminMfaEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($ciphertext) || strlen($tag) !== 16) throw new RuntimeException('Could not protect admin MFA secret.');
    return base64_encode($iv . $tag . $ciphertext);
}

function decryptAdminMfaSecret(string $encoded): string
{
    $raw = base64_decode($encoded, true);
    if (!is_string($raw) || strlen($raw) < 29) throw new RuntimeException('Stored admin MFA secret is invalid.');
    $secret = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', adminMfaEncryptionKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if (!is_string($secret)) throw new RuntimeException('Could not read stored admin MFA secret.');
    return $secret;
}

/** Return the accepted 30-second counter and reject previously used codes. */
function verifyAdminTotp(string $secret, string $code, ?int $lastCounter = null): ?int
{
    if (!preg_match('/^\d{6}$/', $code)) return null;
    $key = adminMfaSecretBytes($secret);
    if (!is_string($key)) return null;
    $currentCounter = intdiv(time(), 30);
    for ($counter = $currentCounter - 1; $counter <= $currentCounter + 1; $counter++) {
        if ($counter < 0 || ($lastCounter !== null && $counter <= $lastCounter)) continue;
        $binaryCounter = pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        $expected = str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
        if (hash_equals($expected, $code)) return $counter;
    }
    return null;
}

function portalRequestBasePath(): string
{
    $scriptPath = '';
    $scriptFilename = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($scriptFilename !== false && $documentRoot !== false) {
        $normalizedScript = str_replace('\\', '/', $scriptFilename);
        $normalizedRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');
        if (strncasecmp($normalizedScript, $normalizedRoot . '/', strlen($normalizedRoot) + 1) === 0) {
            $scriptPath = '/' . ltrim(substr($normalizedScript, strlen($normalizedRoot)), '/');
        }
    }
    if ($scriptPath === '') {
        $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    }
    $scriptDirectory = dirname($scriptPath);
    return $scriptDirectory === '/' || $scriptDirectory === '.' ? '' : rtrim($scriptDirectory, '/');
}

function unavailablePageMarkup(string $titleId = 'unavailable-title'): string
{
    $appBasePath = portalRequestBasePath();
    $homePath = $appBasePath . '/index.php';
    $originalPath = (string) ($_SERVER['REDIRECT_URL'] ?? $_SERVER['REQUEST_URI'] ?? '/');
    $originalQuery = (string) ($_SERVER['REDIRECT_QUERY_STRING'] ?? '');
    $attemptedAddress = trim($originalPath . ($originalQuery !== '' && !str_contains($originalPath, '?') ? '?' . $originalQuery : ''));
    $attemptedAddress = preg_replace('/[\x00-\x1F\x7F]/u', '', $attemptedAddress) ?? '/';
    $attemptedAddress = preg_replace('/([?&](?:token|secret|password|key)=)[^&#]*/i', '$1[redacted]', $attemptedAddress) ?? $attemptedAddress;
    $attemptedAddress = substr($attemptedAddress, 0, 500);
    $attemptedParts = parse_url($attemptedAddress);
    $attemptedPath = is_array($attemptedParts) && isset($attemptedParts['path']) ? (string) $attemptedParts['path'] : '/';
    if ($attemptedPath === '') $attemptedPath = '/';
    if (is_array($attemptedParts) && isset($attemptedParts['query'])) $attemptedPath .= '?' . $attemptedParts['query'];
    if (is_array($attemptedParts) && isset($attemptedParts['fragment'])) $attemptedPath .= '#' . $attemptedParts['fragment'];
    $publicHome = $homePath . '#landing';
    $escapedHome = htmlspecialchars($publicHome, ENT_QUOTES, 'UTF-8');
    $escapedAddress = htmlspecialchars($attemptedAddress, ENT_QUOTES, 'UTF-8');
    $escapedMobileAddress = htmlspecialchars($attemptedPath, ENT_QUOTES, 'UTF-8');
    $reportHref = 'mailto:deleonjuliusakeem@gmail.com?subject=Report%20a%20broken%20link&body=' . rawurlencode('Broken link: ' . $attemptedAddress);
    return '<div class="unavailable-page-content">'
        . '<main class="unavailable-main"><section class="unavailable-content" aria-labelledby="' . htmlspecialchars($titleId, ENT_QUOTES, 'UTF-8') . '">'
        . '<p class="unavailable-code" aria-hidden="true">404</p>'
        . '<h1 id="' . htmlspecialchars($titleId, ENT_QUOTES, 'UTF-8') . '">This page isn’t available.</h1>'
        . '<p class="unavailable-description">The page you&#39;re looking for may have been moved or removed.</p>'
        . '<p class="unavailable-address"><span>Address you tried:</span> <code class="unavailable-address-desktop">' . $escapedAddress . '</code><code class="unavailable-address-mobile">' . $escapedMobileAddress . '</code></p>'
        . '<div class="unavailable-actions"><a class="unavailable-home" href="' . $escapedHome . '">Return to home</a>'
        . '<a class="unavailable-back" href="' . $escapedHome . '">Go back</a></div>'
        . '<nav class="unavailable-quick-links" aria-label="Quick links">'
        . '<a href="' . htmlspecialchars($homePath, ENT_QUOTES, 'UTF-8') . '#bulletin">Disclosures</a><span aria-hidden="true">·</span>'
        . '<a href="' . htmlspecialchars($homePath, ENT_QUOTES, 'UTF-8') . '#login">Request a document</a><span aria-hidden="true">·</span>'
        . '<a href="' . htmlspecialchars($homePath, ENT_QUOTES, 'UTF-8') . '#login">Log in</a></nav>'
        . '<div class="unavailable-help"><p><span class="unavailable-help-desktop">Still stuck? <a href="mailto:deleonjuliusakeem@gmail.com">Contact the barangay office</a><span aria-hidden="true"> · </span><a class="unavailable-report" href="' . htmlspecialchars($reportHref, ENT_QUOTES, 'UTF-8') . '">Report a broken link</a></span><span class="unavailable-help-mobile"><span>Still stuck? <a href="mailto:deleonjuliusakeem@gmail.com">Contact the barangay office</a></span><span><a class="unavailable-report" href="' . htmlspecialchars($reportHref, ENT_QUOTES, 'UTF-8') . '">Report a broken link</a></span></span></p></div>'
        . '</section></main></div>';
}

function renderUnavailablePage(int $statusCode = 404): void
{
    http_response_code($statusCode);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    // Resolve assets from the actual app location, including local subfolders.
    $appBasePath = portalRequestBasePath();
    $assetPrefix = htmlspecialchars($appBasePath, ENT_QUOTES, 'UTF-8');
    $pageBase = htmlspecialchars($appBasePath . '/index.php', ENT_QUOTES, 'UTF-8');
    $adminInitialSection = null;
    $suppressSiteDisclaimer = true;
    ob_start();
    require dirname(__DIR__) . '/includes/shared/site-header.php';
    $siteHeader = ob_get_clean();
    ob_start();
    require dirname(__DIR__) . '/includes/shared/site-footer.php';
    $siteFooter = ob_get_clean();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#f1f6ec"><base href="' . $pageBase . '"><title>Page unavailable</title>'
        . '<link rel="icon" type="image/png" href="' . $assetPrefix . '/assets/favicon-optimized.png">'
        . '<link rel="stylesheet" href="' . $assetPrefix . '/css/base.css?v=20261023-universal-custom-scrollbar-v1">'
        . '<link rel="stylesheet" href="' . $assetPrefix . '/css/home.css?v=20261023-static-request-arrows-v1">'
        . '<link rel="stylesheet" href="' . $assetPrefix . '/css/components.css?v=20261015-performance-queue">'
        . '<link rel="stylesheet" href="' . $assetPrefix . '/css/public.css?v=20261016-sticky-menu-stable">'
        . '<link rel="stylesheet" href="' . $assetPrefix . '/css/shared-responsive.css?v=20261017-profile-security-columns">'
        . '<link rel="stylesheet" href="' . $assetPrefix . '/css/unavailable.css?v=20261023-mobile-404-wrap-v2">'
        . '<link rel="stylesheet" href="' . $assetPrefix . '/css/portal-public.css?v=20261021-performance-assets-paging-v1">'
        . '<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>'
        . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></head>'
        . '<body class="unavailable-view guest-user-view public-portal-view" data-admin-entry-authorized="0">' . $siteHeader
        . '<div class="unavailable-shell">' . unavailablePageMarkup() . '</div>' . $siteFooter
        . '<script src="' . $assetPrefix . '/script.js?v=20261022-login-null-guards-v1" defer></script></body></html>';
    exit;
}

/** Use one generic response for blocked admin access and unavailable URLs. */
function denyAdminEntryAccess(?bool $asJson = null): void
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

/** Enforce the secret entry gate and then the normal authenticated-admin requirement. */
function requireAdminAccess(?string $permission = null): void
{
    if (!hasAdminEntryAuthorization()) denyAdminEntryAccess(true);
    if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'Admin' && !empty($_SESSION['admin_mfa_verified_at'])) {
        if ($permission === null) return;
        $roleQuery = $GLOBALS['pdo']->prepare("SELECT u.staff_role, bs.is_active AS linked_staff_active
            FROM user u LEFT JOIN barangay_staff bs ON bs.portal_user_id = u.user_id
            WHERE u.user_id = ? AND u.role = ? LIMIT 1");
        $roleQuery->execute([(int) $_SESSION['user_id'], 'Admin']);
        $roleRecord = $roleQuery->fetch(PDO::FETCH_ASSOC) ?: [];
        if (array_key_exists('linked_staff_active', $roleRecord) && $roleRecord['linked_staff_active'] !== null && (int) $roleRecord['linked_staff_active'] !== 1) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Portal access has been disabled for this staff account.']);
            exit;
        }
        $staffRole = (string) ($roleRecord['staff_role'] ?? '');
        $rolePermissions = [
            'super_admin' => ['*'],
            'service_clerk' => ['requests.view', 'requests.manage', 'requests.create', 'residents.view', 'documents.preview'],
            'cashier' => ['payments.view', 'payments.manage', 'collections.view'],
            'document_issuer' => ['requests.view', 'documents.preview', 'documents.issue'],
        ];
        $allowed = $rolePermissions[$staffRole] ?? [];
        if (in_array('*', $allowed, true) || in_array($permission, $allowed, true)) return;
        if (function_exists('logPermissionFailure')) logPermissionFailure();
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'You do not have permission to use this function.']);
        exit;
    }

    if (function_exists('logPermissionFailure')) logPermissionFailure();
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Administrator access required']);
    exit;
}

/** Soft-archive stale, unverified accounts with no submitted requests. */
function archiveExpiredUnverifiedResidents(PDO $pdo): void
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT IGNORE INTO portal_maintenance (maintenance_key, last_run_at) VALUES ('archive_unverified_residents', '1970-01-01 00:00:00')")->execute();
        $check = $pdo->query("SELECT last_run_at FROM portal_maintenance WHERE maintenance_key = 'archive_unverified_residents' FOR UPDATE");
        $lastRun = strtotime((string) $check->fetchColumn()) ?: 0;
        if ($lastRun > time() - 86400) { $pdo->commit(); return; }
        $pdo->exec("UPDATE resident r
            SET r.archived_at = UTC_TIMESTAMP(), r.is_active = 0, r.auth_version = r.auth_version + 1
            WHERE r.archived_at IS NULL AND r.email_verified_at IS NULL
              AND r.created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
              AND NOT EXISTS (SELECT 1 FROM document_request dr WHERE dr.resident_id = r.resident_id)");
        $pdo->exec("DELETE evt FROM email_verification_token evt JOIN resident r ON r.resident_id = evt.resident_id WHERE r.archived_at IS NOT NULL");
        $pdo->exec("UPDATE portal_maintenance SET last_run_at = UTC_TIMESTAMP() WHERE maintenance_key = 'archive_unverified_residents'");
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function sendAppSecurityHeaders(): void
{
    if (headers_sent()) return;
    $contentSecurityPolicy = "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; font-src 'self' https://cdnjs.cloudflare.com data:; img-src 'self' data: blob: https:; connect-src 'self' https://www.google.com/recaptcha/; frame-src 'self' https://www.google.com/recaptcha/ https://recaptcha.google.com/recaptcha/";
    if (appIsHttps()) $contentSecurityPolicy .= '; upgrade-insecure-requests';
    header('Content-Security-Policy: ' . $contentSecurityPolicy);
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (appIsHttps()) header('Strict-Transport-Security: max-age=31536000');
}

function startAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $GLOBALS['sessionExpiredThisRequest'] = false;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $isLocalHost = (bool) preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(?::\d{1,5})?$/', $host);
    $secureCookie = appIsHttps() || (!$isLocalHost && strtolower((string) (getenv('APP_ENV') ?: '')) === 'production');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    // Admin sessions require a recent successful entry-key check and completed MFA.
    if (($_SESSION['role'] ?? '') === 'Admin' && (!hasAdminEntryAuthorization() || empty($_SESSION['admin_mfa_verified_at']))) {
        $GLOBALS['adminEntryDeniedThisRequest'] = true;
        $_SESSION = [];
        session_regenerate_id(true);
    }

    $now = time();
    $idleTimeout = 30 * 60;
    $absoluteTimeout = 8 * 60 * 60;
    $last = (int) ($_SESSION['_last_activity'] ?? $now);
    $created = (int) ($_SESSION['_created_at'] ?? $now);
    if (($now - $last) > $idleTimeout || ($now - $created) > $absoluteTimeout) {
        $GLOBALS['sessionExpiredThisRequest'] = true;
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
    if (empty($GLOBALS['skipAppSessionForPublicRead'])) startAppSession();
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
        $scriptPath = strtolower(str_replace('\\', '/', (string) parse_url((string) ($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH)));
        $isAdminEntryForm = $method === 'POST'
            && ($_POST['admin_entry_submit'] ?? null) === '1'
            && isset($_GET['admin_access_form'])
            && str_ends_with($scriptPath, '/index.php');
        if ($fetchSite === 'cross-site' || !isSameOriginRequest() || (!$isAdminEntryForm && !csrfTokenIsValid())) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Your request could not be verified. Reload the page and try again.']);
            exit;
        }
    }
}

function isSameOriginRequest(): bool
{
    $fetchSite = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($fetchSite === 'same-origin') return true;
    if ($fetchSite === 'cross-site') return false;

    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
        $originPort = parse_url($origin, PHP_URL_PORT);
        $hostOnly = preg_replace('/:\d+$/', '', $host);
        $hostPort = preg_match('/:\d+$/', $host) ? (int) substr($host, strrpos($host, ':') + 1) : null;
        return $originHost !== '' && $originHost === $hostOnly && ($originPort === null || $hostPort === null || (int) $originPort === $hostPort);
    }
    // Some browsers omit Referer when the page uses a no-referrer policy.
    // Fetch Metadata identifies same-origin form submissions; the session-bound
    // CSRF token remains mandatory in enforceRequestSafety().
    if (in_array($fetchSite, ['same-origin', 'same-site'], true)) return true;
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
    return strlen($password) >= 8
        && strlen($password) <= 256
        && preg_match('/[a-z]/', $password)
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[0-9]/', $password);
}

function isAllowedEmailProvider(string $email): bool
{
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
    static $domains = [
        'gmail.com', 'googlemail.com',
        'yahoo.com', 'yahoo.com.ph', 'yahoo.co.uk', 'yahoo.ca', 'yahoo.com.au', 'yahoo.co.nz', 'ymail.com', 'rocketmail.com',
        'outlook.com', 'outlook.ph', 'hotmail.com', 'hotmail.co.uk', 'hotmail.com.au', 'live.com', 'live.com.ph', 'msn.com',
        'icloud.com', 'me.com', 'mac.com',
        'proton.me', 'protonmail.com', 'protonmail.ch', 'pm.me'
    ];
    return in_array($domain, $domains, true);
}

function isValidPhilippineMobile(string $phone): bool
{
    if (!preg_match('/^(?:09[0-9]{9}|\\+639[0-9]{9})$/', $phone)) return false;
    $national = str_starts_with($phone, '+63') ? '0' . substr($phone, 3) : $phone;
    static $prefixes = [
        '0817', '0895', '0896', '0897', '0898',
        '0900', '0904', '0905', '0906', '0907', '0908', '0909', '0910', '0911', '0912', '0913', '0914', '0915',
        '0916', '0917', '0918', '0919', '0920', '0921', '0922', '0923', '0924', '0925', '0926', '0927', '0928',
        '0929', '0930', '0931', '0932', '0933', '0934', '0935', '0936', '0937', '0938', '0939', '0940', '0941',
        '0942', '0943', '0944', '0945', '0946', '0947', '0948', '0949', '0950', '0951', '0952', '0953', '0954',
        '0955', '0956', '0957', '0958', '0959', '0961', '0962', '0963', '0964', '0965', '0966', '0967', '0968',
        '0969', '0970', '0971', '0972', '0973', '0974', '0975', '0976', '0977', '0978', '0979', '0980', '0981',
        '0982', '0985', '0989', '0990', '0991', '0992', '0993', '0994', '0995', '0996', '0997', '0998', '0999'
    ];
    return in_array(substr($national, 0, 4), $prefixes, true);
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
    if (!in_array($kind, ['proofs', 'disclosures', 'profile-photos'], true)) throw new InvalidArgumentException('Invalid private storage type.');
    loadPortalEnvFile();
    $config = portalConfig();
    $base = $config['private_storage'];
    $configuredBase = trim((string) ($_ENV['APP_PRIVATE_STORAGE'] ?? getenv('APP_PRIVATE_STORAGE') ?: ''));
    $applicationRoot = dirname(__DIR__);
    $protectedBase = $applicationRoot . DIRECTORY_SEPARATOR . 'private_storage';
    $normalizePath = static fn(string $path): string => rtrim(str_replace('\\', '/', $path), '/');
    $samePath = static fn(string $left, string $right): bool => DIRECTORY_SEPARATOR === '\\'
        ? strcasecmp($normalizePath($left), $normalizePath($right)) === 0
        : $normalizePath($left) === $normalizePath($right);

    $resolve = static function (string $storageBase, bool $allowProtectedInRoot) use ($kind, $applicationRoot): string {
        $directory = rtrim($storageBase, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $kind;
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Private upload storage is unavailable.');
        }
        $realDirectory = realpath($directory);
        $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($realDirectory === false || $documentRoot === false) {
            throw new RuntimeException('Private upload storage is not available to PHP.');
        }
        $documentRootPrefix = rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $isInsideDocumentRoot = $realDirectory === $documentRoot
            || str_starts_with(strtolower($realDirectory), strtolower($documentRootPrefix));
        if ($isInsideDocumentRoot) {
            $realApplicationRoot = realpath($applicationRoot);
            $realProtectedBase = $realApplicationRoot === false ? false : realpath($realApplicationRoot . DIRECTORY_SEPARATOR . 'private_storage');
            $protectedRules = $realProtectedBase === false ? false : @file_get_contents($realProtectedBase . DIRECTORY_SEPARATOR . '.htaccess');
            $expectedProtectedDirectory = $realProtectedBase === false ? false : $realProtectedBase . DIRECTORY_SEPARATOR . $kind;
            $isProtectedApplicationStorage = $allowProtectedInRoot
                && $realProtectedBase !== false
                && $expectedProtectedDirectory !== false
                && $realDirectory === $expectedProtectedDirectory
                && is_string($protectedRules)
                && preg_match('/^\\s*Require\\s+all\\s+denied\\s*$/mi', $protectedRules) === 1;
            if (!$isProtectedApplicationStorage) {
                throw new RuntimeException('Private upload storage is not configured in a protected location.');
            }
        }
        if (!is_writable($realDirectory)) throw new RuntimeException('Private upload storage is not writable by PHP.');
        return $realDirectory;
    };

    $isProtectedBase = $samePath($base, $protectedBase);
    if ($isProtectedBase) return $resolve($protectedBase, true);
    try {
        return $resolve($base, false);
    } catch (Throwable $error) {
        $storageLabel = $configuredBase !== '' ? 'Configured' : 'Default';
        error_log($storageLabel . ' private upload storage is unavailable (' . $error->getMessage() . '); trying the protected application storage fallback.');
        try {
            return $resolve($protectedBase, true);
        } catch (Throwable $fallbackError) {
            throw new RuntimeException(
                'Private upload storage is unavailable; the configured storage and protected application fallback both failed.',
                0,
                $fallbackError
            );
        }
    }
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
    $kind = '';
    $filename = '';
    if (preg_match('#^uploads/(proofs|disclosures)/([^/]+)$#i', $normalized, $legacy)) {
        $kind = strtolower($legacy[1]);
        $filename = $legacy[2];
        if (!preg_match('/\.(?:pdf|docx|jpg|jpeg|png)$/i', $filename)
            || preg_match('/[\x00-\x1f\x7f]/', $filename)
            || $filename === '.' || $filename === '..') return null;
    } elseif (preg_match('#^(?:private/)?(proofs|disclosures)/([a-f0-9]{32}\.(?:pdf|docx|jpg|jpeg|png))$#i', $normalized, $stored)) {
        $kind = strtolower($stored[1]);
        $filename = $stored[2];
    } else {
        return null;
    }

    $directory = realpath(privateStorageDirectory($kind));
    $candidate = $directory === false ? false : realpath($directory . DIRECTORY_SEPARATOR . $filename);
    if ($candidate !== false && is_file($candidate)
        && str_starts_with(strtolower($candidate), strtolower(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
        return $candidate;
    }
    return null;
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
