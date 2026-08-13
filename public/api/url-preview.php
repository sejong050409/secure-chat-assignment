<?php
require __DIR__ . '/../../vendor/autoload.php';

use SecureChat\Auth;
use SecureChat\Database;
use SecureChat\MessageRepository;
use SecureChat\UrlPreview;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); Auth::json(['error' => 'Method not allowed']); }
$userId = Auth::requireUser();
Auth::verifyCsrf();
$data = json_decode(file_get_contents('php://input'), true) ?: [];
$friendId = (int) ($data['recipient_id'] ?? 0);
$url = trim((string) ($data['url'] ?? ''));
if ($friendId <= 0 || !Auth::areFriends($userId, $friendId)) { http_response_code(403); Auth::json(['error' => 'Not authorized']); }
try {
    $preview = UrlPreview::fetch($url);
} catch (Throwable $e) {
    http_response_code(400); Auth::json(['error' => $e->getMessage()]);
}
$stmt = Database::pdo()->prepare('INSERT INTO messages(sender_id, receiver_id, type, body) VALUES(?, ?, "url", ?)');
$stmt->execute([$userId, $friendId, json_encode($preview, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
$messageId = (int) Database::pdo()->lastInsertId();
Auth::json(['message' => MessageRepository::get($messageId)]);
