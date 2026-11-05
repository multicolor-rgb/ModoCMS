<?php
declare(strict_types=1);

/**
 * Modo CMS content API.
 *
 * Authentication: Authorization: Bearer <token>
 * Tokens are stored as SHA-256 hashes. GET is read-only; POST/PUT/PATCH/DELETE
 * write content and require the same capabilities as the admin panel.
 */

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    http_response_code(204);
    exit;
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

require_once __DIR__ . '/../core/bootstrap.php';

use Core\Audit;
use Core\Auth;
use Core\Database;
use Core\I18n;
use Core\Logger;
use Core\PageCache;
use Core\PageSlug;
use Core\PageTypes;
use Core\Security;
use Core\Sitemap;

/** Sends a JSON response and stops the request. */
function apiRespond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Sends a JSON error and stops the request. */
function apiError(int $status, string $message, array $extra = []): void
{
    apiRespond($status, ['status' => 'error', 'message' => $message] + $extra);
}

/**
 * Resolves the bearer token to a user row and applies expiry + rate limiting.
 *
 * @return array{user:array<string,mixed>, token_hash:string}
 */
function apiAuthenticate(): array
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = (string)($headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));

    if (!preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        apiError(401, 'Missing or malformed Authorization header. Use: Bearer <API_KEY>');
    }

    $rawToken = $matches[1];
    $tokenHash = hash('sha256', $rawToken);
    $db = Database::getConnection();

    $stmt = $db->prepare("SELECT id, username, role, api_token, api_token_expires FROM users WHERE api_token = :token LIMIT 1");
    $stmt->execute([':token' => $tokenHash]);
    $user = $stmt->fetch();

    // Backwards compatibility: tokens generated before 3.0.9 were stored in
    // plaintext. Upgrade them transparently on first use.
    if (!$user) {
        $legacy = $db->prepare("SELECT id, username, role, api_token, api_token_expires FROM users WHERE api_token = :token LIMIT 1");
        $legacy->execute([':token' => $rawToken]);
        $user = $legacy->fetch();

        if ($user) {
            $db->prepare("UPDATE users SET api_token = :hash WHERE id = :id")
                ->execute([':hash' => $tokenHash, ':id' => (int)$user['id']]);
            $user['api_token'] = $tokenHash;
        }
    }

    if (!$user) {
        apiError(401, 'Invalid API token.');
    }

    $expires = (string)($user['api_token_expires'] ?? '');
    if ($expires !== '' && strtotime($expires) !== false && strtotime($expires) < time()) {
        apiError(401, 'API token has expired. Generate a new token in your profile.');
    }

    if (!Security::rateLimit('api:' . $tokenHash, 120, 60)) {
        header('Retry-After: 60');
        apiError(429, 'Too many API requests. Try again in a minute.');
    }

    return ['user' => $user, 'token_hash' => $tokenHash];
}

/** @return array<string,mixed> */
function apiInput(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return is_array($_POST) ? $_POST : [];
}

function apiRequireCapability(string $role, string $capability): void
{
    if (!Auth::roleCan($role, $capability)) {
        apiError(403, 'Your token does not have the "' . $capability . '" capability.');
    }
}

function apiLocale(string $lang): string
{
    $available = I18n::getAvailableLanguages();
    $lang = trim($lang);

    if ($lang !== '' && array_key_exists($lang, $available)) {
        return $lang;
    }

    return I18n::getDefaultLocale();
}

function apiPurgeCaches(): void
{
    try {
        if (class_exists(PageCache::class)) {
            PageCache::purge();
        }
        if (class_exists(Sitemap::class)) {
            Sitemap::generate();
        }
    } catch (\Throwable $e) {
        Logger::exception($e, 'API cache/sitemap refresh failed');
    }
}

/** @param array<string,mixed> $page */
function apiPagePayload(array $page, array $meta = []): array
{
    return [
        'id'                => (int)$page['id'],
        'title'             => (string)$page['title'],
        'slug'              => (string)$page['slug'],
        'content'           => (string)($page['content'] ?? ''),
        'type'              => (string)$page['type'],
        'status'            => (string)$page['status'],
        'lang'              => (string)$page['lang'],
        'page_type_id'      => isset($page['page_type_id']) ? (int)$page['page_type_id'] : null,
        'parent_id'         => (int)($page['parent_id'] ?? 0),
        'translation_group' => (string)($page['translation_group'] ?? ''),
        'featured_image'    => (string)($page['featured_image'] ?? ''),
        'meta_title'        => (string)($page['meta_title'] ?? ''),
        'meta_description'  => (string)($page['meta_description'] ?? ''),
        'published_at'      => $page['published_at'] ?? null,
        'deleted_at'        => $page['deleted_at'] ?? null,
        'created_at'        => $page['created_at'] ?? null,
        'updated_at'        => $page['updated_at'] ?? null,
        'author_id'         => isset($page['author_id']) ? (int)$page['author_id'] : null,
        'meta'              => $meta,
    ];
}

/** @return array<int,array{key:string,value:mixed,type:string}> */
function apiMetaRows(array $input): array
{
    $rows = [];
    $meta = $input['meta'] ?? null;
    if (!is_array($meta)) {
        return $rows;
    }

    $allowedTypes = ['text', 'textarea', 'number', 'checkbox', 'boolean', 'color', 'image', 'gallery', 'json', 'richtext'];
    foreach ($meta as $key => $value) {
        if (!is_string($key) || $key === '' || preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $key) !== 1) {
            continue;
        }

        $type = 'text';
        if (is_array($value) && isset($value['type'], $value['value'])) {
            $type = in_array((string)$value['type'], $allowedTypes, true) ? (string)$value['type'] : 'text';
            $value = $value['value'];
        }

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $type = 'json';
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
            $type = 'boolean';
        }

        $rows[] = ['key' => $key, 'value' => (string)$value, 'type' => $type];
    }

    return $rows;
}

/** @param array<int,array{key:string,value:mixed,type:string}> $rows */
function apiSaveMeta(int $pageId, array $rows): void
{
    if ($rows === []) {
        return;
    }

    $db = Database::getConnection();
    $stmt = $db->prepare("
        INSERT INTO page_meta (page_id, meta_key, meta_value, meta_type)
        VALUES (:page_id, :key, :value, :type)
        ON CONFLICT(page_id, meta_key) DO UPDATE SET meta_value = :value2, meta_type = :type2
    ");

    foreach ($rows as $row) {
        $stmt->execute([
            ':page_id' => $pageId,
            ':key'     => $row['key'],
            ':value'   => (string)$row['value'],
            ':type'    => $row['type'],
            ':value2'  => (string)$row['value'],
            ':type2'   => $row['type'],
        ]);
    }
}

function apiFetchPage(int $id): ?array
{
    $stmt = Database::getConnection()->prepare("SELECT * FROM pages WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $page = $stmt->fetch();

    return $page ?: null;
}

function apiFetchMeta(int $pageId): array
{
    $stmt = Database::getConnection()->prepare("SELECT meta_key, meta_value, meta_type FROM page_meta WHERE page_id = :id ORDER BY meta_key ASC");
    $stmt->execute([':id' => $pageId]);

    $meta = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $meta[(string)$row['meta_key']] = [
            'value' => $row['meta_value'],
            'type'  => $row['meta_type'],
        ];
    }

    return $meta;
}

try {
    $auth = apiAuthenticate();
    $authUser = $auth['user'];
    $role = (string)$authUser['role'];
    $userId = (int)$authUser['id'];
    $db = Database::getConnection();
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    // ---------------------------------------------------------------------
    // Read
    // ---------------------------------------------------------------------
    if ($method === 'GET') {
        apiRequireCapability($role, 'manage_pages');

        $id = (int)($_GET['id'] ?? 0);
        if ($id > 0) {
            $page = apiFetchPage($id);
            $includeTrash = (($_GET['trash'] ?? '') === '1');
            if (!$page || (!$includeTrash && !empty($page['deleted_at']))) {
                apiError(404, 'Entry not found.');
            }
            if (!Auth::roleCan($role, 'edit_others_pages') && (int)($page['author_id'] ?? 0) !== $userId) {
                apiError(403, 'You can only read your own entries.');
            }

            apiRespond(200, ['status' => 'success', 'data' => apiPagePayload($page, apiFetchMeta($id))]);
        }

        $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
        $pageNumber = max(1, (int)($_GET['page'] ?? 1));
        $offset = ($pageNumber - 1) * $perPage;

        $where = [(($_GET['trash'] ?? '') === '1') ? 'p.deleted_at IS NOT NULL' : 'p.deleted_at IS NULL'];
        $params = [];

        if (!empty($_GET['type']) && in_array($_GET['type'], ['page', 'post'], true)) {
            $where[] = 'p.type = :type';
            $params[':type'] = $_GET['type'];
        }
        if (!empty($_GET['status']) && in_array($_GET['status'], ['published', 'draft'], true)) {
            $where[] = 'p.status = :status';
            $params[':status'] = $_GET['status'];
        }
        if (!empty($_GET['lang']) && is_string($_GET['lang'])) {
            $where[] = 'p.lang = :lang';
            $params[':lang'] = apiLocale($_GET['lang']);
        }
        if (isset($_GET['page_type']) && is_string($_GET['page_type']) && $_GET['page_type'] !== '') {
            $where[] = 'pt.slug = :page_type';
            $params[':page_type'] = $_GET['page_type'];
        }
        if (isset($_GET['q']) && is_string($_GET['q']) && trim($_GET['q']) !== '') {
            $where[] = '(p.title LIKE :q OR p.content LIKE :q)';
            $params[':q'] = '%' . trim($_GET['q']) . '%';
        }
        if (!Auth::roleCan($role, 'edit_others_pages')) {
            $where[] = 'p.author_id = :author_id';
            $params[':author_id'] = $userId;
        }

        $whereSql = implode(' AND ', $where);

        $count = $db->prepare("SELECT COUNT(*) FROM pages p LEFT JOIN page_types pt ON pt.id = p.page_type_id WHERE {$whereSql}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $list = $db->prepare("
            SELECT p.*, u.username AS author_name, pt.slug AS page_type_slug
            FROM pages p
            LEFT JOIN users u ON u.id = p.author_id
            LEFT JOIN page_types pt ON pt.id = p.page_type_id
            WHERE {$whereSql}
            ORDER BY p.updated_at DESC, p.id DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $key => $value) {
            $list->bindValue($key, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $list->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $list->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $list->execute();

        $items = [];
        foreach ($list->fetchAll() ?: [] as $row) {
            $payload = apiPagePayload($row);
            $payload['author'] = (string)($row['author_name'] ?? '');
            $payload['page_type'] = (string)($row['page_type_slug'] ?? '');
            $items[] = $payload;
        }

        apiRespond(200, [
            'status' => 'success',
            'data'   => $items,
            'pagination' => [
                'page'     => $pageNumber,
                'per_page' => $perPage,
                'total'    => $total,
                'pages'    => (int)ceil($total / $perPage),
            ],
        ]);
    }

    $input = apiInput();

    // ---------------------------------------------------------------------
    // Create
    // ---------------------------------------------------------------------
    if ($method === 'POST') {
        apiRequireCapability($role, 'manage_pages');
        apiRequireCapability($role, 'create_pages');

        $title = trim((string)($input['title'] ?? ''));
        if ($title === '') {
            apiError(422, 'Validation error: "title" field is required.');
        }

        $status = in_array($input['status'] ?? '', ['published', 'draft'], true) ? (string)$input['status'] : 'published';
        if ($status === 'published') {
            apiRequireCapability($role, 'publish_pages');
        }

        $content = (string)($input['content'] ?? '');
        $type = in_array($input['type'] ?? '', ['post', 'page'], true) ? (string)$input['type'] : 'post';
        $lang = apiLocale((string)($input['lang'] ?? I18n::getDefaultLocale()));

        $pageTypeId = (int)($input['page_type_id'] ?? 0);
        if (!empty($input['page_type'])) {
            $apiPageType = PageTypes::findBySlug((string)$input['page_type']);
            if ($apiPageType) {
                $pageTypeId = (int)$apiPageType['id'];
                $type = (string)$apiPageType['base_type'];
            }
        }
        if ($pageTypeId > 0) {
            $apiPageType = PageTypes::find($pageTypeId);
            if (!$apiPageType || (string)$apiPageType['base_type'] !== $type) {
                $pageTypeId = 0;
            }
        }

        $publishedAt = null;
        if (!empty($input['published_at'])) {
            $timestamp = strtotime((string)$input['published_at']);
            $publishedAt = $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
        }

        $slug = trim((string)($input['slug'] ?? ''));
        if ($slug === '') {
            $slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));
        }
        $slug = PageSlug::unique($slug !== '' ? $slug : 'entry', $lang);

        $stmt = $db->prepare("
            INSERT INTO pages (
                title, slug, content, type, page_type_id, status, lang,
                translation_group, featured_image, meta_title, meta_description,
                author_id, published_at
            ) VALUES (
                :title, :slug, :content, :type, :page_type_id, :status, :lang,
                :group_key, :featured_image, :meta_title, :meta_description,
                :author_id, :published_at
            )
        ");
        $stmt->execute([
            ':title'            => $title,
            ':slug'             => $slug,
            ':content'          => $content,
            ':type'             => $type,
            ':page_type_id'     => $pageTypeId > 0 ? $pageTypeId : null,
            ':status'           => $status,
            ':lang'             => $lang,
            ':group_key'        => trim((string)($input['translation_group'] ?? '')) ?: bin2hex(random_bytes(8)),
            ':featured_image'   => trim((string)($input['featured_image'] ?? '')),
            ':meta_title'       => trim((string)($input['meta_title'] ?? '')),
            ':meta_description' => trim((string)($input['meta_description'] ?? '')),
            ':author_id'        => $userId,
            ':published_at'     => $publishedAt,
        ]);

        $newId = (int)$db->lastInsertId();
        if ($pageTypeId > 0) {
            PageTypes::applyDefaults($newId, $pageTypeId);
        }
        apiSaveMeta($newId, apiMetaRows($input));

        $page = apiFetchPage($newId) ?: [];
        $createdPageType = $pageTypeId > 0 ? PageTypes::find($pageTypeId) : null;

        Audit::log('api.content.create', 'page:' . $newId, ['type' => $type, 'status' => $status]);
        apiPurgeCaches();

        apiRespond(201, [
            'status'  => 'success',
            'message' => 'Entry created successfully.',
            'data'    => apiPagePayload($page, apiFetchMeta($newId)) + [
                'url'       => ($lang !== I18n::getDefaultLocale() ? '/' . $lang : '') . '/' . ($type === 'post' ? \Core\Router::getPostsPageSlug() . '/' : '') . $slug,
                'page_type' => $createdPageType['slug'] ?? null,
                'author'    => $authUser['username'],
            ],
        ]);
    }

    // ---------------------------------------------------------------------
    // Update
    // ---------------------------------------------------------------------
    if ($method === 'PUT' || $method === 'PATCH') {
        apiRequireCapability($role, 'manage_pages');

        $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) {
            apiError(422, 'Validation error: "id" is required.');
        }

        $existing = apiFetchPage($id);
        if (!$existing || !empty($existing['deleted_at'])) {
            apiError(404, 'Entry not found.');
        }
        if (!Auth::roleCan($role, 'edit_others_pages') && (int)($existing['author_id'] ?? 0) !== $userId) {
            apiError(403, 'You can only edit your own entries.');
        }

        $status = array_key_exists('status', $input)
            ? (in_array($input['status'], ['published', 'draft'], true) ? (string)$input['status'] : (string)$existing['status'])
            : (string)$existing['status'];
        if ($status === 'published' && (string)$existing['status'] !== 'published') {
            apiRequireCapability($role, 'publish_pages');
        }

        $title = array_key_exists('title', $input) ? trim((string)$input['title']) : (string)$existing['title'];
        if ($title === '') {
            apiError(422, 'Validation error: "title" cannot be empty.');
        }

        $lang = array_key_exists('lang', $input) ? apiLocale((string)$input['lang']) : (string)$existing['lang'];
        $slug = array_key_exists('slug', $input) ? trim((string)$input['slug']) : (string)$existing['slug'];
        if ($slug === '') {
            $slug = (string)$existing['slug'];
        }
        if ($slug !== (string)$existing['slug'] || $lang !== (string)$existing['lang']) {
            $slug = PageSlug::unique($slug, $lang, $id);
        }

        $publishedAt = $existing['published_at'] ?? null;
        if (array_key_exists('published_at', $input)) {
            $publishedAt = $input['published_at'] === null || $input['published_at'] === ''
                ? null
                : (strtotime((string)$input['published_at']) !== false ? date('Y-m-d H:i:s', strtotime((string)$input['published_at'])) : null);
        }

        $update = $db->prepare("
            UPDATE pages SET
                title = :title,
                slug = :slug,
                content = :content,
                type = :type,
                status = :status,
                lang = :lang,
                featured_image = :featured_image,
                meta_title = :meta_title,
                meta_description = :meta_description,
                published_at = :published_at,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $update->execute([
            ':title'            => $title,
            ':slug'             => $slug,
            ':content'          => array_key_exists('content', $input) ? (string)$input['content'] : (string)($existing['content'] ?? ''),
            ':type'             => array_key_exists('type', $input) && in_array($input['type'], ['page', 'post'], true) ? (string)$input['type'] : (string)$existing['type'],
            ':status'           => $status,
            ':lang'             => $lang,
            ':featured_image'   => array_key_exists('featured_image', $input) ? trim((string)$input['featured_image']) : (string)($existing['featured_image'] ?? ''),
            ':meta_title'       => array_key_exists('meta_title', $input) ? trim((string)$input['meta_title']) : (string)($existing['meta_title'] ?? ''),
            ':meta_description' => array_key_exists('meta_description', $input) ? trim((string)$input['meta_description']) : (string)($existing['meta_description'] ?? ''),
            ':published_at'     => $publishedAt,
            ':id'               => $id,
        ]);

        apiSaveMeta($id, apiMetaRows($input));

        Audit::log('api.content.update', 'page:' . $id, ['status' => $status]);
        apiPurgeCaches();

        apiRespond(200, [
            'status'  => 'success',
            'message' => 'Entry updated successfully.',
            'data'    => apiPagePayload(apiFetchPage($id) ?: [], apiFetchMeta($id)),
        ]);
    }

    // ---------------------------------------------------------------------
    // Delete
    // ---------------------------------------------------------------------
    if ($method === 'DELETE') {
        apiRequireCapability($role, 'delete_pages');

        $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) {
            apiError(422, 'Validation error: "id" is required.');
        }

        $existing = apiFetchPage($id);
        if (!$existing) {
            apiError(404, 'Entry not found.');
        }
        if (!Auth::roleCan($role, 'edit_others_pages') && (int)($existing['author_id'] ?? 0) !== $userId) {
            apiError(403, 'You can only delete your own entries.');
        }

        $force = (($_GET['force'] ?? '') === '1');
        if ($force) {
            foreach (['page_meta', 'page_tags', 'page_categories'] as $table) {
                $db->prepare("DELETE FROM {$table} WHERE page_id = :id")->execute([':id' => $id]);
            }
            $db->prepare("DELETE FROM pages WHERE id = :id")->execute([':id' => $id]);
            Audit::log('api.content.delete', 'page:' . $id, ['title' => (string)$existing['title'], 'force' => true]);
            $message = 'Entry deleted permanently.';
        } else {
            $db->prepare("UPDATE pages SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id")->execute([':id' => $id]);
            Audit::log('api.content.trash', 'page:' . $id, ['title' => (string)$existing['title']]);
            $message = 'Entry moved to trash.';
        }
        apiPurgeCaches();

        apiRespond(200, ['status' => 'success', 'message' => $message]);
    }

    header('Allow: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    apiError(405, 'Method not allowed.');
} catch (\Throwable $e) {
    Logger::exception($e, 'API request failed', ['method' => $_SERVER['REQUEST_METHOD'] ?? '']);
    apiError(500, 'An internal error occurred. The details were written to the server log.');
}
