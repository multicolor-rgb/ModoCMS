<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    http_response_code(204);
    exit;
}

header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../core/bootstrap.php';
use Core\Database;
use Core\I18n;
use Core\PageTypes;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed. Use POST.']);
    exit;
}

$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Missing or malformed Authorization header. Use format: Bearer <API_KEY>']);
    exit;
}

$apiToken = $matches[1];
$db = Database::getConnection();

$userStmt = $db->prepare("SELECT id, username, role FROM users WHERE api_token = :token LIMIT 1");
$userStmt->execute([':token' => $apiToken]);
$authUser = $userStmt->fetch();

if (!$authUser) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid API token.']);
    exit;
}

$rawBody = file_get_contents('php://input');
$inputData = json_decode($rawBody, true);
if (!is_array($inputData)) {
    $inputData = $_POST;
}

$title = trim((string)($inputData['title'] ?? ''));
if ($title === '') {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Validation error: "title" field is required.']);
    exit;
}

$content = (string)($inputData['content'] ?? '');
$type = in_array($inputData['type'] ?? '', ['post', 'page'], true) ? $inputData['type'] : 'post';
$pageTypeId = (int)($inputData['page_type_id'] ?? 0);
if (!empty($inputData['page_type'])) {
    $apiPageType = PageTypes::findBySlug((string)$inputData['page_type']);
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
$status = in_array($inputData['status'] ?? '', ['published', 'draft'], true) ? $inputData['status'] : 'published';
$lang = trim((string)($inputData['lang'] ?? I18n::getDefaultLocale()));
$translationGroup = trim((string)($inputData['translation_group'] ?? bin2hex(random_bytes(8))));
$featuredImage = trim((string)($inputData['featured_image'] ?? ''));
$metaTitle = trim((string)($inputData['meta_title'] ?? ''));
$metaDescription = trim((string)($inputData['meta_description'] ?? ''));

$slug = trim((string)($inputData['slug'] ?? ''));
if ($slug === '') {
    $slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));
}

$slug = \Core\PageSlug::unique($slug, $lang);

try {
    $stmt = $db->prepare("
        INSERT INTO pages (
            title, slug, content, type, page_type_id, status, lang,
            translation_group, featured_image, meta_title, meta_description, author_id
        ) VALUES (
            :title, :slug, :content, :type, :page_type_id, :status, :lang,
            :group_key, :featured_image, :meta_title, :meta_description, :author_id
        )
    ");

    $stmt->execute([
        ':title' => $title,
        ':slug' => $slug,
        ':content' => $content,
        ':type' => $type,
        ':page_type_id' => $pageTypeId > 0 ? $pageTypeId : null,
        ':status' => $status,
        ':lang' => $lang,
        ':group_key' => $translationGroup,
        ':featured_image' => $featuredImage,
        ':meta_title' => $metaTitle,
        ':meta_description' => $metaDescription,
        ':author_id' => (int)$authUser['id']
    ]);

    $newId = (int)$db->lastInsertId();

    if ($pageTypeId > 0) {
        PageTypes::applyDefaults($newId, $pageTypeId);
    }
    $createdPageType = $pageTypeId > 0 ? PageTypes::find($pageTypeId) : null;

    http_response_code(201);
    echo json_encode([
        'status' => 'success',
        'message' => 'Entry created successfully.',
        'data' => [
            'id' => $newId,
            'title' => $title,
            'slug' => $slug,
            'url' => ($lang !== I18n::getDefaultLocale() ? '/' . $lang : '') . '/' . ($type === 'post' ? \Core\Router::getPostsPageSlug() . '/' : '') . $slug,
            'type' => $type,
            'page_type_id' => $pageTypeId > 0 ? $pageTypeId : null,
            'page_type' => $createdPageType['slug'] ?? null,
            'status' => $status,
            'lang' => $lang,
            'translation_group' => $translationGroup,
            'author' => $authUser['username']
        ]
    ]);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
