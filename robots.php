<?php
declare(strict_types=1);

require_once __DIR__ . '/api/config.php';
$siteUrl = rtrim((string) (portalConfig()['app_url'] ?? ''), '/');
$appPath = $siteUrl !== '' ? rtrim((string) parse_url($siteUrl, PHP_URL_PATH), '/') : '';
$blockedPaths = ['/api/', '/includes/', '/private_storage/', '/uploads/'];
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo "User-agent: *\n";
echo 'Allow: ' . ($appPath !== '' ? $appPath : '') . "/\n";
foreach ($blockedPaths as $path) echo 'Disallow: ' . $appPath . $path . "\n";
if ($siteUrl !== '') echo 'Sitemap: ' . $siteUrl . "/sitemap.xml\n";
