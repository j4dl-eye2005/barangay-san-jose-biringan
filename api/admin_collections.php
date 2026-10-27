<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
requireAdminAccess('collections.view');

$manilaNow = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$utc = new DateTimeZone('UTC');
$todayStart = (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->setTimezone($utc);
$tomorrowStart = $todayStart->modify('+1 day');
$monthStart = $manilaNow->modify('first day of this month')->setTime(0, 0)->setTimezone($utc);
$todayQuery = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM request_payment_transaction WHERE status = 'confirmed' AND recorded_at >= ? AND recorded_at < ?");
$todayQuery->execute([$todayStart->format('Y-m-d H:i:s'), $tomorrowStart->format('Y-m-d H:i:s')]);
$today = $todayQuery->fetchColumn();
$monthQuery = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM request_payment_transaction WHERE status = 'confirmed' AND recorded_at >= ?");
$monthQuery->execute([$monthStart->format('Y-m-d H:i:s')]);
$month = $monthQuery->fetchColumn();
$pending = $pdo->query("SELECT COUNT(*) FROM document_request WHERE payment_status IN ('Unpaid', 'Pending Verification', 'Rejected')")->fetchColumn();

$payments = $pdo->query("SELECT rpt.payment_id, rpt.request_id, rpt.amount, rpt.method, rpt.reference, rpt.status, DATE_FORMAT(rpt.recorded_at, '%Y-%m-%dT%H:%i:%sZ') AS recorded_at,
        dr.tracking_code, dt.name AS document_type,
        COALESCE(NULLIF(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name, r.suffix), ''), dr.walk_in_name, 'Walk-in resident') AS resident_name,
        CONCAT_WS(' ', u.first_name, u.last_name) AS recorded_by
    FROM request_payment_transaction rpt
    JOIN document_request dr ON dr.request_id = rpt.request_id
    JOIN document_type dt ON dt.document_type_id = dr.document_type_id
    LEFT JOIN resident r ON r.resident_id = dr.resident_id
    LEFT JOIN `user` u ON u.user_id = rpt.recorded_by_user_id
    ORDER BY rpt.recorded_at DESC, rpt.payment_id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

$outstanding = $pdo->query("SELECT dr.request_id, dr.tracking_code, dr.payment_status, dr.current_status,
        COALESCE(dr.assessed_fee, dt.fee_amount) AS fee_amount, dr.business_name, dr.business_category,
        EXISTS (SELECT 1 FROM request_payment_transaction rpt WHERE rpt.request_id = dr.request_id AND rpt.status = 'confirmed') AS has_confirmed_payment,
        dr.business_address, dr.business_activity,
        CASE WHEN dr.payment_proof_path IS NOT NULL THEN CONCAT('api/payment_proof.php?id=', dr.request_id) ELSE NULL END AS payment_proof_url,
        dt.name AS document_type,
        COALESCE(NULLIF(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name, r.suffix), ''), dr.walk_in_name, 'Walk-in resident') AS resident_name
    FROM document_request dr
    JOIN document_type dt ON dt.document_type_id = dr.document_type_id
    LEFT JOIN resident r ON r.resident_id = dr.resident_id
    WHERE dr.payment_status IN ('Unpaid', 'Pending Verification', 'Rejected')
       OR (COALESCE(dr.assessed_fee, dt.fee_amount) IS NULL AND LOWER(dt.name) LIKE '%business%')
    ORDER BY (dr.payment_status = 'Pending Verification') DESC, dr.created_at ASC, dr.request_id ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'summary' => ['today' => (string) $today, 'month' => (string) $month, 'open' => (int) $pending],
    'payments' => $payments,
    'outstanding' => $outstanding,
]);
?>
