<?php
declare(strict_types=1);

namespace Core;

/**
 * Fast, deterministic image transformations used by the media editor and the
 * bulk transform tool. Prefers ImageMagick (extension or CLI) and falls back
 * to GD when neither is available.
 *
 * The canonical pipeline is:
 *   auto-orient EXIF -> rotate -> flip -> crop -> resize -> encode.
 */
final class ImageTransform
{
    private const SUPPORTED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * @param array{
     *   rotate?: int,
     *   flip_x?: bool,
     *   flip_y?: bool,
     *   crop?: array{x?: int|float, y?: int|float, width?: int|float, height?: int|float}|null,
     *   max_width?: int,
     *   max_height?: int,
     *   quality?: int,
     *   format?: string
     * } $options
     * @return array{ok:bool, tmp?:string, mime?:string, width?:int, height?:int, error?:string}
     */
    public static function render(string $source, array $options = []): array
    {
        if (!is_file($source) || !is_readable($source)) {
            return ['ok' => false, 'error' => 'missing'];
        }

        $info = @getimagesize($source);
        if (!is_array($info) || (int) $info[0] <= 0 || (int) $info[1] <= 0) {
            return ['ok' => false, 'error' => 'invalid'];
        }

        $sourceMime = strtolower((string) ($info['mime'] ?? ''));
        if (!in_array($sourceMime, self::SUPPORTED_MIME, true)) {
            return ['ok' => false, 'error' => 'unsupported'];
        }

        $outputMime = self::normalizeFormat((string) ($options['format'] ?? ''), $sourceMime);
        if ($outputMime === null) {
            return ['ok' => false, 'error' => 'unsupported'];
        }

        $rotate = self::normalizeRotation((int) ($options['rotate'] ?? 0));
        $flipX = !empty($options['flip_x']);
        $flipY = !empty($options['flip_y']);
        $quality = max(1, min(100, (int) ($options['quality'] ?? 90)));

        $orientation = ImageOrientation::read($source);
        $sourceWidth = (int) $info[0];
        $sourceHeight = (int) $info[1];
        if (in_array($orientation, [5, 6, 7, 8], true)) {
            [$sourceWidth, $sourceHeight] = [$sourceHeight, $sourceWidth];
        }

        $crop = null;
        if (!empty($options['crop']) && is_array($options['crop'])) {
            $crop = self::normalizeCrop($options['crop'], $sourceWidth, $sourceHeight, $rotate);
        }

        $maxWidth = max(0, (int) ($options['max_width'] ?? 0));
        $maxHeight = max(0, (int) ($options['max_height'] ?? 0));

        $backend = self::backend();
        if ($backend === 'none') {
            return ['ok' => false, 'error' => 'unsupported'];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'modo-img-');
        if ($tmp === false) {
            return ['ok' => false, 'error' => 'failed'];
        }

        try {
            $rendered = match ($backend) {
                'imagick' => self::renderImagick($source, $tmp, $outputMime, $rotate, $flipX, $flipY, $crop, $maxWidth, $maxHeight, $quality),
                'cli'     => self::renderCli($source, $tmp, $outputMime, $rotate, $flipX, $flipY, $crop, $maxWidth, $maxHeight, $quality),
                default   => self::renderGd($source, $tmp, $outputMime, $rotate, $flipX, $flipY, $crop, $maxWidth, $maxHeight, $quality),
            };
            if (!$rendered) {
                @unlink($tmp);
                return ['ok' => false, 'error' => 'failed'];
            }

            $resultInfo = @getimagesize($tmp);
            if (!is_array($resultInfo) || (int) $resultInfo[0] <= 0 || (int) $resultInfo[1] <= 0) {
                @unlink($tmp);
                return ['ok' => false, 'error' => 'failed'];
            }

            return [
                'ok' => true,
                'tmp' => $tmp,
                'mime' => $outputMime,
                'width' => (int) $resultInfo[0],
                'height' => (int) $resultInfo[1],
            ];
        } catch (\Throwable $e) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'failed'];
        }
    }

    public static function backend(): string
    {
        if (class_exists(\Imagick::class)) {
            return 'imagick';
        }
        if (MediaLibrary::imageCliBinary() !== null) {
            return 'cli';
        }
        if (function_exists('imagecreatetruecolor') && function_exists('imagecreatefromjpeg')) {
            return 'gd';
        }

        return 'none';
    }

    private static function normalizeFormat(string $format, string $sourceMime): ?string
    {
        $format = strtolower(trim($format));
        $map = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'image/jpeg' => 'image/jpeg',
            'image/png' => 'image/png',
            'image/webp' => 'image/webp',
        ];
        if (isset($map[$format])) {
            return $map[$format];
        }

        return in_array($sourceMime, self::SUPPORTED_MIME, true) ? $sourceMime : null;
    }

    private static function normalizeRotation(int $rotate): int
    {
        $rotate %= 360;
        if ($rotate < 0) {
            $rotate += 360;
        }
        $allowed = [0, 90, 180, 270];

        return in_array($rotate, $allowed, true) ? $rotate : 0;
    }

    /**
     * @param array{x?:int|float,y?:int|float,width?:int|float,height?:int|float} $crop
     * @return array{x:int,y:int,width:int,height:int}
     */
    private static function normalizeCrop(array $crop, int $sourceWidth, int $sourceHeight, int $rotate): array
    {
        $width = (int) round((float) ($crop['width'] ?? 0));
        $height = (int) round((float) ($crop['height'] ?? 0));
        $x = (int) round((float) ($crop['x'] ?? 0));
        $y = (int) round((float) ($crop['y'] ?? 0));

        $rotated = $rotate === 90 || $rotate === 270;
        $canvasWidth = $rotated ? $sourceHeight : $sourceWidth;
        $canvasHeight = $rotated ? $sourceWidth : $sourceHeight;

        $width = max(1, min($canvasWidth, $width));
        $height = max(1, min($canvasHeight, $height));
        $x = max(0, min($canvasWidth - $width, $x));
        $y = max(0, min($canvasHeight - $height, $y));

        return ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height];
    }

    /**
     * @param array{x:int,y:int,width:int,height:int}|null $crop
     */
    private static function renderImagick(
        string $source,
        string $tmp,
        string $mime,
        int $rotate,
        bool $flipX,
        bool $flipY,
        ?array $crop,
        int $maxWidth,
        int $maxHeight,
        int $quality
    ): bool {
        try {
            $image = new \Imagick($source);
        } catch (\Throwable $e) {
            return false;
        }

        try {
            if (method_exists($image, 'autoOrient')) {
                $image->autoOrient();
            }
            if ($rotate !== 0) {
                $image->rotateImage(new \ImagickPixel('none'), $rotate);
            }
            if ($flipX) {
                $image->flopImage();
            }
            if ($flipY) {
                $image->flipImage();
            }
            if ($crop !== null) {
                $image->cropImage($crop['width'], $crop['height'], $crop['x'], $crop['y']);
                $image->setImagePage(0, 0, 0, 0);
            }

            [$targetWidth, $targetHeight] = self::targetSize(
                (int) $image->getImageWidth(),
                (int) $image->getImageHeight(),
                $maxWidth,
                $maxHeight
            );
            if ($targetWidth !== (int) $image->getImageWidth() || $targetHeight !== (int) $image->getImageHeight()) {
                $image->resizeImage($targetWidth, $targetHeight, \Imagick::FILTER_LANCZOS, 1, true);
            }

            if ($mime === 'image/jpeg') {
                $image->setImageBackgroundColor('white');
                $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                $image->setImageFormat('jpeg');
            } elseif ($mime === 'image/webp') {
                $image->setImageFormat('webp');
            } else {
                $image->setImageFormat('png');
            }
            $image->setImageCompressionQuality($quality);
            $image->stripImage();

            return $image->writeImage($tmp) && is_file($tmp) && (int) @filesize($tmp) > 0;
        } finally {
            $image->clear();
        }
    }

    /**
     * @param array{x:int,y:int,width:int,height:int}|null $crop
     */
    private static function renderCli(
        string $source,
        string $tmp,
        string $mime,
        int $rotate,
        bool $flipX,
        bool $flipY,
        ?array $crop,
        int $maxWidth,
        int $maxHeight,
        int $quality
    ): bool {
        $binary = MediaLibrary::imageCliBinary();
        if ($binary === null) {
            return false;
        }

        $command = escapeshellarg($binary) . ' ' . escapeshellarg($source) . ' -auto-orient';
        if ($rotate !== 0) {
            $command .= ' -rotate ' . escapeshellarg((string) $rotate);
        }
        if ($flipX) {
            $command .= ' -flop';
        }
        if ($flipY) {
            $command .= ' -flip';
        }
        if ($crop !== null) {
            $command .= ' -crop ' . escapeshellarg(
                $crop['width'] . 'x' . $crop['height'] . '+' . $crop['x'] . '+' . $crop['y']
            ) . ' +repage';
        }

        $geometry = self::resizeGeometry($maxWidth, $maxHeight);
        if ($geometry !== '') {
            $command .= ' -resize ' . escapeshellarg($geometry);
        }

        if ($mime === 'image/jpeg') {
            $command .= ' -background white -alpha remove -alpha off';
            $prefix = 'jpeg';
        } elseif ($mime === 'image/webp') {
            $prefix = 'webp';
        } else {
            $prefix = 'png';
        }

        $command .= ' -strip -colorspace sRGB -quality ' . escapeshellarg((string) $quality)
            . ' ' . escapeshellarg($prefix . ':' . $tmp) . ' 2>/dev/null';

        $output = [];
        $code = 1;
        @exec($command, $output, $code);

        return $code === 0 && is_file($tmp) && (int) @filesize($tmp) > 0;
    }

    /**
     * @param array{x:int,y:int,width:int,height:int}|null $crop
     */
    private static function renderGd(
        string $source,
        string $tmp,
        string $mime,
        int $rotate,
        bool $flipX,
        bool $flipY,
        ?array $crop,
        int $maxWidth,
        int $maxHeight,
        int $quality
    ): bool {
        $info = @getimagesize($source);
        if (!is_array($info) || (int) $info[0] * (int) $info[1] > 40000000) {
            return false;
        }

        $image = self::loadGd($source);
        if ($image === null) {
            return false;
        }

        try {
            $image = ImageOrientation::applyGd($image, $source);

            if ($rotate !== 0) {
                $background = function_exists('imagecolorallocatealpha')
                    ? @imagecolorallocatealpha($image, 0, 0, 0, 127)
                    : 0;
                $rotated = @imagerotate($image, -$rotate, $background);
                if ($rotated === false) {
                    return false;
                }
                if ($rotated !== $image) {
                    @imagedestroy($image);
                }
                $image = $rotated;
            }

            if ($flipX && function_exists('imageflip')) {
                @imageflip($image, IMG_FLIP_HORIZONTAL);
            }
            if ($flipY && function_exists('imageflip')) {
                @imageflip($image, IMG_FLIP_VERTICAL);
            }

            if ($crop !== null) {
                $cropped = @imagecrop($image, $crop);
                if ($cropped === false) {
                    return false;
                }
                @imagedestroy($image);
                $image = $cropped;
            }

            [$targetWidth, $targetHeight] = self::targetSize(imagesx($image), imagesy($image), $maxWidth, $maxHeight);
            if ($targetWidth !== imagesx($image) || $targetHeight !== imagesy($image)) {
                $resized = @imagescale($image, $targetWidth, $targetHeight, IMG_BICUBIC);
                if ($resized === false) {
                    return false;
                }
                @imagedestroy($image);
                $image = $resized;
            }

            $saved = false;
            if ($mime === 'image/jpeg') {
                $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
                if ($flat === false) {
                    return false;
                }
                $white = imagecolorallocate($flat, 255, 255, 255);
                imagefilledrectangle($flat, 0, 0, imagesx($flat) - 1, imagesy($flat) - 1, $white);
                imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
                $saved = @imagejpeg($flat, $tmp, $quality);
                imagedestroy($flat);
            } elseif ($mime === 'image/webp' && function_exists('imagewebp')) {
                imagealphablending($image, true);
                imagesavealpha($image, true);
                $saved = @imagewebp($image, $tmp, $quality);
            } else {
                $saved = @imagepng($image, $tmp, max(0, min(9, (int) round((100 - $quality) / 10))));
            }

            return $saved && is_file($tmp) && (int) @filesize($tmp) > 0;
        } finally {
            if ($image instanceof \GdImage || is_resource($image)) {
                @imagedestroy($image);
            }
        }
    }

    /** @return resource|\GdImage|null */
    private static function loadGd(string $source)
    {
        $info = @getimagesize($source);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';

        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($source) ?: null,
            'image/png' => @imagecreatefrompng($source) ?: null,
            'image/webp' => function_exists('imagecreatefromwebp') ? (@imagecreatefromwebp($source) ?: null) : null,
            default => null,
        };
    }

    /** @return array{0:int,1:int} */
    private static function targetSize(int $width, int $height, int $maxWidth, int $maxHeight): array
    {
        if ($width <= 0 || $height <= 0) {
            return [max(1, $width), max(1, $height)];
        }

        $scale = 1.0;
        if ($maxWidth > 0) {
            $scale = min($scale, $maxWidth / $width);
        }
        if ($maxHeight > 0) {
            $scale = min($scale, $maxHeight / $height);
        }
        if ($scale >= 1) {
            return [$width, $height];
        }

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    private static function resizeGeometry(int $maxWidth, int $maxHeight): string
    {
        if ($maxWidth > 0 && $maxHeight > 0) {
            return $maxWidth . 'x' . $maxHeight . '>';
        }
        if ($maxWidth > 0) {
            return $maxWidth . 'x>';
        }
        if ($maxHeight > 0) {
            return 'x' . $maxHeight . '>';
        }

        return '';
    }
}
