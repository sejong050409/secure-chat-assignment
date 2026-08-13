<?php
namespace SecureChat;

final class Auth
{
    public static function bootSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name('secure_chat_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => getenv('COOKIE_SECURE') === '1',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public static function id(): ?int
    {
        self::bootSession();
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function requireUser(): int
    {
        $id = self::id();
        if (!$id) {
            http_response_code(401);
            self::json(['error' => 'Authentication required']);
        }
        return $id;
    }

    public static function csrfToken(): string
    {
        self::bootSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(): void
    {
        self::bootSession();
        $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
        $expected = $_SESSION['csrf_token'] ?? '';
        if ($expected === '' || !is_string($provided) || !hash_equals($expected, $provided)) {
            http_response_code(403);
            self::json(['error' => 'Invalid CSRF token']);
        }
    }

    public static function areFriends(int $a, int $b): bool
    {
        $stmt = Database::pdo()->prepare('SELECT 1 FROM friends WHERE user_id = ? AND friend_id = ?');
        $stmt->execute([$a, $b]);
        return (bool) $stmt->fetchColumn();
    }

    public static function json(array $data): never
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
