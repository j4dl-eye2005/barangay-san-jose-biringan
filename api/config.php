<?php
declare(strict_types=1);

/** Load deployment secrets from a protected project-root .env when the host has no env UI. */
function loadPortalEnvFile(): void
{
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;
    $envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($envFile)) return;
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) continue;
        if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }
        // Store in $_ENV directly so this works even when putenv() is disabled
        // (e.g. InfinityFree shared hosting). Only set if not already present
        // so real environment variables still take precedence.
        if (!isset($_ENV[$name])) {
            $_ENV[$name] = $value;
        }
        // Attempt putenv() as well for hosts that do support it; ignore failure.
        @putenv($name . '=' . $value);
    }
}

function portalConfig(): array
{
    loadPortalEnvFile();
    // Read from $_ENV first (works even when putenv() is disabled on shared
    // hosts like InfinityFree), then fall back to getenv() for environments
    // that set variables through server config rather than a .env file.
    $env = static fn(string $key): string => trim((string) ($_ENV[$key] ?? getenv($key) ?: ''));
    $appUrl = $env('APP_URL');
    $privateStorage = $env('APP_PRIVATE_STORAGE');
    if ($privateStorage === '') {
        $privateStorage = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'barangay_portal_private';
    } elseif (!str_starts_with($privateStorage, DIRECTORY_SEPARATOR)
        && !preg_match('/^[A-Za-z]:[\\\\\/]/', $privateStorage)) {
        // Relative paths are resolved from the application root, which lets
        // restricted hosts use a protected folder inside their htdocs tree.
        $privateStorage = dirname(__DIR__) . DIRECTORY_SEPARATOR . $privateStorage;
    }
    $requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($appUrl === '' && preg_match('/^(localhost|127\.0\.0\.1)(:\d{1,5})?$/', $requestHost, $hostMatch)) {
        $appUrl = 'http://' . $hostMatch[1] . ($hostMatch[2] ?? '') . '/barangay_portal_new';
    }
    return [
        'db_host'         => $env('DB_HOST'),
        'db_port'         => $env('DB_PORT') ?: '3306',
        'db_name'         => $env('DB_NAME'),
        'db_user'         => $env('DB_USER'),
        'db_password'     => $env('DB_PASS'),
        'smtp_host'       => $env('SMTP_HOST'),
        'smtp_port'       => $env('SMTP_PORT') ?: '587',
        'smtp_username'   => $env('SMTP_USERNAME'),
        'smtp_password'   => $env('SMTP_PASSWORD'),
        'smtp_encryption' => strtolower($env('SMTP_ENCRYPTION') ?: 'tls'),
        'from_email'      => $env('MAIL_FROM_EMAIL'),
        'from_name'       => $env('MAIL_FROM_NAME') ?: 'Barangay San Jose',
        'recaptcha_site_key' => $env('RECAPTCHA_SITE_KEY'),
        'recaptcha_secret' => $env('RECAPTCHA_SECRET_KEY'),
        'app_url' => rtrim($appUrl, '/'),
        // Keep default uploads outside the Apache document root (htdocs).
        'private_storage' => rtrim($privateStorage, DIRECTORY_SEPARATOR),
    ];
}

/** CAPTCHA may be skipped only when explicitly enabled on a local server. */
function portalLocalCaptchaBypass(): bool
{
    loadPortalEnvFile();
    // Set this to false to require CAPTCHA during local development.
    // This flag alone is insufficient: both the host and client must be loopback.
    $enabled = true;
    if (isset($_ENV['RECAPTCHA_LOCAL_BYPASS']) || getenv('RECAPTCHA_LOCAL_BYPASS') !== false) {
        $setting = strtolower(trim((string) ($_ENV['RECAPTCHA_LOCAL_BYPASS'] ?? getenv('RECAPTCHA_LOCAL_BYPASS'))));
        $enabled = in_array($setting, ['1', 'true', 'yes', 'on'], true);
    }
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $isLocalHost = (bool) preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(?::\d{1,5})?$/', $host);
    $isLocalClient = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
    return $enabled && $isLocalHost && $isLocalClient;
}
