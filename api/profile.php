<?php
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$role = (string) ($_SESSION['role'] ?? '');
$isAdmin = $role === 'Admin' && !empty($_SESSION['user_id']);
$id = $isAdmin ? (int) $_SESSION['user_id'] : (($role === 'Resident') ? (int) ($_SESSION['resident_id'] ?? 0) : 0);
if ($id < 1 || !in_array($role, ['Admin', 'Resident'], true)) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Sign in to continue.']); exit; }
$table = $isAdmin ? '`user`' : 'resident';
$idColumn = $isAdmin ? 'user_id' : 'resident_id';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$data = $method === 'POST' ? jsonBody(8192) : [];
$action = is_string($data['action'] ?? null) ? $data['action'] : '';

if ($method === 'POST' && $action === 'sessions_revoke_others' && $isAdmin) {
    $pdo->beginTransaction();
    try {
        $currentVersion = $pdo->prepare("UPDATE $table SET auth_version = auth_version + 1 WHERE $idColumn = ?");
        $currentVersion->execute([$id]);
        if ($currentVersion->rowCount() !== 1) throw new RuntimeException('Account not found.');
        $version = $pdo->prepare("SELECT auth_version FROM $table WHERE $idColumn = ?"); $version->execute([$id]);
        $newAuthVersion = (int) $version->fetchColumn();
        $remove = $pdo->prepare('DELETE FROM account_session WHERE account_type = ? AND account_id = ? AND session_key <> ?');
        $remove->execute([$isAdmin ? 'admin' : 'resident', $id, hash('sha256', session_id())]);
        if ($isAdmin) $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, 'admin_sessions_revoked', 'Signed out of other devices from profile')")->execute([$id]);
        $pdo->commit();
        $_SESSION['_auth_version'] = $newAuthVersion;
        echo json_encode(['success'=>true,'message'=>'Signed out of all other devices.']);
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); http_response_code(500); echo json_encode(['success'=>false,'message'=>'Could not sign out other devices.']); }
    exit;
}

if ($method === 'POST' && $action === 'save_admin_details' && $isAdmin) {
    $position = trim(is_string($data['position'] ?? null) ? $data['position'] : '');
    $phone = trim(is_string($data['phone'] ?? null) ? $data['phone'] : '');
    $positionLength = function_exists('mb_strlen') ? mb_strlen($position, 'UTF-8') : strlen($position);
    $phoneLength = function_exists('mb_strlen') ? mb_strlen($phone, 'UTF-8') : strlen($phone);
    if ($positionLength > 120 || $phoneLength > 30) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Position or contact number is too long.']); exit; }
    $save = $pdo->prepare('UPDATE user SET position_title = ?, contact_number = ? WHERE user_id = ? AND role = ?');
    $save->execute([$position !== '' ? $position : null, $phone !== '' ? $phone : null, $id, 'Admin']);
    $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, 'admin_profile_updated', 'Updated position or contact details')")->execute([$id]);
    echo json_encode(['success'=>true,'message'=>'Account details saved.']); exit;
}

if ($method === 'POST' && $action === 'save_resident_profile' && !$isAdmin && $role === 'Resident') {
    $address = preg_replace('/\s+/u', ' ', trim(is_string($data['address'] ?? null) ? $data['address'] : '')) ?? '';
    $birthDate = trim(is_string($data['dateOfBirth'] ?? null) ? $data['dateOfBirth'] : '');
    $gender = trim(is_string($data['gender'] ?? null) ? $data['gender'] : '');
    $addressLength = function_exists('mb_strlen') ? mb_strlen($address, 'UTF-8') : strlen($address);
    $manilaTimezone = new DateTimeZone('Asia/Manila');
    $todayInManila = new DateTimeImmutable('today', $manilaTimezone);
    $dateOfBirth = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate, $manilaTimezone);
    $birthDateValid = $dateOfBirth instanceof DateTimeImmutable
        && $dateOfBirth->format('Y-m-d') === $birthDate
        && $dateOfBirth <= $todayInManila
        && $dateOfBirth >= $todayInManila->modify('-120 years');
    $allowedGenders = ['', 'Female', 'Male', 'Other', 'Prefer not to say'];
    if ($address === '' || $addressLength > 255 || preg_match('/[\x00-\x1F\x7F]/', $address)
        || !$birthDateValid || !in_array($gender, $allowedGenders, true)) {
        http_response_code(400);
        echo json_encode(['success'=>false,'message'=>'Enter a valid home address and birthdate, and choose a valid gender option.']);
        exit;
    }
    $save = $pdo->prepare('UPDATE resident SET address = ?, date_of_birth = ?, gender = ? WHERE resident_id = ? AND archived_at IS NULL');
    $save->execute([$address, $birthDate, $gender !== '' ? $gender : null, $id]);
    if ($save->rowCount() < 1) {
        $exists = $pdo->prepare('SELECT COUNT(*) FROM resident WHERE resident_id = ? AND archived_at IS NULL');
        $exists->execute([$id]);
        if ((int) $exists->fetchColumn() < 1) {
            http_response_code(404);
            echo json_encode(['success'=>false,'message'=>'Resident profile not found.']);
            exit;
        }
    }
    echo json_encode(['success'=>true,'message'=>'Your home address and personal details were saved.','address'=>$address,'dateOfBirth'=>$birthDate,'gender'=>$gender]);
    exit;
}

if ($method === 'POST' && $action === 'save_notifications' && $isAdmin) {
    $save = $pdo->prepare('UPDATE user SET notification_new_requests = ?, notification_payment_verification = ? WHERE user_id = ? AND role = ?');
    $save->execute([!empty($data['newRequests']) ? 1 : 0, !empty($data['paymentVerification']) ? 1 : 0, $id, 'Admin']);
    $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, 'admin_notification_preferences_updated', 'Updated notification preferences')")->execute([$id]);
    echo json_encode(['success'=>true,'message'=>'Notification preferences saved.']); exit;
}

if ($method === 'POST' && $isAdmin && in_array($action, ['mfa_begin','mfa_confirm','mfa_recovery_codes'], true)) {
    $admin = $pdo->prepare('SELECT password_hash, admin_mfa_secret_enc, admin_mfa_enabled_at, admin_mfa_last_counter FROM user WHERE user_id = ? AND role = ? LIMIT 1');
    $admin->execute([$id, 'Admin']); $account = $admin->fetch();
    if (!$account) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Account not found.']); exit; }
    if ($action === 'mfa_recovery_codes' && (empty($account['admin_mfa_enabled_at']) || empty($account['admin_mfa_secret_enc']))) { http_response_code(409); echo json_encode(['success'=>false,'message'=>'Set up authenticator MFA before generating recovery codes.']); exit; }
    if ($action === 'mfa_begin') {
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';
        if (!password_verify($password, (string) $account['password_hash'])) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Enter your current password to continue.']); exit; }
        $_SESSION['profile_mfa_setup_secret'] = generateAdminMfaSecret();
        $_SESSION['profile_mfa_setup_expires'] = time() + 600;
        echo json_encode(['success'=>true,'secret'=>$_SESSION['profile_mfa_setup_secret'],'replacing'=>!empty($account['admin_mfa_enabled_at'])]); exit;
    }
    if ($action === 'mfa_confirm') {
        $secret = (string) ($_SESSION['profile_mfa_setup_secret'] ?? '');
        if ($secret === '' || (int) ($_SESSION['profile_mfa_setup_expires'] ?? 0) < time()) { unset($_SESSION['profile_mfa_setup_secret'], $_SESSION['profile_mfa_setup_expires']); http_response_code(400); echo json_encode(['success'=>false,'message'=>'Setup expired. Start again.']); exit; }
        $code = trim(is_string($data['code'] ?? null) ? $data['code'] : '');
        $counter = preg_match('/^\d{6}$/', $code) ? verifyAdminTotp($secret, $code, null) : null;
        if ($counter === null) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Enter a current 6-digit code from your authenticator app.']); exit; }
        $codes = [];
        for ($i=0; $i<10; $i++) $codes[] = strtoupper(bin2hex(random_bytes(5)));
        $pdo->beginTransaction();
        try {
            $save = $pdo->prepare('UPDATE user SET admin_mfa_secret_enc = ?, admin_mfa_enabled_at = UTC_TIMESTAMP(), admin_mfa_last_counter = ? WHERE user_id = ?');
            $save->execute([encryptAdminMfaSecret($secret), $counter, $id]);
            $pdo->prepare('DELETE FROM admin_mfa_recovery_code WHERE user_id = ?')->execute([$id]);
            $insert = $pdo->prepare('INSERT INTO admin_mfa_recovery_code (user_id, code_hash) VALUES (?, ?)');
            foreach ($codes as $recovery) $insert->execute([$id, hash('sha256', $recovery)]);
            $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, 'admin_mfa_profile_updated', 'Authenticator set up or reset from profile')")->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); http_response_code(500); echo json_encode(['success'=>false,'message'=>'Could not save authenticator settings.']); exit; }
        unset($_SESSION['profile_mfa_setup_secret'], $_SESSION['profile_mfa_setup_expires']);
        echo json_encode(['success'=>true,'recoveryCodes'=>$codes,'message'=>'Authenticator enabled. Save these recovery codes now.']); exit;
    }
    $password = is_string($data['password'] ?? null) ? $data['password'] : '';
    $code = strtoupper(trim(is_string($data['code'] ?? null) ? $data['code'] : ''));
    if (!password_verify($password, (string) $account['password_hash']) || !preg_match('/^\d{6}$/', $code)) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Enter your current password and authenticator code.']); exit; }
    $secret = decryptAdminMfaSecret((string) $account['admin_mfa_secret_enc']);
    $matchedCounter = verifyAdminTotp($secret, $code, $account['admin_mfa_last_counter'] === null ? null : (int)$account['admin_mfa_last_counter']);
    if ($matchedCounter === null) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'The authenticator code is invalid or expired.']); exit; }
    $counterUpdate = $pdo->prepare('UPDATE user SET admin_mfa_last_counter = ? WHERE user_id = ? AND (admin_mfa_last_counter IS NULL OR admin_mfa_last_counter < ?)');
    $counterUpdate->execute([$matchedCounter, $id, $matchedCounter]);
    if ($counterUpdate->rowCount() !== 1) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'That authenticator code has already been used. Wait for the next code.']); exit; }
    $codes = []; for ($i=0; $i<10; $i++) $codes[] = strtoupper(bin2hex(random_bytes(5)));
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM admin_mfa_recovery_code WHERE user_id = ?')->execute([$id]);
        $insert = $pdo->prepare('INSERT INTO admin_mfa_recovery_code (user_id, code_hash) VALUES (?, ?)');
        foreach ($codes as $recovery) $insert->execute([$id, hash('sha256', $recovery)]);
        $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, 'admin_mfa_recovery_codes_generated', 'Generated new MFA recovery codes')")->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); http_response_code(500); echo json_encode(['success'=>false,'message'=>'Could not generate recovery codes.']); exit; }
    echo json_encode(['success'=>true,'recoveryCodes'=>$codes,'message'=>'New recovery codes generated. Previous codes no longer work.']); exit;
}

if ($method !== 'GET') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed.']); exit; }
$fields = $isAdmin
    ? 'email, first_name, middle_name, last_name, profile_photo, position_title, contact_number, staff_role, notification_new_requests, notification_payment_verification, password_changed_at, admin_mfa_enabled_at'
    : 'email, first_name, middle_name, last_name, suffix, phone_number, address, date_of_birth, civil_status, gender, profile_photo, password_changed_at, email_verified_at';
$stmt = $pdo->prepare("SELECT $fields FROM $table WHERE $idColumn = ? LIMIT 1"); $stmt->execute([$id]); $profile = $stmt->fetch();
if (!$profile) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Profile not found.']); exit; }
$payload = ['success'=>true,'photo'=>$profile['profile_photo'] ?: null,'passwordChangedAt'=>$profile['password_changed_at']];
if ($isAdmin) {
    $payload['staffRole'] = $profile['staff_role'] ?: 'service_clerk';
    $permissionLabels = [
        'super_admin' => ['Manage staff access', 'Review requests, residents, collections, disclosures, and security activity', 'Issue reviewed print-ready documents'],
        'service_clerk' => ['Review and update requests', 'Create walk-in requests', 'View resident accounts', 'Preview documents'],
        'cashier' => ['Review payment submissions', 'Record in-person collections', 'View collection history'],
        'document_issuer' => ['View requests needed to prepare documents', 'Preview and record staff-reviewed document printouts'],
    ];
    $payload['permissions'] = $permissionLabels[$payload['staffRole']] ?? [];
    $payload += ['position'=>$profile['position_title'] ?: '','contactNumber'=>$profile['contact_number'] ?: '','mfaEnabled'=>!empty($profile['admin_mfa_enabled_at']),'mfaEnabledAt'=>$profile['admin_mfa_enabled_at'],'notifications'=>['newRequests'=>(bool)$profile['notification_new_requests'],'paymentVerification'=>(bool)$profile['notification_payment_verification']]];
    $sessionList = $pdo->prepare('SELECT session_key, user_agent, created_at, last_active_at FROM account_session WHERE account_type = ? AND account_id = ? AND last_active_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY) ORDER BY last_active_at DESC LIMIT 20');
    $sessionList->execute(['admin',$id]); $payload['sessions'] = array_map(static function($row) { return ['current'=>hash_equals((string)$row['session_key'],hash('sha256',session_id())),'device'=>substr((string)$row['user_agent'],0,120) ?: 'Unknown device','createdAt'=>$row['created_at'],'lastActiveAt'=>$row['last_active_at']]; }, $sessionList->fetchAll());
    $signin = $pdo->prepare("SELECT action, details, created_at FROM audit_log WHERE user_id = ? AND action IN ('admin_login_mfa','admin_mfa_failed','failed_admin_login') ORDER BY created_at DESC LIMIT 10"); $signin->execute([$id]); $payload['signIns'] = $signin->fetchAll();
    $actions = $pdo->prepare("SELECT action, details, created_at FROM audit_log WHERE user_id = ? AND action NOT IN ('admin_login_mfa','admin_mfa_failed') ORDER BY created_at DESC LIMIT 10"); $actions->execute([$id]); $payload['recentActions'] = $actions->fetchAll();
} else {
    $payload += ['name'=>trim(implode(' ', array_filter([$profile['first_name'], $profile['middle_name'], $profile['last_name'], $profile['suffix']]))), 'phoneNumber'=>$profile['phone_number'] ?: '', 'address'=>$profile['address'] ?: '', 'dateOfBirth'=>$profile['date_of_birth'] ?: null, 'civilStatus'=>$profile['civil_status'] ?: '', 'gender'=>$profile['gender'] ?: '', 'emailVerified'=>!empty($profile['email_verified_at'])];
}
echo json_encode($payload);
?>
