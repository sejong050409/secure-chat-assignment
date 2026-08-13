<?php
require __DIR__ . '/../vendor/autoload.php';

use SecureChat\Auth;
use SecureChat\Database;

Auth::bootSession();
$userId = Auth::id();
if (!$userId) {
    header('Location: /login.php');
    exit;
}
$stmt = Database::pdo()->prepare('SELECT username FROM users WHERE id = ?');
$stmt->execute([$userId]);
$username = (string) $stmt->fetchColumn();
$csrf = Auth::csrfToken();
$wsUrl = getenv('WS_PUBLIC_URL') ?: 'ws://localhost:8081';
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><title>Secure Chat</title><link rel="stylesheet" href="/assets/app.css"></head>
<body data-user-id="<?= (int) $userId ?>" data-ws-url="<?= htmlspecialchars($wsUrl, ENT_QUOTES, 'UTF-8') ?>">
<header><strong>Secure Chat</strong><span><?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></span><form method="post" action="/logout.php"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><button>Logout</button></form></header>
<main class="layout">
<aside><h2>Friends</h2><form id="friend-form"><input id="friend-name" maxlength="32" placeholder="username" required><button>Add</button></form><p id="friend-error" class="error"></p><div id="friends"></div></aside>
<section class="chat"><div id="chat-title">Choose a friend</div><div id="messages" aria-live="polite"></div><div class="composer"><textarea id="message-input" maxlength="4000" placeholder="Message"></textarea><button id="send-btn">Send</button><label class="file-button">Attach<input id="file-input" type="file" hidden></label><button id="url-btn">URL</button></div><div id="status"></div></section>
</main>
<script src="/assets/app.js" defer></script>
</body></html>
