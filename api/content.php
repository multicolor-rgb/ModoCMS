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

$checkStmt = $db->prepare("SELECT COUNT(*) FROM pages WHERE slug = :slug AND lang = :lang");
$checkStmt->execute([':slug' => $slug, ':lang' => $lang]);
if ((int)$checkStmt->fetchColumn() > 0) {
    $slug .= '-' . bin2hex(random_bytes(3));
}

try {
    $stmt = $db->prepare("
        INSERT INTO pages (
            title, slug, content, type, status, lang, 
            translation_group, featured_image, meta_title, meta_description, author_id
        ) VALUES (
            :title, :slug, :content, :type, :status, :lang, 
            :group_key, :featured_image, :meta_title, :meta_description, :author_id
        )
    ");

    $stmt->execute([
        ':title' => $title,
        ':slug' => $slug,
        ':content' => $content,
        ':type' => $type,
        ':status' => $status,
        ':lang' => $lang,
        ':group_key' => $translationGroup,
        ':featured_image' => $featuredImage,
        ':meta_title' => $metaTitle,
        ':meta_description' => $metaDescription,
        ':author_id' => (int)$authUser['id']
    ]);

    $newId = (int)$db->lastInsertId();

    http_response_code(201);
    echo json_encode([
        'status' => 'success',
        'message' => 'Entry created successfully.',
        'data' => [
            'id' => $newId,
            'title' => $title,
            'slug' => $slug,
            'url' => ($lang !== I18n::getDefaultLocale() ? '/' . $lang : '') . '/' . $slug,
            'type' => $type,
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
