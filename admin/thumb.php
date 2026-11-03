<?php
declare(strict_types=1);

define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\MediaLibrary;

// Thumbnails are only used inside the admin area. Requiring a session also
// stops anonymous visitors from using this endpoint as an image resizer.
if (!Auth::check()) {
    http_response_code(401);
    exit;
}

// Release the session lock before touching the filesystem. Browsers request
// many thumbnails in parallel; keeping the lock would serialise all of them.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$storedPath = isset($_GET['p']) && is_string($_GET['p']) ? (string) $_GET['p'] : '';
$width = isset($_GET['w']) ? (int) $_GET['w'] : 160;

if ($storedPath === ''
    || preg_match('#^https?://#i', $storedPath) === 1
    || !in_array($width, MediaLibrary::THUMBNAIL_WIDTHS, true)
) {
    http_response_code(400);
    exit;
}

// Only files below uploads/ may be previewed, and never via path traversal.
$relative = ltrim(str_replace('\\', '/', (string) (parse_url($storedPath, PHP_URL_PATH) ?: $storedPath)), '/');
if (!str_starts_with($relative, 'uploads/') || in_array('..', explode('/', $relative), true)) {
    http_response_code(400);
    exit;
}

try {
    $thumbnail = MediaLibrary::ensureThumbnail($storedPath, $width);
} catch (\Throwable $e) {
    // Thumbnail generation is an optimisation; fall back to the original.
    $thumbnail = null;
}

if ($thumbnail === null || !is_file($thumbnail)) {
    // Unsupported source (SVG/GIF), missing GD or a very large original:
    // fall back to the original file instead of breaking the picker.
    $url = MediaLibrary::urlForStoredPath($storedPath);
    if (preg_match('#^https?://#i', $url) === 1 || !str_starts_with($url, '/')) {
        http_response_code(404);
        exit;
    }
    header('Location: ' . $url, true, 302);
    exit;
}

$mtime = (int) @filemtime($thumbnail);
$etag = '"' . sha1($thumbnail . '|' . $mtime) . '"';

if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . (str_ends_with($thumbnail, '.webp') ? 'image/webp' : 'image/jpeg'));
header('Cache-Control: private, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
header('ETag: ' . $etag);

$size = @filesize($thumbnail);
if ($size !== false) {
    header('Content-Length: ' . $size);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
    readfile($thumbnail);
}
