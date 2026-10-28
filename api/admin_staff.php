<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
requireAdminAccess('staff.manage');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    $staff = $pdo->query("SELECT bs.staff_id, bs.full_name, bs.position_title, bs.staff_kind, bs.contact_number, bs.email,
            bs.portal_user_id, bs.is_active, bs.updated_at, u.staff_role
        FROM barangay_staff bs
        LEFT JOIN `user` u ON u.user_id = bs.portal_user_id
        ORDER BY bs.is_active DESC, FIELD(bs.staff_kind, 'official', 'staff'), bs.full_name")->fetchAll(PDO::FETCH_ASSOC);
    $accounts = $pdo->query("SELECT user_id, CONCAT_WS(' ', first_name, middle_name, last_name) AS full_name, email, staff_role
        FROM `user` WHERE role = 'Admin' ORDER BY first_name, last_name")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'staff' => $staff, 'accounts' => $accounts]);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'GET or POST request required.']);
    exit;
}

$data = jsonBody(8192);
$action = is_string($data['action'] ?? null) ? $data['action'] : 'save';
$staffId = filter_var($data['staff_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($action === 'deactivate' || $action === 'restore') {
    if (!$staffId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A staff directory record is required.']);
        exit;
    }
    try {
        $pdo->beginTransaction();
        $lookup = $pdo->prepare('SELECT is_active, portal_user_id FROM barangay_staff WHERE staff_id = ? FOR UPDATE');
        $lookup->execute([$staffId]);
        $record = $lookup->fetch(PDO::FETCH_ASSOC);
        if (!$record) throw new DomainException('Staff directory record not found.');
        $linkedRole = null;
        if ($record['portal_user_id']) {
            $linkedAccount = $pdo->prepare("SELECT staff_role FROM `user` WHERE user_id = ? AND role = 'Admin' FOR UPDATE");
            $linkedAccount->execute([(int) $record['portal_user_id']]);
            $linkedRole = $linkedAccount->fetchColumn();
        }
        if ($action === 'deactivate' && (int) $record['is_active'] === 1 && $record['portal_user_id'] && $linkedRole === 'super_admin') {
            $remainingAdmins = $pdo->prepare("SELECT COUNT(*) FROM `user` u LEFT JOIN barangay_staff bs ON bs.portal_user_id = u.user_id
                WHERE u.role = 'Admin' AND u.staff_role = 'super_admin' AND u.user_id <> ? AND (bs.staff_id IS NULL OR bs.is_active = 1)");
            $remainingAdmins->execute([(int) $record['portal_user_id']]);
            if ((int) $remainingAdmins->fetchColumn() < 1) throw new DomainException('Add or assign another super-admin before disabling this account.');
        }
        $update = $pdo->prepare('UPDATE barangay_staff SET is_active = ?, updated_by_user_id = ? WHERE staff_id = ?');
        $update->execute([$action === 'restore' ? 1 : 0, (int) $_SESSION['user_id'], $staffId]);
        $pdo->prepare('INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)')->execute([
            (int) $_SESSION['user_id'], 'staff_directory_updated', 'Staff record ' . $staffId . ' set ' . ($action === 'restore' ? 'active' : 'inactive')
        ]);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => $action === 'restore' ? 'Staff record and linked portal access restored.' : 'Staff record marked inactive and linked portal access disabled.']);
    } catch (DomainException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => $error->getMessage()]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Staff directory status update failed.');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Staff status could not be updated.']);
    }
    exit;
}

$fullName = trim(is_string($data['full_name'] ?? null) ? $data['full_name'] : '');
$position = trim(is_string($data['position_title'] ?? null) ? $data['position_title'] : '');
$kind = is_string($data['staff_kind'] ?? null) ? $data['staff_kind'] : '';
$phone = trim(is_string($data['contact_number'] ?? null) ? $data['contact_number'] : '');
$email = strtolower(trim(is_string($data['email'] ?? null) ? $data['email'] : ''));
$portalUserRaw = $data['portal_user_id'] ?? null;
$portalUserId = filter_var($portalUserRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$staffRole = is_string($data['staff_role'] ?? null) ? $data['staff_role'] : null;
$allowedRoles = ['super_admin', 'service_clerk', 'cashier', 'document_issuer'];
if ((($data['staff_id'] ?? null) !== null && $data['staff_id'] !== '' && !$staffId)
    || ($portalUserRaw !== null && $portalUserRaw !== '' && !$portalUserId)
    || strlen($fullName) < 2 || strlen($fullName) > 180 || !preg_match('/^[\p{L}\p{M} .\'-]+$/u', $fullName)
    || $position === '' || strlen($position) > 120 || preg_match('/[\x00-\x1F\x7F]/', $position)
    || !in_array($kind, ['official', 'staff'], true)
    || strlen($phone) > 30 || preg_match('/[\x00-\x1F\x7F]/', $phone)
    || ($email !== '' && (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)))
    || ($portalUserId && !in_array($staffRole, $allowedRoles, true))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Check the staff name, position, contact details, category, and portal role.']);
    exit;
}

try {
    $pdo->beginTransaction();
    $oldPortalUserId = null;
    if ($staffId) {
        $existing = $pdo->prepare('SELECT portal_user_id FROM barangay_staff WHERE staff_id = ? FOR UPDATE');
        $existing->execute([$staffId]);
        $oldPortalUserId = $existing->fetchColumn();
        if ($oldPortalUserId === false) throw new DomainException('Staff directory record not found.');
        $oldPortalUserId = $oldPortalUserId === null ? null : (int) $oldPortalUserId;
    }
    if ($portalUserId) {
        $linked = $pdo->prepare($staffId
            ? 'SELECT COUNT(*) FROM barangay_staff WHERE portal_user_id = ? AND staff_id <> ?'
            : 'SELECT COUNT(*) FROM barangay_staff WHERE portal_user_id = ?');
        if ($staffId) $linked->execute([$portalUserId, $staffId]);
        else $linked->execute([$portalUserId]);
        if ((int) $linked->fetchColumn() > 0) throw new DomainException('That administrator account is already linked to another staff record.');
        $account = $pdo->prepare("SELECT staff_role FROM `user` WHERE user_id = ? AND role = 'Admin' FOR UPDATE");
        $account->execute([$portalUserId]);
        $oldRole = $account->fetchColumn();
        if ($oldRole === false) throw new DomainException('Choose an existing administrator account.');
        if ($oldRole === 'super_admin' && $staffRole !== 'super_admin') {
            $remaining = $pdo->prepare("SELECT COUNT(*) FROM `user` u LEFT JOIN barangay_staff bs ON bs.portal_user_id = u.user_id
                WHERE u.role = 'Admin' AND u.staff_role = 'super_admin' AND u.user_id <> ? AND (bs.staff_id IS NULL OR bs.is_active = 1)");
            $remaining->execute([$portalUserId]);
            if ((int) $remaining->fetchColumn() < 1) throw new DomainException('At least one active super-admin account must remain.');
        }
        $pdo->prepare('UPDATE `user` SET staff_role = ?, position_title = ?, contact_number = ? WHERE user_id = ?')->execute([$staffRole, $position, $phone !== '' ? $phone : null, $portalUserId]);
    }
    if ($oldPortalUserId && $oldPortalUserId !== $portalUserId) {
        $oldAccount = $pdo->prepare("SELECT staff_role FROM `user` WHERE user_id = ? AND role = 'Admin' FOR UPDATE");
        $oldAccount->execute([$oldPortalUserId]);
        $oldRole = $oldAccount->fetchColumn();
        if ($oldRole === 'super_admin') {
            $remaining = $pdo->prepare("SELECT COUNT(*) FROM `user` u LEFT JOIN barangay_staff bs ON bs.portal_user_id = u.user_id
                WHERE u.role = 'Admin' AND u.staff_role = 'super_admin' AND u.user_id <> ? AND (bs.staff_id IS NULL OR bs.is_active = 1)");
            $remaining->execute([$oldPortalUserId]);
            if ((int) $remaining->fetchColumn() < 1) throw new DomainException('Assign another super-admin before unlinking this account.');
        }
        $pdo->prepare("UPDATE `user` SET staff_role = 'inactive', position_title = NULL, contact_number = NULL WHERE user_id = ?")->execute([$oldPortalUserId]);
    }
    if ($staffId) {
        $save = $pdo->prepare('UPDATE barangay_staff SET full_name = ?, position_title = ?, staff_kind = ?, contact_number = ?, email = ?, portal_user_id = ?, is_active = 1, updated_by_user_id = ? WHERE staff_id = ?');
        $save->execute([$fullName, $position, $kind, $phone !== '' ? $phone : null, $email !== '' ? $email : null, $portalUserId, (int) $_SESSION['user_id'], $staffId]);
        if ($save->rowCount() < 1) {
            $exists = $pdo->prepare('SELECT COUNT(*) FROM barangay_staff WHERE staff_id = ?');
            $exists->execute([$staffId]);
            if ((int) $exists->fetchColumn() < 1) throw new DomainException('Staff directory record not found.');
        }
        $auditDetail = 'Updated staff record ' . $staffId;
    } else {
        $save = $pdo->prepare('INSERT INTO barangay_staff (full_name, position_title, staff_kind, contact_number, email, portal_user_id, updated_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $save->execute([$fullName, $position, $kind, $phone !== '' ? $phone : null, $email !== '' ? $email : null, $portalUserId, (int) $_SESSION['user_id']]);
        $staffId = (int) $pdo->lastInsertId();
        $auditDetail = 'Added staff record ' . $staffId;
    }
    $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, 'staff_directory_updated', ?)")->execute([(int) $_SESSION['user_id'], $auditDetail]);
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Staff details and portal access saved.']);
} catch (DomainException $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Staff directory update failed.');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Staff details could not be saved.']);
}
?>
