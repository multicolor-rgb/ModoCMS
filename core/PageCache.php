<?php
declare(strict_types=1);

namespace Core;

/**
 * Class PageCache
 *
 * Lightweight full-page cache for anonymous frontend traffic. Rendered HTML is
 * stored under data/cache/ and served directly on subsequent requests, honouring
 * the configured TTL. Logged-in users, the admin area and the live Customizer
 * preview are never cached.
 */
final class PageCache
{
    private static bool $capturing = false;
    private static string $cacheKey = '';

    public static function isEnabled(): bool
    {
        return Router::getOption('cache_enabled', '0') === '1';
    }

    private static function ttl(): int
    {
        $ttl = (int)Router::getOption('cache_ttl', '3600');
        return $ttl > 0 ? $ttl : 3600;
    }

    public static function dir(): string
    {
        $dir = dirname(__DIR__) . '/data/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * Whether the current request is eligible for caching (anonymous, GET,
     * frontend, not a preview).
     */
    public static function canCacheRequest(): bool
    {
        if (!self::isEnabled()) {
            return false;
        }
        if (defined('IN_ADMIN') && IN_ADMIN === true) {
            return false;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return false;
        }
        if (class_exists(Auth::class) && Auth::check()) {
            return false;
        }
        if (class_exists(Customizer::class) && Customizer::isPreviewActive()) {
            return false;
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        if (str_contains($path, '/admin') || str_ends_with($path, 'sitemap.xml') || str_ends_with($path, 'robots.txt')) {
            return false;
        }

        return true;
    }

    private static function key(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $locale = class_exists(I18n::class) ? I18n::getLocale() : '';

        return hash('sha256', $host . '|' . $uri . '|' . $locale);
    }

    /**
     * Serves a fresh cached copy when available, otherwise starts buffering the
     * response so it can be stored at shutdown.
     */
    public static function maybeServe(): void
    {
        if (!self::canCacheRequest()) {
            return;
        }

        self::$cacheKey = self::key();
        $file = self::dir() . '/' . self::$cacheKey . '.html';

        if (is_file($file) && (time() - (int)filemtime($file)) < self::ttl()) {
            if (!headers_sent()) {
                header('X-Modo-Cache: HIT');
                header('Cache-Control: public, max-age=' . self::ttl());
            }
            readfile($file);
            exit;
        }

        self::$capturing = true;
        ob_start();
        register_shutdown_function([self::class, 'store']);
    }

    /**
     * Shutdown handler: stores the buffered response when it is a cacheable 200.
     */
    public static function store(): void
    {
        if (!self::$capturing) {
            return;
        }
        self::$capturing = false;

        $buffer = ob_get_contents();
        if (ob_get_level() > 0) {
            @ob_end_flush();
        }

        if ($buffer === false || $buffer === '' || !self::isEnabled()) {
            return;
        }
        if ((int)http_response_code() !== 200) {
            return;
        }
        if (!self::canCacheRequest()) {
            return;
        }

        @file_put_contents(self::dir() . '/' . self::$cacheKey . '.html', $buffer, LOCK_EX);
    }

    /** Removes every cached page. */
    public static function purge(): int
    {
        $removed = 0;
        foreach (glob(self::dir() . '/*.html') ?: [] as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }

    /** Returns cache statistics (number of entries and total size in bytes). */
    public static function stats(): array
    {
        $files = glob(self::dir() . '/*.html') ?: [];
        $bytes = 0;
        foreach ($files as $file) {
            $bytes += (int)@filesize($file);
        }
        return ['count' => count($files), 'bytes' => $bytes];
    }
}
