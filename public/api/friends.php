<?php
require __DIR__ . '/../../vendor/autoload.php';

use SecureChat\Auth;
use SecureChat\Database;

$userId = Auth::requireUser();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = Database::pdo()->prepare('SELECT u.id, u.username FROM friends f JOIN users u ON u.id = f.friend_id WHERE f.user_id = ? ORDER BY u.username');
    $stmt->execute([$userId]);
    Auth::json(['friends' => $stmt->fetchAll()]);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $username = trim((string) ($data['username'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
        http_response_code(400); Auth::json(['error' => 'Invalid username']);
    }
    $pdo = Database::pdo();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $friendId = (int) ($stmt->fetchColumn() ?: 0);
    if (!$friendId || $friendId === $userId) {
        http_response_code(400); Auth::json(['error' => 'User not found']);
    }
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare('INSERT IGNORE INTO friends(user_id, friend_id) VALUES(?, ?)');
        $insert->execute([$userId, $friendId]);
        $insert->execute([$friendId, $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack(); throw $e;
    }
    Auth::json(['ok' => true]);
}
http_response_code(405); Auth::json(['error' => 'Method not allowed']);
