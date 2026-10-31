<?php
require_once __DIR__ . '/../includes/request_workflow_config.php';

function requestWorkflowConfig(): array
{
    static $config;
    return $config ??= require __DIR__ . '/../includes/request_workflow_config.php';
}

function requestTransitionTarget(string $currentStatus, string $action): ?string
{
    static $moves = [
        'Pending' => ['Approve' => 'Approved', 'Reject' => 'Rejected', 'Cancel' => 'Cancelled'],
        'Approved' => ['Ready' => 'Ready for Pickup'],
        'Ready for Pickup' => ['Pickup' => 'Completed', 'Reschedule' => 'Reschedule Requested', 'Expire' => 'Expired'],
        'Reschedule Requested' => ['RescheduleApprove' => 'Ready for Pickup', 'RescheduleReject' => 'Ready for Pickup'],
    ];
    return $moves[$currentStatus][$action] ?? null;
}

/** Persist one allowed status move and its history record inside the caller's transaction. */
function applyRequestTransition(PDO $pdo, int $requestId, string $currentStatus, string $action, ?int $actorUserId, ?string $notes, array $changes = []): ?string
{
    $newStatus = requestTransitionTarget($currentStatus, $action);
    if ($newStatus === null) return null;

    $allowedColumns = ['pickup_date', 'pickup_time', 'pickup_deadline_at', 'owner_id_checked_at', 'owner_id_checked_by_user_id', 'rejection_reason'];
    $sets = ['current_status = ?'];
    $values = [$newStatus];
    foreach ($changes as $column => $value) {
        if ($column === 'pickup_deadline_days') {
            $sets[] = 'pickup_deadline_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY)';
            $values[] = max(1, min(30, (int) $value));
            continue;
        }
        if (!in_array($column, $allowedColumns, true)) throw new InvalidArgumentException('Unsupported request transition field.');
        $sets[] = "`$column` = ?";
        $values[] = $value;
    }
    $values[] = $requestId;
    $values[] = $currentStatus;
    $update = $pdo->prepare('UPDATE document_request SET ' . implode(', ', $sets) . ' WHERE request_id = ? AND current_status = ?');
    $update->execute($values);
    if ($update->rowCount() !== 1) return null;

    $log = $pdo->prepare('INSERT INTO request_status_log (request_id, updated_by_user_id, status, notes) VALUES (?, ?, ?, ?)');
    $log->execute([$requestId, $actorUserId, $newStatus, $notes]);
    return $newStatus;
}

/** Expire overdue pickup requests when a resident or staff page is opened. */
function expireReadyRequests(PDO $pdo, ?int $actorUserId = null): int
{
    $pdo->beginTransaction();
    try {
        $find = $pdo->query("SELECT request_id FROM document_request
            WHERE current_status = 'Ready for Pickup' AND pickup_deadline_at IS NOT NULL
              AND pickup_deadline_at <= UTC_TIMESTAMP() FOR UPDATE");
        $ids = array_map('intval', $find->fetchAll(PDO::FETCH_COLUMN));
        foreach ($ids as $requestId) {
            $newStatus = applyRequestTransition($pdo, $requestId, 'Ready for Pickup', 'Expire', $actorUserId, 'Pickup deadline passed.');
            if ($newStatus !== null) {
                $audit = $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, 'request_expired', ?)");
                $audit->execute([$actorUserId, 'Request ID ' . $requestId . ' expired after the pickup deadline.']);
            }
        }
        $pdo->commit();
        return count($ids);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Overdue request expiry failed.');
        return 0;
    }
}

function requestAddressOptions(): array
{
    return ['puroks' => requestWorkflowConfig()['puroks'], 'streets' => requestWorkflowConfig()['streets']];
}

function validateStructuredAddress(array $data, string $prefix = ''): ?string
{
    $config = requestAddressOptions();
    $field = static fn(string $name): string => $prefix === '' ? $name : $prefix . ucfirst($name);
    $house = trim(is_string($data[$field('houseLotNumber')] ?? null) ? $data[$field('houseLotNumber')] : '');
    $purok = trim(is_string($data[$field('purok')] ?? null) ? $data[$field('purok')] : '');
    $street = trim(is_string($data[$field('street')] ?? null) ? $data[$field('street')] : '');
    if ($house === '' || strlen($house) > 40 || preg_match('/[\x00-\x1F\x7F]/', $house)
        || !in_array($purok, $config['puroks'], true) || !in_array($street, $config['streets'], true)) return null;
    if (strlen($street) > 100 || preg_match('/[^\p{L}\p{M}0-9 .\'-]/u', $street)) return null;
    $address = $house . ', ' . $street . ', ' . $purok . ', Barangay San Jose';
    $length = function_exists('mb_strlen') ? mb_strlen($address, 'UTF-8') : strlen($address);
    return $length >= 5 && strlen($address) <= 255 ? $address : null;
}

function validPersonName(string $name, int $maxLength = 100, int $minLength = 2): bool
{
    $name = preg_replace('/\s+/u', ' ', trim($name)) ?? '';
    $length = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    if ($length < $minLength || $length > $maxLength || !preg_match('/^[\p{L}\p{M} .\'-]+$/u', $name)) return false;
    $letters = preg_replace('/[^\p{L}\p{M}]/u', '', $name) ?? '';
    $characters = preg_split('//u', $letters, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($characters) < $minLength) return false;
    $letters = function_exists('mb_strtolower') ? mb_strtolower($letters, 'UTF-8') : strtolower($letters);
    return count($characters) < 2 || !preg_match('/^(.)\1+$/us', $letters);
}
