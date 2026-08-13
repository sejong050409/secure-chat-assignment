<?php
require __DIR__ . '/../vendor/autoload.php';
use SecureChat\Auth;

Auth::bootSession();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST required');
}
Auth::verifyCsrf();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: /login.php');
