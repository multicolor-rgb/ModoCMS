<?php
declare(strict_types=1);

namespace Core;

/**
 * Signed preview links for unpublished content.
 *
 * The token is stateless: it contains the page id and expiry and is signed
 * with a local secret stored in data/preview.key. No database rows are needed
 * and links stop working automatically after their TTL.
 */
final class Preview
{
    private const DEFAULT_TTL = 86400;

    private static function secret(): string
    {
        $path = dirname(__DIR__) . '/data/preview.key';
        if (is_file($path)) {
            $secret = trim((string)@file_get_contents($path));
            if ($secret !== '') {
                return $secret;
            }
        }

        $secret = bin2hex(random_bytes(32));
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($path, $secret . "\n", LOCK_EX);
        @chmod($path, 0600);

        return $secret;
    }

    public static function token(int $pageId, int $ttl = self::DEFAULT_TTL): string
    {
        $expires = time() + max(60, $ttl);
        $payload = $pageId . '.' . $expires;
        $signature = hash_hmac('sha256', $payload, self::secret());

        return rtrim(strtr(base64_encode($payload . '.' . $signature), '+/', '-_'), '=');
    }

    /** Returns the page id for a valid token, or null. */
    public static function validate(string $token): ?int
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 512) {
            return null;
        }

        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        if ($decoded === false) {
            return null;
        }

        $parts = explode('.', $decoded);
        if (count($parts) !== 3) {
            return null;
        }

        [$id, $expires, $signature] = $parts;
        $pageId = (int)$id;
        $expires = (int)$expires;
        if ($pageId <= 0 || $expires < time()) {
            return null;
        }

        $expected = hash_hmac('sha256', $id . '.' . $expires, self::secret());
        return hash_equals($expected, $signature) ? $pageId : null;
    }

    /** Absolute URL that opens the saved version of a document. */
    public static function url(int $pageId, int $ttl = self::DEFAULT_TTL): string
    {
        return rtrim(Router::getSiteUrl(), '/') . '/preview?token=' . urlencode(self::token($pageId, $ttl));
    }
}
