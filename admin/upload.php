<?php
declare(strict_types=1);

define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Database;
use Core\MediaLibrary;

header('Content-Type: application/json; charset=utf-8');

// Verify user authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Ensure valid POST upload request
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml'];
$maxSize = 8 * 1024 * 1024; // 8MB

// Validate upload status and file size
if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file size or upload failure']);
    exit;
}

// Validate MIME type via fileinfo
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);

if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Disallowed format. Allowed: JPG, PNG, WEBP, GIF, SVG']);
    exit;
}

// Sanitize SVG files to protect against Stored XSS
if ($mime === 'image/svg+xml') {
    $svgContent = (string)file_get_contents($file['tmp_name']);
    if (preg_match('/<script|javascript:|onload|onerror|onclick/i', $svgContent)) {
        http_response_code(400);
        echo json_encode(['error' => 'SVG file contains disallowed embedded scripts.']);
        exit;
    }
}

// Map MIME type to safe extension
$extMap = [
    'image/jpeg'    => 'jpg',
    'image/png'     => 'png',
    'image/webp'    => 'webp',
    'image/gif'     => 'gif',
    'image/svg+xml' => 'svg'
];
$ext = $extMap[$mime] ?? 'bin';
$safeName = bin2hex(random_bytes(16)) . '.' . $ext;

$uploadDir = MediaLibrary::rootPath();
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}

// Optional destination folder selected in the media library. "root" (or an
// empty value) means the uploads directory itself.
$folder = (string)($_POST['folder'] ?? '');
$folderDir = null;
$folderSegment = '';
if ($folder !== '' && $folder !== 'root') {
    $folderDir = MediaLibrary::resolveFolder($folder);
    if ($folderDir === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid destination folder']);
        exit;
    }
    $folderSegment = basename($folderDir);
}

if (move_uploaded_file($file['tmp_name'], ($folderDir ?? $uploadDir) . '/' . $safeName)) {
    $storagePath = '/uploads/' . ($folderSegment !== '' ? $folderSegment . '/' : '') . $safeName;
    $publicUrl = MediaLibrary::urlForStoredPath($storagePath);

    // Automatic WebP conversion (Settings → Media & Images).
    $webpPath = null;
    if (\Core\ImageOptimizer::isEnabled() && \Core\ImageOptimizer::isSupported()) {
        $converted = \Core\ImageOptimizer::convertToWebp(($folderDir ?? $uploadDir) . '/' . $safeName);
        if ($converted !== null) {
            $webpPath = '/uploads/' . ($folderSegment !== '' ? $folderSegment . '/' : '') . basename($converted);
        }
    }

    // Record media entry in database
    $db = Database::getConnection();
    $stmt = $db->prepare("
        INSERT INTO media (filename, filepath, mime_type, file_size, webp_path, user_id) 
        VALUES (:f, :p, :m, :s, :w, :u)
    ");
    $stmt->execute([
        ':f' => basename($file['name']),
        ':p' => $storagePath,
        ':m' => $mime,
        ':s' => $file['size'],
        ':w' => $webpPath,
        ':u' => Auth::id()
    ]);

    // Warm every picker size so the media modal does not have to resize the
    // original on first view. No-op when no image backend is available.
    try {
        MediaLibrary::ensureThumbnails(
            $storagePath,
            MediaLibrary::galleryThumbnailsAuto()
                ? MediaLibrary::thumbnailGenerationWidths()
                : MediaLibrary::THUMBNAIL_PREVIEW_WIDTHS
        );
    } catch (\Throwable $e) {
        // The upload itself succeeded; a missing thumbnail is not fatal.
    }

    // Return URL compatible with TinyMCE and file pickers
    echo json_encode([
        'location' => $publicUrl,
        'url'      => $publicUrl
    ]);
    exit;
}

http_response_code(500);
echo json_encode(['error' => 'Failed to save file on disk']);