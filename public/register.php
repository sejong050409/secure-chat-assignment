<?php
require __DIR__ . '/../vendor/autoload.php';

use SecureChat\Auth;
use SecureChat\Database;

Auth::bootSession();
if (Auth::id()) {
    header('Location: /');
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::verifyCsrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $username)) {
        $error = 'Username must be 3-32 letters, numbers, or underscores.';
    } elseif (strlen($password) < 10 || strlen($password) > 128) {
        $error = 'Password must be 10-128 characters.';
    } else {
        try {
            $stmt = Database::pdo()->prepare('INSERT INTO users(username, password_hash) VALUES(?, ?)');
            $stmt->execute([$username, password_hash($password, PASSWORD_ARGON2ID)]);
            header('Location: /login.php?registered=1');
            exit;
        } catch (Throwable $e) {
            $error = 'That username is already in use.';
        }
    }
}
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Register - Secure Chat</title><link rel="stylesheet" href="/assets/app.css"></head>
<body class="auth-page"><main class="auth-card"><h1>Create account</h1><?php if ($error): ?><p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><label>Username<input name="username" required minlength="3" maxlength="32" autocomplete="username"></label><label>Password<input name="password" type="password" required minlength="10" maxlength="128" autocomplete="new-password"></label><button>Create account</button></form><p><a href="/login.php">Already have an account?</a></p></main></body></html>
