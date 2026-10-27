<?php
require_once __DIR__ . '/db.php';
requireAdminAccess('documents.preview');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");

$requestId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$requestId) { http_response_code(400); exit('A valid request ID is required.'); }
$stmt = $pdo->prepare("SELECT dr.request_id, dr.tracking_code, dr.purpose, dr.current_status, dr.payment_status,
        dr.request_source, dr.walk_in_name, dr.walk_in_address, dr.business_name, dr.business_category,
        dr.business_address, dr.business_activity, COALESCE(dr.assessed_fee, dt.fee_amount) AS fee_amount,
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
$age = '';
if (!empty($request['date_of_birth'])) {
    try { $age = (string) (new DateTimeImmutable((string) $request['date_of_birth']))->diff($manila)->y; } catch (Throwable $ignored) { $age = ''; }
}
$type = strtolower(trim((string) $request['document_type']));
$isBusiness = str_contains($type, 'business');
$isIndigency = str_contains($type, 'indigency');
$isResidency = str_contains($type, 'residency');
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
    $body = $personDetails . '<p>Indigency status and any supporting facts must be verified and approved by authorized barangay personnel before official issuance. This sample does not make that determination.</p>';
} elseif ($isResidency) {
    $body = $personDetails . '<p>Residency details must be checked against barangay records and verified by authorized personnel before official issuance.</p>';
} else {
    $body = $personDetails . '<p>The applicable barangay clearance facts and purpose must be reviewed and approved by authorized personnel before official issuance.</p>';
}
if ($purpose !== '') $body .= '<p><strong>Stated purpose:</strong> ' . $escape($purpose) . '</p>';
$documentNumber = 'Not recorded';
$issuedByRole = in_array($staffRole, ['super_admin', 'document_issuer'], true);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($title) ?> · Sample preview</title>
    <link rel="stylesheet" href="../document-preview.css">
</head>
<body>
    <div class="preview-toolbar" role="region" aria-label="Document preview actions">
        <div><strong>Staff-only sample preview</strong><span>Verify the facts and follow the barangay’s approved form before issuance. Save as PDF through your browser’s Print dialog.</span></div>
        <div class="preview-actions">
            <?php if ($issuedByRole): ?><button type="button" id="record-sample-issue" data-request-id="<?= (int) $requestId ?>">Record reviewed sample</button><?php endif; ?>
            <button type="button" class="secondary" id="print-document">Print / Save as PDF</button>
        </div>
        <p id="issue-feedback" class="issue-feedback" aria-live="polite"></p>
    </div>
    <main class="paper" aria-label="Print-ready document sample">
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
        <p class="document-number">Request tracking: <?= $escape($request['tracking_code']) ?> <span>·</span> Document no.: <strong id="document-number"><?= $escape($documentNumber) ?></strong></p>
        <section class="document-body"><?= $body ?></section>
        <p class="issued-date">Prepared on <?= $escape($manila->format('F j, Y')) ?> for staff review.</p>
        <div class="signature-row">
            <div class="signature-block"><span class="signature-line"></span><strong>Authorized staff review</strong><small>Signature and printed name required before official issuance</small></div>
            <div class="signature-block"><span class="signature-line"></span><strong>Punong Barangay</strong><small>Signature required on the approved official copy</small></div>
        </div>
        <footer class="document-footer"><span>Barangay San Jose Digital Portal · Academic project sample</span><span>Not an official government document</span></footer>
    </main>
    <script src="../document-preview.js" defer></script>
</body>
</html>
