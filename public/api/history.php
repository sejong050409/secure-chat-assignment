<?php
require __DIR__ . '/../../vendor/autoload.php';
use SecureChat\Auth;
use SecureChat\MessageRepository;

$userId = Auth::requireUser();
$friendId = (int) ($_GET['friend_id'] ?? 0);
if ($friendId <= 0 || !Auth::areFriends($userId, $friendId)) {
    http_response_code(403); Auth::json(['error' => 'Not authorized']);
}
Auth::json(['messages' => MessageRepository::history($userId, $friendId)]);
