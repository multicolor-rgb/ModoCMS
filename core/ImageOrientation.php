<?php
declare(strict_types=1);

namespace Core;

/**
 * Class ImageOrientation
 *
 * Applies the EXIF orientation stored by phones and cameras to GD images.
 * Browsers honour the EXIF tag when displaying the original file, so every
 * generated derivative (thumbnail, WebP copy) must apply it too.
 */
final class ImageOrientation
{
    /**
     * Returns the EXIF orientation (1-8) for a local image, or 1 when unknown.
     */
    public static function read(string $source): int
    {
        if (!function_exists('exif_read_data')) {
            return 1;
        }

        $extension = strtolower((string) pathinfo($source, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg'], true) || !is_file($source)) {
            return 1;
        }

        $exif = @exif_read_data($source);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return ($orientation >= 1 && $orientation <= 8) ? $orientation : 1;
    }

    /**
     * Rotates/flips a GD image according to its EXIF orientation.
     *
     * @param resource|\GdImage $image
     * @return resource|\GdImage
     */
    public static function applyGd($image, string $source)
    {
        $orientation = self::read($source);
        if ($orientation === 1) {
            return $image;
        }

        // Transparent background for formats that support alpha. JPEG ignores
        // it, so the same helper is safe for every source type.
        $background = function_exists('imagecolorallocatealpha')
            ? @imagecolorallocatealpha($image, 0, 0, 0, 127)
            : 0;

        $transform = static function ($img, int $angle) use ($background) {
            $rotated = @imagerotate($img, $angle, $background);
            if ($rotated === false) {
                return $img;
            }
            if ($rotated !== $img) {
                @imagedestroy($img);
            }
            return $rotated;
        };

        switch ($orientation) {
            case 2:
                if (function_exists('imageflip')) {
                    @imageflip($image, IMG_FLIP_HORIZONTAL);
                }
                return $image;
            case 3:
                return $transform($image, 180);
            case 4:
                if (function_exists('imageflip')) {
                    @imageflip($image, IMG_FLIP_VERTICAL);
                }
                return $image;
            case 5:
                $image = $transform($image, -90);
                if (function_exists('imageflip')) {
                    @imageflip($image, IMG_FLIP_HORIZONTAL);
                }
                return $image;
            case 6:
                return $transform($image, -90);
            case 7:
                $image = $transform($image, -90);
                if (function_exists('imageflip')) {
                    @imageflip($image, IMG_FLIP_VERTICAL);
                }
                return $image;
            case 8:
                return $transform($image, 90);
            default:
                return $image;
        }
    }
}
