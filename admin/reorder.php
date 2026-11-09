<?php
declare(strict_types=1);

define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\ContentOrder;
use Core\Database;
use Core\Security;

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

Auth::requireCapability('manage_pages');

if (!Security::verifyCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

if (!ContentOrder::isEnabled()) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'Manual ordering is disabled']);
    exit;
}

$type = (string) ($_POST['type'] ?? '');
if (!in_array($type, ['post', 'page'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid content type']);
    exit;
}

$parentId = $type === 'page' ? max(0, (int) ($_POST['parent_id'] ?? 0)) : 0;
$lang = (string) ($_POST['lang'] ?? 'all');
$ids = $_POST['ids'] ?? [];
if (!is_array($ids)) {
    $ids = [];
}
$ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

if (!$ids) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'No documents supplied']);
    exit;
}

$db = Database::getConnection();
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$params = $ids;
$sql = "SELECT id, author_id FROM pages
        WHERE id IN ($placeholders) AND type = ? AND parent_id = ? AND deleted_at IS NULL";
$params[] = $type;
$params[] = $parentId;
if ($lang !== '' && $lang !== 'all') {
    $sql .= " AND lang = ?";
    $params[] = $lang;
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (count($rows) !== count($ids)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Some documents do not match the requested group']);
    exit;
}

if (!Auth::can('edit_others_pages')) {
    $ownId = (int) Auth::id();
    foreach ($rows as $row) {
        if ((int) ($row['author_id'] ?? 0) !== $ownId) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'You cannot reorder these documents']);
            exit;
        }
    }
}

try {
    $updated = ContentOrder::reorder($ids, $type, $parentId, $lang);
    echo json_encode(['ok' => true, 'updated' => $updated]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not save the new order']);
}
