<?php
declare(strict_types=1);

$GLOBALS['skipAppSessionForPublicRead'] = true;
require_once __DIR__ . '/api/db.php';
$siteUrl = rtrim((string) (portalConfig()['app_url'] ?? ''), '/');
if ($siteUrl === '') {
    http_response_code(503);
    header('Content-Type: application/xml; charset=utf-8');
    header('Cache-Control: no-store');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\"></urlset>";
    exit;
}

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600, stale-while-revalidate=86400');
$records = $pdo->query('SELECT disclosure_id, timestamp_posted FROM disclosure WHERE is_archived = FALSE ORDER BY disclosure_id ASC')->fetchAll();
$escapeXml = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
echo '  <url><loc>' . $escapeXml($siteUrl . '/index.php') . '</loc><changefreq>weekly</changefreq><priority>1.0</priority></url>' . "\n";
foreach ($records as $record) {
    $id = (int) $record['disclosure_id'];
    $lastModified = strtotime((string) $record['timestamp_posted']);
    echo '  <url><loc>' . $escapeXml($siteUrl . '/record.php?id=' . $id) . '</loc>';
    if ($lastModified !== false) echo '<lastmod>' . date('Y-m-d', $lastModified) . '</lastmod>';
    echo '<changefreq>monthly</changefreq><priority>0.6</priority></url>' . "\n";
}
echo "</urlset>\n";
