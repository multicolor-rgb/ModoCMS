<?php
declare(strict_types=1);

/**
 * AJAX endpoint for the post/page revision (version history) system.
 *
 * Actions (?action=):
 *   list      - JSON list of revisions for a document.
 *   get       - full revision payload (used by the preview modal).
 *   snapshot  - create a manual revision of the current DB state.
 *   autosave  - store the unsaved editor content as an 'autosave' revision.
 *   restore   - restore a revision into the live document.
 *   delete    - delete a single revision.
 *
 * All actions require the manage_pages capability and a valid CSRF token
 * (sent either as the `csrf_token` POST field or the X-CSRF-Token header).
 */

define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Database;
use Core\Security;
use Core\Revisions;

header('Content-Type: application/json; charset=utf-8');

Auth::requireCapability('manage_pages');

$db = Database::getConnection();

$respond = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!Security::verifyCsrfToken(is_string($token) ? $token : '')) {
    $respond(['status' => 'error', 'message' => 'Invalid CSRF token'], 403);
}

$action     = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
$pageId     = (int) ($_POST['page_id'] ?? $_GET['page_id'] ?? 0);
$revisionId = (int) ($_POST['revision_id'] ?? $_GET['revision_id'] ?? 0);

/** Checks whether the current user may edit the given document. */
$canEditPage = static function (int $pageId) use ($db): bool {
    if ($pageId <= 0) {
        return false;
    }
    $stmt = $db->prepare("SELECT author_id FROM pages WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $pageId]);
    $author = $stmt->fetchColumn();
    if ($author === false) {
        return false;
    }
    if (!Auth::can('edit_others_pages') && (int) $author !== (int) Auth::id()) {
        return false;
    }
    return true;
};

/** Resolves the owning page of a revision (or 0). */
$pageIdForRevision = static function (int $revisionId) use ($db): int {
    if ($revisionId <= 0) {
        return 0;
    }
    $stmt = $db->prepare("SELECT page_id FROM page_revisions WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $revisionId]);
    $val = $stmt->fetchColumn();
    return $val === false ? 0 : (int) $val;
};

/** Builds the revision list payload shared by several actions. */
$listPayload = static function (int $pageId): array {
    $rows = Revisions::list($pageId);
    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id'          => (int) $r['id'],
            'title'       => (string) $r['title'],
            'source'      => (string) $r['source'],
            'note'        => (string) ($r['note'] ?? ''),
            'author_id'   => (int) $r['author_id'],
            'content_len' => (int) $r['content_len'],
            'created_at'  => (string) $r['created_at'],
        ];
    }
    return [
        'status'      => 'success',
        'items'       => $items,
        'count'       => Revisions::count($pageId),
        'max'         => Revisions::maxPerPage(),
        'server_time' => date('c'),
    ];
};

switch ($action) {
    case 'list':
        if (!$canEditPage($pageId)) {
            $respond(['status' => 'error', 'message' => 'Access denied.'], 403);
        }
        $respond($listPayload($pageId));

    case 'get':
        $ownerPage = $pageIdForRevision($revisionId);
        if (!$canEditPage($ownerPage)) {
            $respond(['status' => 'error', 'message' => 'Access denied.'], 403);
        }
        $rev = Revisions::get($revisionId);
        if (!$rev) {
            $respond(['status' => 'error', 'message' => 'Revision not found.'], 404);
        }
        $respond([
            'status'   => 'success',
            'revision' => [
                'id'         => (int) $rev['id'],
                'title'      => (string) $rev['title'],
                'content'    => (string) $rev['content'],
                'type'       => (string) $rev['type'],
                'status'     => (string) $rev['status'],
                'source'     => (string) $rev['source'],
                'created_at' => (string) $rev['created_at'],
            ],
        ]);

    case 'snapshot':
        if (!$canEditPage($pageId)) {
            $respond(['status' => 'error', 'message' => 'Access denied.'], 403);
        }
        if (!Revisions::isEnabled()) {
            $respond(['status' => 'error', 'message' => 'Revisions are disabled.'], 409);
        }
        $newId = Revisions::snapshot($pageId, 'manual');
        $payload = $listPayload($pageId);
        $payload['revision_id'] = $newId;
        $payload['stored'] = $newId !== null;
        $respond($payload);

    case 'autosave':
        if (!$canEditPage($pageId)) {
            $respond(['status' => 'error', 'message' => 'Access denied.'], 403);
        }
        if (!Revisions::isEnabled()) {
            $respond(['status' => 'error', 'message' => 'Revisions are disabled.'], 409);
        }

        // Custom fields snapshot (mirrors the editor save normalization).
        $metaJson = [];
        $keys   = $_POST['custom_field_key'] ?? [];
        $values = (array) ($_POST['custom_field_value'] ?? []);
        $types  = (array) ($_POST['custom_field_type'] ?? []);
        if (is_array($keys)) {
            $seen = [];
            foreach (array_values($keys) as $i => $rawKey) {
                $metaKey = strtolower(trim((string) preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string) $rawKey), '_'));
                if ($metaKey === '' || in_array($metaKey, $seen, true)) {
                    continue;
                }
                $seen[] = $metaKey;
                $metaJson[] = [
                    'key'   => $metaKey,
                    'value' => (string) ($values[$i] ?? ''),
                    'type'  => (string) ($types[$i] ?? 'text'),
                ];
            }
        }

        $data = [
            'title'             => (string) ($_POST['title'] ?? ''),
            'slug'              => (string) ($_POST['slug'] ?? ''),
            'content'           => (string) ($_POST['content'] ?? ''),
            'type'              => in_array($_POST['type'] ?? '', ['post', 'page'], true) ? $_POST['type'] : 'post',
            'status'            => (string) ($_POST['status'] ?? 'published'),
            'lang'              => (string) ($_POST['lang'] ?? ''),
            'translation_group' => (string) ($_POST['translation_group'] ?? ''),
            'featured_image'    => (string) ($_POST['featured_image'] ?? ''),
            'meta_title'        => (string) ($_POST['meta_title'] ?? ''),
            'meta_description'  => (string) ($_POST['meta_description'] ?? ''),
            'tags'              => (string) ($_POST['tags'] ?? ''),
            'meta_json'         => json_encode($metaJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $newId = Revisions::store($pageId, $data, 'autosave');
        $payload = $listPayload($pageId);
        $payload['revision_id'] = $newId;
        $payload['stored'] = $newId !== null;
        $payload['autosaved_at'] = date('H:i:s');
        $respond($payload);

    case 'restore':
        $ownerPage = $pageIdForRevision($revisionId);
        if (!$canEditPage($ownerPage)) {
            $respond(['status' => 'error', 'message' => 'Access denied.'], 403);
        }
        if (!Revisions::restore($revisionId)) {
            $respond(['status' => 'error', 'message' => 'Could not restore revision.'], 400);
        }
        $respond(['status' => 'success', 'page_id' => $ownerPage]);

    case 'delete':
        $ownerPage = $pageIdForRevision($revisionId);
        if (!$canEditPage($ownerPage)) {
            $respond(['status' => 'error', 'message' => 'Access denied.'], 403);
        }
        if (!Revisions::delete($revisionId)) {
            $respond(['status' => 'error', 'message' => 'Revision not found.'], 404);
        }
        $payload = $listPayload($ownerPage);
        $payload['deleted'] = true;
        $respond($payload);

    default:
        $respond(['status' => 'error', 'message' => 'Unknown action.'], 400);
}
