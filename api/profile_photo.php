<?php
require_once __DIR__ . '/db.php';
header('Cache-Control: no-store, private');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$role = (string) ($_SESSION['role'] ?? '');
$isAdmin = $role === 'Admin' && !empty($_SESSION['user_id']);
$id = $isAdmin ? (int) $_SESSION['user_id'] : (($role === 'Resident') ? (int) ($_SESSION['resident_id'] ?? 0) : 0);
if ($id < 1) { http_response_code(401); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'message'=>'Log in to continue.']); exit; }
$table = $isAdmin ? '`user`' : 'resident'; $idColumn = $isAdmin ? 'user_id' : 'resident_id';
$uploadDir = privateStorageDirectory('profile-photos');
if ($method === 'GET') {
    $findPhoto = $pdo->prepare("SELECT profile_photo FROM $table WHERE $idColumn = ? LIMIT 1");
    $findPhoto->execute([$id]);
    $photo = $findPhoto->fetchColumn();
    if (!is_string($photo) || !preg_match('/^[a-f0-9]{32}\.(?:webp|jpg)$/', $photo)) { http_response_code(404); exit; }
    $photoPath = $uploadDir . DIRECTORY_SEPARATOR . $photo;
    $photoInfo = is_file($photoPath) ? @getimagesize($photoPath) : false;
    $expectedMime = str_ends_with($photo, '.webp') ? 'image/webp' : 'image/jpeg';
    if (!is_array($photoInfo) || ($photoInfo['mime'] ?? '') !== $expectedMime) { http_response_code(404); exit; }
    header('Content-Type: ' . $expectedMime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . (string) filesize($photoPath));
    readfile($photoPath);
    exit;
}
header('Content-Type: application/json; charset=utf-8');
if ($method !== 'POST') { http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed.']); exit; }
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$find = $pdo->prepare("SELECT profile_photo FROM $table WHERE $idColumn = ? LIMIT 1"); $find->execute([$id]); $old = $find->fetchColumn();
if ($old === false) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Account not found.']); exit; }
if ($action === 'remove') {
    $pdo->prepare("UPDATE $table SET profile_photo = NULL WHERE $idColumn = ?")->execute([$id]);
    if ($isAdmin) $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, 'admin_profile_photo_removed', 'Removed profile photo')")->execute([$id]);
    if (is_string($old) && preg_match('/^[a-f0-9]{32}\.(?:webp|jpg)$/', $old)) { $path = $uploadDir . DIRECTORY_SEPARATOR . $old; if (is_file($path)) @unlink($path); }
    echo json_encode(['success'=>true,'photo'=>null]); exit;
}
if ($action !== 'upload' || !isset($_FILES['photo']) || !is_uploaded_file($_FILES['photo']['tmp_name'])) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Choose a photo to upload.']); exit; }
$file = $_FILES['photo'];
if ((int)$file['error'] !== UPLOAD_ERR_OK) { http_response_code(400); echo json_encode(['success'=>false,'message'=>((int)$file['error'] === UPLOAD_ERR_INI_SIZE || (int)$file['error'] === UPLOAD_ERR_FORM_SIZE) ? 'Choose an image under 2 MB.' : 'The photo could not be uploaded.']); exit; }
if ((int)$file['size'] < 1 || (int)$file['size'] > 2 * 1024 * 1024) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Choose an image under 2 MB.']); exit; }
$imageInfo = @getimagesize($file['tmp_name']);
$mime = is_array($imageInfo) ? (string)($imageInfo['mime'] ?? '') : '';
if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Choose a JPG, PNG, or WebP image.']); exit; }
if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor') || !function_exists('imagecopyresampled')) { http_response_code(503); echo json_encode(['success'=>false,'message'=>'Photo uploads are not enabled on this site.']); exit; }
if (!function_exists('imagewebp') && !function_exists('imagejpeg')) { http_response_code(503); echo json_encode(['success'=>false,'message'=>'Photo uploads are not enabled on this site.']); exit; }
if ((int)($imageInfo[0] ?? 0) < 1 || (int)($imageInfo[1] ?? 0) < 1 || (int)$imageInfo[0] * (int)$imageInfo[1] > 25000000) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Choose a smaller-dimension image.']); exit; }
$bytes = file_get_contents($file['tmp_name']); $source = is_string($bytes) ? @imagecreatefromstring($bytes) : false;
if (!$source) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'That image could not be read.']); exit; }
$width = imagesx($source); $height = imagesy($source); $side = min($width, $height); $sx = (int)(($width - $side) / 2); $sy = (int)(($height - $side) / 2);
$useWebp = function_exists('imagewebp');
$extension = $useWebp ? 'webp' : 'jpg';
$target = imagecreatetruecolor(256,256);
if ($useWebp) { imagealphablending($target,false); imagesavealpha($target,true); }
else { $background = imagecolorallocate($target,255,255,255); imagefill($target,0,0,$background); imagealphablending($target,true); }
if (!imagecopyresampled($target,$source,0,0,$sx,$sy,256,256,$side,$side)) { imagedestroy($source); imagedestroy($target); http_response_code(500); echo json_encode(['success'=>false,'message'=>'Could not prepare that image.']); exit; }
if (!is_dir($uploadDir) && !@mkdir($uploadDir,0755,true) && !is_dir($uploadDir)) { imagedestroy($source); imagedestroy($target); http_response_code(500); echo json_encode(['success'=>false,'message'=>'Photo storage is unavailable.']); exit; }
$name = bin2hex(random_bytes(16)) . '.' . $extension; $path = $uploadDir . DIRECTORY_SEPARATOR . $name;
$saved = $useWebp ? @imagewebp($target,$path,85) : @imagejpeg($target,$path,88); imagedestroy($source); imagedestroy($target);
if (!$saved) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Could not save that photo.']); exit; }
try {
    $pdo->beginTransaction();
    $save = $pdo->prepare("UPDATE $table SET profile_photo = ? WHERE $idColumn = ?"); $save->execute([$name,$id]);
    if ($isAdmin) $pdo->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, 'admin_profile_photo_updated', 'Updated profile photo')")->execute([$id]);
    $pdo->commit();
}
catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); @unlink($path); http_response_code(500); echo json_encode(['success'=>false,'message'=>'Could not save that photo.']); exit; }
if (is_string($old) && preg_match('/^[a-f0-9]{32}\.(?:webp|jpg)$/',$old)) { $oldPath = $uploadDir . DIRECTORY_SEPARATOR . $old; if (is_file($oldPath)) @unlink($oldPath); }
echo json_encode(['success'=>true,'photo'=>$name,'photoUrl'=>'api/profile_photo.php']);
?>
