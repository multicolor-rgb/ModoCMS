<?php
declare(strict_types=1);

define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Security;

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$token = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!Security::verifyCsrfToken($token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$theme = strtolower(trim((string)($_POST['theme'] ?? '')));
if (!in_array($theme, ['auto', 'light', 'dark'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid theme']);
    exit;
}

if (!Auth::setAdminTheme($theme)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not save the theme']);
    exit;
}

\Core\Audit::log('settings.admin_theme', 'user:' . Auth::id(), ['theme' => $theme]);

echo json_encode(['ok' => true, 'theme' => $theme]);
