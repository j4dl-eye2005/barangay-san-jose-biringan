<?php
declare(strict_types=1);

$GLOBALS['skipAppSessionForPublicRead'] = true;
require_once __DIR__ . '/api/db.php';
$portalSettings = portalConfig();
$siteUrl = rtrim((string) ($portalSettings['app_url'] ?? ''), '/');
$recordId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$isRecordFragment = ($_GET['fragment'] ?? '') === '1';
if (!$isRecordFragment && $recordId !== false && $recordId !== null) {
    header('Cache-Control: no-store');
    header('Location: index.php#record?id=' . (int) $recordId, true, 302);
    exit;
}

$record = null;
if ($recordId !== false && $recordId !== null) {
    $stmt = $pdo->prepare("SELECT d.disclosure_id, d.category_id, d.title, d.content, d.timestamp_posted,
            c.category_name, da.attachment_id, da.file_name, da.file_type
        FROM disclosure d
        JOIN disclosure_category c ON c.category_id = d.category_id
        LEFT JOIN disclosure_attachment da ON da.attachment_id = (
            SELECT MIN(attachment_id) FROM disclosure_attachment WHERE disclosure_id = d.disclosure_id
        )
        WHERE d.disclosure_id = ? AND d.is_archived = FALSE
        LIMIT 1");
    $stmt->execute([(int) $recordId]);
    $record = $stmt->fetch() ?: null;
}

function recordEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function recordDescription(string $content): string
{
    $plainText = trim((string) preg_replace('/\\s+/u', ' ', strip_tags($content)));
    if (function_exists('mb_substr')) return mb_substr($plainText, 0, 160, 'UTF-8');
    return substr($plainText, 0, 160);
}

$notFound = $record === null;
http_response_code($notFound ? 404 : 200);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: ' . ($notFound ? 'no-store' : 'public, max-age=60, stale-while-revalidate=300'));

$title = $notFound ? 'Public record unavailable' : (string) $record['title'];
$categoryName = $notFound ? 'Public Records' : (string) $record['category_name'];
$description = $notFound
    ? 'This public record is unavailable or is no longer published.'
    : recordDescription((string) $record['content']);
$pageTitle = $notFound ? $title . ' | Barangay San Jose' : $title . ' | ' . $categoryName . ' | Barangay San Jose';
$canonicalUrl = !$notFound && $siteUrl !== '' ? $siteUrl . '/record.php?id=' . (int) $record['disclosure_id'] : '';
$postedAt = !$notFound ? strtotime((string) $record['timestamp_posted']) : false;
if ($isRecordFragment) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
    if ($notFound) {
        http_response_code(404);
        echo json_encode(['success' => false]);
        exit;
    }
    echo json_encode([
        'success' => true,
        'id' => (int) $record['disclosure_id'],
        'categoryId' => (int) $record['category_id'],
        'category' => (string) $record['category_name'],
        'title' => (string) $record['title'],
        'content' => (string) $record['content'],
        'published' => $postedAt !== false ? date(DATE_ATOM, $postedAt) : null,
        'publishedLabel' => $postedAt !== false ? date('F j, Y', $postedAt) : null,
        'attachmentId' => !empty($record['attachment_id']) ? (int) $record['attachment_id'] : null,
        'fileName' => (string) ($record['file_name'] ?? ''),
        'fileType' => (string) ($record['file_type'] ?? '')
    ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= recordEscape($description) ?>">
    <meta name="theme-color" content="#f3f6f0">
    <?php if (!$notFound): ?><meta name="robots" content="index,follow,max-image-preview:large">
    <?php if ($canonicalUrl !== ''): ?><link rel="canonical" href="<?= recordEscape($canonicalUrl) ?>"><?php endif; ?>
    <meta property="og:type" content="article">
    <meta property="og:title" content="<?= recordEscape($pageTitle) ?>">
    <meta property="og:description" content="<?= recordEscape($description) ?>">
    <?php if ($siteUrl !== ''): ?><meta property="og:image" content="<?= recordEscape($siteUrl . '/assets/barangay-seal.svg') ?>"><?php endif; ?>
    <?php if ($canonicalUrl !== ''): ?><meta property="og:url" content="<?= recordEscape($canonicalUrl) ?>"><?php endif; ?>
    <?php else: ?><meta name="robots" content="noindex,follow"><?php endif; ?>
    <title><?= recordEscape($pageTitle) ?></title>
    <link rel="icon" type="image/png" href="assets/favicon-optimized.png">
    <link rel="stylesheet" href="css/base.css?v=20261023-universal-custom-scrollbar-v1">
    <link rel="stylesheet" href="css/record.css?v=20261028-record-portal-shell-v1">
</head>
<body>
    <a class="record-skip-link" href="#record-main">Skip to main content</a>
    <header class="record-header">
        <div class="record-header-inner">
            <div class="record-utility-row">
                <span><?= recordEscape(date('l, F j, Y')) ?></span>
                <div><a href="index.php#contact">Contact</a><span aria-hidden="true"></span><span>English</span></div>
            </div>
            <div class="record-brand-row">
                <a class="record-brand" href="index.php#landing" aria-label="Barangay San Jose home">
                    <img src="assets/barangay-seal.svg" alt="" width="128" height="128" decoding="async">
                    <span><strong>Barangay San Jose</strong><small>Digital services and public records</small></span>
                    <i aria-hidden="true"></i>
                    <small class="record-brand-statement">DIGITAL DISCLOSURE &amp;<br>DOCUMENT REQUEST PORTAL</small>
                </a>
                <a class="record-home-link" href="index.php#bulletin">Browse public records</a>
            </div>
        </div>
    </header>
    <main class="record-main" id="record-main" tabindex="-1">
        <nav class="record-breadcrumb" aria-label="Breadcrumb">
            <a href="index.php#landing">Home</a><span aria-hidden="true">›</span><a href="index.php#bulletin">Public Records</a>
            <?php if (!$notFound): ?><span aria-hidden="true">›</span><span aria-current="page"><?= recordEscape($categoryName) ?></span><?php endif; ?>
        </nav>
        <article class="record-card">
            <?php if ($notFound): ?>
                <p class="record-eyebrow">Public Records</p>
                <h1><?= recordEscape($title) ?></h1>
                <p>This record may have been unpublished or the link may be incorrect.</p>
                <a class="record-action" href="index.php#bulletin">Return to Public Records</a>
            <?php else: ?>
                <p class="record-eyebrow"><?= recordEscape($categoryName) ?></p>
                <h1><?= recordEscape($title) ?></h1>
                <?php if ($postedAt !== false): ?><p class="record-date"><time datetime="<?= recordEscape(date(DATE_ATOM, $postedAt)) ?>">Published <?= recordEscape(date('F j, Y', $postedAt)) ?></time></p><?php endif; ?>
                <div class="record-content"><?= nl2br(recordEscape((string) $record['content']), false) ?></div>
                <?php if (!empty($record['attachment_id'])): ?>
                    <section class="record-attachment" aria-labelledby="attachment-heading">
                        <h2 id="attachment-heading">Attached document</h2>
                        <a href="api/public_attachment.php?id=<?= (int) $record['attachment_id'] ?>" target="_blank" rel="noopener">
                            <?= recordEscape((string) ($record['file_name'] ?: 'Open attached document')) ?>
                            <span class="visually-hidden"> (opens in a new tab)</span>
                        </a>
                        <?php if (!empty($record['file_type'])): ?><span class="record-file-type"><?= recordEscape(strtoupper((string) $record['file_type'])) ?></span><?php endif; ?>
                    </section>
                <?php endif; ?>
                <a class="record-action" href="index.php#bulletin?category=<?= (int) $record['category_id'] ?>">Browse more <?= recordEscape($categoryName) ?></a>
            <?php endif; ?>
        </article>
    </main>
    <aside class="record-disclaimer-band" aria-label="Site disclaimer">
        <p><strong>Disclaimer:</strong> This website is created for demonstration and academic purposes only as part of an SIA subject project. It is not a real company, official service, registered business, or commercial website. No claims are made regarding actual business operations, legal standing, or real-world services.</p>
    </aside>
    <footer class="record-footer">
        <div class="record-footer-inner">
            <div class="record-footer-brand">
                <img src="assets/barangay-seal.svg" alt="" width="128" height="128" decoding="async">
                <span><strong>Barangay San Jose</strong><small>Digital services and public records</small></span>
            </div>
            <nav class="record-footer-links" aria-label="Portal links">
                <a href="index.php#resident-dashboard">Request a document</a>
                <a href="index.php#resident-profile">Track my requests</a>
                <a href="index.php#bulletin">Public Records</a>
                <a href="index.php#privacy-policy">Privacy Policy</a>
                <a href="index.php#terms">Terms</a>
                <a href="index.php#moderation">Moderation</a>
                <a href="index.php#copyright">Copyright</a>
            </nav>
            <p class="record-footer-note">&copy; <?= recordEscape(date('Y')) ?> Barangay San Jose. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
