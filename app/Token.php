<?php
namespace SecureChat;

final class Token
{
    private static function b64urlEncode(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $s): string|false
    {
        $pad = strlen($s) % 4;
        if ($pad) {
            $s .= str_repeat('=', 4 - $pad);
        }
        return base64_decode(strtr($s, '-_', '+/'), true);
    }

    public static function issue(int $userId): string
    {
        $payload = json_encode([
            'uid' => $userId,
            'exp' => time() + 60,
            'nonce' => bin2hex(random_bytes(12)),
        ], JSON_UNESCAPED_SLASHES);
        $encoded = self::b64urlEncode($payload);
        $sig = hash_hmac('sha256', $encoded, getenv('APP_KEY') ?: '', true);
        return $encoded . '.' . self::b64urlEncode($sig);
    }

    public static function verify(string $token): ?int
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $sigText] = $parts;
        $sig = self::b64urlDecode($sigText);
        if ($sig === false) {
            return null;
        }
        $expected = hash_hmac('sha256', $encoded, getenv('APP_KEY') ?: '', true);
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $payloadText = self::b64urlDecode($encoded);
        if ($payloadText === false) {
            return null;
        }
        $payload = json_decode($payloadText, true);
        if (!is_array($payload) || !isset($payload['uid'], $payload['exp'])) {
            return null;
        }
        if ((int) $payload['exp'] < time()) {
            return null;
        }
        return (int) $payload['uid'];
    }
}
