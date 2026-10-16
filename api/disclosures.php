<?php
require_once 'db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query("
        SELECT d.disclosure_id, d.category_id, d.published_by_user_id, d.title, d.content,
               d.is_archived, d.timestamp_posted, c.category_name,
               da.attachment_id, da.file_name,
               CASE WHEN da.attachment_id IS NULL THEN NULL ELSE CONCAT('api/public_attachment.php?id=', da.attachment_id) END AS file_path
        FROM disclosure d
        JOIN disclosure_category c ON d.category_id = c.category_id
        LEFT JOIN disclosure_attachment da ON d.disclosure_id = da.disclosure_id
        WHERE d.is_archived = FALSE
        ORDER BY d.timestamp_posted DESC
    ");
    echo json_encode($stmt->fetchAll());
} 

elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdminAccess();

    try {
        $title = trim(is_string($_POST['title'] ?? null) ? $_POST['title'] : '');
        $content = trim(is_string($_POST['content'] ?? null) ? $_POST['content'] : '');
        $categoryId = filter_var($_POST['categoryId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $file = $_FILES['file'] ?? null;

        $titleLength = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
        $contentLength = function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);
        if (!$title || !$content || $titleLength > 120 || $contentLength > 2000 || !in_array($categoryId, [1, 2, 3, 4], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Choose a category and enter a title (up to 120 characters) and description (up to 2,000 characters)']);
            exit;
        }

        $attachment = null;
        if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'Attachment must be a file no larger than 5 MB']);
                exit;
            }

            $extension = validateUpload($file, ['pdf' => true, 'docx' => true, 'jpg' => true, 'jpeg' => true, 'png' => true], 5 * 1024 * 1024);
            if ($extension === null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Unsupported attachment file type']);
                exit;
            }

            $uploadDirectory = privateStorageDirectory('disclosures');

            $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
            $storedPath = $uploadDirectory . DIRECTORY_SEPARATOR . $storedName;
            if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
                throw new RuntimeException('Unable to save attachment');
            }

            $originalName = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename(str_replace('\\', '/', (string) ($file['name'] ?? ''))));
            $attachment = [
                'name' => substr((string) $originalName, 0, 255) ?: 'document.' . $extension,
                'path' => 'disclosures/' . $storedName,
                'type' => $extension,
                'storedPath' => $storedPath
            ];
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO disclosure (category_id, published_by_user_id, title, content) VALUES (?, ?, ?, ?)");
        $stmt->execute([$categoryId, $_SESSION['user_id'], $title, $content]);
        $disclosureId = $pdo->lastInsertId();

        if ($attachment) {
            $att = $pdo->prepare("INSERT INTO disclosure_attachment (disclosure_id, file_name, file_path, file_type) VALUES (?, ?, ?, ?)");
            $att->execute([$disclosureId, $attachment['name'], $attachment['path'], $attachment['type']]);
        }

        $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)');
        $audit->execute([$_SESSION['user_id'], 'disclosure_published', "Disclosure #$disclosureId: $title"]);

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        error_log('Disclosure create failed: ' . $e->getMessage());
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!empty($attachment['storedPath']) && is_file($attachment['storedPath'])) unlink($attachment['storedPath']);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Disclosure could not be posted']);
    }
}

elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    requireAdminAccess();
    $data = jsonBody(12000);
    $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
    $title = trim(is_string($data['title'] ?? null) ? $data['title'] : '');
    $content = trim(is_string($data['content'] ?? null) ? $data['content'] : '');
    $categoryId = filter_var($data['categoryId'] ?? null, FILTER_VALIDATE_INT);
    $titleLength = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
    $contentLength = function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);
    if (!$id || !$title || !$content || $titleLength > 120 || $contentLength > 2000 || !in_array($categoryId, [1, 2, 3, 4], true)) {
        http_response_code(400);
        echo json_encode(['success'=>false, 'message'=>'Choose a category and enter a title (up to 120 characters) and description (up to 2,000 characters)']);
        exit;
    }
    $beforeStmt = $pdo->prepare('SELECT title, content FROM disclosure WHERE disclosure_id = ? AND is_archived = FALSE');
    $beforeStmt->execute([$id]);
    $before = $beforeStmt->fetch();
    $stmt = $pdo->prepare('UPDATE disclosure SET category_id = ?, title = ?, content = ? WHERE disclosure_id = ? AND is_archived = FALSE');
    $stmt->execute([$categoryId, $title, $content, $id]);
    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare('SELECT disclosure_id FROM disclosure WHERE disclosure_id = ? AND is_archived = FALSE');
        $check->execute([$id]);
        if (!$check->fetch()) {
            http_response_code(404);
            echo json_encode(['success'=>false, 'message'=>'Disclosure not found']);
            exit;
        }
    }
    $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)');
    $audit->execute([$_SESSION['user_id'], 'disclosure_edited', json_encode(['disclosure_id' => $id, 'title' => $title, 'before' => $before, 'after' => ['title' => $title, 'content' => $content]], JSON_UNESCAPED_UNICODE)]);
    echo json_encode(['success'=>true]);
}

elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    requireAdminAccess();
    $data = jsonBody(8192);
    $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success'=>false, 'message'=>'A valid disclosure ID is required']);
        exit;
    }
    $disclosure = $pdo->prepare('SELECT title FROM disclosure WHERE disclosure_id = ? AND is_archived = FALSE');
    $disclosure->execute([$id]);
    $title = $disclosure->fetchColumn();
    if (!$title) {
        http_response_code(404);
        echo json_encode(['success'=>false, 'message'=>'Disclosure not found']);
        exit;
    }
    $stmt = $pdo->prepare('UPDATE disclosure SET is_archived = TRUE WHERE disclosure_id = ? AND is_archived = FALSE');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['success'=>false, 'message'=>'Disclosure not found']);
        exit;
    }
    $audit = $pdo->prepare('INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)');
    $audit->execute([$_SESSION['user_id'], 'disclosure_unpublished', "Disclosure #$id: $title"]);
    echo json_encode(['success'=>true]);
}
?>
