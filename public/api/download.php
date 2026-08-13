<?php
require __DIR__ . '/../../vendor/autoload.php';

use SecureChat\Auth;
use SecureChat\Database;

$userId = Auth::requireUser();
$attachmentId = (int) ($_GET['id'] ?? 0);
$stmt = Database::pdo()->prepare(
    'SELECT a.original_name, a.stored_name, a.mime_type, a.file_size, m.sender_id, m.receiver_id
     FROM attachments a JOIN messages m ON m.id = a.message_id WHERE a.id = ?'
);
$stmt->execute([$attachmentId]);
$file = $stmt->fetch();
if (!$file || ($userId !== (int)$file['sender_id'] && $userId !== (int)$file['receiver_id'])) {
    http_response_code(404); exit('Not found');
}
$path = '/var/www/storage/uploads/' . $file['stored_name'];
if (!is_file($path)) { http_response_code(404); exit('Not found'); }
$inlineRequested = ($_GET['inline'] ?? '') === '1';
$inlineAllowed = str_starts_with((string)$file['mime_type'], 'image/');
$disposition = ($inlineRequested && $inlineAllowed) ? 'inline' : 'attachment';
$safeName = str_replace(['"', "\r", "\n"], '', (string)$file['original_name']);
header('Content-Type: ' . $file['mime_type']);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');
readfile($path);
