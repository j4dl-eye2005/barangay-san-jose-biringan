<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/request_workflow.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
requireAdminAccess('requests.manage');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['success'=>false, 'message'=>'POST request required.']); exit; }
$data = jsonBody(4096);
$action = is_string($data['action'] ?? null) ? $data['action'] : '';
$residentId = filter_var($data['residentId'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if ($action !== 'lift_cooldown' || !$residentId) { http_response_code(400); echo json_encode(['success'=>false, 'message'=>'A valid resident and action are required.']); exit; }
$pdo->beginTransaction();
try {
    $lock = $pdo->prepare('SELECT cooldown_until, lifted_at FROM resident_request_cooldown WHERE resident_id = ? FOR UPDATE');
    $lock->execute([$residentId]);
    $cooldown = $lock->fetch(PDO::FETCH_ASSOC);
    if (!$cooldown || $cooldown['lifted_at'] !== null || strtotime((string) $cooldown['cooldown_until'] . ' UTC') <= time()) {
        $pdo->rollBack(); http_response_code(409); echo json_encode(['success'=>false, 'message'=>'There is no active request cooldown to lift.']); exit;
    }
    $update = $pdo->prepare('UPDATE resident_request_cooldown SET lifted_at = UTC_TIMESTAMP(), lifted_by_user_id = ? WHERE resident_id = ? AND lifted_at IS NULL');
    $update->execute([(int) $_SESSION['user_id'], (int) $residentId]);
    $audit = $pdo->prepare("INSERT INTO audit_log (user_id, subject_resident_id, action, details) VALUES (?, ?, 'resident_request_cooldown_lifted', ?)");
    $audit->execute([(int) $_SESSION['user_id'], (int) $residentId, 'resident_id=' . (int) $residentId . '; previous_cooldown_until=' . $cooldown['cooldown_until']]);
    $pdo->commit();
    echo json_encode(['success'=>true, 'message'=>'The resident request cooldown was lifted.']);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Resident request cooldown lift failed.');
    http_response_code(500); echo json_encode(['success'=>false, 'message'=>'The cooldown could not be lifted.']);
}
?>
