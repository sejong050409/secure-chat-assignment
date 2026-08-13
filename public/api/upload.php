<?php
require __DIR__ . '/../../vendor/autoload.php';

use SecureChat\Auth;
use SecureChat\Database;
use SecureChat\MessageRepository;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); Auth::json(['error' => 'Method not allowed']); }
$userId = Auth::requireUser();
Auth::verifyCsrf();
$friendId = (int) ($_POST['recipient_id'] ?? 0);
if ($friendId <= 0 || !Auth::areFriends($userId, $friendId)) { http_response_code(403); Auth::json(['error' => 'Not authorized']); }
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) { http_response_code(400); Auth::json(['error' => 'Upload failed']); }
$file = $_FILES['file'];
if ((int) $file['size'] <= 0 || (int) $file['size'] > 8 * 1024 * 1024) { http_response_code(400); Auth::json(['error' => 'Maximum file size is 8 MB']); }

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file($file['tmp_name']);
$allowed = [
    'image/jpeg' => ['ext' => 'jpg', 'type' => 'image'],
    'image/png' => ['ext' => 'png', 'type' => 'image'],
    'image/gif' => ['ext' => 'gif', 'type' => 'image'],
    'image/webp' => ['ext' => 'webp', 'type' => 'image'],
    'application/pdf' => ['ext' => 'pdf', 'type' => 'file'],
    'text/plain' => ['ext' => 'txt', 'type' => 'file'],
    'application/zip' => ['ext' => 'zip', 'type' => 'file'],
];
if (!isset($allowed[$mime])) { http_response_code(400); Auth::json(['error' => 'File type is not allowed']); }
$original = basename((string) $file['name']);
$original = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $original) ?: 'file', 0, 200);
$stored = bin2hex(random_bytes(32)) . '.' . $allowed[$mime]['ext'];
$target = '/var/www/storage/uploads/' . $stored;
if (!move_uploaded_file($file['tmp_name'], $target)) { http_response_code(500); Auth::json(['error' => 'Could not store upload']); }
chmod($target, 0640);

$pdo = Database::pdo();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('INSERT INTO messages(sender_id, receiver_id, type, body) VALUES(?, ?, ?, NULL)');
    $stmt->execute([$userId, $friendId, $allowed[$mime]['type']]);
    $messageId = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare('INSERT INTO attachments(message_id, original_name, stored_name, mime_type, file_size) VALUES(?, ?, ?, ?, ?)');
    $stmt->execute([$messageId, $original, $stored, $mime, (int) $file['size']]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    @unlink($target);
    throw $e;
}
Auth::json(['message' => MessageRepository::get($messageId)]);
