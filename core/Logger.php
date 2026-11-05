<?php
declare(strict_types=1);

namespace Core;

/**
 * Minimal structured logger.
 *
 * Writes one JSON object per line to data/logs/app.log. The directory is
 * protected from HTTP access by the shipped .htaccess / nginx rules. Logging
 * never throws: a broken log file must not take the site down.
 */
final class Logger
{
    public static function path(): string
    {
        return dirname(__DIR__) . '/data/logs/app.log';
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        try {
            $entry = [
                'time'    => date('c'),
                'level'   => strtoupper($level),
                'message' => $message,
                'context' => $context,
            ];

            if (class_exists(Auth::class) && Auth::check()) {
                $entry['user_id'] = Auth::id();
            }

            $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($line === false) {
                return;
            }

            $path = self::path();
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }

            if (!@file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX)) {
                @error_log('[ModoCMS] ' . $level . ': ' . $message);
            }
        } catch (\Throwable $e) {
            @error_log('[ModoCMS] ' . $message);
        }
    }

    /** @param array<string,mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function exception(\Throwable $e, string $message = '', array $context = []): void
    {
        $context += [
            'exception' => $e::class,
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ];

        self::log('error', $message !== '' ? $message : $e->getMessage(), $context);
    }
}
