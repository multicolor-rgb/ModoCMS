<?php
declare(strict_types=1);

namespace Core;

/**
 * Class ImageOptimizer
 *
 * Native GD-based image pipeline. Generates a sibling .webp copy next to every
 * uploaded JPEG/PNG so themes can serve modern, lighter formats without any
 * external dependency.
 */
final class ImageOptimizer
{
    public static function isEnabled(): bool
    {
        return Router::getOption('webp_enabled', '0') === '1';
    }

    public static function quality(): int
    {
        $quality = (int)Router::getOption('webp_quality', '82');
        return max(1, min(100, $quality));
    }

    /** Whether the PHP build can actually emit WebP images. */
    public static function isSupported(): bool
    {
        return function_exists('imagewebp');
    }

    /**
     * Converts a JPEG/PNG file to WebP. Returns the absolute destination path on
     * success, or null when the file is unsupported / conversion is disabled.
     */
    public static function convertToWebp(string $sourcePath, ?string $destPath = null, ?int $quality = null): ?string
    {
        if (!self::isSupported() || !is_file($sourcePath)) {
            return null;
        }

        $info = @getimagesize($sourcePath);
        $mime = $info['mime'] ?? '';

        $image = null;
        switch ($mime) {
            case 'image/jpeg':
                $image = @imagecreatefromjpeg($sourcePath);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($sourcePath);
                break;
            default:
                return null; // Already WebP / GIF / SVG → nothing to do.
        }

        if (!$image) {
            return null;
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);

        if ($destPath === null) {
            $destPath = preg_replace('/\.[^.]+$/', '', $sourcePath) . '.webp';
        }

        $quality = $quality ?? self::quality();
        $quality = max(1, min(100, $quality));

        $ok = @imagewebp($image, $destPath, $quality);
        imagedestroy($image);

        return $ok ? $destPath : null;
    }

    /**
     * Converts a stored upload (given its "/uploads/name.ext" path) and returns
     * the resulting "/uploads/name.webp" path, or null when not applicable.
     */
    public static function convertStoredUpload(string $storedPath): ?string
    {
        if (!self::isEnabled() || !self::isSupported()) {
            return null;
        }

        $relative = ltrim(parse_url($storedPath, PHP_URL_PATH) ?: $storedPath, '/');
        $absolute = dirname(__DIR__) . '/' . $relative;

        $result = self::convertToWebp($absolute);
        if ($result === null) {
            return null;
        }

        return '/' . ltrim(str_replace(dirname(__DIR__), '', $result), '/');
    }
}
