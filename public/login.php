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

    $stmt = Database::pdo()->prepare('SELECT id, password_hash FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header('Location: /');
        exit;
    }
    usleep(250000);
    $error = 'Invalid username or password.';
}
$csrf = htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login - Secure Chat</title><link rel="stylesheet" href="/assets/app.css"></head>
<body class="auth-page"><main class="auth-card"><h1>Secure Chat</h1><?php if (isset($_GET['registered'])): ?><p class="success">Account created. Please sign in.</p><?php endif; ?><?php if ($error): ?><p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"><label>Username<input name="username" required autocomplete="username"></label><label>Password<input name="password" type="password" required autocomplete="current-password"></label><button>Sign in</button></form><p><a href="/register.php">Create account</a></p></main></body></html>
