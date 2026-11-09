<?php
declare(strict_types=1);

define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\ImageTransform;
use Core\MediaLibrary;
use Core\Security;

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(180);

function mediaTransformError(int $status, string $error): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!Auth::check()) {
    mediaTransformError(401, 'unauthorized');
}
if (!Auth::can('manage_media') && !Auth::can('manage_pages')) {
    mediaTransformError(403, 'forbidden');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    mediaTransformError(405, 'method');
}

if (!Security::verifyCsrfToken((string) ($_POST['csrf'] ?? ''))) {
    mediaTransformError(419, 'csrf');
}

$storedPath = $_POST['path'] ?? null;
if (!is_string($storedPath) || $storedPath === '') {
    mediaTransformError(400, 'missing');
}

$source = MediaLibrary::absolutePath($storedPath);
if ($source === null || !is_file($source)) {
    mediaTransformError(404, 'missing');
}

$mode = (string) ($_POST['mode'] ?? 'overwrite');
if (!in_array($mode, ['overwrite', 'copy'], true)) {
    mediaTransformError(400, 'invalid');
}

$crop = null;
if (isset($_POST['crop']) && is_array($_POST['crop'])) {
    $crop = [
        'x' => (float) ($_POST['crop']['x'] ?? 0),
        'y' => (float) ($_POST['crop']['y'] ?? 0),
        'width' => (float) ($_POST['crop']['width'] ?? 0),
        'height' => (float) ($_POST['crop']['height'] ?? 0),
    ];
}

$info = @getimagesize($source);
$sourceMime = is_array($info) ? strtolower((string) ($info['mime'] ?? '')) : '';
$options = [
    'rotate' => (int) ($_POST['rotate'] ?? 0),
    'flip_x' => !empty($_POST['flip_x']),
    'flip_y' => !empty($_POST['flip_y']),
    'crop' => $crop,
    'max_width' => max(0, (int) ($_POST['max_width'] ?? 0)),
    'max_height' => max(0, (int) ($_POST['max_height'] ?? 0)),
    'quality' => max(1, min(100, (int) ($_POST['quality'] ?? 90))),
    'format' => $sourceMime,
];

$result = ImageTransform::render($source, $options);
if (empty($result['ok']) || empty($result['tmp'])) {
    $error = (string) ($result['error'] ?? 'failed');
    $status = match ($error) {
        'missing', 'invalid' => 400,
        'unsupported' => 503,
        default => 500,
    };
    mediaTransformError($status, $error);
}

$tmp = (string) $result['tmp'];
$mime = (string) ($result['mime'] ?? $sourceMime);
$saved = MediaLibrary::saveEditedFile($storedPath, $tmp, $mime, $mode === 'overwrite', Auth::id());
@unlink($tmp);

if (empty($saved['ok'])) {
    $error = (string) ($saved['error'] ?? 'failed');
    $status = match ($error) {
        'missing' => 404,
        'readonly' => 409,
        'invalid' => 400,
        default => 500,
    };
    mediaTransformError($status, $error);
}

try {
    \Core\Audit::log('media.transform', $storedPath, [
        'mode' => $mode,
        'rotate' => $options['rotate'],
        'width' => $result['width'] ?? null,
        'height' => $result['height'] ?? null,
    ]);
} catch (\Throwable $e) {
    // Audit logging is best-effort.
}

echo json_encode([
    'ok' => true,
    'path' => $saved['path'] ?? '',
    'url' => MediaLibrary::versionedUrlForStoredPath((string) ($saved['path'] ?? '')),
    'filename' => $saved['filename'] ?? '',
    'width' => $result['width'] ?? null,
    'height' => $result['height'] ?? null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
