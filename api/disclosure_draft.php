<?php
require_once 'db.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
requireAdminAccess();

$adminId = (int) $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'GET') {
    try {
        $stmt = $pdo->prepare("SELECT category_id, title, content, DATE_FORMAT(updated_at, '%Y-%m-%dT%H:%i:%sZ') AS updatedAt
                               FROM disclosure_draft WHERE admin_user_id = ? LIMIT 1");
        $stmt->execute([$adminId]);
        $draft = $stmt->fetch() ?: null;
        if ($draft) $draft['category_id'] = $draft['category_id'] === null ? null : (int) $draft['category_id'];
        echo json_encode(['success' => true, 'draft' => $draft]);
    } catch (Throwable $error) {
        error_log('Disclosure draft load failed.');
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Saved drafts are temporarily unavailable.']);
    }
    exit;
}

if ($method === 'POST') {
    $data = jsonBody(8192);
    $categoryValue = $data['categoryId'] ?? null;
    $title = $data['title'] ?? '';
    $content = $data['content'] ?? '';
    $categoryIsEmpty = $categoryValue === '' || $categoryValue === null;
    $categoryId = $categoryIsEmpty || (!is_string($categoryValue) && !is_int($categoryValue))
        ? null
        : filter_var($categoryValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $titleLength = is_string($title) ? (function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title)) : PHP_INT_MAX;
    $contentLength = is_string($content) ? (function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content)) : PHP_INT_MAX;

    if ((!$categoryIsEmpty && (!$categoryId || !in_array($categoryId, [1, 2, 3, 4], true)))
        || $titleLength > 120 || $contentLength > 2000) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Choose a valid category and keep the title to 120 characters and the description to 2,000 characters or fewer.']);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $save = $pdo->prepare("INSERT INTO disclosure_draft (admin_user_id, category_id, title, content, created_at, updated_at)
                               VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                               ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), title = VALUES(title), content = VALUES(content), updated_at = UTC_TIMESTAMP()");
        $save->execute([$adminId, $categoryId, $title, $content]);

        $audit = $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, 'disclosure_draft_saved', 'Disclosure draft saved to account')");
        $audit->execute([$adminId]);

        $read = $pdo->prepare("SELECT category_id, title, content, DATE_FORMAT(updated_at, '%Y-%m-%dT%H:%i:%sZ') AS updatedAt
                               FROM disclosure_draft WHERE admin_user_id = ? LIMIT 1");
        $read->execute([$adminId]);
        $draft = $read->fetch();
        if ($draft) $draft['category_id'] = $draft['category_id'] === null ? null : (int) $draft['category_id'];
        $pdo->commit();
        echo json_encode(['success' => true, 'draft' => $draft]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Disclosure draft save failed.');
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Draft could not be saved to your account. Try again.']);
    }
    exit;
}

if ($method === 'DELETE') {
    try {
        $pdo->beginTransaction();
        $delete = $pdo->prepare('DELETE FROM disclosure_draft WHERE admin_user_id = ?');
        $delete->execute([$adminId]);
        if ($delete->rowCount() > 0) {
            $audit = $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, 'disclosure_draft_deleted', 'Disclosure draft deleted from account')");
            $audit->execute([$adminId]);
        }
        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Disclosure draft delete failed.');
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'Draft could not be deleted. Try again.']);
    }
    exit;
}

header('Allow: GET, POST, DELETE');
http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
?>
