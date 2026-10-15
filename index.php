<?php
require_once __DIR__ . '/api/security.php';
enforceRequestSafety();
startAppSession();
if (!empty($GLOBALS['adminIpDeniedThisRequest'])) {
    renderUnavailablePage();
}
$adminIpAllowed = isAllowedAdminIp();
if (isset($_GET['error']) && $_GET['error'] === '404') renderUnavailablePage();
if (array_key_exists('verify', $_GET) && (!is_string($_GET['verify']) || !preg_match('/^[a-f0-9]{64}$/i', $_GET['verify']))) {
    renderUnavailablePage();
}
if (array_key_exists('reset', $_GET) && (!is_string($_GET['reset']) || !preg_match('/^[a-f0-9]{64}$/i', $_GET['reset']))) {
    renderUnavailablePage();
}
$portalSettings = portalConfig();
$recaptchaSiteKey = $portalSettings['recaptcha_site_key'] ?? '';
$localCaptchaBypass = portalLocalCaptchaBypass();
// Section routes set this before including the shared portal shell.
$verifyToken = '';
if (isset($_GET['verify']) && preg_match('/^[a-f0-9]{64}$/i', $_GET['verify'])) {
    $verifyToken = strtolower($_GET['verify']);
}
$resetToken = '';
$resetContext = 'resident';
if (isset($_GET['reset']) && preg_match('/^[a-f0-9]{64}$/i', $_GET['reset'])) {
    $resetToken = strtolower($_GET['reset']);
    $resetContext = isset($_GET['context']) && $_GET['context'] === 'admin' ? 'admin' : 'resident';
}
if ($resetContext === 'admin' && !$adminIpAllowed) {
    renderUnavailablePage();
}
$adminInitialSection = $adminInitialSection ?? null;
$validAdminSections = ['overview', 'requests', 'residents', 'disclosures', 'activity'];
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
    <meta name="recaptcha-site-key" content="<?= htmlspecialchars($recaptchaSiteKey, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="recaptcha-local-bypass" content="<?= $localCaptchaBypass ? 'true' : 'false' ?>">
    <?php if (!$localCaptchaBypass): ?><script src="https://www.google.com/recaptcha/api.js?onload=onRecaptchaLoad&amp;render=explicit" defer></script><?php endif; ?>
    <?php if ($verifyToken !== ''): ?>
    <meta name="email-verify-token" content="<?= htmlspecialchars($verifyToken, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <?php if ($resetToken !== ''): ?>
    <meta name="password-reset-token" content="<?= htmlspecialchars($resetToken, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="password-reset-context" content="<?= htmlspecialchars($resetContext, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <title>Barangay San Jose Digital Portal</title>
    <link rel="stylesheet" href="css/base.css?v=20261015-performance-queue">
    <link rel="stylesheet" href="css/home.css?v=20261015-performance-queue">
    <link rel="stylesheet" href="css/components.css?v=20261015-performance-queue">
    <link rel="stylesheet" href="css/auth.css?v=20261015-performance-queue">
    <link rel="stylesheet" href="css/admin.css?v=20261015-performance-queue">
    <link rel="stylesheet" href="css/public.css?v=20261015-performance-queue">
    <link rel="stylesheet" href="css/admin-tables.css?v=20261015-performance-queue">
    <link rel="stylesheet" href="css/shared-responsive.css?v=20261015-performance-queue">
    <link rel="stylesheet" href="css/admin-responsive.css?v=20261015-performance-queue">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body data-initial-admin-section="<?= htmlspecialchars((string) $adminInitialSection, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-entry-route="<?= $verifyToken !== '' ? 'verification' : ($resetToken !== '' ? 'password-reset' : '') ?>" data-admin-ip-allowed="<?= $adminIpAllowed ? '1' : '0' ?>">
    <script src="route-boot.js?v=20261015-performance-queue"></script>
    <a class="skip-link" href="#app-container">Skip to main content</a>

    <?php require __DIR__ . '/includes/shared/site-header.php'; ?>

    <main id="app-container" tabindex="-1">
        <section id="landing" class="section active" aria-labelledby="landing-title">

            <div class="hero">
                <div class="hero-intro">
                    <h1 id="landing-title">Get your barangay documents <span class="accent-text">online</span></h1>
                    <p class="description">Request certificates and clearances, track your application, and read public barangay records — all in one place.</p>
                    <div class="hero-buttons" id="hero-guest-actions">
                        <button class="btn-solid" type="button" data-action-click="checkAuthAndRedirect('register')"><i class="fas fa-user-plus" aria-hidden="true"></i> Register as a resident</button>
                        <button class="btn-outline btn-secondary" type="button" data-action-click="checkAuthAndRedirect('request')"><i class="fas fa-arrow-right-to-bracket" aria-hidden="true"></i> Log in to request</button>
                    </div>
                    <p class="hero-privacy" id="hero-guest-helper"><i class="fas fa-lock" aria-hidden="true"></i> <span>Your data is protected under RA&nbsp;10173. <button class="hero-privacy-link" type="button" data-action-click="showSection('privacy-policy')">Read our privacy notice</button></span></p>
                    <div class="hero-buttons hidden" id="hero-user-actions">                        <button class="btn-solid" type="button" data-action-click="openDashboard()"><i class="fas fa-gauge" aria-hidden="true"></i> Go to my dashboard</button>
                        <button class="btn-outline btn-secondary" type="button" data-action-click="showSection('bulletin')"><i class="fas fa-bullhorn" aria-hidden="true"></i> View public records</button>
                    </div>
                </div>

                <aside class="hero-steps-card" aria-labelledby="hero-steps-title">
                    <p class="eyebrow">Getting started</p>
                    <h2 id="hero-steps-title">Request a document in three steps</h2>
                    <ol class="inline-steps">
                        <li class="inline-step">
                            <span class="step-badge">1</span>
                            <div>
                                <strong>Create your account</strong>
                                <p>Register and upload proof of residency for verification.</p>
                            </div>
                        </li>
                        <li class="inline-step">
                            <span class="step-badge">2</span>
                            <div>
                                <strong>Submit a request</strong>
                                <p>Choose the document you need and tell us the purpose.</p>
                            </div>
                        </li>
                        <li class="inline-step">
                            <span class="step-badge">3</span>
                            <div>
                                <strong>Track and collect</strong>
                                <p>Follow your request status online and get notified when it's ready.</p>
                            </div>
                        </li>
                    </ol>
                </aside>
            </div>

            <section class="how-it-works" aria-labelledby="how-it-works-title">
                <p class="eyebrow">Documents you can request</p>
                <h2 id="how-it-works-title">Available at Barangay San Jose</h2>
                <p class="doc-strip-note">Most documents are ready in 1–3 working days. Pick up at Barangay Hall, Mon–Fri, 8&nbsp;am–5&nbsp;pm.</p>
                <ol class="steps-strip doc-strip" role="list">
                    <li class="step-item doc-item">
                        <button class="doc-card-btn" type="button" data-action-click="requestDoc('Barangay Clearance')" aria-label="Request Barangay Clearance">
                            <span class="doc-item-fee">₱50</span>
                            <h3>Barangay Clearance</h3>
                            <p>General-purpose clearance for employment, business, or personal use.</p>
                            <span class="doc-item-action">Request <i class="fas fa-arrow-right" aria-hidden="true"></i></span>
                        </button>
                    </li>
                    <li class="step-item doc-item">
                        <button class="doc-card-btn" type="button" data-action-click="requestDoc('Certificate of Residency')" aria-label="Request Certificate of Residency">
                            <span class="doc-item-fee">₱50</span>
                            <h3>Certificate of Residency</h3>
                            <p>Confirms you are a registered resident of Barangay San Jose.</p>
                            <span class="doc-item-action">Request <i class="fas fa-arrow-right" aria-hidden="true"></i></span>
                        </button>
                    </li>
                    <li class="step-item doc-item">
                        <button class="doc-card-btn" type="button" data-action-click="requestDoc('Certificate of Indigency')" aria-label="Request Certificate of Indigency">
                            <span class="doc-item-fee free">Free</span>
                            <h3>Certificate of Indigency</h3>
                            <p>For residents who need proof of financial need for government programs.</p>
                            <span class="doc-item-action">Request <i class="fas fa-arrow-right" aria-hidden="true"></i></span>
                        </button>
                    </li>
                    <li class="step-item doc-item">
                        <button class="doc-card-btn" type="button" data-action-click="requestDoc('Barangay Business Clearance')" aria-label="Request Barangay Business Clearance">
                            <span class="doc-item-fee">₱200</span>
                            <h3>Barangay Business Clearance</h3>
                            <p>Barangay-level clearance required before applying for a city business permit.</p>
                            <span class="doc-item-action">Request <i class="fas fa-arrow-right" aria-hidden="true"></i></span>
                        </button>
                    </li>
                </ol>
                <div class="mobile-carousel-dots" id="doc-carousel-dots" role="group" aria-label="Document cards"></div>

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
                <div class="dashboard-header">
                    <div>
                        <p class="eyebrow">Transparency center</p>
                        <h1 id="bulletin-title">SK Public Bulletin Board</h1>
                        <p>Browse official announcements, financial records, projects, and meeting minutes.</p>
                    </div>
                    <div id="bulletin-admin-action" class="hidden bulletin-admin-action">
                        <button class="btn-solid" type="button" data-action-click="openDisclosureComposer()"><i class="fas fa-paperclip" aria-hidden="true"></i> Add bulletin document</button>
                    </div>
                </div>
                <div class="dashboard-grid bulletin-grid">
                    <article class="card bulletin-card" id="bulletin-card-1" role="link" tabindex="0" aria-label="View Announcements" data-action-click="activateDisclosureCategory(event, 1)" data-action-keydown="handleDisclosureCardKeydown(event, 1)"><button class="category-heading" data-action-click="openDisclosureCategory(1)" type="button"><span><i class="fas fa-bullhorn" aria-hidden="true"></i> Announcements</span><i class="fas fa-arrow-right" aria-hidden="true"></i></button><ul class="bulletin-list" id="list-1"><li><p class="empty-state">No items yet.</p></li></ul></article>
                    <article class="card bulletin-card" id="bulletin-card-2" role="link" tabindex="0" aria-label="View Financial Reports" data-action-click="activateDisclosureCategory(event, 2)" data-action-keydown="handleDisclosureCardKeydown(event, 2)"><button class="category-heading" data-action-click="openDisclosureCategory(2)" type="button"><span><i class="fas fa-chart-pie" aria-hidden="true"></i> Financial Reports</span><i class="fas fa-arrow-right" aria-hidden="true"></i></button><ul class="bulletin-list" id="list-2"><li><p class="empty-state">No items yet.</p></li></ul></article>
                    <article class="card bulletin-card" id="bulletin-card-3" role="link" tabindex="0" aria-label="View Projects and Budget" data-action-click="activateDisclosureCategory(event, 3)" data-action-keydown="handleDisclosureCardKeydown(event, 3)"><button class="category-heading" data-action-click="openDisclosureCategory(3)" type="button"><span><i class="fas fa-project-diagram" aria-hidden="true"></i> Projects &amp; Budget</span><i class="fas fa-arrow-right" aria-hidden="true"></i></button><ul class="bulletin-list" id="list-3"><li><p class="empty-state">No items yet.</p></li></ul></article>
                    <article class="card bulletin-card" id="bulletin-card-4" role="link" tabindex="0" aria-label="View Meeting Minutes" data-action-click="activateDisclosureCategory(event, 4)" data-action-keydown="handleDisclosureCardKeydown(event, 4)"><button class="category-heading" data-action-click="openDisclosureCategory(4)" type="button"><span><i class="fas fa-file-alt" aria-hidden="true"></i> Meeting Minutes</span><i class="fas fa-arrow-right" aria-hidden="true"></i></button><ul class="bulletin-list" id="list-4"><li><p class="empty-state">No items yet.</p></li></ul></article>
                </div>
            </div>
        </section>

        <section id="login" class="section auth-section" aria-labelledby="login-title">
            <div class="split-screen">
                <div class="left-panel" aria-hidden="true">
                    <div class="panel-content">
                        <img src="Barangay San Jose, Biringan City.png" alt="" class="panel-logo" loading="lazy">
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
                            <div class="input-group"><label for="login-email">Email address</label><input type="email" id="login-email" maxlength="190" autocomplete="email" placeholder="you@example.com" required></div>
                            <div class="input-group login-password-group"><label for="login-password">Password</label><div class="password-field"><input type="password" id="login-password" maxlength="256" autocomplete="current-password" required><button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false" data-action-click="togglePassword('login-password', this)"><i class="fas fa-eye" aria-hidden="true"></i></button></div><button class="login-forgot-link" type="button" data-action-click="openForgotPassword('resident')">Forgot password?</button></div>
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

        <section id="admin-login" class="section <?= $adminIpAllowed ? 'auth-section' : 'unavailable-section' ?>" aria-labelledby="<?= $adminIpAllowed ? 'admin-login-title' : 'admin-login-unavailable-title' ?>">
            <?php if (!$adminIpAllowed): ?>
            <?= unavailablePageMarkup('admin-login-unavailable-title') ?>
            <?php else: ?>
            <div class="split-screen">
                <div class="left-panel" aria-hidden="true">
                    <div class="panel-content">
                        <img src="Barangay San Jose, Biringan City.png" alt="" class="panel-logo" loading="lazy">
                        <span class="badge">Administrator access</span>
                        <h1>Digital Disclosure &amp; Document Request Portal</h1>
                        <p>Restricted area for barangay administrators managing requests and records.</p>
                        <div class="panel-footer">Barangay San Jose · <span id="year-admin"></span></div>
                    </div>
                </div>
                <div class="right-panel">
                    <div class="form-wrapper">
                        <p class="eyebrow">Administrator access</p>
                        <h1 id="admin-login-title">Admin log in</h1>
                        <p class="form-subtitle">Authorized barangay staff only.</p>
                        <form id="admin-login-form" data-action-submit="handleAdminLogin(event)">
                            <div class="visually-hidden" aria-hidden="true"><label for="admin-login-website">Leave this field empty</label><input id="admin-login-website" name="website" tabindex="-1" autocomplete="off"></div>
                            <div class="input-group"><label for="admin-login-email">Email address</label><input type="email" id="admin-login-email" maxlength="190" autocomplete="username" placeholder="admin@example.com" required></div>
                            <div class="input-group"><label for="admin-login-password">Password</label><div class="password-field"><input type="password" id="admin-login-password" maxlength="256" autocomplete="current-password" required><button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false" data-action-click="togglePassword('admin-login-password', this)"><i class="fas fa-eye" aria-hidden="true"></i></button></div></div>
                            <button type="submit" class="btn-solid full-width mt-3">Log in</button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </section>

        <section id="forgot-password" class="section auth-section" aria-labelledby="forgot-password-title">
            <div class="split-screen">
                <div class="left-panel" aria-hidden="true"><div class="panel-content"><img src="Barangay San Jose, Biringan City.png" alt="" class="panel-logo" loading="lazy"><span class="badge">Account security</span><h1>Secure access to your portal account.</h1><p>Use a private reset link to choose a new password.</p><div class="panel-footer">Barangay San Jose</div></div></div>
                <div class="right-panel"><div class="form-wrapper">
                    <button class="back-button" type="button" data-action-click="returnFromPasswordReset()"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to log in</button>
                    <p class="eyebrow">Account recovery</p>
                    <h1 id="forgot-password-title">Forgot your password?</h1>
                    <p class="form-subtitle" id="password-reset-intro">Enter the email address for your portal account. We’ll send a secure link if it matches an account.</p>
                    <p class="login-error hidden" id="password-reset-error" role="alert" aria-live="polite"></p>
                    <p class="password-reset-success hidden" id="password-reset-success" role="status" aria-live="polite"></p>
                    <form id="forgot-password-form" data-action-submit="requestPasswordReset(event)">
                        <div class="input-group"><label for="password-reset-email">Email address</label><input type="email" id="password-reset-email" autocomplete="email" placeholder="you@example.com" required></div>
                        <input type="hidden" id="password-reset-context" value="resident">
                        <button type="submit" class="btn-solid full-width mt-3" id="password-reset-submit">Send reset link</button>
                    </form>
                    <form id="reset-password-form" class="hidden" data-action-submit="completePasswordReset(event)">
                        <div class="input-group"><label for="new-password">New password</label><input type="password" id="new-password" autocomplete="new-password" minlength="12" maxlength="256" required></div>
                        <div class="input-group"><label for="confirm-new-password">Confirm new password</label><input type="password" id="confirm-new-password" autocomplete="new-password" minlength="12" maxlength="256" required></div>
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
                        <img src="Barangay San Jose, Biringan City.png" alt="" class="panel-logo" loading="lazy">
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
                        <p class="form-subtitle">Submit your details and proof of residency. A barangay administrator will verify your account before it's activated.<br><span class="reg-required-note">* Required. Middle name, suffix, and phone number are optional.</span></p>

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
                                    <input type="tel" id="register-phone" name="phone" autocomplete="tel" placeholder="09XX XXX XXXX">
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

                            <!-- Proof of residency -->
                            <div class="reg-section">
                                <p class="reg-group-label">Proof of residency <span class="req-star" aria-hidden="true">*</span></p>
                                <div class="reg-proof-chips">
                                    <span class="reg-chip">Utility bill (last 3 months)</span>
                                    <span class="reg-chip">Barangay certificate or clearance</span>
                                    <span class="reg-chip">Government ID with address</span>
                                    <span class="reg-chip">Lease or rental agreement</span>
                                </div>
                                <div class="reg-dropzone" id="reg-dropzone" role="button" tabindex="0" aria-label="Upload proof of residency">
                                    <i class="fas fa-cloud-arrow-up reg-dropzone-icon" aria-hidden="true"></i>
                                    <p class="reg-dropzone-label">Drag &amp; drop here, or <span class="reg-dropzone-browse">browse</span></p>
                                    <p class="reg-dropzone-hint">PDF, DOCX, JPG, or PNG · max 5 MB</p>
                                    <input type="file" id="register-proof" name="proof" accept=".pdf,.docx,.jpg,.jpeg,.png" required class="reg-dropzone-input">
                                </div>
                                <div class="reg-dropzone-preview hidden" id="reg-dropzone-preview">
                                    <span class="reg-file-icon"><i class="fas fa-file-lines" aria-hidden="true"></i></span>
                                    <div class="reg-file-info">
                                        <span id="reg-dropzone-filename" class="reg-file-name"></span>
                                        <span id="reg-dropzone-filesize" class="reg-file-size"></span>
                                    </div>
                                    <button type="button" class="reg-file-action" data-action-click="regClearFile()">Remove</button>
                                    <button type="button" class="reg-file-action reg-file-replace" data-action-click="openFilePicker('register-proof')">Replace</button>
                                </div>
                                <p class="reg-proof-privacy"><i class="fas fa-lock" aria-hidden="true"></i> Visible only to barangay administrators for verification.</p>
                                <span class="reg-field-error" id="reg-proof-error" role="alert"></span>
                            </div>

                            <!-- Account -->
                            <div class="reg-section">
                                <p class="reg-group-label">Account</p>
                                <div class="reg-grid reg-grid-pw">
                                    <div class="reg-field reg-col-6">
                                        <label for="register-password">Password <span class="req-star" aria-hidden="true">*</span></label>
                                        <div class="password-field reg-pw-field">
                                            <input type="password" name="password" id="register-password" minlength="12" maxlength="256" autocomplete="new-password" required aria-describedby="register-pw-checklist register-password-error">
                                            <button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false" data-action-click="togglePassword('register-password', this)"><i class="fas fa-eye" aria-hidden="true"></i></button>
                                        </div>
                                        <div class="pw-strength-bar" aria-hidden="true">
                                            <span class="pw-strength-seg" id="pwseg-1"></span>
                                            <span class="pw-strength-seg" id="pwseg-2"></span>
                                            <span class="pw-strength-seg" id="pwseg-3"></span>
                                            <span class="pw-strength-seg" id="pwseg-4"></span>
                                        </div>
                                        <ul class="pw-checklist" id="register-pw-checklist" aria-live="polite">
                                            <li id="pwc-length"  class="pwc-item"><i class="far fa-circle pwc-icon" aria-hidden="true"></i><span>12+ characters</span></li>
                                            <li id="pwc-upper"  class="pwc-item"><i class="far fa-circle pwc-icon" aria-hidden="true"></i><span>Uppercase (A-Z)</span></li>
                                            <li id="pwc-lower"  class="pwc-item"><i class="far fa-circle pwc-icon" aria-hidden="true"></i><span>Lowercase (a-z)</span></li>
                                            <li id="pwc-number" class="pwc-item"><i class="far fa-circle pwc-icon" aria-hidden="true"></i><span>Number (0-9)</span></li>
                                        </ul>
                                        <span class="reg-field-error" id="register-password-error" role="alert"></span>
                                    </div>
                                    <div class="reg-field reg-col-6">
                                        <label for="register-confirm-password">Confirm password <span class="req-star" aria-hidden="true">*</span></label>
                                        <div class="password-field reg-pw-field">
                                            <input type="password" name="confirmPassword" id="register-confirm-password" minlength="12" maxlength="256" autocomplete="new-password" required aria-describedby="pwc-match register-confirm-password-error">
                                            <button type="button" class="password-toggle" aria-label="Show confirm password" aria-pressed="false" data-action-click="togglePassword('register-confirm-password', this)"><i class="fas fa-eye" aria-hidden="true"></i></button>
                                        </div>
                                        <p class="pwc-match-line" id="pwc-match" aria-live="polite"><i class="far fa-circle pwc-icon" aria-hidden="true"></i><span>Passwords match</span></p>
                                        <span class="reg-field-error" id="register-confirm-password-error" role="alert"></span>
                                    </div>
                                </div>
                                <div class="reg-consent-row">
                                    <label class="register-consent" id="register-consent-label">
                                        <input type="checkbox" id="register-terms-accepted" name="termsAccepted" value="1" required disabled aria-describedby="register-policy-read-hint">
                                        <span>I agree to the <button type="button" class="text-button" data-action-click="openRegisterPolicy('terms')">Terms &amp; Conditions</button> and <button type="button" class="text-button" data-action-click="openRegisterPolicy('privacy-policy')">Privacy Policy</button></span>
                                    </label>
                                    <p class="register-policy-read-hint" id="register-policy-read-hint" aria-live="polite">Read both documents to enable agreement.</p>
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
                <div class="dashboard-header">
                    <div><p class="eyebrow">Resident workspace</p><h1 id="resident-dashboard-title">Document requests</h1><div class="resident-request-tabs" role="tablist" aria-label="Document request sections"><button type="button" id="resident-request-tab-new" role="tab" aria-selected="true" aria-controls="resident-request-panel-new" class="active" data-action-click="selectResidentRequestTab('new')">New request</button><button type="button" id="resident-request-tab-list" role="tab" aria-selected="false" aria-controls="resident-request-panel-list" data-action-click="selectResidentRequestTab('list')">My requests</button></div><p class="dashboard-welcome">Welcome back, <strong id="resident-name"></strong>. Submit a request or review its progress.</p></div>
                    <button class="btn-outline" type="button" data-action-click="showSection('bulletin')"><i class="fas fa-bullhorn" aria-hidden="true"></i> View public records</button>
                </div>
                <div class="dashboard-grid resident-grid">
                    <div class="card active" id="resident-request-panel-new" data-request-panel="new" role="tabpanel" aria-labelledby="resident-request-tab-new"><div class="card-heading"><span class="step-label">New request</span><h2>Request a document</h2></div><form id="request-form" class="resident-request-form" data-action-submit="handleRequest(event)"><div class="input-group"><label for="doc-type">Document type <span class="req-star" aria-hidden="true">*</span></label><select id="doc-type" required><option value="" selected disabled>Select a document</option></select></div><div class="input-group"><label for="doc-purpose">Purpose <span class="req-star" aria-hidden="true">*</span></label><textarea id="doc-purpose" placeholder="e.g. Employment, scholarship, business permit" required rows="3"></textarea></div><div id="doc-type-info" class="request-summary" aria-live="polite" hidden></div><button id="request-submit" type="submit" class="btn-solid full-width">Submit request</button></form><div id="request-submit-confirmation" class="request-submit-confirmation" role="status" aria-live="polite" hidden></div></div>
                    <div class="card" id="resident-request-panel-list" data-request-panel="list" role="tabpanel" aria-labelledby="resident-request-tab-list"><div class="card-heading"><span class="step-label">Request history</span><h2>Track my requests</h2></div><div id="request-list" class="request-list" aria-live="polite"><p class="empty-state">You haven't submitted any document requests yet.</p></div></div>
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
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('pp-contact')">Contact</button></li>
                    </ol>
                </nav>
                <div class="policy-page">
                    <header class="policy-header policy-privacy">
                        <p class="eyebrow">Legal</p>
                        <h1 id="privacy-policy-title">Privacy Policy</h1>
                        <p class="policy-updated"><span class="policy-updated-date"><i class="fas fa-circle-check" aria-hidden="true"></i> Last updated October 3, 2026</span><span class="policy-academic-note">Academic project — no real data collected</span></p></header>
                    <p class="policy-lead">This portal lets residents create accounts, submit proof of residency, and request barangay documents. We are committed to handling your personal information responsibly and in line with the Data Privacy Act of 2012 (RA 10173).</p>

                    <h2 id="pp-collect">Information we collect</h2>
                    <p>We may collect the following when you use the portal:</p>
                    <ul>
                        <li>Account details (name, email, phone number, home address)</li>
                        <li>Proof-of-residency files you upload (PDF, DOCX, JPG, or PNG)</li>
                        <li>Document request details — type, purpose, and status</li>
                        <li>Date and time of registrations and requests</li>
                        <li>IP address, browser, and device info for security purposes</li>
                        <li>Server logs and error records</li>
                        <li>Anything you share when contacting us</li>
                    </ul>

                    <h2 id="pp-use">How we use it</h2>
                    <p>We use this information to operate the portal, verify resident accounts, process document requests, send email notifications, maintain security, and comply with legal requirements.</p>

                    <h2 id="pp-accounts">Accounts &amp; uploads</h2>
                    <p>Registration details and uploaded proof-of-residency files are reviewed by barangay administrators. Please only upload documents that are yours and avoid including information not needed for verification.</p>

                    <h2 id="pp-retention">Data retention</h2>
                    <p>We keep account records, files, request history, and logs for as long as needed to operate the portal and meet legal obligations. Fraudulent or inappropriate content may be removed at any time.</p>

                    <h2 id="pp-security">Security</h2>
                    <p>We take reasonable steps to protect your information. No internet service is completely secure, and we cannot guarantee absolute protection.</p>

                    <h2 id="pp-third">Third-party services</h2>
                    <p>We may use hosting or email-delivery services to run the portal. These providers may process personal information under their own policies.</p>

                    <h2 id="pp-cookies">Cookies</h2>
                    <p>We may use cookies to keep you signed in and improve performance. You can disable cookies in your browser, but some features may stop working.</p>

                    <h2 id="pp-rights">Your rights</h2>
                    <p>Under RA 10173, you may request access to, correction of, or deletion of your personal data. To remove your account or an uploaded document, contact us below.</p>

                    <h2 id="pp-changes">Changes</h2>
                    <p>We may update this policy at any time. Changes will appear on this page with a new date.</p>

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
                        <p class="policy-updated"><span class="policy-updated-date"><i class="fas fa-circle-check" aria-hidden="true"></i> Last updated October 3, 2026</span><span class="policy-academic-note">Academic project — no real data collected</span></p></header>
                    <p class="policy-lead">By using this portal, you agree to these Terms and Conditions. Please read them before registering or submitting any request.</p>

                    <h2 id="tc-acceptance">Acceptance</h2>
                    <p>You agree to use this portal only for lawful purposes and in a way that does not harm others or violate these rules.</p>

                    <h2 id="tc-accounts">Accounts &amp; services</h2>
                    <p>This portal lets residents register accounts, submit proof of residency, and request barangay documents. Access is granted after administrator verification. You are responsible for keeping your login secure and for the accuracy of what you submit.</p>

                    <h2 id="tc-responsibilities">Your responsibilities</h2>
                    <p>You agree not to:</p>
                    <ul>
                        <li>Provide false, misleading, or fraudulent registration details</li>
                        <li>Upload forged or altered proof-of-residency documents</li>
                        <li>Impersonate another person or submit requests on their behalf without authority</li>
                        <li>Use the portal for illegal activity or to harm others</li>
                        <li>Upload copyrighted material without permission</li>
                        <li>Share sensitive data about other people without their consent</li>
                    </ul>

                    <h2 id="tc-admin">Administration rights</h2>
                    <p>We may review, approve, reject, or remove any registration, request, or upload that violates these terms or appears fraudulent. Accounts that repeatedly misuse the portal may be suspended.</p>

                    <h2 id="tc-warranties">No warranties</h2>
                    <p>This portal is provided "as is." We do not guarantee uninterrupted, secure, or error-free access.</p>

                    <h2 id="tc-liability">Liability</h2>
                    <p>We are not liable for damages arising from the use of this portal, including issues from user-submitted content, downtime, or security events.</p>

                    <h2 id="tc-third">Third-party content</h2>
                    <p>We are not responsible for content submitted by users or for third-party links or services that appear on this portal.</p>

                    <h2 id="tc-changes">Changes</h2>
                    <p>We may update, change, or remove features at any time without notice.</p>

                    <h2 id="tc-termination">Termination</h2>
                    <p>We may suspend or remove access if we believe a user has violated these Terms.</p>

                    <h2 id="tc-law">Governing law</h2>
                    <p>These Terms are governed by the laws of the Republic of the Philippines.</p>

                    <h2 id="tc-contact">Contact</h2>
                    <div class="policy-contact-card">
                        <span class="policy-contact-icon"><i class="fas fa-envelope" aria-hidden="true"></i></span>
                        <div class="policy-contact-body">
                            <strong>Barangay San Jose Digital Portal</strong>
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
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('mod-approval')">No guarantee of approval</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('mod-responsibility')">User responsibility</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('mod-contact')">Contact</button></li>
                    </ol>
                </nav>
                <div class="policy-page">
                    <header class="policy-header policy-moderation">
                        <p class="eyebrow">Legal</p>
                        <h1 id="moderation-title">Moderation Policy</h1>
                        <p class="policy-updated"><span class="policy-updated-date"><i class="fas fa-circle-check" aria-hidden="true"></i> Last updated October 3, 2026</span><span class="policy-academic-note">Academic project — no real data collected</span></p></header>
                    <p class="policy-lead">We aim to keep this portal safe and useful for the community. This policy explains how submissions are reviewed and how to report problems.</p>

                    <h2 id="mod-prohibited">Prohibited content</h2>
                    <p>We may reject or remove submissions that include:</p>
                    <ul>
                        <li>Threats, harassment, or abusive language</li>
                        <li>Hate speech or discriminatory statements</li>
                        <li>Defamation or false accusations</li>
                        <li>Spam or repeated junk submissions</li>
                        <li>Fraudulent, forged, or falsified documents</li>
                        <li>Illegal activity or instructions</li>
                        <li>Personal information about others shared without consent</li>
                        <li>Copyrighted material without permission</li>
                    </ul>

                    <h2 id="mod-review">How we review</h2>
                    <p>Administrators review registrations, uploaded proof of residency, and document requests when they are submitted. Content may be rejected if it appears harmful, fraudulent, or unsuitable.</p>

                    <h2 id="mod-reporting">Reporting problems</h2>
                    <p>If you see a problem, email us at <a href="mailto:deleonjuliusakeem@gmail.com">deleonjuliusakeem@gmail.com</a> with a description of the issue and any relevant details.</p>

                    <h2 id="mod-removal">Removal &amp; rejection</h2>
                    <p>We may remove content or reject requests in response to a valid complaint, legal notice, or administrator decision.</p>

                    <h2 id="mod-repeat">Repeat violations</h2>
                    <p>Users who repeatedly submit harmful or fraudulent content may have their accounts suspended.</p>

                    <h2 id="mod-approval">No guarantee of approval</h2>
                    <p>We are not required to approve any registration or request. Accounts may be removed at any time, with or without notice.</p>

                    <h2 id="mod-responsibility">User responsibility</h2>
                    <p>Provide accurate information and avoid content that could harm or misidentify others. We expect respectful, lawful use.</p>

                    <h2 id="mod-contact">Contact</h2>
                    <div class="policy-contact-card">
                        <span class="policy-contact-icon"><i class="fas fa-envelope" aria-hidden="true"></i></span>
                        <div class="policy-contact-body">
                            <strong>Barangay San Jose Digital Portal</strong>
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
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('cr-response')">Response</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('cr-counter')">Counter-notice</button></li>
                        <li><button type="button" class="policy-toc-btn" data-action-click="scrollToId('cr-contact')">Contact</button></li>
                    </ol>
                </nav>
                <div class="policy-page">
                    <header class="policy-header policy-copyright">
                        <p class="eyebrow">Legal</p>
                        <h1 id="copyright-title">Copyright &amp; DMCA Notice</h1>
                        <p class="policy-updated"><span class="policy-updated-date"><i class="fas fa-circle-check" aria-hidden="true"></i> Last updated October 3, 2026</span><span class="policy-academic-note">Academic project — no real data collected</span></p></header>
                    <p class="policy-lead">We respect intellectual property rights. If you believe content on this portal infringes your copyright, please contact us.</p>

                    <h2 id="cr-policy">Copyright policy</h2>
                    <p>Content submitted to this portal may be copyright-protected. We do not allow material belonging to others without permission or a lawful right to use it.</p>

                    <h2 id="cr-reporting">Reporting infringement</h2>
                    <p>Send a written notice that includes:</p>
                    <ul>
                        <li>Your full name and contact information</li>
                        <li>A description of the work you believe has been infringed</li>
                        <li>The location of the allegedly infringing material on the portal</li>
                        <li>A good-faith statement that the use is unauthorized</li>
                        <li>A statement that the information in the notice is accurate</li>
                        <li>Your signature</li>
                    </ul>

                    <h2 id="cr-response">Response</h2>
                    <p>We review all notices and may remove or disable access to material that appears to violate copyright law, and may contact the user who submitted it.</p>

                    <h2 id="cr-counter">Counter-notice</h2>
                    <p>If you believe content was removed by mistake, contact us with a counter-notice. We will review it and may restore the content if appropriate.</p>

                    <h2 id="cr-contact">Contact</h2>
                    <div class="policy-contact-card">
                        <span class="policy-contact-icon"><i class="fas fa-envelope" aria-hidden="true"></i></span>
                        <div class="policy-contact-body">
                            <strong>Barangay San Jose Digital Portal</strong>
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
    <script src="script.js?v=20261015-pagination" defer></script>
</body>
</html>










