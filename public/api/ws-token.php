<?php
require __DIR__ . '/../../vendor/autoload.php';
use SecureChat\Auth;
use SecureChat\Token;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); Auth::json(['error' => 'Method not allowed']); }
$userId = Auth::requireUser();
Auth::verifyCsrf();
Auth::json(['token' => Token::issue($userId)]);
