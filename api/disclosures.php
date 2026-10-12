<?php
require_once 'db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query("
        SELECT d.*, c.category_name, 
               da.file_path, da.file_name
        FROM disclosure d
        JOIN disclosure_category c ON d.category_id = c.category_id
        LEFT JOIN disclosure_attachment da ON d.disclosure_id = da.disclosure_id
        WHERE d.is_archived = FALSE
        ORDER BY d.timestamp_posted DESC
    ");
    echo json_encode($stmt->fetchAll());
} 

elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') {
        http_response_code(403);
        echo json_encode(['success'=>false, 'message'=>'Administrator access required']);
        exit;
    }

    try {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $categoryId = (int) ($_POST['categoryId'] ?? 1);
        $file = $_FILES['file'] ?? null;

        if (!$title || !$content || !in_array($categoryId, [1, 2, 3, 4], true)) {
            echo json_encode(['success' => false, 'message' => 'Title, description, and a valid category are required']);
            exit;
        }

        $attachment = null;
        if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'Attachment must be a file no larger than 5 MB']);
                exit;
            }

            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowedExtensions = ['pdf', 'docx', 'jpg', 'jpeg', 'png'];
            if (!in_array($extension, $allowedExtensions, true)) {
                echo json_encode(['success' => false, 'message' => 'Unsupported attachment file type']);
                exit;
            }

            $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'disclosures';
            if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true)) {
                throw new RuntimeException('Unable to create attachment directory');
            }

            $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
            $storedPath = $uploadDirectory . DIRECTORY_SEPARATOR . $storedName;
            if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
                throw new RuntimeException('Unable to save attachment');
            }

            $attachment = [
                'name' => basename($file['name']),
                'path' => 'uploads/disclosures/' . $storedName,
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
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (!empty($attachment['storedPath']) && is_file($attachment['storedPath'])) unlink($attachment['storedPath']);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Disclosure could not be posted']);
    }
}

elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') {
        http_response_code(403);
        echo json_encode(['success'=>false, 'message'=>'Administrator access required']);
        exit;
    }
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
    $title = trim($data['title'] ?? '');
    $content = trim($data['content'] ?? '');
    $categoryId = filter_var($data['categoryId'] ?? null, FILTER_VALIDATE_INT);
    if (!$id || !$title || !$content || !in_array($categoryId, [1, 2, 3, 4], true)) {
        http_response_code(400);
        echo json_encode(['success'=>false, 'message'=>'Title, description, and a valid category are required']);
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
    $audit->execute([$_SESSION['user_id'], 'disclosure_edited', "Disclosure #$id: $title"]);
    echo json_encode(['success'=>true]);
}

elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') {
        http_response_code(403);
        echo json_encode(['success'=>false, 'message'=>'Administrator access required']);
        exit;
    }
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
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
