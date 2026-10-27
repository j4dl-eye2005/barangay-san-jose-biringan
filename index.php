<?php
require_once __DIR__ . '/api/security.php';
enforceRequestSafety();
startAppSession();
if ((isset($_SESSION['user_id']) || isset($_SESSION['resident_id'])) && !isset($_POST['admin_entry_submit'])) {
    // Validate account auth_version before rendering an authenticated shell.
    require_once __DIR__ . '/api/db.php';
}
if (isset($_POST['admin_entry_submit'])) {
    $providedKey = is_string($_POST['admin_entry_key'] ?? null) ? $_POST['admin_entry_key'] : '';
    // Verify a valid key before opening MySQL; the entry gate should not
    // reject valid credentials just because the database is temporarily down.
    if (authorizeAdminEntry($providedKey)) {
        header('Location: index.php#admin-login', true, 303);
        exit;
    }
    require_once __DIR__ . '/api/db.php';
    if (!rateLimit($pdo, 'admin-entry-key', 5, 900)) denyAdminEntryAccess(false);
    http_response_code(401);
    $adminAccessError = true;
}
if (isset($_GET['admin_access_form'])) {
    if (!empty($_SESSION['admin_entry_authorized']) && hasAdminEntryAuthorization()) {
        header('Location: index.php#admin-login', true, 303);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('Referrer-Policy: no-referrer');
    $csrf = htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8');
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $appBasePath = rtrim(dirname($scriptName), '/.');
    $appBasePath = $appBasePath === '' ? '' : $appBasePath;
    $formAction = htmlspecialchars($appBasePath . '/index.php?admin_access_form=1', ENT_QUOTES, 'UTF-8');
    $logoUrl = htmlspecialchars($appBasePath . '/assets/barangay-seal.svg', ENT_QUOTES, 'UTF-8');
    $faviconUrl = htmlspecialchars($appBasePath . '/assets/favicon-optimized.png', ENT_QUOTES, 'UTF-8');
    $baseCssUrl = htmlspecialchars($appBasePath . '/css/base.css?v=20261023-universal-custom-scrollbar-v1', ENT_QUOTES, 'UTF-8');
    $homeCssUrl = htmlspecialchars($appBasePath . '/css/home.css?v=20261023-static-request-arrows-v1', ENT_QUOTES, 'UTF-8');
    $componentsCssUrl = htmlspecialchars($appBasePath . '/css/components.css?v=20261016-admin-mfa-step', ENT_QUOTES, 'UTF-8');
    $publicCssUrl = htmlspecialchars($appBasePath . '/css/public.css?v=20261016-sticky-menu-stable', ENT_QUOTES, 'UTF-8');
    $responsiveCssUrl = htmlspecialchars($appBasePath . '/css/shared-responsive.css?v=20261027-proof-preview-styles-v2', ENT_QUOTES, 'UTF-8');
    $cssUrl = htmlspecialchars($appBasePath . '/css/admin-access.css?v=20261021-admin-access-panel-contrast-v3', ENT_QUOTES, 'UTF-8');
    $portalCssUrl = htmlspecialchars($appBasePath . '/css/portal-public.css?v=20261023-mobile-auth-actions-v1', ENT_QUOTES, 'UTF-8');
    $jsUrl = htmlspecialchars($appBasePath . '/admin-access.js?v=20261021-shared-mobile-shell-v1', ENT_QUOTES, 'UTF-8');
    $adminInitialSection = null;
    ob_start();
    require __DIR__ . '/includes/shared/site-header.php';
    $siteHeaderMarkup = ob_get_clean();
    $siteHeaderMarkup = str_replace(
        'src="assets/barangay-seal.svg"',
        'src="' . $logoUrl . '"',
        $siteHeaderMarkup
    );
    $suppressSiteDisclaimer = true;
    ob_start();
    require __DIR__ . '/includes/shared/site-footer.php';
    $siteFooterMarkup = ob_get_clean();
    $errorHidden = !empty($adminAccessError) ? '' : ' hidden';
    $errorInvalid = !empty($adminAccessError) ? ' aria-invalid="true"' : '';
    $year = date('Y');
    $document = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#f1f6ec">
    <link rel="preload" as="image" href="__HERO_IMAGE_URL__" type="image/webp" fetchpriority="high">
    <title>Administrator access | Barangay San Jose Digital Portal</title>
    <link rel="icon" type="image/png" href="__FAVICON_URL__">
    <link rel="stylesheet" href="__BASE_CSS_URL__" media="(max-width: 767px)">
    <link rel="stylesheet" href="__HOME_CSS_URL__" media="(max-width: 767px)">
    <link rel="stylesheet" href="__COMPONENTS_CSS_URL__" media="(max-width: 767px)">
    <link rel="stylesheet" href="__PUBLIC_CSS_URL__" media="(max-width: 767px)">
    <link rel="stylesheet" href="__RESPONSIVE_CSS_URL__" media="(max-width: 767px)">
    <link rel="stylesheet" href="__CSS_URL__">
    <link rel="stylesheet" href="__PORTAL_CSS_URL__" media="(max-width: 767px)">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="(max-width: 767px)">
    <script src="__JS_URL__" defer></script>
</head>
<body class="admin-access-page guest-user-view public-portal-view">
    __HEADER_MARKUP__
    <div class="access-layout">
        <main class="split-screen access-stage">
            <aside class="left-panel" aria-hidden="true">
                <div class="panel-content">
                    <img src="__LOGO_URL__" alt="" class="panel-logo" width="128" height="128">
                    <span class="badge">Restricted access</span>
                    <h1>Authorized personnel only.</h1>
                    <p>Secure access for barangay administration.</p>
                    <div class="panel-footer">Barangay San Jose · __YEAR__</div>
                </div>
            </aside>

            <section class="right-panel" aria-labelledby="access-heading">
                <a class="back-button" href="__PUBLIC_URL__"><span aria-hidden="true">←</span> Back to home</a>
                <div class="form-wrapper">
                    <p class="eyebrow">Restricted area</p>
                    <h1 id="access-heading">Administrator access</h1>
                    <p class="form-subtitle">Enter your private access key to continue to administrator sign-in.</p>

                    <form id="admin-access-form" method="post" action="__FORM_ACTION__">
                        <input type="hidden" name="admin_entry_submit" value="1">
                        <input type="hidden" name="csrf_token" value="__CSRF__">
                        <div class="input-group">
                            <label for="admin-entry-key">Private access key</label>
                            <div class="password-field">
                                <input id="admin-entry-key" name="admin_entry_key" type="password" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="Enter your access key" required aria-describedby="admin-entry-error"__ERROR_INVALID__>
                                <button class="password-toggle" type="button" id="access-key-toggle" aria-label="Show access key" aria-pressed="false">
                                    <svg class="eye-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Z" stroke="currentColor" stroke-width="1.7"></path><circle cx="12" cy="12" r="2.7" stroke="currentColor" stroke-width="1.7"></circle></svg>
                                </button>
                            </div>
                        </div>
                        <p id="admin-entry-error" class="login-error" role="alert" aria-live="polite"__ERROR_HIDDEN__>Invalid access key.</p>
                        <button id="admin-access-submit" class="btn-solid full-width mt-3 access-submit" type="submit" disabled>
                            <span class="submit-label">Continue</span>
                            <span class="submit-spinner" aria-hidden="true"></span>
                        </button>
                        <p class="switch-form">Need help? <a class="text-button" href="__CONTACT_URL__">Contact the barangay office</a></p>
                    </form>
                </div>
            </section>
        </main>
    </div>
    __FOOTER_MARKUP__
</body>
</html>
HTML;
    $document = str_replace(
        ['__BASE_CSS_URL__', '__HOME_CSS_URL__', '__COMPONENTS_CSS_URL__', '__PUBLIC_CSS_URL__', '__RESPONSIVE_CSS_URL__', '__CSS_URL__', '__PORTAL_CSS_URL__', '__JS_URL__', '__LOGO_URL__', '__FAVICON_URL__', '__HERO_IMAGE_URL__', '__FORM_ACTION__', '__CSRF__', '__ERROR_INVALID__', '__ERROR_HIDDEN__', '__PUBLIC_URL__', '__CONTACT_URL__', '__YEAR__', '__HEADER_MARKUP__', '__FOOTER_MARKUP__'],
        [$baseCssUrl, $homeCssUrl, $componentsCssUrl, $publicCssUrl, $responsiveCssUrl, $cssUrl, $portalCssUrl, $jsUrl, $logoUrl, $faviconUrl, htmlspecialchars($appBasePath . '/assets/barangay-hall-background.webp', ENT_QUOTES, 'UTF-8'), $formAction, $csrf, $errorInvalid, $errorHidden, htmlspecialchars($appBasePath . '/index.php#landing', ENT_QUOTES, 'UTF-8'), htmlspecialchars($appBasePath . '/index.php#contact-info', ENT_QUOTES, 'UTF-8'), $year, $siteHeaderMarkup, $siteFooterMarkup],
        $document
    );
    echo $document;
    exit;
}
if (!empty($GLOBALS['adminEntryDeniedThisRequest'])) {
    renderUnavailablePage();
}
$adminEntryAuthorized = hasAdminEntryAuthorization();
if (isset($_GET['error']) && $_GET['error'] === '404') renderUnavailablePage();
if (array_key_exists('verify', $_GET) && (!is_string($_GET['verify']) || !preg_match('/^[a-f0-9]{64}$/i', $_GET['verify']))) {
    renderUnavailablePage();
}
if (array_key_exists('reset', $_GET) && (!is_string($_GET['reset']) || !preg_match('/^[a-f0-9]{64}$/i', $_GET['reset']))) {
    renderUnavailablePage();
}
$portalSettings = portalConfig();
$canonicalUrl = !empty($portalSettings['app_url']) ? rtrim($portalSettings['app_url'], '/') . '/index.php' : '';
$recaptchaSiteKey = $portalSettings['recaptcha_site_key'] ?? '';
$localCaptchaBypass = portalLocalCaptchaBypass();
// Section routes set this before including the shared portal shell.
$verifyToken = '';
if (isset($_GET['verify']) && preg_match('/^[a-f0-9]{64}$/i', $_GET['verify'])) {
    $verifyToken = strtolower($_GET['verify']);
}
$resetToken = '';
$resetContext = 'resident';
$resetReturnToProfile = false;
if (isset($_GET['reset']) && preg_match('/^[a-f0-9]{64}$/i', $_GET['reset'])) {
    $resetToken = strtolower($_GET['reset']);
    $resetContext = isset($_GET['context']) && $_GET['context'] === 'admin' ? 'admin' : 'resident';
    $resetReturnToProfile = isset($_GET['return']) && $_GET['return'] === 'profile';
}
if ($resetContext === 'admin' && !$adminEntryAuthorized) {
    renderUnavailablePage();
}
$adminInitialSection = $adminInitialSection ?? null;
$validAdminSections = ['overview', 'requests', 'residents', 'collections', 'staff', 'disclosures', 'activity'];
if (!in_array($adminInitialSection, $validAdminSections, true)) {
    $adminInitialSection = null;
}
if ($adminInitialSection !== null && (($_SESSION['role'] ?? '') !== 'Admin' || empty($_SESSION['user_id']))) {
    renderUnavailablePage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f5f8f2">
    <meta name="description" content="Request barangay documents, track applications, and browse public records from Barangay San Jose.">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Barangay San Jose Digital Portal">
    <meta property="og:description" content="Request barangay documents, track applications, and browse public records from Barangay San Jose.">
    <?php if (!empty($portalSettings['app_url'])): ?><meta property="og:image" content="<?= htmlspecialchars(rtrim((string) $portalSettings['app_url'], '/') . '/assets/barangay-seal.svg', ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
    <?php if ($canonicalUrl !== ''): ?><meta property="og:url" content="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
    <?php if ($canonicalUrl !== ''): ?><link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
    <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <meta name="recaptcha-site-key" content="<?= htmlspecialchars($recaptchaSiteKey, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="recaptcha-local-bypass" content="<?= $localCaptchaBypass ? 'true' : 'false' ?>">
    <link rel="icon" type="image/png" href="assets/favicon-optimized.png">
    <?php if (!$localCaptchaBypass): ?><script src="https://www.google.com/recaptcha/api.js?onload=onRecaptchaLoad&amp;render=explicit" defer></script><?php endif; ?>
    <?php if ($verifyToken !== ''): ?>
    <meta name="email-verify-token" content="<?= htmlspecialchars($verifyToken, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <?php if ($resetToken !== ''): ?>
    <meta name="password-reset-token" content="<?= htmlspecialchars($resetToken, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="password-reset-context" content="<?= htmlspecialchars($resetContext, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="password-reset-return-to-profile" content="<?= $resetReturnToProfile ? 'true' : 'false' ?>">
    <?php endif; ?>
    <title>Barangay San Jose Digital Portal</title>
    <link rel="stylesheet" href="css/base.css?v=20261023-universal-custom-scrollbar-v1">
    <link rel="stylesheet" href="css/home.css?v=20261023-static-request-arrows-v1">
    <link rel="stylesheet" href="css/components.css?v=20261017-direct-password-change">
    <link data-portal-route-css="auth" data-portal-css-href="css/auth.css?v=20261027-resident-profile-edit-v2">
    <link data-portal-route-css="admin" data-portal-css-href="css/admin.css?v=20261023-server-disclosure-drafts-v1">
    <link rel="stylesheet" href="css/public.css?v=20261016-sticky-menu-stable">
    <link data-portal-route-css="admin" data-portal-css-href="css/admin-tables.css?v=20261027-staff-directory-ui-v1">
    <link rel="stylesheet" href="css/shared-responsive.css?v=20261027-proof-preview-styles-v2">
    <link data-portal-route-css="admin" data-portal-css-href="css/admin-responsive.css?v=20261027-proof-preview-layout-v1">
    <link data-portal-route-css="unavailable" data-portal-css-href="css/unavailable.css?v=20261023-mobile-404-wrap-v2">
    <link rel="stylesheet" href="css/portal-public.css?v=20261027-admin-navigation-compact-tabs-v2">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body data-initial-admin-section="<?= htmlspecialchars((string) $adminInitialSection, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-entry-route="<?= $verifyToken !== '' ? 'verification' : ($resetToken !== '' ? 'password-reset' : '') ?>" data-admin-entry-authorized="<?= $adminEntryAuthorized ? '1' : '0' ?>" data-account-role="<?= htmlspecialchars((string) ($_SESSION['role'] ?? 'Guest'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <script src="route-boot.js?v=20261022-route-css-cascade-v1"></script>
    <a class="skip-link" href="#app-container">Skip to main content</a>

    <?php require __DIR__ . '/includes/shared/site-header.php'; ?>

    <main id="app-container" tabindex="-1">
        <section id="landing" class="section active" aria-labelledby="landing-title">

            <div class="hero">
                <div class="hero-intro">
                    <h1 id="landing-title"><span class="hero-title-line">Welcome to</span> <span class="hero-title-line">Barangay San Jose</span></h1>
                    <p class="description"><span class="hero-description-line">Request barangay documents, track</span> <span class="hero-description-line">your application, and read public</span> <span class="hero-description-line">records published by the barangay.</span></p>
                    <form class="hero-search" role="search" data-action-submit="portalSearch(this); return false">
                        <label class="visually-hidden" for="hero-search-input">Search for documents or public records</label>
                        <i class="fas fa-search" aria-hidden="true"></i>
                        <input id="hero-search-input" name="query" type="search" autocomplete="off" placeholder="Search for documents or public records">
                    </form>
                    <div class="hero-document-chips" aria-label="Quick document requests">
                        <button type="button" data-action-click="requestDoc('Barangay Clearance')">Barangay Clearance</button>
                        <button type="button" data-action-click="requestDoc('Certificate of Residency')">Certificate of Residency</button>
                        <button type="button" data-action-click="requestDoc('Barangay Business Clearance')">Barangay Business Clearance</button>
                    </div>
                </div>

                <aside class="hero-popular-services" aria-labelledby="popular-services-title">
                    <h2 id="popular-services-title">Popular services</h2>
                    <div class="popular-services-grid">
                        <button type="button" data-action-click="checkAuthAndRedirect('request')"><span class="popular-service-icon"><i class="fas fa-file-circle-plus" aria-hidden="true"></i></span><span>Request a document</span></button>
                        <button type="button" data-action-click="checkAuthAndRedirect('request')"><span class="popular-service-icon"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i></span><span>Track my requests</span></button>
                        <button type="button" data-action-click="showSection('bulletin')"><span class="popular-service-icon"><i class="fas fa-folder-open" aria-hidden="true"></i></span><span>Public records</span></button>
                        <button type="button" data-action-click="openDashboard()"><span class="popular-service-icon"><i class="fas fa-user" aria-hidden="true"></i></span><span>My account</span></button>
                    </div>
                    <button class="view-all-services" type="button" data-action-click="showHomeAnchor('services')">View all services <i class="fas fa-arrow-right" aria-hidden="true"></i></button>
                </aside>
                <p class="hero-privacy"><span>Your data is protected under RA&nbsp;10173. <button class="hero-privacy-link" type="button" data-action-click="showSection('privacy-policy')">Read our privacy notice.</button></span></p>
            </div>

            <section class="how-it-works" id="services" aria-labelledby="how-it-works-title">
                <p class="eyebrow">Documents you can request</p>
                <h2 id="how-it-works-title">Available at Barangay San Jose</h2>
                <p class="doc-strip-note">Most documents are ready in 1–3 working days. Pick up at Barangay Hall, Mon–Fri, 8&nbsp;am–5&nbsp;pm.</p>
                <ol class="steps-strip doc-strip" role="list">
                    <li class="step-item doc-item">
                        <button class="doc-card-btn" type="button" data-action-click="requestDoc('Barangay Clearance')" aria-label="Request Barangay Clearance">
                            <span class="doc-item-fee" data-document-fee-for="Barangay Clearance">Fee confirmed at the barangay hall</span>
                            <h3>Barangay Clearance</h3>
                            <p>General-purpose clearance for employment, business, or personal use.</p>
                            <span class="doc-item-action">Start request <i class="fas fa-arrow-right" aria-hidden="true"></i></span>
                        </button>
                    </li>
                    <li class="step-item doc-item">
                        <button class="doc-card-btn" type="button" data-action-click="requestDoc('Certificate of Residency')" aria-label="Request Certificate of Residency">
                            <span class="doc-item-fee" data-document-fee-for="Certificate of Residency">Fee confirmed at the barangay hall</span>
                            <h3>Certificate of Residency</h3>
                            <p>Confirms you are a registered resident of Barangay San Jose.</p>
                            <span class="doc-item-action">Start request <i class="fas fa-arrow-right" aria-hidden="true"></i></span>
                        </button>
                    </li>
                    <li class="step-item doc-item">
                        <button class="doc-card-btn" type="button" data-action-click="requestDoc('Certificate of Indigency')" aria-label="Request Certificate of Indigency">
                            <span class="doc-item-fee" data-document-fee-for="Certificate of Indigency">Fee confirmed at the barangay hall</span>
                            <h3>Certificate of Indigency</h3>
                            <p>For residents who need proof of financial need for government programs.</p>
                            <span class="doc-item-action">Start request <i class="fas fa-arrow-right" aria-hidden="true"></i></span>
                        </button>
                    </li>
                    <li class="step-item doc-item">
                        <button class="doc-card-btn" type="button" data-action-click="requestDoc('Barangay Business Clearance')" aria-label="Request Barangay Business Clearance">
                            <span class="doc-item-fee" data-document-fee-for="Barangay Business Clearance">Fee confirmed at the barangay hall</span>
                            <h3>Barangay Business Clearance</h3>
                            <p>Barangay-level clearance required before applying for a city business permit.</p>
                            <span class="doc-item-action">Start request <i class="fas fa-arrow-right" aria-hidden="true"></i></span>
                        </button>
                    </li>
                </ol>
                <div class="mobile-carousel-dots" id="doc-carousel-dots" role="group" aria-label="Document cards"></div>
                <div class="document-finder" aria-labelledby="document-finder-title">
                    <div class="document-finder-intro">
                        <h3 id="document-finder-title">Not sure which document you need?</h3>
                        <p>Choose what you need it for to see a suggested match.</p>
                    </div>
                    <label class="visually-hidden" for="document-finder-purpose">What do you need the document for?</label>
                    <select id="document-finder-purpose" data-action-change="updateLandingDocumentFinder(this)">
                        <option value="">Choose a purpose</option>
                        <option value="general">Employment, business, or personal use</option>
                        <option value="residency">Confirm that I live in Barangay San Jose</option>
                        <option value="indigency">Show financial need for a government program</option>
                        <option value="business">Apply for a city business permit</option>
                    </select>
                    <div class="document-finder-result" id="document-finder-result" role="status" aria-live="polite" hidden>
                        <div class="document-finder-result-inner" id="document-finder-result-inner"></div>
                    </div>
                </div>
                <div class="portal-service-guide">
                    <section class="getting-started-section" aria-labelledby="getting-started-title">
                        <div class="getting-started-card">
                            <p class="eyebrow">Getting started</p>
                            <h2 id="getting-started-title">Request a document in three steps</h2>
                            <ol class="inline-steps">
                                <li class="inline-step"><span class="step-badge">1</span><div><strong>Create your account</strong><p>Create an account and verify your email.</p></div></li>
                                <li class="inline-step"><span class="step-badge">2</span><div><strong>Submit a request</strong><p>Choose the document you need and tell us the purpose.</p></div></li>
                                <li class="inline-step"><span class="step-badge">3</span><div><strong>Track and collect</strong><p>Follow your request status online and get notified when it's ready.</p></div></li>
                            </ol>
                        </div>
                    </section>

                    <p class="eyebrow service-strip-label">More from the barangay</p>
                    <nav class="service-strip" aria-label="Quick links to portal services">
                        <button class="service-card service-card-ghost" type="button" data-action-click="checkAuthAndRedirect('request')">
                            <span class="service-icon"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i></span>
                            <span class="service-body"><strong>Track a request</strong><p>Check the status of your application.</p></span>
                            <i class="fas fa-arrow-right service-arrow" aria-hidden="true"></i>
                        </button>
                        <button class="service-card service-card-ghost" type="button" data-action-click="showSection('bulletin')">
                            <span class="service-icon"><i class="fas fa-folder-open" aria-hidden="true"></i></span>
                            <span class="service-body"><strong>Public records</strong><p>Sangguniang Kabataan (SK) notices, reports, and minutes.</p></span>
                            <i class="fas fa-arrow-right service-arrow" aria-hidden="true"></i>
                        </button>
                        <button class="service-card service-card-ghost" type="button" data-action-click="focusContactInfo()">
                            <span class="service-icon"><i class="fas fa-headset" aria-hidden="true"></i></span>
                            <span class="service-body"><strong>Help &amp; contact</strong><p>Office hours, location, and how to reach us.</p></span>
                            <i class="fas fa-arrow-right service-arrow" aria-hidden="true"></i>
                        </button>
                    </nav>
                </div>
            </section>

            <div class="landing-sections">
                <section class="landing-block" aria-labelledby="latest-announcements-title">
                    <div class="block-head block-head-row">
                        <div>
                            <p class="eyebrow">Transparency center</p>
                            <h2 id="latest-announcements-title">Latest public announcements</h2>
                        </div>
                        <button class="mobile-view-all" type="button" data-action-click="showSection('bulletin')">View all <i class="fas fa-arrow-right" aria-hidden="true"></i></button>
                    </div>
                    <div class="latest-announcements" id="latest-announcements" aria-live="polite" aria-busy="true">
                        <p class="empty-state" id="latest-announcements-status" role="status">Loading announcements...</p>
                    </div>
                    <div class="mobile-carousel-dots" id="announcement-carousel-dots" role="group" aria-label="Latest announcements"></div>

                    <div class="trust-row" id="contact-info" tabindex="-1" aria-label="Barangay contact and office hours">
                        <div class="trust-item">
                            <span class="trust-icon"><i class="fas fa-clock" aria-hidden="true"></i></span>
                            <div><strong>Office hours</strong><p>Monday to Friday, 8:00&nbsp;AM – 5:00&nbsp;PM</p></div>
                        </div>
                        <div class="trust-item">
                            <span class="trust-icon"><i class="fas fa-location-dot" aria-hidden="true"></i></span>
                            <div><strong>Barangay Hall</strong><p>Barangay San Jose, Biringan City</p></div>
                        </div>
                        <div class="trust-item">
                            <span class="trust-icon"><i class="fas fa-headset" aria-hidden="true"></i></span>
                            <div><strong>Need help?</strong><p><a href="mailto:deleonjuliusakeem@gmail.com">deleonjuliusakeem@gmail.com</a></p></div>
                        </div>
                    </div>
                </section>
            </div>
        </section>

        <section id="bulletin" class="section" aria-labelledby="bulletin-title">
            <div class="page-shell">
                <div class="bulletin-intro-panel">
                <nav class="bulletin-breadcrumb" aria-label="Breadcrumb">
                    <button type="button" data-action-click="showSection('landing')">Home</button>
                    <span aria-hidden="true">/</span>
                    <span>Public Records</span>
                    <span aria-hidden="true">/</span>
                    <span id="bulletin-breadcrumb-current" aria-current="page">SK Public Bulletin Board</span>
                </nav>
                <div class="dashboard-header">
                    <div>
                        <button class="back-link bulletin-category-back" id="bulletin-category-back" type="button" data-action-click="showBulletinOverview()" hidden><i class="fas fa-arrow-left" aria-hidden="true"></i> All public records</button>
                        <p class="eyebrow" id="bulletin-eyebrow">Transparency center</p>
                        <div class="bulletin-title-row">
                            <h1 id="bulletin-title">SK Public Bulletin Board</h1>
                            <img src="assets/Sangguniang_Kabataan_logo.svg" alt="Sangguniang Kabataan" class="bulletin-sk-logo" width="256" height="256" loading="lazy" decoding="async">
                        </div>
                        <p id="bulletin-description">Browse official announcements, financial records, projects, and meeting minutes.</p>
                    </div>
                    <div id="bulletin-admin-action" class="hidden bulletin-admin-action">
                        <button class="btn-solid" type="button" data-action-click="openDisclosureComposer()"><i class="fas fa-paperclip" aria-hidden="true"></i> Add bulletin document</button>
                    </div>
                </div>
                <div class="public-records-search" id="public-records-search">
                    <form role="search" data-action-submit="searchPublicRecords(this); return false">
                        <label class="visually-hidden" for="public-records-search-input">Search public records and document names</label>
                        <i class="fas fa-search" aria-hidden="true"></i>
                        <input id="public-records-search-input" name="query" type="search" autocomplete="off" enterkeyhint="search" placeholder="Search public records and document names">
                        <button class="public-records-search-clear" type="button" aria-label="Clear search" hidden data-action-click="clearPublicRecordsSearchInput()"><i class="fas fa-xmark" aria-hidden="true"></i></button>
                        <button type="submit" aria-label="Search"><i class="fas fa-magnifying-glass" aria-hidden="true"></i><span>Search</span></button>
                    </form>
                    <p id="public-records-search-summary" aria-live="polite"></p>
                </div>
                <section class="public-records-search-results" id="public-records-search-results" aria-live="polite" hidden></section>

                <section class="bulletin-latest-section" aria-labelledby="bulletin-latest-title" id="bulletin-latest-section">
                    <div class="bulletin-section-heading">
                        <div>
                            <h2 id="bulletin-latest-title">Latest Announcements</h2>
                        </div>
                        <button type="button" class="bulletin-text-link" data-action-click="openDisclosureCategory(1)">All announcements <i class="fas fa-arrow-right" aria-hidden="true"></i></button>
                    </div>
                    <div class="bulletin-latest-grid" id="bulletin-latest-list" aria-live="polite" aria-busy="true" style="--bulletin-latest-columns: 2">
                        <article class="bulletin-announcement-card bulletin-announcement-skeleton" aria-hidden="true"><span class="bulletin-announcement-skeleton-bar bulletin-announcement-skeleton-badge"></span><span class="bulletin-announcement-skeleton-bar bulletin-announcement-skeleton-title"></span><span class="bulletin-announcement-skeleton-bar bulletin-announcement-skeleton-date"></span><div class="bulletin-announcement-skeleton-summary"><span class="bulletin-announcement-skeleton-bar"></span><span class="bulletin-announcement-skeleton-bar"></span><span class="bulletin-announcement-skeleton-bar"></span></div><span class="bulletin-announcement-skeleton-bar bulletin-announcement-skeleton-link"></span></article>
                        <article class="bulletin-announcement-card bulletin-announcement-skeleton" aria-hidden="true"><span class="bulletin-announcement-skeleton-bar bulletin-announcement-skeleton-badge"></span><span class="bulletin-announcement-skeleton-bar bulletin-announcement-skeleton-title"></span><span class="bulletin-announcement-skeleton-bar bulletin-announcement-skeleton-date"></span><div class="bulletin-announcement-skeleton-summary"><span class="bulletin-announcement-skeleton-bar"></span><span class="bulletin-announcement-skeleton-bar"></span><span class="bulletin-announcement-skeleton-bar"></span></div><span class="bulletin-announcement-skeleton-bar bulletin-announcement-skeleton-link"></span></article>
                    </div>
                </section>
                </div>

                <div class="bulletin-overview-content" id="bulletin-overview-content">
                    <section class="bulletin-archive-section" aria-labelledby="bulletin-archive-title">
                        <div class="bulletin-section-heading bulletin-archive-heading">
                            <div>
                                <p class="eyebrow">Official records</p>
                                <h2 id="bulletin-archive-title">Browse Public Records</h2>
                            </div>
                        </div>
                        <div class="bulletin-grid" id="bulletin-category-filters" role="group" aria-label="Filter public records by category">
                            <button class="bulletin-filter-card is-active" type="button" data-bulletin-filter="all" aria-pressed="true" data-action-click="setBulletinFilter('all')">
                                <span class="bulletin-filter-icon"><i class="fas fa-folder-open" aria-hidden="true"></i></span>
                                <span class="bulletin-filter-copy"><strong>All Records</strong><small>Browse the full archive</small></span>
                                <span class="bulletin-filter-count" id="bulletin-count-all">—</span>
                            </button>
                            <button class="bulletin-filter-card" type="button" data-bulletin-filter="1" aria-pressed="false" data-action-click="setBulletinFilter(1)">
                                <span class="bulletin-filter-icon"><i class="fas fa-bullhorn" aria-hidden="true"></i></span>
                                <span class="bulletin-filter-copy"><strong>Announcements</strong><small data-default-subtitle="Notices and community updates">Notices and community updates</small></span>
                                <span class="bulletin-filter-count" id="bulletin-count-1">—</span>
                            </button>
                            <button class="bulletin-filter-card" type="button" data-bulletin-filter="2" aria-pressed="false" data-action-click="setBulletinFilter(2)">
                                <span class="bulletin-filter-icon"><i class="fas fa-chart-pie" aria-hidden="true"></i></span>
                                <span class="bulletin-filter-copy"><strong>Financial Reports</strong><small data-default-subtitle="Published financial records">Published financial records</small></span>
                                <span class="bulletin-filter-count" id="bulletin-count-2">—</span>
                            </button>
                            <button class="bulletin-filter-card" type="button" data-bulletin-filter="3" aria-pressed="false" data-action-click="setBulletinFilter(3)">
                                <span class="bulletin-filter-icon"><i class="fas fa-project-diagram" aria-hidden="true"></i></span>
                                <span class="bulletin-filter-copy"><strong>Projects &amp; Budget</strong><small data-default-subtitle="Plans and budget documents">Plans and budget documents</small></span>
                                <span class="bulletin-filter-count" id="bulletin-count-3">—</span>
                            </button>
                            <button class="bulletin-filter-card" type="button" data-bulletin-filter="4" aria-pressed="false" data-action-click="setBulletinFilter(4)">
                                <span class="bulletin-filter-icon"><i class="fas fa-file-alt" aria-hidden="true"></i></span>
                                <span class="bulletin-filter-copy"><strong>Meeting Minutes</strong><small data-default-subtitle="Published meeting records">Published meeting records</small></span>
                                <span class="bulletin-filter-count" id="bulletin-count-4">—</span>
                            </button>
                        </div>
                        <div class="bulletin-filter-dots mobile-carousel-dots" id="bulletin-filter-dots" role="group" aria-label="Choose a public records category">
                            <button class="mobile-carousel-dot" type="button" data-action-click="setBulletinFilter('all')" data-bulletin-filter-dot="all" aria-label="All Records" aria-current="true"></button>
                            <button class="mobile-carousel-dot" type="button" data-action-click="setBulletinFilter(1)" data-bulletin-filter-dot="1" aria-label="Announcements"></button>
                            <button class="mobile-carousel-dot" type="button" data-action-click="setBulletinFilter(2)" data-bulletin-filter-dot="2" aria-label="Financial Reports"></button>
                            <button class="mobile-carousel-dot" type="button" data-action-click="setBulletinFilter(3)" data-bulletin-filter-dot="3" aria-label="Projects and Budget"></button>
                            <button class="mobile-carousel-dot" type="button" data-action-click="setBulletinFilter(4)" data-bulletin-filter-dot="4" aria-label="Meeting Minutes"></button>
                        </div>
                        <p class="bulletin-filter-subtitle" id="bulletin-filter-subtitle" aria-live="polite">Browse the full archive</p>
                        <div class="bulletin-archive-list" id="bulletin-archive-list" aria-live="polite" aria-busy="true">
                            <p class="visually-hidden" role="status">Loading public records…</p>
                            <?php for ($skeletonRow = 0; $skeletonRow < 3; $skeletonRow++): ?>
                            <div class="bulletin-record-row bulletin-record-skeleton" aria-hidden="true"><div class="bulletin-record-main"><span class="bulletin-record-skeleton-bar bulletin-record-skeleton-title"></span><span class="bulletin-record-skeleton-bar bulletin-record-skeleton-meta"></span><span class="bulletin-record-skeleton-bar bulletin-record-skeleton-summary"></span><span class="bulletin-record-skeleton-bar bulletin-record-skeleton-summary"></span></div><div class="bulletin-record-actions"><span class="bulletin-record-skeleton-action"></span></div></div>
                            <?php endfor; ?>
                        </div>
                    </section>
                </div>
                <section class="bulletin-category-view" id="bulletin-category-view" aria-live="polite" hidden>
                    <div class="card bulletin-category-posts" id="bulletin-category-posts" aria-busy="true">
                        <div class="bulletin-category-list" id="bulletin-category-list">
                            <p class="visually-hidden" role="status">Loading public records…</p>
                            <?php for ($skeletonRow = 0; $skeletonRow < 3; $skeletonRow++): ?>
                            <div class="bulletin-record-row bulletin-record-skeleton" aria-hidden="true"><div class="bulletin-record-main"><span class="bulletin-record-skeleton-bar bulletin-record-skeleton-title"></span><span class="bulletin-record-skeleton-bar bulletin-record-skeleton-meta"></span><span class="bulletin-record-skeleton-bar bulletin-record-skeleton-summary"></span><span class="bulletin-record-skeleton-bar bulletin-record-skeleton-summary"></span></div><div class="bulletin-record-actions"><span class="bulletin-record-skeleton-action"></span></div></div>
                            <?php endfor; ?>
                        </div>
                    </div>
                </section>
            </div>
        </section>

        <section id="login" class="section auth-section" aria-labelledby="login-title">
            <div class="split-screen">
                <div class="left-panel" aria-hidden="true">
                    <div class="panel-content">
                        <img src="assets/barangay-seal.svg" alt="" class="panel-logo" width="128" height="128" loading="lazy" decoding="async">
                        <span class="badge">Secure resident services</span>
                        <h1>Digital Disclosure &amp; Document Request Portal</h1>
                        <p>Use your verified account to request documents and monitor their status.</p>
                        <div class="panel-footer">Barangay San Jose · <span id="year-1"></span></div>
                    </div>
                </div>
                <div class="right-panel">
                    <button class="back-button" type="button" data-action-click="showSection('landing')"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to home</button>
                    <div class="form-wrapper">
                        <p class="eyebrow">Account access</p>
                        <h1 id="login-title">Log in</h1>
                        <p class="form-subtitle" id="login-context-msg">Enter your credentials to continue to the portal.</p>
                        <p class="login-error hidden" id="login-error" role="alert" aria-live="polite"></p>
                        <form id="login-form" data-action-submit="handleLogin(event)">
                            <div class="visually-hidden" aria-hidden="true"><label for="login-website">Leave this field empty</label><input id="login-website" name="website" tabindex="-1" autocomplete="off"></div>
                            <div class="input-group"><label for="login-email">Email address</label><input type="email" id="login-email" maxlength="190" autocomplete="email" placeholder="you@example.com" required><span class="reg-field-error" id="login-email-error" role="alert"></span></div>
                            <div class="input-group login-password-group"><label for="login-password">Password</label><div class="password-field"><input type="password" id="login-password" maxlength="256" autocomplete="current-password" placeholder="Enter your password" required><button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false" data-action-click="togglePassword('login-password', this)"><i class="fas fa-eye" aria-hidden="true"></i></button></div><button class="login-forgot-link" type="button" data-action-click="openForgotPassword('resident')">Forgot password?</button></div>
                            <?php if (!$localCaptchaBypass): ?><div class="g-recaptcha recaptcha-widget" data-sitekey="<?= htmlspecialchars($recaptchaSiteKey, ENT_QUOTES, 'UTF-8') ?>" data-size="invisible" id="recaptcha-login"></div><?php endif; ?>
                            <button type="submit" class="btn-solid full-width mt-3" id="login-submit">Log in</button>
                            <p class="switch-form">New to the portal? <button type="button" class="text-button" data-action-click="showSection('register')">Register your account</button></p>
                        </form>
                    </div>
                </div>
            </div>
        </section>

        <section id="page-unavailable" class="section unavailable-section" aria-labelledby="unavailable-title">
            <?= unavailablePageMarkup() ?>
        </section>

        <section id="admin-login" class="section <?= $adminEntryAuthorized ? 'auth-section' : 'unavailable-section' ?>" aria-labelledby="<?= $adminEntryAuthorized ? 'admin-login-title' : 'admin-login-unavailable-title' ?>">
            <?php if (!$adminEntryAuthorized): ?>
            <?= unavailablePageMarkup('admin-login-unavailable-title') ?>
            <?php else: ?>
            <div class="split-screen">
                <div class="left-panel" aria-hidden="true">
                    <div class="panel-content">
                        <img src="assets/barangay-seal.svg" alt="" class="panel-logo" width="128" height="128" loading="lazy" decoding="async">
                        <span class="badge">Administrator access</span>
                        <h1>Digital Disclosure &amp; Document Request Portal</h1>
                        <p>Restricted area for barangay administrators managing requests and records.</p>
                        <div class="panel-footer">Barangay San Jose · <span id="year-admin"></span></div>
                    </div>
                </div>
                <div class="right-panel">
                    <button class="back-button" type="button" data-action-click="showSection('landing')"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to home</button>
                    <div class="form-wrapper">
                        <p class="eyebrow">Staff login</p>
                        <div id="admin-login-signin-step">
                            <h1 id="admin-login-title">Admin login</h1>
                            <p class="form-subtitle">Authorized barangay staff only.</p>
                            <p class="login-error hidden" id="admin-login-error" role="alert" aria-live="polite"></p>
                            <form id="admin-login-form" data-action-submit="handleAdminLogin(event)">
                                <div class="visually-hidden" aria-hidden="true"><label for="admin-login-website">Leave this field empty</label><input id="admin-login-website" name="website" tabindex="-1" autocomplete="off"></div>
                                <div class="input-group"><label for="admin-login-email">Email address</label><input type="email" id="admin-login-email" maxlength="190" autocomplete="username" placeholder="admin@example.com" required><span class="reg-field-error" id="admin-login-email-error" role="alert"></span></div>
                                <div class="input-group"><label for="admin-login-password">Password</label><div class="password-field"><input type="password" id="admin-login-password" maxlength="256" autocomplete="current-password" placeholder="Enter your password" required><button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false" data-action-click="togglePassword('admin-login-password', this)"><i class="fas fa-eye" aria-hidden="true"></i></button></div></div>
                                <button type="submit" class="btn-solid full-width mt-3">Log in</button>
                            </form>
                            <p class="switch-form">Need help? <a class="text-button" href="mailto:deleonjuliusakeem@gmail.com">Contact the barangay office</a></p>
                        </div>
                        <div id="admin-mfa-step" class="hidden" aria-live="polite">
                            <h2 id="admin-mfa-title">Verify your identity</h2>
                            <p class="form-subtitle" id="admin-mfa-instructions">Enter the 6-digit code from your authenticator app.</p>
                            <div id="admin-mfa-secret-wrap" class="hidden">
                                <section class="admin-mfa-step admin-mfa-key-step" aria-labelledby="admin-mfa-key-step-title">
                                    <h3 class="admin-mfa-step-title" id="admin-mfa-key-step-title"><span class="admin-mfa-step-number">1.</span> Add the key to your authenticator app</h3>
                                    <p class="admin-mfa-step-description">Choose to add an account manually, then pick a time-based code (TOTP) with 6 digits.</p>
                                    <div id="admin-mfa-secret-unit" class="admin-mfa-secret-copy"><code id="admin-mfa-secret" aria-label="Authenticator setup key"></code><span class="admin-mfa-copy-control"><button type="button" class="btn-outline admin-mfa-copy-button" aria-label="Copy key" title="Copy key" data-action-click="copyAuthenticatorSetupKey('admin-mfa-secret', this)"><i class="fas fa-copy" aria-hidden="true"></i></button><span class="admin-mfa-copy-tooltip" aria-hidden="true" hidden>Copied</span></span></div>
                                    <p class="admin-mfa-private-note"><i class="fas fa-lock" aria-hidden="true"></i><span>Keep this key private. It will only be shown during setup.</span></p>
                                </section>
                                <span id="admin-mfa-copy-live" class="visually-hidden" aria-live="polite" aria-atomic="true"></span>
                            </div>
                            <form id="admin-mfa-form" data-action-submit="handleAdminMfaSubmit(event)">
                                <div class="input-group admin-mfa-code-group admin-mfa-step admin-mfa-code-step" id="admin-mfa-authenticator-mode">
                                    <label class="admin-mfa-step-title" id="admin-mfa-code-label"><span class="admin-mfa-step-number" id="admin-mfa-code-step-number">2.</span><span id="admin-mfa-code-step-text">Enter the 6-digit code</span></label>
                                    <div class="admin-mfa-code-fields" id="admin-mfa-code-fields" role="group" aria-labelledby="admin-mfa-code-label" aria-describedby="admin-mfa-code-help admin-mfa-error">
                                        <input class="admin-mfa-digit" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code" maxlength="6" aria-label="Digit 1 of 6" data-code-index="0" data-action-input="updateAdminMfaDigits(event)" data-action-keydown="handleAdminMfaDigitKeydown(event)" data-action-paste="handleAdminMfaPaste(event)">
                                        <input class="admin-mfa-digit" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" maxlength="1" aria-label="Digit 2 of 6" data-code-index="1" data-action-input="updateAdminMfaDigits(event)" data-action-keydown="handleAdminMfaDigitKeydown(event)" data-action-paste="handleAdminMfaPaste(event)">
                                        <input class="admin-mfa-digit" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" maxlength="1" aria-label="Digit 3 of 6" data-code-index="2" data-action-input="updateAdminMfaDigits(event)" data-action-keydown="handleAdminMfaDigitKeydown(event)" data-action-paste="handleAdminMfaPaste(event)">
                                        <input class="admin-mfa-digit" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" maxlength="1" aria-label="Digit 4 of 6" data-code-index="3" data-action-input="updateAdminMfaDigits(event)" data-action-keydown="handleAdminMfaDigitKeydown(event)" data-action-paste="handleAdminMfaPaste(event)">
                                        <input class="admin-mfa-digit" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" maxlength="1" aria-label="Digit 5 of 6" data-code-index="4" data-action-input="updateAdminMfaDigits(event)" data-action-keydown="handleAdminMfaDigitKeydown(event)" data-action-paste="handleAdminMfaPaste(event)">
                                        <input class="admin-mfa-digit" type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" maxlength="1" aria-label="Digit 6 of 6" data-code-index="5" data-action-input="updateAdminMfaDigits(event)" data-action-keydown="handleAdminMfaDigitKeydown(event)" data-action-paste="handleAdminMfaPaste(event)">
                                    </div>
                                    <p class="admin-mfa-field-help" id="admin-mfa-code-help">Open your authenticator app and enter its current 6-digit code.</p>
                                    <button type="button" class="text-button admin-mfa-mode-switch" id="admin-mfa-recovery-toggle" data-action-click="showAdminMfaRecoveryCodeInput()" hidden>Use a recovery code</button>
                                    <input type="hidden" id="admin-mfa-code" value="">
                                </div>
                                <div class="input-group admin-mfa-recovery-group" id="admin-mfa-recovery-wrap" hidden>
                                    <label for="admin-mfa-recovery-code" class="admin-mfa-step-title">Enter a recovery code</label>
                                    <input id="admin-mfa-recovery-code" type="text" inputmode="text" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="20" aria-describedby="admin-mfa-recovery-help admin-mfa-error" data-action-input="handleAdminMfaRecoveryInput(event)">
                                    <p class="admin-mfa-field-help" id="admin-mfa-recovery-help">Use one of the 20-character codes saved during authenticator setup. Each code works once.</p>
                                    <button type="button" class="text-button admin-mfa-mode-switch" data-action-click="useAdminMfaAuthenticatorCode(true)">Use authenticator code</button>
                                </div>
                                <p class="admin-mfa-error hidden" id="admin-mfa-error" role="alert" aria-live="polite"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i><span id="admin-mfa-error-message"></span></p>
                                <button type="submit" class="btn-solid full-width mt-3" id="admin-mfa-submit" disabled><span class="admin-mfa-submit-label">Verify code</span><span class="admin-mfa-submit-progress hidden"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Verifying...</span></button>
                            </form>
                            <button type="button" class="back-button admin-mfa-cancel" data-action-click="cancelAdminMfa()"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Admin login</button>
                            <p class="switch-form">Need help? <a class="text-button" href="mailto:deleonjuliusakeem@gmail.com">Contact the barangay office</a></p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </section>

        <section id="forgot-password" class="section auth-section<?= $resetReturnToProfile ? ' profile-password-flow' : '' ?>" aria-labelledby="forgot-password-title">
            <div class="split-screen">
                <div class="left-panel" aria-hidden="true"><div class="panel-content"><img src="assets/barangay-seal.svg" alt="" class="panel-logo" width="128" height="128" loading="lazy" decoding="async"><span class="badge">Account security</span><h1>Secure access to your portal account.</h1><p>Use a private reset link to choose a new password.</p><div class="panel-footer">Barangay San Jose</div></div></div>
                <div class="right-panel"><div class="form-wrapper">
                    <button class="back-button" type="button" data-action-click="returnFromPasswordReset()"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to log in</button>
                    <p class="eyebrow" id="password-reset-eyebrow">Account recovery</p>
                    <h1 id="forgot-password-title">Forgot your password?</h1>
                    <p class="form-subtitle" id="password-reset-intro">Enter the email address for your portal account. We’ll send a secure link if it matches an account.</p>
                    <p class="login-error hidden" id="password-reset-error" role="alert" aria-live="polite"></p>
                    <p class="password-reset-success hidden" id="password-reset-success" role="status" aria-live="polite"></p>
                    <form id="forgot-password-form" data-action-submit="requestPasswordReset(event)">
                        <div class="input-group"><label for="password-reset-email">Email address</label><input type="email" id="password-reset-email" autocomplete="email" placeholder="you@example.com" required><span class="reg-field-error" id="password-reset-email-error" role="alert"></span></div>
                        <input type="hidden" id="password-reset-context" value="resident">
                        <button type="submit" class="btn-solid full-width mt-3" id="password-reset-submit">Send reset link</button>
                    </form>
                    <form id="reset-password-form" class="hidden" data-action-submit="completePasswordReset(event)">
                        <div class="input-group"><label for="new-password">New password</label><input type="password" id="new-password" autocomplete="new-password" minlength="8" maxlength="256" required aria-describedby="reset-pw-checklist"><ul class="pw-checklist" id="reset-pw-checklist" aria-live="polite"><li id="reset-pwc-length" class="pwc-item"><i class="far fa-circle pwc-icon" aria-hidden="true"></i><span>8+ characters</span></li><li id="reset-pwc-upper" class="pwc-item"><i class="far fa-circle pwc-icon" aria-hidden="true"></i><span>Uppercase (A-Z)</span></li><li id="reset-pwc-lower" class="pwc-item"><i class="far fa-circle pwc-icon" aria-hidden="true"></i><span>Lowercase (a-z)</span></li><li id="reset-pwc-number" class="pwc-item"><i class="far fa-circle pwc-icon" aria-hidden="true"></i><span>Number (0-9)</span></li></ul></div>
                        <div class="input-group"><label for="confirm-new-password">Confirm new password</label><input type="password" id="confirm-new-password" autocomplete="new-password" minlength="8" maxlength="256" required aria-describedby="reset-pwc-match"><p class="pwc-match-line" id="reset-pwc-match" aria-live="polite"><i class="far fa-circle pwc-icon" aria-hidden="true"></i><span>Passwords match</span></p></div>
                        <button type="submit" class="btn-solid full-width mt-3" id="reset-password-submit">Save new password</button>
                    </form>
                    <p class="switch-form"><button type="button" class="text-button" data-action-click="returnFromPasswordReset()">Return to log in</button></p>
                </div></div>
            </div>
        </section>

        <section id="register" class="section auth-section" aria-labelledby="register-title">
            <div class="split-screen reg-split-screen">
                <div class="left-panel reg-left-panel" aria-hidden="true">
                    <div class="panel-content">
                        <img src="assets/barangay-seal.svg" alt="" class="panel-logo" width="128" height="128" loading="lazy" decoding="async">
                        <span class="badge">Resident verification</span>
                        <h1>Skip the trip to the hall.</h1>
                        <p>Register once, then track all your requests online.</p>
                        <div class="panel-footer">Barangay San Jose · <span id="year-2"></span></div>
                    </div>
                </div>
                <div class="right-panel reg-right-panel">
                    <div class="reg-form-container">
                        <button class="back-button" type="button" data-action-click="showSection('landing')"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to home</button>
                        <p class="eyebrow">Resident enrollment</p>
                        <h1 id="register-title">Create your account</h1>
                        <p class="form-subtitle">Create an account to browse the portal and track your document requests.<br><span class="reg-required-note">* Required. Middle name, suffix, civil status, gender, and phone number are optional. Verify your email before submitting a request.</span></p>

                        <form id="register-form" data-action-submit="handleRegister(event)" novalidate>
                            <div class="visually-hidden" aria-hidden="true"><label for="register-website">Leave this field empty</label><input id="register-website" name="website" tabindex="-1" autocomplete="off"></div>

                            <!-- Row 1: First (3) | Middle (3) | Last (3) | Suffix (3) -->
                            <p class="reg-group-label">Personal details</p>
                            <div class="reg-grid">
                                <div class="reg-field reg-col-3">
                                    <label for="register-first-name">First name <span class="req-star" aria-hidden="true">*</span></label>
                                    <input type="text" id="register-first-name" name="firstName" autocomplete="given-name" required aria-describedby="register-first-name-error">
                                    <span class="reg-field-error" id="register-first-name-error" role="alert"></span>
                                </div>
                                <div class="reg-field reg-col-3">
                                    <label for="register-middle-name">Middle name</label>
                                    <input type="text" id="register-middle-name" name="middleName" autocomplete="additional-name">
                                </div>
                                <div class="reg-field reg-col-3">
                                    <label for="register-last-name">Last name <span class="req-star" aria-hidden="true">*</span></label>
                                    <input type="text" id="register-last-name" name="lastName" autocomplete="family-name" required aria-describedby="register-last-name-error">
                                    <span class="reg-field-error" id="register-last-name-error" role="alert"></span>
                                </div>
                                <div class="reg-field reg-col-3">
                                    <label for="register-suffix">Suffix</label>
                                    <select id="register-suffix" name="suffix" autocomplete="honorific-suffix">
                                        <option value="">None</option>
                                        <option value="Jr.">Jr.</option>
                                        <option value="Sr.">Sr.</option>
                                        <option value="II">II</option>
                                        <option value="III">III</option>
                                        <option value="IV">IV</option>
                                    </select>
                                </div>
                            </div>

                            <p class="reg-group-label">Resident information</p>
                            <div class="reg-grid">
                                <div class="reg-field reg-col-4">
                                    <label for="register-birth-date">Birthdate <span class="req-star" aria-hidden="true">*</span></label>
                                    <input type="date" id="register-birth-date" name="birthDate" autocomplete="bday" required max="<?= (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d') ?>" aria-describedby="register-birth-date-hint">
                                    <span class="reg-hint" id="register-birth-date-hint">Age is calculated from this date when a document needs it.</span>
                                </div>
                                <div class="reg-field reg-col-4">
                                    <label for="register-civil-status">Civil status</label>
                                    <select id="register-civil-status" name="civilStatus"><option value="">Prefer not to provide</option><option>Single</option><option>Married</option><option>Widowed</option><option>Separated</option><option>Prefer not to say</option></select>
                                </div>
                                <div class="reg-field reg-col-4">
                                    <label for="register-gender">Gender</label>
                                    <select id="register-gender" name="gender"><option value="">Prefer not to provide</option><option>Female</option><option>Male</option><option>Other</option><option>Prefer not to say</option></select>
                                </div>
                            </div>

                            <!-- Row 2: Email (6) | Phone (6) -->
                            <p class="reg-group-label">Contact</p>
                            <div class="reg-grid">
                                <div class="reg-field reg-col-6">
                                    <label for="register-email">Email address <span class="req-star" aria-hidden="true">*</span></label>
                                    <input type="email" id="register-email" name="email" autocomplete="email" required placeholder="you@example.com" aria-describedby="register-email-error">
                                    <span class="reg-field-error" id="register-email-error" role="alert"></span>
                                </div>
                                <div class="reg-field reg-col-6">
                                    <label for="register-phone">Phone number</label>
                                    <input type="tel" id="register-phone" name="phone" autocomplete="tel" placeholder="09XXXXXXXXX" maxlength="13" aria-describedby="register-phone-error">
                                    <span class="reg-field-error" id="register-phone-error" role="alert"></span>
                                </div>
                            </div>

                            <!-- Address: no eyebrow, full-width -->
                            <div class="reg-section reg-address-section">
                                <div class="reg-grid">
                                    <div class="reg-field reg-col-12">
                                        <label for="register-address">Home address <span class="req-star" aria-hidden="true">*</span></label>
                                        <input type="text" id="register-address" name="address" autocomplete="street-address" required placeholder="House no., street, purok" aria-describedby="register-address-hint register-address-error">
                                        <span class="reg-hint" id="register-address-hint">e.g. 12 Rizal St., Purok 3, Barangay San Jose</span>
                                        <span class="reg-field-error" id="register-address-error" role="alert"></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Account -->
                            <div class="reg-section">
                                <p class="reg-group-label">Account</p>
                                <div class="reg-grid reg-grid-pw">
                                    <div class="reg-field reg-col-6">
                                        <label for="register-password">Password <span class="req-star" aria-hidden="true">*</span></label>
                                        <div class="password-field reg-pw-field">
                                            <input type="password" name="password" id="register-password" minlength="8" maxlength="256" autocomplete="new-password" required aria-describedby="register-pw-checklist register-password-error">
                                            <button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false" data-action-click="togglePassword('register-password', this)"><i class="fas fa-eye" aria-hidden="true"></i></button>
                                        </div>
                                        <div class="pw-strength-bar" aria-hidden="true">
                                            <span class="pw-strength-seg" id="pwseg-1"></span>
                                            <span class="pw-strength-seg" id="pwseg-2"></span>
                                            <span class="pw-strength-seg" id="pwseg-3"></span>
                                            <span class="pw-strength-seg" id="pwseg-4"></span>
                                        </div>
                                        <ul class="pw-checklist" id="register-pw-checklist" aria-live="polite">
                                            <li id="pwc-length"  class="pwc-item"><i class="far fa-circle pwc-icon" aria-hidden="true"></i><span>8+ characters</span></li>
                                            <li id="pwc-upper"  class="pwc-item"><i class="far fa-circle pwc-icon" aria-hidden="true"></i><span>Uppercase (A-Z)</span></li>
                                            <li id="pwc-lower"  class="pwc-item"><i class="far fa-circle pwc-icon" aria-hidden="true"></i><span>Lowercase (a-z)</span></li>
                                            <li id="pwc-number" class="pwc-item"><i class="far fa-circle pwc-icon" aria-hidden="true"></i><span>Number (0-9)</span></li>
                                        </ul>
                                        <span class="reg-field-error" id="register-password-error" role="alert"></span>
                                    </div>
                                    <div class="reg-field reg-col-6">
                                        <label for="register-confirm-password">Confirm password <span class="req-star" aria-hidden="true">*</span></label>
                                        <div class="password-field reg-pw-field">
                                            <input type="password" name="confirmPassword" id="register-confirm-password" minlength="8" maxlength="256" autocomplete="new-password" required aria-describedby="pwc-match register-confirm-password-error">
                                            <button type="button" class="password-toggle" aria-label="Show confirm password" aria-pressed="false" data-action-click="togglePassword('register-confirm-password', this)"><i class="fas fa-eye" aria-hidden="true"></i></button>
                                        </div>
                                        <p class="pwc-match-line" id="pwc-match" aria-live="polite"><i class="far fa-circle pwc-icon" aria-hidden="true"></i><span>Passwords match</span></p>
                                        <span class="reg-field-error" id="register-confirm-password-error" role="alert"></span>
                                    </div>
                                </div>
                                <div class="reg-consent-row">
                                    <label class="register-consent" id="register-consent-label">
                                        <input type="checkbox" id="register-terms-accepted" name="termsAccepted" value="1" required>
                                        <span>I agree to the <button type="button" class="text-button" data-action-click="openRegisterPolicy('terms')">Terms &amp; Conditions</button> and <button type="button" class="text-button" data-action-click="openRegisterPolicy('privacy-policy')">Privacy Policy</button>.</span>
                                    </label>
                                    <input type="hidden" name="privacyNoticeAcknowledged" value="1">
                                </div>
                                <div class="reg-error-summary hidden" id="reg-error-summary" role="alert"></div>
                                <?php if (!$localCaptchaBypass): ?><div class="g-recaptcha recaptcha-widget" data-sitekey="<?= htmlspecialchars($recaptchaSiteKey, ENT_QUOTES, 'UTF-8') ?>" data-size="invisible" id="recaptcha-register"></div><?php endif; ?>
                                <button type="submit" class="btn-solid reg-submit-btn" id="reg-submit-btn">Submit registration</button>
                                <p class="switch-form reg-login-link">Already registered? <button type="button" class="text-button" data-action-click="showSection('login')">Log in here</button></p>
                            </div>

                        </form>
                    </div>
                </div>
            </div>
        </section>

        <section id="resident-dashboard" class="section" aria-labelledby="resident-dashboard-title">
            <div class="page-shell workspace-shell">
                <aside id="email-verification-banner" class="email-verification-banner" role="status" hidden>
                    <div class="email-verification-message"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i><span>Check your inbox to verify your email before submitting a request.</span></div>
                    <button type="button" class="btn-outline" data-action-click="resendResidentVerification()">Resend verification</button>
                </aside>
                <div class="dashboard-header">
                    <div><p class="eyebrow">Resident workspace</p><h1 id="resident-dashboard-title">Document requests</h1><div class="resident-request-tabs" role="tablist" aria-label="Document request sections"><button type="button" id="resident-request-tab-new" role="tab" aria-selected="true" aria-controls="resident-request-panel-new" class="active" data-action-click="selectResidentRequestTab('new')">New request</button><button type="button" id="resident-request-tab-list" role="tab" aria-selected="false" aria-controls="resident-request-panel-list" data-action-click="selectResidentRequestTab('list')">My requests</button></div><p class="dashboard-welcome">Welcome back, <strong id="resident-name"></strong>. Submit a request or review its progress.</p></div>
                    <button class="btn-outline" type="button" data-action-click="showSection('bulletin')"><i class="fas fa-folder-open" aria-hidden="true"></i> View public records</button>
                </div>
                <div class="dashboard-grid resident-grid">
                    <div class="card active" id="resident-request-panel-new" data-request-panel="new" role="tabpanel" aria-labelledby="resident-request-tab-new">
                        <div class="card-heading"><span class="step-label">New request</span><h2>Request a document</h2><p class="form-hint request-required-note">All fields are required.</p></div>
                        <form id="request-form" class="resident-request-form" enctype="multipart/form-data" novalidate data-action-submit="handleRequest(event)">
                            <div class="input-group">
                                <label for="doc-type">Document type</label>
                                <select id="doc-type" name="documentTypeId" required aria-describedby="request-doc-type-error"><option value="" selected disabled>Select a document</option></select>
                                <span class="reg-field-error" id="request-doc-type-error" role="alert"></span>
                                <div id="doc-type-info" class="request-summary" aria-live="polite">
                                    <div class="request-summary-content">
                                        <span class="request-summary-label">Request summary</span>
                                        <p class="request-summary-placeholder">Select a document to view its details.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="input-group">
                                <label for="doc-purpose">Purpose</label>
                                <select id="doc-purpose" name="purposeCategory" required aria-describedby="request-purpose-error">
                                    <option value="" selected disabled>Select a purpose</option>
                                    <option value="Employment">Employment</option><option value="Scholarship">Scholarship</option><option value="Business permit">Business permit</option><option value="School requirement">School requirement</option><option value="Other">Other</option>
                                </select>
                                <span class="reg-field-error" id="request-purpose-error" role="alert"></span>
                                <div class="request-purpose-other" id="request-purpose-other-group" hidden>
                                    <input type="text" id="doc-purpose-other" maxlength="2000" placeholder="Enter your purpose" aria-label="Other purpose" aria-describedby="request-purpose-other-error">
                                    <span class="reg-field-error" id="request-purpose-other-error" role="alert"></span>
                                </div>
                            </div>
                            <fieldset id="online-business-fields" class="input-group business-request-fields" hidden>
                                <legend>Business details</legend>
                                <label for="request-business-name">Business name</label><input id="request-business-name" name="businessName" maxlength="180" autocomplete="organization" disabled>
                                <label for="request-business-category">Business registration type</label><select id="request-business-category" name="businessCategory" disabled><option value="">Choose type</option><option value="solo">Individual / sole proprietor</option><option value="organization">Organization / partnership / corporation</option></select>
                                <label for="request-business-address">Business address</label><input id="request-business-address" name="businessAddress" maxlength="255" disabled>
                                <label for="request-business-activity">Business activity</label><input id="request-business-activity" name="businessActivity" maxlength="180" placeholder="e.g. sari-sari store, food services" disabled>
                                <p class="form-hint">The barangay will confirm the applicable fee. A barangay business clearance does not replace a city or municipal business permit or required inspections.</p>
                            </fieldset>
                            <fieldset class="input-group request-proof-group">
                                <label for="request-proof-type">Proof of Residency</label>
                                <select id="request-proof-type" name="proofType" required aria-describedby="request-proof-type-error"><option value="" selected disabled>Select proof type</option><option value="National ID">National ID</option><option value="Certificate of Residency">Certificate of Residency</option></select>
                                <span class="reg-field-error" id="request-proof-type-error" role="alert"></span>
                                <button type="button" class="request-upload-area" id="request-proof-picker" aria-describedby="request-proof-guidance request-proof-error" data-action-click="openRequestProofPicker()"><i class="fas fa-camera" aria-hidden="true"></i><span>Choose a file or take a photo</span><small class="request-upload-guidance" id="request-proof-guidance">PDF, JPG, or PNG · maximum 5 MB</small></button>
                                <input type="file" id="request-proof" name="proof" class="request-proof-input" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/*" aria-hidden="true" tabindex="-1">
                                <div class="request-proof-preview" id="request-proof-preview" hidden>
                                    <img id="request-proof-thumbnail" alt="" hidden>
                                    <div class="request-proof-file-details"><strong id="request-proof-name"></strong><span id="request-proof-size"></span></div>
                                    <div class="request-proof-actions"><button type="button" data-action-click="removeRequestProof()">Remove</button><button type="button" data-action-click="openRequestProofPicker()">Replace</button></div>
                                </div>
                                <span class="reg-field-error request-proof-error" id="request-proof-error" role="alert"></span>
                                <p class="request-proof-privacy" id="request-proof-privacy"><i class="fas fa-lock" aria-hidden="true"></i><span class="request-proof-privacy-desktop">This uploaded proof may contain <strong>sensitive information</strong>. It is stored for the sample request workflow and available to project administrators; this prototype is not an official barangay service.</span><span class="request-proof-privacy-mobile">Your ID is only used to verify that you live in the barangay. It is not shown on public records.</span></p>
                            </fieldset>
                            <button id="request-submit" type="submit" class="btn-solid full-width" aria-describedby="request-submit-guidance request-verification-note">Submit request</button>
                            <p id="request-submit-guidance" class="request-submit-guidance"><span class="request-guidance-desktop">You can track this request under <a href="#resident-dashboard" data-action-click="openResidentProfileQuickLink('track'); return false">Track Request</a>.</span><span class="request-guidance-mobile">You can track this request under <a href="#resident-request-tab-list" data-action-click="selectResidentRequestTab('list'); return false">Track Request</a>.</span><span id="request-processing-guidance"></span></p>
                            <p id="request-verification-note" class="request-verification-note" hidden>Verify your email to submit a request.</p>
                            <p id="request-submit-error" class="reg-field-error request-submit-error" role="alert" hidden></p>
                        </form>
                        <div id="request-submit-confirmation" class="request-submit-confirmation" role="status" aria-live="polite" hidden></div>
                    </div>
                    <div class="card" id="resident-request-panel-list" data-request-panel="list" role="tabpanel" aria-labelledby="resident-request-tab-list">
                        <div class="card-heading"><span class="step-label">Request history</span><h2>Track my requests</h2><div class="request-filter-tabs" role="tablist" aria-label="Filter requests"><button type="button" id="request-filter-active" role="tab" aria-selected="true" aria-controls="request-list" class="active" data-action-click="selectResidentRequestStatusTab('active')">Active</button><button type="button" id="request-filter-completed" role="tab" aria-selected="false" aria-controls="request-list" data-action-click="selectResidentRequestStatusTab('completed')">Completed</button></div></div>
                        <div id="request-list" class="request-list" aria-live="polite"></div>
                    </div>
                </div>
            </div>
        </section>

        <template id="resident-profile-home-slot"></template>
        <section id="resident-profile" class="section" aria-labelledby="resident-profile-title">
            <div class="page-shell workspace-shell resident-profile-page">
                <div class="resident-profile-heading">
                    <button class="resident-profile-back" type="button" data-action-click="openDashboard()"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to dashboard</button>
                    <h1 id="resident-profile-title">My profile</h1>
                </div>
                <div class="resident-profile-identity" aria-label="Profile account">
                    <button type="button" class="resident-profile-avatar" id="resident-profile-avatar" aria-label="Change profile photo" data-action-click="openProfilePhotoPicker()"><span id="resident-profile-initials" aria-hidden="true"></span><img id="resident-profile-photo-image" alt="" hidden><span class="resident-profile-camera" aria-hidden="true"><i class="fas fa-camera"></i></span></button>
                    <div class="resident-profile-identity-copy">
                        <strong id="resident-profile-header-name"></strong>
                        <span id="resident-profile-header-email"></span>
                    </div>
                    <small id="resident-profile-account-label" class="admin-profile-account-pill">Barangay San Jose Resident Account</small>
                    <div class="resident-profile-photo-actions"><input id="resident-profile-photo-input" type="file" accept="image/jpeg,image/png,image/webp" hidden><button type="button" class="profile-text-action" data-action-click="openProfilePhotoPicker()">Change photo</button><button type="button" class="profile-text-action" id="resident-profile-photo-remove" data-action-click="removeProfilePhoto()" hidden>Remove photo</button><span id="resident-profile-photo-error" role="status" aria-live="polite"></span><div id="resident-profile-photo-preview-actions" hidden><button type="button" class="btn-solid" data-action-click="saveProfilePhoto()">Save photo</button><button type="button" class="btn-outline" data-action-click="cancelProfilePhotoPreview()">Cancel</button></div></div>
                </div>
                <div class="resident-profile-columns">
                    <section class="card resident-profile-card resident-profile-details-card" aria-labelledby="resident-profile-details-title">
                        <div class="card-heading resident-profile-card-heading"><h2 id="resident-profile-details-title">Your details</h2><span class="resident-record-label"><i class="fas fa-lock" aria-hidden="true"></i> Official record</span></div>
                        <dl class="resident-profile-details">
                            <div class="resident-profile-detail-row" id="resident-profile-phone-row"><span class="resident-profile-item-icon"><i class="fas fa-phone" aria-hidden="true"></i></span><div class="resident-profile-detail-copy"><dt>Phone number</dt><dd id="resident-profile-phone"></dd></div></div>
                            <div class="resident-profile-detail-row" id="resident-profile-address-row"><span class="resident-profile-item-icon"><i class="fas fa-house" aria-hidden="true"></i></span><div class="resident-profile-detail-copy"><dt>Home address</dt><dd id="resident-profile-address"></dd></div></div>
                            <div class="resident-profile-detail-row"><span class="resident-profile-item-icon"><i class="fas fa-cake-candles" aria-hidden="true"></i></span><div class="resident-profile-detail-copy"><dt>Birthdate / age</dt><dd id="resident-profile-birthdate"></dd></div></div>
                            <div class="resident-profile-detail-row"><span class="resident-profile-item-icon"><i class="fas fa-id-card" aria-hidden="true"></i></span><div class="resident-profile-detail-copy"><dt>Civil status</dt><dd id="resident-profile-civil-status"></dd></div></div>
                            <div class="resident-profile-detail-row"><span class="resident-profile-item-icon"><i class="fas fa-user" aria-hidden="true"></i></span><div class="resident-profile-detail-copy"><dt>Gender</dt><dd id="resident-profile-gender"></dd></div></div>
                        </dl>
                        <button type="button" class="btn-outline resident-profile-only resident-profile-edit-trigger" id="resident-profile-edit-details" data-action-click="openResidentProfileEdit()">Edit home address and personal details</button>
                        <form id="resident-profile-details-form" class="resident-profile-edit-form resident-profile-only" data-action-submit="saveResidentProfileDetails(event)" hidden>
                            <p class="field-help">These details prefill your own document requests. Age is calculated from your birthdate.</p>
                            <div class="input-group"><label for="resident-profile-address-input">Home address</label><textarea id="resident-profile-address-input" maxlength="255" rows="2" autocomplete="street-address" required aria-describedby="resident-profile-address-hint" data-action-input="updateResidentProfileSaveState()"></textarea><span class="field-help" id="resident-profile-address-hint">Enter your current house number, street, purok or sitio, and barangay.</span></div>
                            <div class="input-group"><label for="resident-profile-birthdate-input">Birthdate</label><input id="resident-profile-birthdate-input" type="date" required max="<?= (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d') ?>" autocomplete="bday" aria-describedby="resident-profile-birthdate-hint" data-action-input="updateResidentProfileSaveState()"><span class="field-help" id="resident-profile-birthdate-hint">Age will be recalculated automatically.</span></div>
                            <div class="input-group"><label for="resident-profile-gender-input">Gender</label><select id="resident-profile-gender-input" data-action-change="updateResidentProfileSaveState()"><option value="">Prefer not to provide</option><option value="Female">Female</option><option value="Male">Male</option><option value="Other">Other</option><option value="Prefer not to say">Prefer not to say</option></select></div>
                            <div class="resident-profile-edit-actions"><button type="submit" class="btn-solid" id="resident-profile-details-save" disabled>Save details</button><button type="button" class="btn-outline" data-action-click="cancelResidentProfileEdit()">Cancel</button><span id="resident-profile-details-feedback" role="status" aria-live="polite"></span></div>
                        </form>
                        <form id="admin-profile-details-form" class="admin-profile-form admin-profile-only" data-action-submit="saveAdminProfileDetails(event)" hidden>
                            <div class="resident-profile-detail-row admin-profile-edit-row"><span class="resident-profile-item-icon"><i class="fas fa-user-shield" aria-hidden="true"></i></span><div class="resident-profile-detail-copy"><span class="admin-profile-row-label">Account type</span><span class="admin-profile-readonly-value">Administrator<i class="fas fa-lock" aria-hidden="true"></i></span></div></div>
                            <div class="resident-profile-detail-row admin-profile-edit-row"><span class="resident-profile-item-icon"><i class="fas fa-briefcase" aria-hidden="true"></i></span><div class="resident-profile-detail-copy"><label for="admin-profile-position">Position or office</label><input id="admin-profile-position" maxlength="120" placeholder="e.g. Barangay Secretary" data-action-input="updateAdminProfileDetailsSaveState()"></div></div>
                            <div class="resident-profile-detail-row admin-profile-edit-row"><span class="resident-profile-item-icon"><i class="fas fa-phone" aria-hidden="true"></i></span><div class="resident-profile-detail-copy"><label for="admin-profile-contact">Contact number</label><input id="admin-profile-contact" maxlength="30" type="tel" placeholder="09XXXXXXXXX" data-action-input="updateAdminProfileDetailsSaveState()"></div></div>
                            <div class="admin-profile-details-actions"><button type="submit" class="btn-solid" id="admin-profile-details-save" disabled>Save details</button><span class="admin-profile-save-feedback" id="admin-profile-details-saved" role="status" aria-live="polite" hidden><i class="fas fa-circle-check" aria-hidden="true"></i> Details saved</span></div>
                        </form>
                        <p class="resident-profile-correction-note" id="resident-profile-correction-note"><i class="fas fa-circle-info" aria-hidden="true"></i><span>To correct your name or phone number, visit the barangay office. You can update your home address, birthdate, and gender here.</span></p>
                    </section>
                    <section class="card resident-profile-card resident-security-card" aria-labelledby="resident-security-title">
                        <div class="card-heading"><h2 id="resident-security-title">Login and security</h2></div>
                        <div class="resident-security-rows">
                            <section class="resident-security-row" aria-labelledby="resident-email-summary-label"><div class="resident-security-summary"><div class="resident-security-leading"><span class="resident-profile-item-icon"><i class="fas fa-envelope" aria-hidden="true"></i></span><div class="resident-security-copy"><h3 id="resident-email-summary-label">Email</h3><p><span class="resident-email-status" id="resident-profile-email-summary-status"></span><button type="button" class="resident-profile-resend hidden" id="resident-profile-resend" data-action-click="resendResidentVerification()">Resend verification</button></p></div></div><button class="btn-outline resident-security-change" type="button" aria-label="Change email" data-action-click="showSection('resident-change-email')">Change email</button></div></section>
                            <section class="resident-security-row" aria-labelledby="resident-password-summary-label"><div class="resident-security-summary"><div class="resident-security-leading"><span class="resident-profile-item-icon"><i class="fas fa-key" aria-hidden="true"></i></span><div class="resident-security-copy"><h3 id="resident-password-summary-label">Password</h3><p class="resident-password-dots" aria-label="Password set">••••</p><small class="resident-password-last-changed">Last changed: Not available</small></div></div><button class="btn-outline resident-security-change" type="button" aria-label="Change password" data-action-click="showSection('resident-change-password')">Change password</button></div></section>
                            <section class="resident-security-row admin-profile-mfa-row admin-profile-only" aria-labelledby="admin-profile-mfa-title" hidden>
                                <div class="resident-security-summary"><div class="resident-security-leading"><span class="resident-profile-item-icon"><i class="fas fa-shield-halved" aria-hidden="true"></i></span><div class="resident-security-copy"><div class="admin-profile-subheading"><h3 id="admin-profile-mfa-title">Two-factor authentication</h3><span class="admin-profile-enabled-badge" id="admin-profile-mfa-enabled-badge" hidden>Enabled</span></div><p id="admin-profile-mfa-status">Loading status…</p></div></div></div>
                                <div class="admin-profile-actions"><button type="button" class="btn-outline" id="admin-profile-mfa-setup" data-action-click="beginAdminProfileMfa()">Set up authenticator</button><button type="button" class="btn-outline admin-profile-danger-button" id="admin-profile-mfa-reset" data-action-click="beginAdminProfileMfa()" hidden>Reset authenticator</button><button type="button" class="btn-outline" id="admin-profile-mfa-recovery" data-action-click="generateAdminProfileMfaRecoveryCodes()" hidden>Generate recovery codes</button></div>
                                <div id="admin-profile-mfa-setup-box" hidden><p id="admin-profile-mfa-secret-hint">Add this key to your authenticator app:</p><div class="admin-mfa-secret-copy"><code id="admin-profile-mfa-secret"></code><button type="button" class="btn-outline admin-mfa-copy-button" data-action-click="copyAuthenticatorSetupKey('admin-profile-mfa-secret')"><i class="fas fa-copy" aria-hidden="true"></i> Copy key</button></div><form id="admin-profile-mfa-confirm-form" data-action-submit="confirmAdminProfileMfa(event)"><label>Current 6-digit code<input id="admin-profile-mfa-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required></label><button type="submit" class="btn-solid">Confirm authenticator</button><button type="button" class="btn-outline" data-action-click="cancelAdminProfileMfa()">Cancel</button></form></div>
                            </section>
                        </div>
                    </section>
                </div>
                <section class="resident-profile-quick-links" aria-labelledby="resident-profile-quick-links-title">
                    <h2 id="resident-profile-quick-links-title">Quick links</h2>
                    <div class="resident-profile-quick-link-grid">
                        <button type="button" class="resident-profile-quick-link" data-action-click="openResidentProfileQuickLink('request')"><span class="resident-profile-quick-link-icon"><i class="fas fa-file-circle-plus" aria-hidden="true"></i></span><span><strong>Request a document</strong><small>Start a new document request.</small></span><i class="fas fa-arrow-right resident-profile-quick-link-arrow" aria-hidden="true"></i></button>
                        <button type="button" class="resident-profile-quick-link" data-action-click="openResidentProfileQuickLink('track')"><span class="resident-profile-quick-link-icon"><i class="fas fa-list-check" aria-hidden="true"></i></span><span><strong>Track my requests</strong><small>Review your request status and updates.</small></span><i class="fas fa-arrow-right resident-profile-quick-link-arrow" aria-hidden="true"></i></button>
                        <button type="button" class="resident-profile-quick-link" data-action-click="openResidentProfileQuickLink('records')"><span class="resident-profile-quick-link-icon"><i class="fas fa-folder-open" aria-hidden="true"></i></span><span><strong>Public records</strong><small>Browse announcements and public records.</small></span><i class="fas fa-arrow-right resident-profile-quick-link-arrow" aria-hidden="true"></i></button>
                    </div>
                </section>
                <section class="card resident-profile-card admin-profile-only" id="admin-profile-permissions-card" hidden aria-labelledby="admin-profile-permissions-title">
                    <div class="card-heading"><h2 id="admin-profile-permissions-title">Permissions</h2></div>
                    <ul id="admin-profile-permissions" class="admin-profile-list admin-profile-permissions-list"></ul>
                </section>
                <section class="card resident-profile-card admin-profile-only" id="admin-profile-security-card" hidden aria-labelledby="admin-profile-security-title">
                    <div class="card-heading"><h2 id="admin-profile-security-title">Administrator security</h2></div>
                    <div class="admin-profile-security-block"><h3>Active sessions</h3><ul id="admin-profile-sessions" class="admin-profile-list admin-profile-session-list"></ul><button type="button" class="btn-outline admin-profile-danger-button" id="admin-profile-revoke-sessions" data-action-click="revokeOtherAdminSessions()" hidden>Log out of all other devices</button></div>
                    <div class="admin-profile-security-block"><h3>Recent login activity</h3><ul id="admin-profile-signins" class="admin-profile-list admin-profile-activity-list"></ul><a class="admin-profile-view-all" href="admin_activity.php#admin-activity">View all</a></div>
                </section>
                <section class="card resident-profile-card admin-profile-only" id="admin-profile-notifications-card" hidden aria-labelledby="admin-profile-notifications-title">
                    <div class="card-heading"><h2 id="admin-profile-notifications-title">Notifications</h2></div>
                    <form id="admin-profile-notifications-form" data-action-submit="saveAdminNotificationPreferences(event)">
                        <label class="admin-profile-toggle-row" for="admin-notify-requests"><span class="admin-profile-toggle-copy"><strong>Email me about new requests</strong><small>Get an email when a resident submits a request.</small></span><input type="checkbox" id="admin-notify-requests" data-action-change="updateAdminNotificationSaveState()"><span class="admin-profile-switch" aria-hidden="true"></span></label>
                        <label class="admin-profile-toggle-row" for="admin-notify-payments"><span class="admin-profile-toggle-copy"><strong>Email me when residents submit payment proof</strong><small>Get an email when a paid request needs your review.</small></span><input type="checkbox" id="admin-notify-payments" data-action-change="updateAdminNotificationSaveState()"><span class="admin-profile-switch" aria-hidden="true"></span></label>
                        <button type="submit" class="btn-outline" id="admin-profile-notifications-save" disabled>Save preferences</button>
                    </form>
                </section>
                <section class="card resident-profile-card admin-profile-only" id="admin-profile-actions-card" hidden aria-labelledby="admin-profile-actions-title"><div class="card-heading"><h2 id="admin-profile-actions-title">My recent actions</h2><a class="admin-profile-view-all" href="admin_activity.php#admin-activity">Full activity log</a></div><ul id="admin-profile-recent-actions" class="admin-profile-list admin-profile-activity-list"></ul></section>
                <section class="resident-profile-quick-links admin-profile-only" id="admin-profile-quick-links" hidden aria-labelledby="admin-profile-quick-links-title"><h2 id="admin-profile-quick-links-title">Quick links</h2><div class="resident-profile-quick-link-grid"><button type="button" class="resident-profile-quick-link" data-action-click="showSection('admin-dashboard', false, 'requests')"><span class="resident-profile-quick-link-icon"><i class="fas fa-file-lines"></i></span><span><strong>Requests queue</strong><small>Review and update document requests.</small></span><i class="fas fa-arrow-right resident-profile-quick-link-arrow"></i></button><button type="button" class="resident-profile-quick-link" data-action-click="showSection('admin-dashboard', false, 'residents')"><span class="resident-profile-quick-link-icon"><i class="fas fa-users"></i></span><span><strong>Resident accounts</strong><small>Review registered resident accounts.</small></span><i class="fas fa-arrow-right resident-profile-quick-link-arrow"></i></button><button type="button" class="resident-profile-quick-link" data-action-click="showSection('admin-dashboard', false, 'disclosures')"><span class="resident-profile-quick-link-icon"><i class="fas fa-bullhorn"></i></span><span><strong>Post disclosure</strong><small>Publish an official public notice.</small></span><i class="fas fa-arrow-right resident-profile-quick-link-arrow"></i></button></div></section>
            </div>
        </section>
        <section id="resident-change-email" class="section auth-section resident-security-page" aria-labelledby="resident-change-email-title">
            <div class="split-screen">
                <div class="left-panel" aria-hidden="true">
                    <div class="panel-content">
                        <img src="assets/barangay-seal.svg" alt="" class="panel-logo" width="128" height="128" loading="lazy" decoding="async">
                        <span class="badge">Secure resident services</span>
                        <h1>Digital Disclosure &amp; Document Request Portal</h1>
                        <p>Manage your account email and keep your document requests up to date.</p>
                        <div class="panel-footer">Barangay San Jose · <span id="resident-change-email-year"></span></div>
                    </div>
                </div>
                <div class="right-panel">
                    <button class="back-button" type="button" data-action-click="showSection('resident-profile')"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to profile</button>
                    <div class="form-wrapper">
                        <p class="eyebrow">Login and security</p>
                        <h1 id="resident-change-email-title">Change email</h1>
                        <p class="form-subtitle">Enter your new email address and current password.</p>
                        <form id="resident-email-form" data-action-submit="changeResidentEmail(event)" novalidate>
                            <div class="input-group"><label for="resident-new-email">New email</label><input type="email" id="resident-new-email" name="email" autocomplete="email" placeholder="Enter your new email address" required aria-describedby="resident-new-email-hint resident-new-email-error"><span class="form-hint" id="resident-new-email-hint">We'll send a verification link to your new email. Your current email stays active until you verify the new one.</span><span class="reg-field-error" id="resident-new-email-error" role="alert"></span></div>
                            <div class="input-group"><label for="resident-email-password">Your password</label><div class="password-field"><input type="password" id="resident-email-password" name="password" autocomplete="current-password" placeholder="Enter your password" required aria-describedby="resident-email-password-error resident-email-password-hint"><button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false" data-action-click="togglePassword('resident-email-password', this)"><i class="fas fa-eye" aria-hidden="true"></i></button></div><span class="form-hint" id="resident-email-password-hint">We ask for your password to confirm it's you.</span><span class="reg-field-error" id="resident-email-password-error" role="alert"></span></div>
                            <button type="submit" class="btn-solid full-width mt-3" id="resident-email-submit" disabled>Send verification link</button>
                            <p class="resident-email-feedback" id="resident-email-feedback" role="status" aria-live="polite"></p>
                        </form>
                    </div>
                </div>
            </div>
        </section>

        <section id="resident-change-password" class="section auth-section resident-security-page" aria-labelledby="resident-change-password-title">
            <div class="split-screen">
                <div class="left-panel" aria-hidden="true">
                    <div class="panel-content">
                        <img src="assets/barangay-seal.svg" alt="" class="panel-logo" width="128" height="128" loading="lazy" decoding="async">
                        <span class="badge">Account security</span>
                        <h1>Secure access to your portal account.</h1>
                        <p>Choose a new password for your Barangay San Jose account.</p>
                        <div class="panel-footer">Barangay San Jose · <span id="resident-change-password-year"></span></div>
                    </div>
                </div>
                <div class="right-panel">
                    <button class="back-button" type="button" data-action-click="showSection('resident-profile')"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to profile</button>
                    <div class="form-wrapper">
                        <p class="eyebrow">Login and security</p>
                        <h1 id="resident-change-password-title">Change password</h1>
                        <p class="form-subtitle">Create a new password for your Barangay San Jose portal account.</p>
                        <p class="login-error hidden" id="resident-password-error" role="alert" aria-live="polite"></p>
                        <p class="password-reset-success hidden" id="resident-password-success" role="status" aria-live="polite"></p>
                        <form id="resident-password-form" data-action-submit="changeResidentPassword(event)" novalidate>
                            <div class="input-group">
                                <label for="resident-new-password">New password</label>
                                <div class="password-field"><input type="password" id="resident-new-password" name="password" autocomplete="new-password" minlength="8" maxlength="256" required aria-describedby="resident-change-pw-checklist resident-new-password-error"><button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false" data-action-click="togglePassword('resident-new-password', this)"><i class="fas fa-eye" aria-hidden="true"></i></button></div>
                                <ul class="pw-checklist" id="resident-change-pw-checklist" aria-live="polite"><li id="resident-change-pwc-length" class="pwc-item"><i class="far fa-circle pwc-icon" aria-hidden="true"></i><span>8+ characters</span></li><li id="resident-change-pwc-upper" class="pwc-item"><i class="far fa-circle pwc-icon" aria-hidden="true"></i><span>Uppercase (A-Z)</span></li><li id="resident-change-pwc-lower" class="pwc-item"><i class="far fa-circle pwc-icon" aria-hidden="true"></i><span>Lowercase (a-z)</span></li><li id="resident-change-pwc-number" class="pwc-item"><i class="far fa-circle pwc-icon" aria-hidden="true"></i><span>Number (0-9)</span></li></ul>
                                <span class="reg-field-error" id="resident-new-password-error" role="alert"></span>
                            </div>
                            <div class="input-group">
                                <label for="resident-confirm-new-password">Confirm new password</label>
                                <div class="password-field"><input type="password" id="resident-confirm-new-password" name="confirmation" autocomplete="new-password" minlength="8" maxlength="256" required aria-describedby="resident-change-pwc-match"><button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false" data-action-click="togglePassword('resident-confirm-new-password', this)"><i class="fas fa-eye" aria-hidden="true"></i></button></div>
                                <p class="pwc-match-line" id="resident-change-pwc-match" aria-live="polite"><i class="far fa-circle pwc-icon" aria-hidden="true"></i><span>Passwords match</span></p>
                            </div>
                            <button type="submit" class="btn-solid full-width mt-3" id="resident-password-submit" disabled>Save new password</button>
                        </form>
                        <p class="switch-form"><button type="button" class="text-button" data-action-click="showSection('resident-profile')">Back to profile</button></p>
                    </div>
                </div>
            </div>
        </section>

        <?php require __DIR__ . '/includes/admin/dashboard.php'; ?>        <section id="privacy-policy" class="section policy-section" aria-labelledby="privacy-policy-title">
            <div class="policy-progress-bar" aria-hidden="true"><div class="policy-progress-fill"></div></div>
            <button class="back-button policy-back policy-back-mobile" type="button" data-action-click="showSection('landing')"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to home</button>
            <div class="policy-layout">
                <nav class="policy-toc" aria-label="Privacy Policy sections">
                    <button class="back-button policy-back" type="button" data-action-click="showSection('landing')"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to home</button>
                    <p class="policy-toc-label">On this page</p>
                    <ol class="policy-toc-list">
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('pp-collect')">Information we collect</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('pp-use')">How we use it</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('pp-accounts')">Accounts &amp; uploads</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('pp-retention')">Data retention</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('pp-security')">Security</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('pp-third')">Third-party services</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('pp-cookies')">Cookies</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('pp-rights')">Your rights</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('pp-changes')">Changes</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('pp-project-contact')">Responsible project contact</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('pp-contact')">Contact</button></li>
                    </ol>
                </nav>
                <div class="policy-page">
                    <header class="policy-header policy-privacy">
                        <p class="eyebrow">Legal</p>
                        <h1 id="privacy-policy-title">Privacy Policy</h1>
                    <p class="policy-updated"><span class="policy-updated-date"><i class="fas fa-circle-check" aria-hidden="true"></i> Last updated October 27, 2026</span></p></header>
                    <p class="policy-lead">This is an academic prototype, not an official barangay service. It may process information entered into its forms as described below. This notice models the protections expected for real personal information under the Philippine Data Privacy Act of 2012 (Republic Act No. 10173); the academic-project notice does not mean submitted data is fictional or automatically deleted.</p>

                    <h2 id="pp-collect">Information we collect</h2>
                    <p>Depending on the features you use, the portal may collect:</p>
                    <ul>
                        <li>Identity and account details, including your name, email address, optional phone number, residential address, date of birth, and optional civil status and gender. Age is calculated from the stored date of birth when displayed; age is not stored as a separate value.</li>
                        <li>Profile photos you choose to upload</li>
                        <li>Proof-of-residency files required for online requests, which may contain information from a government-issued ID</li>
                        <li>Payment reference numbers and receipt files submitted for review on paid document requests</li>
                        <li>Document request information, including the document type, stated purpose, tracking number, status, and related processing or payment status</li>
                        <li>Business request details such as the business name, type, address, and activity when a business clearance is requested</li>
                        <li>Request-linked collection records, including assessed amount, payment channel, receipt reference, staff account, and any correction or reversal</li>
                        <li>Staff directory information entered by a super-administrator, including staff name, position, contact details, and linked portal role</li>
                        <li>Document sample preparation records, including a sample document number, request reference, staff account, preparation time, and a content hash</li>
                        <li>Registration, verification, request, and account activity dates and records</li>
                        <li>Technical and security information, such as IP address, browser or device details, session information, rate-limit records, audit entries, and server or error logs</li>
                        <li>Browser storage: a language preference in local storage and a temporary session copy of an administrator disclosure draft when needed; saved drafts are also stored with the administrator account</li>
                        <li>Messages and contact details you send to the project through the portal or listed email</li>
                    </ul>
                    <p>Information entered into the forms is handled as potentially real personal information. Do not provide another person's information unless you are authorized to do so. The academic-project label does not guarantee that submissions are test data.</p>

                    <h2 id="pp-use">How we use it</h2>
                    <p>The prototype uses account details to create and secure an account, verify email, and support account recovery. It uses resident information to pre-fill a resident’s own request, request details and residency proofs to review requests, and payment references and receipt files to reconcile request-linked fees. Business details support business-clearance requests. A staff directory supports internal contact and role assignment. A watermarked sample document may be previewed or printed after the request is approved and the fee is confirmed. Administrators who opt in may receive an email when a resident submits payment proof. Technical records help secure and maintain the application. The prototype does not offer advertising or analytics features.</p>
                    <p>This academic prototype has not documented a final lawful-basis assessment for each processing purpose or a specific legal basis for processing government-issued ID information. This notice describes the project’s intended handling but does not by itself establish a lawful basis. Any real operator must complete that assessment before live use. Registration records acceptance of the Terms and acknowledgment of this Privacy Notice with one checkbox; that checkbox is not blanket consent for every use of information.</p>

                    <h2 id="pp-accounts">Accounts &amp; uploads</h2>
                    <p>Residents can create an account and sign in without administrator approval. Email verification is required before submitting an online document request. The request form requires a National ID or Certificate of Residency. Residents can review and update their home address, birthdate, and gender in their profile; age is recalculated from the birthdate. Access is role-limited: service clerks can review resident accounts and request proofs; cashiers can view payment and collection details; document issuers can access only the request information needed for document preparation; and super-administrators can manage staff roles. Walk-in request details are entered by an administrator to demonstrate the in-person workflow. Do not use another person’s account to request a document for a child or other resident; the prototype does not yet provide a verified representative or guardian workflow.
                    </p>
                    <p>The portal currently does not provide blotter-record or 4Ps-data modules. Do not enter those sensitive records in request purposes, notes, uploads, or other free-text fields. The printable output is permanently marked as a sample and is not an official barangay document.</p>
                    <p>Request proofs, payment receipts, and disclosure attachments are stored outside the public web root by default. Request proofs and payment receipts are served only through administrator-only endpoints. Published disclosure attachments are intentionally available to the public; administrators should not publish an attachment containing personal information unless disclosure is authorized and necessary.</p>

                    <h2 id="pp-retention">Data retention</h2>
                    <p>Unverified accounts with no request are marked archived after 30 days. Archiving disables access but does not itself delete the account record. Password-reset tokens expire after one hour and email-verification links after 24 hours; expired tokens may remain in the database until cleanup runs. Successfully sent email bodies are cleared and their queue records are removed after 30 days.</p>
                    <p>When an administrator deletes a resident account, the application deletes the resident record, linked document requests, residency and payment proof files, profile photo, and queued messages associated with the account. Related request audit details are redacted, while a non-identifying deletion event remains in the audit log. This action does not establish an automatic deletion schedule for other resident accounts, audit logs, failed email metadata, disclosure attachments, or backups. No general retention period for those records has been approved, so they may remain until manually deleted or otherwise removed by the project operator. A real service must set and implement periods consistent with applicable barangay records rules before accepting operational data.</p>

                    <h2 id="pp-security">Security</h2>
                    <p>The application includes password hashing, role-based access checks, administrator multi-factor authentication, session protections, request protections, and restricted storage for request proofs. These are application controls, not a guarantee about the live host, database permissions, staff practices, or backups. A production operator must verify those controls and maintain an incident-response process. If a breach meets the legal notification requirements, the responsible controller must notify affected people and the National Privacy Commission as required by law. Report suspected exposure to the contact below.</p>

                    <h2 id="pp-third">Third-party services</h2>
                    <p>The application is configured to use a website host and database, an SMTP email-delivery service, and Google reCAPTCHA for automated-abuse checks. Those providers may process the technical information or email content needed for their services. The specific hosting and email providers, their processing locations, and the applicable service agreements depend on deployment settings and are not identified in this codebase. A real operator must identify them and confirm whether information is processed outside the Philippines before launch.</p>

                    <h2 id="pp-cookies">Cookies</h2>
                    <p>The portal uses an essential session cookie to keep you signed in and protect account actions. Google reCAPTCHA may use cookies or similar technical signals when its checks run. The application saves the selected language in local storage, saves administrator disclosure drafts to the administrator's account, and may keep a temporary session copy while recovering unsaved draft changes. The codebase contains no optional advertising or analytics cookies. Blocking cookies may prevent sign-in and protected features from working.</p>

                    <h2 id="pp-rights">Your rights</h2>
                    <p>Subject to the conditions and exceptions in RA 10173, you may ask to be informed about processing; access or receive a copy of your personal information; correct inaccurate or incomplete information; object to or withdraw consent from certain processing; request erasure or blocking where allowed; request data portability where applicable; and file a complaint with the National Privacy Commission. You may also seek damages where the law provides that right.</p>
                    <p>To make a privacy request or report suspected misuse, email the project contact below. Identify the account or request and explain what you are asking us to do. We may request reasonable information to verify your identity. The project contact will review the request and explain any legal or records obligation that limits what can be done. You may also file a complaint with the National Privacy Commission under its applicable process.</p>

                    <h2 id="pp-changes">Changes</h2>
                    <p>We may revise this policy when our services, practices, or legal obligations change. The revised version and its effective or last-updated date will be posted on this page. For material changes, we will provide notice through the portal or by email when reasonably practicable.</p>

                    <h2 id="pp-project-contact">Responsible project contact</h2>
                    <p>Barangay San Jose Digital Portal academic project. Send privacy questions or requests to <a href="mailto:deleonjuliusakeem@gmail.com">deleonjuliusakeem@gmail.com</a>. A formally designated Data Protection Officer or real barangay personal information controller is not identified by this prototype.</p>

                    <h2 id="pp-contact">Contact</h2>
                    <div class="policy-contact-card">
                        <span class="policy-contact-icon"><i class="fas fa-envelope" aria-hidden="true"></i></span>
                        <div class="policy-contact-body">
                            <strong>Barangay San Jose Digital Portal</strong>
                            <a href="mailto:deleonjuliusakeem@gmail.com">deleonjuliusakeem@gmail.com</a>
                        </div>
                    </div>

                    <nav class="policy-related" aria-label="Related policies">
                        <p class="policy-related-label">Related</p>
                        <button type="button" data-action-click="showSection('terms')">Terms &amp; Conditions</button>
                        <button type="button" data-action-click="showSection('moderation')">Moderation Policy</button>
                        <button type="button" data-action-click="showSection('copyright')">Copyright Notice</button>
                    </nav>
                </div>
            </div>
        </section>

        <section id="terms" class="section policy-section" aria-labelledby="terms-title">
            <div class="policy-progress-bar" aria-hidden="true"><div class="policy-progress-fill"></div></div>
            <button class="back-button policy-back policy-back-mobile" type="button" data-action-click="showSection('landing')"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to home</button>
            <div class="policy-layout">
                <nav class="policy-toc" aria-label="Terms sections">
                    <button class="back-button policy-back" type="button" data-action-click="showSection('landing')"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to home</button>
                    <p class="policy-toc-label">On this page</p>
                    <ol class="policy-toc-list">
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('tc-acceptance')">Acceptance</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('tc-accounts')">Accounts &amp; services</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('tc-responsibilities')">Your responsibilities</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('tc-fees')">Fees and pickup</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('tc-admin')">Admin rights</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('tc-warranties')">No warranties</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('tc-liability')">Liability</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('tc-third')">Third-party content</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('tc-changes')">Changes</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('tc-termination')">Termination</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('tc-law')">Governing law</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('tc-contact')">Contact</button></li>
                    </ol>
                </nav>
                <div class="policy-page">
                    <header class="policy-header policy-terms">
                        <p class="eyebrow">Legal</p>
                        <h1 id="terms-title">Terms &amp; Conditions</h1>
                    <p class="policy-updated"><span class="policy-updated-date"><i class="fas fa-circle-check" aria-hidden="true"></i> Last updated October 23, 2026</span></p></header>
                    <p class="policy-lead">This is an academic prototype, not an official barangay service. By creating an account or using its account features, you agree to these Terms. The separate Privacy Notice explains how information entered into the prototype is handled.</p>

                    <h2 id="tc-acceptance">Acceptance</h2>
                    <p>By creating an account or using account features, you agree to these Terms. If you do not agree, do not create an account. These Terms apply together with the Privacy Policy and any instructions shown for a feature. Registration uses one checkbox to record agreement to these Terms and acknowledgment of the Privacy Policy; that acknowledgment is not consent to optional uses of personal information.</p>

                    <h2 id="tc-accounts">Accounts &amp; services</h2>
                    <p>The prototype allows users to register, submit proof of residency with an online request, track requests, and view sample public disclosures. Users may sign in and browse before verifying their email, but must verify it before submitting an online request. This academic prototype cannot issue an official barangay document or guarantee a real barangay service. Provide accurate information for the exercise and keep your password and authenticator codes confidential.</p>

                    <h2 id="tc-responsibilities">Your responsibilities</h2>
                    <p>You agree not to:</p>
                    <ul>
                        <li>Provide false, misleading, or fraudulent registration details</li>
                        <li>Upload forged or altered proof-of-residency documents</li>
                        <li>Impersonate another person or submit requests on their behalf without authority</li>
                        <li>Use the portal for illegal activity or to harm others</li>
                        <li>Upload files containing malware, or attempt to bypass portal security, access controls, or rate limits</li>
                        <li>Upload or share copyrighted material without permission or another lawful basis</li>
                        <li>Share another person's sensitive information without authority or a lawful purpose</li>
                    </ul>

                    <h2 id="tc-fees">Fees and pickup</h2>
                    <p>Document types and payment-status fields in this prototype are examples for the academic exercise. The prototype does not process or collect payments or issue official documents. For paid requests, residents can submit a reference and receipt for administrator review after paying through a method confirmed directly with the barangay. Listed fees must be confirmed before real use. Free requests do not require payment proof.</p>

                    <h2 id="tc-admin">Administration rights</h2>
                    <p>Project administrators may review submitted files and requests to demonstrate a review workflow. Walk-in records are sample entries. No decision in this prototype is an official eligibility determination, and no request can result in an official document. If you have a concern about a project account or sample request, contact the project email below.</p>

                    <h2 id="tc-warranties">No warranties</h2>
                    <p>This academic prototype may be unavailable, delayed, or changed for maintenance, security, connectivity, or project reasons. We do not warrant uninterrupted or error-free access. A portal status or confirmation is not an official decision or government document.</p>

                    <h2 id="tc-liability">Liability</h2>
                    <p>To the extent permitted by Philippine law, the project operator is not responsible for losses caused by a user's failure to protect account credentials, inaccurate information supplied by a user, third-party services outside the project's control, or interruptions beyond reasonable control. Nothing in these Terms excludes liability or a remedy that cannot lawfully be excluded, limits rights provided by law, or excuses a party from its legal duties.</p>

                    <h2 id="tc-third">Third-party content</h2>
                    <p>User-submitted content remains the responsibility of the person who submits it. External websites and third-party services are governed by their own terms and privacy notices; we do not control their availability or practices. We may review or remove portal content as described in the Moderation Policy.</p>

                    <h2 id="tc-changes">Changes</h2>
                    <p>We may revise these Terms or change portal features to reflect service or legal changes. The current version and its date will be posted here. We will provide notice of material changes through the portal or by email when reasonably practicable. Changes apply from the posted effective date, subject to any rights that applicable law gives you.</p>

                    <h2 id="tc-termination">Termination</h2>
                    <p>We may restrict, suspend, or close access when reasonably necessary for security, legal compliance, service operation, or a violation of these Terms. Where practicable, we will explain the reason and how to contact us about the decision. Closing access does not remove records that must be retained for a lawful purpose.</p>

                    <h2 id="tc-law">Governing law</h2>
                    <p>These Terms are governed by the laws of the Republic of the Philippines. Any dispute will be handled by the appropriate Philippine authority or court, subject to applicable law.</p>

                    <h2 id="tc-contact">Contact</h2>
                    <div class="policy-contact-card">
                        <span class="policy-contact-icon"><i class="fas fa-envelope" aria-hidden="true"></i></span>
                        <div class="policy-contact-body">
                            <strong>Barangay San Jose Digital Portal academic project</strong>
                            <a href="mailto:deleonjuliusakeem@gmail.com">deleonjuliusakeem@gmail.com</a>
                        </div>
                    </div>

                    <nav class="policy-related" aria-label="Related policies">
                        <p class="policy-related-label">Related</p>
                        <button type="button" data-action-click="showSection('privacy-policy')">Privacy Policy</button>
                        <button type="button" data-action-click="showSection('moderation')">Moderation Policy</button>
                        <button type="button" data-action-click="showSection('copyright')">Copyright Notice</button>
                    </nav>
                </div>
            </div>
        </section>

        <section id="moderation" class="section policy-section" aria-labelledby="moderation-title">
            <div class="policy-progress-bar" aria-hidden="true"><div class="policy-progress-fill"></div></div>
            <button class="back-button policy-back policy-back-mobile" type="button" data-action-click="showSection('landing')"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to home</button>
            <div class="policy-layout">
                <nav class="policy-toc" aria-label="Moderation Policy sections">
                    <button class="back-button policy-back" type="button" data-action-click="showSection('landing')"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to home</button>
                    <p class="policy-toc-label">On this page</p>
                    <ol class="policy-toc-list">
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('mod-prohibited')">Prohibited content</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('mod-review')">How we review</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('mod-reporting')">Reporting problems</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('mod-removal')">Removal &amp; rejection</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('mod-repeat')">Repeat violations</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('mod-approval')">Decisions and review</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('mod-responsibility')">User responsibility</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('mod-contact')">Contact</button></li>
                    </ol>
                </nav>
                <div class="policy-page">
                    <header class="policy-header policy-moderation">
                        <p class="eyebrow">Legal</p>
                        <h1 id="moderation-title">Moderation Policy</h1>
                        <p class="policy-updated"><span class="policy-updated-date"><i class="fas fa-circle-check" aria-hidden="true"></i> Last updated October 21, 2026</span></p></header>
                    <p class="policy-lead">This academic prototype is not an official barangay service. This policy explains how project administrators handle account, request, and sample disclosure submissions.</p>

                    <h2 id="mod-prohibited">Prohibited content</h2>
                    <p>We may reject or remove submissions that include:</p>
                    <ul>
                        <li>Threats, harassment, abusive language, hate speech, or discriminatory statements</li>
                        <li>Spam, repeated junk submissions, or attempts to disrupt the service</li>
                        <li>Fraudulent, forged, altered, or misleading registration details or supporting documents</li>
                        <li>Instructions or files intended to facilitate illegal activity or compromise the portal</li>
                        <li>Personal or sensitive information about another person shared without authority or a lawful purpose</li>
                        <li>Material that infringes copyright or another person's rights</li>
                    </ul>

                    <h2 id="mod-review">How we review</h2>
                    <p>Project administrators can review online proof-of-residency files and requests to demonstrate a review workflow. They may ask for clarification or reject a sample submission. No review is an official government decision, and no document is issued by this prototype. Published disclosure content and attachments are public; administrators should not post personal information unless its publication is authorized and necessary.</p>

                    <h2 id="mod-reporting">Reporting problems</h2>
                    <p>To report harmful content, suspected fraud, unauthorized disclosure, or a moderation concern, email <a href="mailto:deleonjuliusakeem@gmail.com">deleonjuliusakeem@gmail.com</a>. Include the relevant tracking number or page, describe the concern, and tell us how we can contact you. Do not email passwords, authenticator codes, or unrelated sensitive documents.</p>

                    <h2 id="mod-removal">Removal &amp; rejection</h2>
                    <p>Depending on the issue, project administrators may restrict access to a file, return a sample request for correction, or reject, unpublish, or remove content. They should provide a reason or next step when reasonably practicable while protecting other people's privacy.</p>

                    <h2 id="mod-repeat">Repeat violations</h2>
                    <p>Repeated or serious violations may lead to additional review, temporary restrictions, account suspension, or account closure. The response will take into account the nature and seriousness of the conduct and any applicable legal or safety needs.</p>

                    <h2 id="mod-approval">Decisions and review</h2>
                    <p>Submitting a registration or sample request does not guarantee review by a particular date or any real-world service. If a submission is rejected or content is removed, contact the project email below with your account email or tracking number to ask for clarification. There is no in-portal appeal feature or guaranteed response time.</p>

                    <h2 id="mod-responsibility">User responsibility</h2>
                    <p>Check information and files before submitting them, use the portal only for your own authorized account and requests, and respect the privacy and rights of other people. Report suspected account compromise or an incorrect moderation action promptly.</p>

                    <h2 id="mod-contact">Contact</h2>
                    <div class="policy-contact-card">
                        <span class="policy-contact-icon"><i class="fas fa-envelope" aria-hidden="true"></i></span>
                        <div class="policy-contact-body">
                            <strong>Barangay San Jose Digital Portal academic project</strong>
                            <a href="mailto:deleonjuliusakeem@gmail.com">deleonjuliusakeem@gmail.com</a>
                        </div>
                    </div>

                    <nav class="policy-related" aria-label="Related policies">
                        <p class="policy-related-label">Related</p>
                        <button type="button" data-action-click="showSection('privacy-policy')">Privacy Policy</button>
                        <button type="button" data-action-click="showSection('terms')">Terms &amp; Conditions</button>
                        <button type="button" data-action-click="showSection('copyright')">Copyright Notice</button>
                    </nav>
                </div>
            </div>
        </section>

        <section id="copyright" class="section policy-section" aria-labelledby="copyright-title">
            <div class="policy-progress-bar" aria-hidden="true"><div class="policy-progress-fill"></div></div>
            <button class="back-button policy-back policy-back-mobile" type="button" data-action-click="showSection('landing')"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to home</button>
            <div class="policy-layout">
                <nav class="policy-toc" aria-label="Copyright Notice sections">
                    <button class="back-button policy-back" type="button" data-action-click="showSection('landing')"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to home</button>
                    <p class="policy-toc-label">On this page</p>
                    <ol class="policy-toc-list">
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('cr-policy')">Copyright policy</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('cr-reporting')">Reporting infringement</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('cr-response')">How reports are handled</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('cr-review')">Requesting a review</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('cr-contact')">Contact</button></li>
                    </ol>
                </nav>
                <div class="policy-page">
                    <header class="policy-header policy-copyright">
                        <p class="eyebrow">Legal</p>
                        <h1 id="copyright-title">Copyright Notice</h1>
                        <p class="policy-updated"><span class="policy-updated-date"><i class="fas fa-circle-check" aria-hidden="true"></i> Last updated October 21, 2026</span></p></header>
                    <p class="policy-lead">We respect intellectual property rights under Philippine law, including the Intellectual Property Code of the Philippines (Republic Act No. 8293). This academic prototype explains how project materials and submissions may be used, and how to report a suspected infringement.</p>

                    <h2 id="cr-policy">Copyright policy</h2>
                    <p>Text, graphics, software, photographs, and other materials on the portal may be protected by copyright or other rights. Those rights remain with the relevant owner. You may not copy or reuse protected portal material except with permission or as allowed by law. Nothing on this page transfers ownership of a government seal, third-party work, or other protected material.</p>
                    <p>You keep your rights in files and materials you submit. By uploading them, you give the portal a limited, non-exclusive permission to store, reproduce, and make them available to authorized personnel only as reasonably needed to review or fulfill your request, secure the service, and meet legal obligations. This permission ends when the material is deleted, except for copies that must be retained for a lawful purpose.</p>

                    <h2 id="cr-reporting">Reporting infringement</h2>
                    <p>Send a written notice that includes:</p>
                    <ul>
                        <li>Your name, preferred contact details, and whether you are the rights holder or authorized to act for them</li>
                        <li>A description of the protected work and the right you believe has been infringed</li>
                        <li>The exact portal URL, request reference, or other location of the material, plus enough detail to identify it</li>
                        <li>A good-faith statement explaining why the use is not authorized by the rights holder, an agent, or the law</li>
                        <li>A statement that the notice is accurate and that you are the rights holder or authorized representative</li>
                        <li>Your typed or written signature</li>
                    </ul>

                    <h2 id="cr-response">How reports are handled</h2>
                    <p>Email the notice to <a href="mailto:deleonjuliusakeem@gmail.com">deleonjuliusakeem@gmail.com</a> with the subject "Copyright report." We will review notices, may ask for supporting information, and may restrict or remove material when appropriate. Where reasonably practicable, we may notify the person who submitted it and provide a chance to respond, while protecting privacy and complying with legal requirements. Submitting a report does not guarantee removal.</p>

                    <h2 id="cr-review">Requesting a review</h2>
                    <p>If you submitted material that was restricted or removed and believe the action was mistaken, email us with the relevant URL or request reference, explain why you are authorized to use the material or believe the use is lawful, and include any supporting information. We will consider the response and may restore access where appropriate. This is an administrative review process and does not replace rights or remedies available under Philippine law.</p>

                    <h2 id="cr-contact">Contact</h2>
                    <div class="policy-contact-card">
                        <span class="policy-contact-icon"><i class="fas fa-envelope" aria-hidden="true"></i></span>
                        <div class="policy-contact-body">
                            <strong>Barangay San Jose Digital Portal academic project</strong>
                            <a href="mailto:deleonjuliusakeem@gmail.com">deleonjuliusakeem@gmail.com</a>
                        </div>
                    </div>

                    <nav class="policy-related" aria-label="Related policies">
                        <p class="policy-related-label">Related</p>
                        <button type="button" data-action-click="showSection('privacy-policy')">Privacy Policy</button>
                        <button type="button" data-action-click="showSection('terms')">Terms &amp; Conditions</button>
                        <button type="button" data-action-click="showSection('moderation')">Moderation Policy</button>
                    </nav>
                </div>
            </div>
        </section>
    </main>
    <?php require __DIR__ . '/includes/shared/site-footer.php'; ?>

    <?php require __DIR__ . '/includes/shared/dialogs.php'; ?>    <div id="toast" class="toast hidden" role="status" aria-live="polite" aria-atomic="true">Message</div>
    <script src="script.js?v=20261027-proof-preview-title-v1" defer></script>
</body>
</html>










