<?php
declare(strict_types=1);

define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Database;
use Core\Router;

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

$uploadDir = __DIR__ . '/../uploads/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}

if (move_uploaded_file($file['tmp_name'], $uploadDir . $safeName)) {
    // Dynamically resolve base subdirectory (e.g. /cleancms) for public URL
    $basePrefix = class_exists('Core\Router') ? Router::getBaseSubdirectory() : '';
    $publicUrl = $basePrefix . '/uploads/' . $safeName;
    $storagePath = '/uploads/' . $safeName;

    // Record media entry in database
    $db = Database::getConnection();
    $stmt = $db->prepare("
        INSERT INTO media (filename, filepath, mime_type, file_size, user_id) 
        VALUES (:f, :p, :m, :s, :u)
    ");
    $stmt->execute([
        ':f' => basename($file['name']),
        ':p' => $storagePath,
        ':m' => $mime,
        ':s' => $file['size'],
        ':u' => Auth::id()
    ]);

    // Return URL compatible with TinyMCE and file pickers
    echo json_encode([
        'location' => $publicUrl,
        'url'      => $publicUrl
    ]);
    exit;
}

http_response_code(500);
echo json_encode(['error' => 'Failed to save file on disk']);