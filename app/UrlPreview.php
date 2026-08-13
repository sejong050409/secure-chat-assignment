<?php
namespace SecureChat;

use RuntimeException;

final class UrlPreview
{
    private const MAX_BYTES = 1024 * 1024;
    private const MAX_REDIRECTS = 3;

    public static function fetch(string $url): array
    {
        $current = trim($url);
        for ($i = 0; $i <= self::MAX_REDIRECTS; $i++) {
            [$safeUrl, $host, $port, $ip] = self::validateAndResolve($current);
            $result = self::requestPinned($safeUrl, $host, $port, $ip);

            if ($result['status'] >= 300 && $result['status'] < 400) {
                if ($i === self::MAX_REDIRECTS || !$result['location']) {
                    throw new RuntimeException('Too many or invalid redirects');
                }
                $current = self::resolveRedirect($safeUrl, $result['location']);
                continue;
            }

            if ($result['status'] < 200 || $result['status'] >= 300) {
                throw new RuntimeException('Remote server returned HTTP ' . $result['status']);
            }
            if (!str_starts_with(strtolower($result['content_type']), 'text/html')) {
                throw new RuntimeException('Only HTML pages can be previewed');
            }
            return self::parseHtml($safeUrl, $result['body']);
        }
        throw new RuntimeException('Preview failed');
    }

    private static function validateAndResolve(string $url): array
    {
        if (strlen($url) > 2048) {
            throw new RuntimeException('URL is too long');
        }
        $parts = parse_url($url);
        if (!$parts || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('Invalid URL');
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Only http/https URLs are allowed');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('URL credentials are not allowed');
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        if ($host === 'localhost') {
            throw new RuntimeException('Local hosts are blocked');
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, [80, 443], true)) {
            throw new RuntimeException('Only ports 80 and 443 are allowed');
        }

        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
            foreach ($records as $record) {
                if (!empty($record['ip'])) $ips[] = $record['ip'];
                if (!empty($record['ipv6'])) $ips[] = $record['ipv6'];
            }
        }
        if (!$ips) {
            throw new RuntimeException('Host did not resolve');
        }
        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                throw new RuntimeException('Private or reserved network targets are blocked');
            }
        }

        return [$url, $host, $port, $ips[0]];
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private static function requestPinned(string $url, string $host, int $port, string $ip): array
    {
        $headers = [];
        $body = '';
        $tooLarge = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_USERAGENT => 'SecureChatPreview/1.0',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers): int {
                $trimmed = trim($line);
                if (str_contains($trimmed, ':')) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($tooLarge) {
            throw new RuntimeException('Remote response is too large');
        }
        if ($ok === false && $status === 0) {
            throw new RuntimeException('Could not fetch URL: ' . $error);
        }
        return [
            'status' => $status,
            'content_type' => $type,
            'location' => $headers['location'] ?? null,
            'body' => $body,
        ];
    }

    private static function resolveRedirect(string $base, string $location): string
    {
        $location = trim($location);
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $baseParts = parse_url($base);
        if (str_starts_with($location, '//')) {
            return $baseParts['scheme'] . ':' . $location;
        }
        $origin = $baseParts['scheme'] . '://' . $baseParts['host'] . (isset($baseParts['port']) ? ':' . $baseParts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $path = $baseParts['path'] ?? '/';
        $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
        return $origin . ($dir ? $dir : '') . '/' . $location;
    }

    private static function parseHtml(string $url, string $html): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        $xpath = new \DOMXPath($dom);

        $title = self::meta($xpath, 'property', 'og:title')
            ?: self::meta($xpath, 'name', 'twitter:title')
            ?: trim($xpath->evaluate('string(//title)'));
        $description = self::meta($xpath, 'property', 'og:description')
            ?: self::meta($xpath, 'name', 'description')
            ?: self::meta($xpath, 'name', 'twitter:description');
        $image = self::meta($xpath, 'property', 'og:image')
            ?: self::meta($xpath, 'name', 'twitter:image');

        return [
            'url' => $url,
            'title' => mb_substr(trim($title ?: $url), 0, 200),
            'description' => mb_substr(trim($description ?: ''), 0, 500),
            'image' => self::safePreviewImage($image),
        ];
    }

    private static function meta(\DOMXPath $xpath, string $attr, string $value): string
    {
        $nodes = $xpath->query('//meta[translate(@' . $attr . ', "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="' . strtolower($value) . '"]/@content');
        return $nodes && $nodes->length ? trim($nodes->item(0)->nodeValue) : '';
    }

    private static function safePreviewImage(string $url): ?string
    {
        if ($url === '') return null;
        $p = parse_url($url);
        if (!$p || !isset($p['scheme']) || !in_array(strtolower($p['scheme']), ['http', 'https'], true)) {
            return null;
        }
        return strlen($url) <= 2048 ? $url : null;
    }
}
