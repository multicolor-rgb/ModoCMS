<?php
declare(strict_types=1);

define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\MediaLibrary;
use Core\Security;

header('Content-Type: application/json; charset=utf-8');

/**
 * Sends a JSON error response and stops the request.
 */
function mediaSaveError(int $status, string $error): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!Auth::check()) {
    mediaSaveError(401, 'unauthorized');
}

if (!Auth::can('manage_pages')) {
    mediaSaveError(403, 'forbidden');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    mediaSaveError(405, 'method');
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if (empty($_POST) && empty($_FILES) && $contentLength > 0) {
    // PHP discards POST data when post_max_size is exceeded. Report the real
    // cause instead of a misleading CSRF failure.
    mediaSaveError(413, 'too_large');
}

$token = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : '';
if (!Security::verifyCsrfToken($token)) {
    mediaSaveError(419, 'csrf');
}

$path = $_POST['path'] ?? null;
if (!is_string($path) || $path === '') {
    mediaSaveError(400, 'missing');
}

$mode = $_POST['mode'] ?? null;
if (!is_string($mode) || !in_array($mode, ['copy', 'overwrite'], true)) {
    mediaSaveError(400, 'invalid');
}

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    mediaSaveError(400, 'missing');
}

$file = $_FILES['file'];
$uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($uploadError !== UPLOAD_ERR_OK) {
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        mediaSaveError(413, 'too_large');
    }
    mediaSaveError(400, $uploadError === UPLOAD_ERR_NO_FILE ? 'missing' : 'invalid');
}

$tmpName = (string) ($file['tmp_name'] ?? '');
$size = (int) ($file['size'] ?? 0);
$maxSize = 40 * 1024 * 1024;

if ($size <= 0) {
    mediaSaveError(400, 'invalid');
}
if ($size > $maxSize) {
    mediaSaveError(413, 'too_large');
}
if ($tmpName === '' || !is_uploaded_file($tmpName)) {
    mediaSaveError(400, 'invalid');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string) $finfo->file($tmpName);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    mediaSaveError(400, 'invalid');
}

$result = MediaLibrary::saveEditedFile($path, $tmpName, $mime, $mode === 'overwrite', Auth::id());

if (empty($result['ok'])) {
    $error = (string) ($result['error'] ?? 'failed');
    $status = match ($error) {
        'missing'  => 404,
        'readonly' => 409,
        'invalid'  => 400,
        default    => 500,
    };
    mediaSaveError($status, $error);
}

echo json_encode([
    'ok'       => true,
    'path'     => $result['path'] ?? '',
    'url'      => $result['url'] ?? '',
    'filename' => $result['filename'] ?? '',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
