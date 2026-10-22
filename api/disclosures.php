<?php
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') $GLOBALS['skipAppSessionForPublicRead'] = true;
require_once 'db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
    $page = max(1, min(1000, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
    $perPage = max(1, min(100, filter_var($_GET['limit'] ?? 40, FILTER_VALIDATE_INT) ?: 40));
    $offset = ($page - 1) * $perPage;
    $categoryId = filter_var($_GET['category'] ?? null, FILTER_VALIDATE_INT);
    if (!in_array($categoryId, [1, 2, 3, 4], true)) $categoryId = null;
    $search = trim(is_string($_GET['search'] ?? null) ? $_GET['search'] : '');
    if (strlen($search) > 100) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Search text is too long.']);
        exit;
    }

    $where = ['d.is_archived = FALSE'];
    $params = [];
    if ($categoryId !== null) {
        $where[] = 'd.category_id = ?';
        $params[] = $categoryId;
    }
    if ($search !== '') {
        $where[] = '(d.title LIKE ? OR d.content LIKE ? OR da.file_name LIKE ? OR c.category_name LIKE ?)';
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $whereSql = implode(' AND ', $where);
    $count = $pdo->prepare("SELECT COUNT(DISTINCT d.disclosure_id)
        FROM disclosure d
        JOIN disclosure_category c ON d.category_id = c.category_id
        LEFT JOIN disclosure_attachment da ON da.attachment_id = (
            SELECT MIN(attachment_id) FROM disclosure_attachment WHERE disclosure_id = d.disclosure_id
        )
        WHERE $whereSql");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $categoryCounts = $pdo->query('SELECT category_id, COUNT(*) AS total FROM disclosure WHERE is_archived = FALSE GROUP BY category_id')->fetchAll();
    $categoryCountsById = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
    foreach ($categoryCounts as $categoryCount) {
        $categoryCountsById[(int) $categoryCount['category_id']] = (int) $categoryCount['total'];
    }
    header('X-Public-Records-Total: ' . $total);
    header('X-Public-Records-Page: ' . $page);
    header('X-Public-Records-Per-Page: ' . $perPage);
    header('X-Public-Records-Category-Counts: ' . rawurlencode(json_encode($categoryCountsById)));

    $stmt = $pdo->prepare(" 
        SELECT d.disclosure_id, d.category_id, d.published_by_user_id, d.title, d.content,
               d.is_archived, d.timestamp_posted, c.category_name,
               da.attachment_id, da.file_name, da.file_type,
               da.file_path AS attachment_storage_path,
               CASE WHEN da.attachment_id IS NULL THEN NULL ELSE CONCAT('api/public_attachment.php?id=', da.attachment_id) END AS file_path
        FROM disclosure d
        JOIN disclosure_category c ON d.category_id = c.category_id
        LEFT JOIN disclosure_attachment da ON da.attachment_id = (
            SELECT MIN(attachment_id) FROM disclosure_attachment WHERE disclosure_id = d.disclosure_id
        )
        WHERE $whereSql
        ORDER BY d.timestamp_posted DESC, d.disclosure_id DESC
        LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $items = $stmt->fetchAll();
    foreach ($items as &$item) {
        $item['file_size'] = null;
        $storedPath = $item['attachment_storage_path'] ?? null;
        if (is_string($storedPath) && $storedPath !== '') {
            try {
                $resolvedPath = resolveStoredUpload($storedPath);
                if ($resolvedPath !== null && is_file($resolvedPath)) {
                    $size = filesize($resolvedPath);
                    $item['file_size'] = $size === false ? null : $size;
                }
            } catch (Throwable $error) {
                // Keep the public record available if attachment metadata cannot be read.
            }
        }
        unset($item['attachment_storage_path']);
    }
    unset($item);
    echo json_encode($items);
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
    $audit->execute([$_SESSION['user_id'], 'disclosure_edited', 'Disclosure ID ' . $id . ' was edited']);
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
