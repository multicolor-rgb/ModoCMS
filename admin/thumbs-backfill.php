<?php
declare(strict_types=1);

define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\MediaLibrary;
use Core\Security;

header('Content-Type: application/json; charset=utf-8');

Auth::requireCapability('manage_pages');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$token = isset($_POST['csrf']) && is_string($_POST['csrf']) ? $_POST['csrf'] : '';
if (!Security::verifyCsrfToken($token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

$offset = isset($_POST['offset']) ? max(0, (int) $_POST['offset']) : 0;
$limit = isset($_POST['limit']) ? max(1, min(20, (int) $_POST['limit'])) : 5;

// The thumbnail work is filesystem/CPU bound and can take a while; release the
// session lock so the admin can keep using the panel while it runs.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$items = MediaLibrary::listImages();
$total = count($items);
$slice = array_slice($items, $offset, $limit);
$processed = 0;

foreach ($slice as $item) {
    $path = (string) ($item['path'] ?? '');
    if ($path === '') {
        continue;
    }
    try {
        MediaLibrary::ensureThumbnails($path, MediaLibrary::thumbnailGenerationWidths());
    } catch (\Throwable $e) {
        // A single broken file must not stop the whole batch.
    }
    $processed++;
}

$next = min($total, $offset + $limit);

echo json_encode([
    'ok'        => true,
    'total'     => $total,
    'offset'    => $offset,
    'next'      => $next,
    'processed' => $processed,
    'done'      => $next >= $total,
], JSON_UNESCAPED_UNICODE);
