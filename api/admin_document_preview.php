<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/payment_receipt_number.php';
requireAdminAccess('documents.preview');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; font-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$requestId) { http_response_code(400); exit('A valid request ID is required.'); }
$stmt = $pdo->prepare("SELECT dr.request_id, dr.tracking_code, dr.purpose, dr.current_status, dr.payment_status,
        dr.request_source, dr.walk_in_name, dr.walk_in_address, dr.business_name, dr.business_category,
        dr.business_address, dr.business_activity, dr.payment_reference, dr.payment_reviewed_at,
        (SELECT rpt.payment_id FROM request_payment_transaction rpt WHERE rpt.request_id = dr.request_id AND rpt.status = 'confirmed' ORDER BY rpt.recorded_at DESC, rpt.payment_id DESC LIMIT 1) AS confirmed_payment_id,
        (SELECT rpt.recorded_at FROM request_payment_transaction rpt WHERE rpt.request_id = dr.request_id AND rpt.status = 'confirmed' ORDER BY rpt.recorded_at DESC, rpt.payment_id DESC LIMIT 1) AS confirmed_payment_recorded_at,
        COALESCE(dr.assessed_fee, dt.fee_amount) AS fee_amount,
        dt.name AS document_type, r.first_name, r.middle_name, r.last_name, r.suffix, r.address,
        r.date_of_birth, r.civil_status, r.gender,
        (SELECT rsl.notes FROM request_status_log rsl WHERE rsl.request_id = dr.request_id AND rsl.status = 'Ready for Pickup' ORDER BY rsl.created_at DESC, rsl.log_id DESC LIMIT 1) AS pickup_notes
    FROM document_request dr
    JOIN document_type dt ON dt.document_type_id = dr.document_type_id
    LEFT JOIN resident r ON r.resident_id = dr.resident_id
    WHERE dr.request_id = ? LIMIT 1");
$stmt->execute([$requestId]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$request) { http_response_code(404); exit('Request not found.'); }
$paid = ($request['fee_amount'] !== null && (float) $request['fee_amount'] <= 0 && $request['payment_status'] === 'Not Required')
    || ($request['fee_amount'] !== null && (float) $request['fee_amount'] > 0 && $request['payment_status'] === 'Paid');
if (!$paid || !in_array($request['current_status'], ['Approved', 'Ready for Pickup', 'Completed'], true)) {
    http_response_code(409);
    exit('This request is not ready for document preparation. Confirm approval and payment first.');
}

$roleStmt = $pdo->prepare("SELECT staff_role FROM `user` WHERE user_id = ? AND role = 'Admin' LIMIT 1");
$roleStmt->execute([(int) $_SESSION['user_id']]);
$staffRole = (string) ($roleStmt->fetchColumn() ?: '');
$punongStmt = $pdo->query("SELECT full_name FROM barangay_staff
    WHERE is_active = 1 AND staff_kind = 'official' AND LOWER(TRIM(position_title)) = 'punong barangay'");
$punongRows = $punongStmt->fetchAll(PDO::FETCH_COLUMN);
$punongName = count($punongRows) === 1 ? trim((string) $punongRows[0]) : 'JULIUS AKEEM DE LEON';
$punongName = $punongName !== ''
    ? (function_exists('mb_strtoupper') ? mb_strtoupper($punongName, 'UTF-8') : strtoupper($punongName))
    : '';
$escape = static fn($value): string => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$fullName = trim(implode(' ', array_filter([$request['first_name'], $request['middle_name'], $request['last_name'], $request['suffix']], static fn($part) => trim((string) $part) !== '')));
if ($fullName === '') $fullName = (string) ($request['walk_in_name'] ?: 'Resident name not recorded');
$address = (string) ($request['address'] ?: $request['walk_in_address'] ?: 'Address to be verified');
$purpose = trim((string) $request['purpose']);
$businessName = trim((string) $request['business_name']);
$businessAddress = trim((string) $request['business_address']);
$businessActivity = trim((string) $request['business_activity']);
$businessCategory = $request['business_category'] === 'solo' ? 'Individual / sole proprietor' : ($request['business_category'] === 'organization' ? 'Organization / partnership / corporation' : 'Not recorded');
$manila = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$dayNumber = (int) $manila->format('j');
$daySuffix = in_array($dayNumber % 100, [11, 12, 13], true) ? 'th' : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$dayNumber % 10] ?? 'th');
$ordinalDay = $dayNumber . $daySuffix;
$age = '';
if (!empty($request['date_of_birth'])) {
    try { $age = (string) (new DateTimeImmutable((string) $request['date_of_birth']))->diff($manila)->y; } catch (Throwable $ignored) { $age = ''; }
}
$type = strtolower(trim((string) $request['document_type']));
$isBusiness = str_contains($type, 'business');
$isIndigency = str_contains($type, 'indigency');
$isResidency = str_contains($type, 'residency');
$usesCertificateLayout = $isIndigency || $isResidency;
$title = $isBusiness ? 'BUSINESS CLEARANCE' : ($isIndigency ? 'CERTIFICATE OF INDIGENCY' : ($isResidency ? 'CERTIFICATE OF RESIDENCY' : 'BARANGAY CLEARANCE'));
$personDetails = '<p>This sample is prepared for <strong>' . $escape($fullName) . '</strong>'
    . ($age !== '' ? ', age ' . $escape($age) : '')
    . ($request['civil_status'] ? ', ' . $escape($request['civil_status']) : '')
    . ', residing at <strong>' . $escape($address) . '</strong>.</p>';
if ($isBusiness) {
    $body = '<p>This sample records a request for barangay-level business clearance. Staff must verify the applicant, the barangay’s current requirements, and the supporting records before any official document is issued.</p>'
        . '<p><strong>Business name:</strong> ' . $escape($businessName ?: 'Not recorded') . '<br><strong>Business type:</strong> ' . $escape($businessCategory)
        . '<br><strong>Business address:</strong> ' . $escape($businessAddress ?: 'Not recorded') . '<br><strong>Business activity:</strong> ' . $escape($businessActivity ?: 'Not recorded') . '</p>'
        . $personDetails;
} elseif ($isIndigency) {
    $body = $personDetails;
} elseif ($isResidency) {
    $body = $personDetails . '<p>Residency details must be checked against barangay records and verified by authorized personnel before official issuance.</p>';
} else {
    $body = $personDetails . '<p>The applicable barangay clearance facts and purpose must be reviewed and approved by authorized personnel before official issuance.</p>';
}
if ($purpose !== '') $body .= '<p><strong>Stated purpose:</strong> ' . $escape($purpose) . '</p>';
$numberStmt = $pdo->prepare('SELECT document_number, issued_at FROM document_issuance WHERE request_id = ? ORDER BY issued_at DESC, issuance_id DESC LIMIT 1');
$numberStmt->execute([$requestId]);
$issuance = $numberStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$documentNumber = (string) ($issuance['document_number'] ?? '');
$formatManilaDate = static function ($value, string $format): string {
    if (!$value) return '';
    try {
        return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Asia/Manila'))
            ->format($format);
    } catch (Throwable $ignored) {
        return '';
    }
};
$issueDate = $manila;
if (!empty($issuance['issued_at'])) {
    try {
        $issueDate = (new DateTimeImmutable((string) $issuance['issued_at'], new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('Asia/Manila'));
    } catch (Throwable $ignored) { $issueDate = $manila; }
}
$issuedDate = $issueDate->format('F d, Y');
$issueYear = $issueDate->format('Y');
$trackingSuffix = strtoupper(substr((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $request['tracking_code']), -4));
$accountNumber = $issueDate->format('y') . '-' . $trackingSuffix;
$permitNumber = $issueDate->format('y-m') . '-' . $trackingSuffix;
$validUntil = 'December 31, ' . $issueDate->format('Y');
$portalReceiptNumber = !empty($request['confirmed_payment_id'])
    ? portalPaymentReceiptNumber((int) $request['confirmed_payment_id'], (string) ($request['confirmed_payment_recorded_at'] ?? ''))
    : 'Not recorded';
$paidDate = $formatManilaDate($request['payment_reviewed_at'] ?? null, 'Y-m-d');
$businessClassification = trim($businessActivity);
if ($businessCategory !== 'Not recorded') {
    $businessClassification .= ($businessClassification !== '' ? ' · ' : '') . $businessCategory;
}
$businessClassification = trim($businessClassification) ?: 'Not recorded';
$issuedByRole = in_array($staffRole, ['super_admin', 'document_issuer'], true);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($title) ?> · Sample preview</title>
    <link rel="stylesheet" href="../document-preview.css?v=20261030-business-clearance-header-v7">
</head>
<body>
    <div class="preview-toolbar" role="region" aria-label="Document preview actions">
        <div><strong>Staff-only sample preview</strong><span>Verify the facts and follow the barangay’s approved form before issuance. Save as PDF through your browser’s Print dialog.</span></div>
        <div class="preview-actions">
            <?php if ($issuedByRole): ?><button type="button" id="record-sample-issue" data-request-id="<?= (int) $requestId ?>" data-business="<?= $isBusiness ? 'true' : 'false' ?>"<?= ($isBusiness && $issuance) ? ' disabled' : '' ?>><?= $isBusiness ? ($issuance ? 'Issue date recorded' : 'Record issue date') : 'Record reviewed sample' ?></button><?php endif; ?>
            <button type="button" class="secondary" id="print-document">Print / Save as PDF</button>
        </div>
        <p id="issue-feedback" class="issue-feedback" aria-live="polite"></p>
    </div>
    <?php if ($isBusiness): ?>
    <main class="paper paper--business" aria-label="Barangay business clearance">
        <div class="business-watermark" aria-hidden="true"><img src="../assets/barangay-seal.svg" alt=""></div>
        <header class="business-header">
            <img src="../assets/barangay-seal.svg" alt="Barangay San Jose seal" class="business-seal">
            <div class="business-heading">
                <p class="business-jurisdiction">Barangay San Jose</p>
                <div class="business-title-lockup">
                    <h1 class="business-main-heading">Business Permit</h1>
                    <div class="business-header-bar" aria-hidden="true"></div>
                </div>
            </div>
            <p class="business-year"><span id="business-year-value"><?= $escape($issueYear) ?></span><small><span>BARANGAY BUSINESS</span><span>CLEARANCE</span></small></p>
        </header>

        <section class="business-fields" aria-label="Business details">
            <div class="business-field"><div class="business-field-label">Business Name</div><p class="business-value"><?= $escape($businessName ?: 'Not recorded') ?></p></div>
            <div class="business-field"><div class="business-field-label">Business Owner</div><p class="business-value"><?= $escape($fullName) ?></p></div>
            <div class="business-field"><div class="business-field-label">Account Number</div><p class="business-value"><?= $escape($accountNumber) ?></p></div>
            <div class="business-field"><div class="business-field-label">Business Address</div><p class="business-value"><?= $escape($businessAddress ?: 'Not recorded') ?></p></div>
            <div class="business-field business-field--wide"><div class="business-field-label">Business Classification</div><p class="business-value"><?= $escape($businessClassification) ?></p></div>
        </section>

        <p class="business-legal">This clearance is prepared from the business owner's application and the information declared in the request. The applicant remains responsible for complying with applicable barangay ordinances, national laws, and the requirements of the concerned city or municipal government. This clearance does not replace a city or municipal business permit. Verify all requirements and secure the appropriate permits from the proper government offices.</p>

        <section class="business-bottom" aria-label="Issue and payment details">
            <div class="business-receipt" aria-label="Payment information">
                <p><span>Portal receipt no.</span><strong><?= $escape($portalReceiptNumber) ?></strong></p>
                <p><span>Date paid</span><strong><?= $escape($paidDate ?: 'Not recorded') ?></strong></p>
                <p><span>Amount paid</span><strong><?= $paid ? '₱ ' . $escape(number_format((float) $request['fee_amount'], 2)) : 'Not recorded' ?></strong></p>
                <p><span>Printed date</span><strong><?= $escape($manila->format('F d, Y')) ?></strong></p>
            </div>
            <div class="business-issued">
                <p><span>Issued on:</span><strong id="issued-date-value"><?= $escape($issuedDate) ?></strong></p>
                <p><span>Unless otherwise revoked, valid until:</span><strong id="valid-until-value"><?= $escape($validUntil) ?></strong></p>
            </div>
            <div class="business-signatory">
                <div class="business-signature-wrap"><img src="../assets/julius-signature.png" alt="Signature of Julius Akeem De Leon" class="business-signature-image"><div class="business-signature-line"></div></div>
                <p class="business-signatory-name"><?= $escape($punongName) ?></p>
                <p class="business-signatory-role">Punong Barangay</p>
                <p class="business-permit-label">PERMIT NO.</p>
                <p class="business-permit-number" id="document-number"><?= $escape($permitNumber) ?></p>
            </div>
        </section>
        <div class="business-footer">Serbisyong Tapat · Pamayanang Maunlad</div>
        <p class="business-document-note">Barangay San Jose Digital Portal · Academic project · Not an official government document</p>
    </main>
    <?php else: ?>
    <main class="paper<?= $usesCertificateLayout ? ' paper--indigency' : '' ?>" aria-label="Print-ready document sample">
        <?php if ($usesCertificateLayout): ?>
        <svg class="indigency-top-ribbon" viewBox="0 0 210 40" preserveAspectRatio="none" aria-hidden="true">
            <rect x="0" y="12" width="210" height="15" fill="#0E5200" />
            <polygon points="112,27 124,12 210,12 210,27" fill="#F2C200" />
            <polygon points="170,0 210,0 210,27 196,27" fill="#0E5200" />
            <polygon points="160,0 170,0 196,27 184,27" fill="#F2C200" />
        </svg>
        <img src="../assets/barangay-seal.svg" alt="Barangay San Jose seal" class="indigency-seal">
        <div class="indigency-watermark" aria-hidden="true"><img src="../assets/barangay-seal.svg" alt=""></div>
        <div class="indigency-content">
            <header class="indigency-heading">
                <span class="indigency-republic">REPUBLIC OF THE PHILIPPINES</span>
                <strong class="indigency-barangay">BARANGAY SAN JOSE</strong>
                <span class="indigency-city">CITY OF BIRINGAN</span>
                <strong class="indigency-office">OFFICE OF THE PUNONG BARANGAY</strong>
                <h1 class="indigency-document-title"><?= $escape($title) ?></h1>
            </header>
            <div class="indigency-divider" aria-hidden="true"></div>
            <section class="indigency-certificate-body">
                <p class="indigency-to">To Whom It May Concern:</p>
                <?php if ($isResidency): ?>
                <p class="indigency-indent">This is to certify that <span class="indigency-field indigency-field-wide"><?= $escape($fullName) ?></span><?php if ($age !== '' || !empty($request['civil_status'])): ?>, <?php endif; ?><?php if ($age !== ''): ?><?php if ((int) $age >= 18): ?>of legal age<?php else: ?><?= $escape($age) ?> years old<?php endif; ?><?php endif; ?><?php if ($age !== '' && !empty($request['civil_status'])): ?> and <?php endif; ?><?php if (!empty($request['civil_status'])): ?><?= $escape($request['civil_status']) ?><?php endif; ?><?php if ($age !== '' || !empty($request['civil_status'])): ?>,<?php endif; ?> is a <strong>PERMANENT RESIDENT</strong> of Barangay San Jose, City of Biringan.</p>
                <p class="indigency-indent">Based on the records of this office, the above-named resident has been residing at <span class="indigency-field indigency-field-fill"><?= $escape($address) ?></span>.</p>
                <p>This CERTIFICATION is being issued upon the request of <span class="indigency-field indigency-field-wide"><?= $escape($fullName) ?></span><?php if ($purpose !== ''): ?> for <span class="indigency-field indigency-field-fill"><?= $escape($purpose) ?></span>.<?php else: ?> for whatever legal purpose it may serve.<?php endif; ?></p>
                <p>Issued this <span class="indigency-field"><?= $escape($ordinalDay) ?></span> day of <span class="indigency-field"><?= $escape($manila->format('F')) ?></span>, <span class="indigency-field"><?= $escape($manila->format('Y')) ?></span> at Barangay San Jose, City of Biringan, Philippines.</p>
                <?php else: ?>
                <p class="indigency-indent">This is to certify that <span class="indigency-field indigency-field-wide"><?= $escape($fullName) ?></span><?php if ($age !== ''): ?>, <span class="indigency-field"><?= $escape($age) ?></span> years old<?php endif; ?>, a resident of <span class="indigency-field indigency-field-fill"><?= $escape($address) ?></span>.</p>
                <p class="indigency-indent">This is to certify further that the above-mentioned person is personally known to me, of good moral character, a law-abiding citizen of the Republic of the Philippines, and that his/her family is listed as one of the INDIGENT families in our barangay.</p>
                <p>This CERTIFICATION is issued upon the request of <span class="indigency-field indigency-field-wide"><?= $escape($fullName) ?></span> for <span class="indigency-field indigency-field-fill"><?= $escape($purpose !== '' ? $purpose : 'Purpose not recorded') ?></span>.</p>
                <p>Issued this <span class="indigency-field"><?= $escape($ordinalDay) ?></span> day of <span class="indigency-field"><?= $escape($manila->format('F')) ?></span>, <span class="indigency-field"><?= $escape($manila->format('Y')) ?></span> at Barangay San Jose, City of Biringan.</p>
                <?php endif; ?>
            </section>
            <div class="indigency-signature">
                <img class="indigency-signature-image" src="../assets/julius-signature.png" alt="Signature of Julius Akeem De Leon">
                <div class="indigency-signature-line" aria-hidden="true"></div>
                <div class="indigency-signatory-label">Julius Akeem De Leon</div>
                <div class="indigency-signature-role">Punong Barangay</div>
            </div>
        </div>
        <svg class="indigency-bottom-ribbon" viewBox="0 0 210 40" preserveAspectRatio="none" aria-hidden="true">
            <rect x="0" y="12" width="210" height="15" fill="#0E5200" />
            <polygon points="112,27 124,12 210,12 210,27" fill="#F2C200" />
            <polygon points="170,0 210,0 210,27 196,27" fill="#0E5200" />
            <polygon points="160,0 170,0 196,27 184,27" fill="#F2C200" />
        </svg>
        <footer class="indigency-contact">Barangay San Jose Digital Portal · Academic project sample · Not an official government document</footer>
        <?php else: ?>
        <div class="sample-watermark" aria-hidden="true">SAMPLE · NOT OFFICIAL</div>
        <header class="document-header">
            <img src="../assets/barangay-seal.svg" alt="Barangay San Jose project seal" class="barangay-seal">
            <div class="header-copy">
                <p>Republic of the Philippines</p>
                <p>Province / City or Municipality: <span class="placeholder">To be confirmed</span></p>
                <h1>BARANGAY SAN JOSE</h1>
                <p>Office of the Punong Barangay</p>
            </div>
            <div class="seal-spacer" aria-hidden="true"></div>
        </header>
        <div class="green-rule"></div>
        <p class="document-kicker">BARANGAY CERTIFICATION · SAMPLE ONLY</p>
        <h2><?= $escape($title) ?></h2>
        <p class="document-number">Request tracking: <?= $escape($request['tracking_code']) ?> <span>·</span> Document no.: <strong id="document-number" class="document-number-value"><?= $escape($documentNumber) ?></strong></p>
        <section class="document-body"><?= $body ?></section>
        <p class="issued-date">Prepared on <?= $escape($manila->format('F j, Y')) ?> for staff review.</p>
        <div class="signature-row">
            <div class="signature-block"><span class="signature-line"></span><strong>Authorized staff review</strong><small>Signature and printed name required before official issuance</small></div>
            <div class="signature-block">
                <span class="signature-line"><?php if ($punongName !== ''): ?><strong class="signature-name"><?= $escape($punongName) ?></strong><?php endif; ?></span>
                <strong>Authorized signatory</strong><strong>Punong Barangay</strong><small>Signature required on the approved official copy</small>
            </div>
        </div>
        <footer class="document-footer"><span>Barangay San Jose Digital Portal · Academic project sample</span><span>Not an official government document</span></footer>
        <?php endif; ?>
    </main>
    <?php endif; ?>
    <script src="../document-preview.js?v=20261030-business-clearance-v2" defer></script>
</body>
</html>
