<?php
declare(strict_types=1);

define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Security;
use Core\Customizer;

header('Content-Type: application/json; charset=utf-8');

if (!Auth::check() || !Auth::can('manage_settings')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Accept both form posts and raw JSON bodies.
$raw = file_get_contents('php://input') ?: '';
$payload = [];
if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}
$payload = array_merge($_POST, $payload);

$action = (string) ($payload['action'] ?? '');
$token = (string) ($payload['csrf_token'] ?? '');

if (!Security::verifyCsrfToken($token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

/** @return array<string,mixed> */
$values = [];
if (isset($payload['values'])) {
    $values = is_array($payload['values']) ? $payload['values'] : (json_decode((string) $payload['values'], true) ?: []);
}

$theme = Customizer::activeTheme();

try {
    switch ($action) {
        case 'save_draft':
            Customizer::setDraft($values, $theme);
            echo json_encode(['ok' => true, 'saved' => count($values)]);
            break;

        case 'publish':
            // Merge the draft with the submitted values so nothing is lost.
            Customizer::setDraft($values, $theme);
            $count = Customizer::publish(Customizer::getDraft($theme), $theme);
            echo json_encode(['ok' => true, 'published' => $count, 'token' => Customizer::startPreview($theme)]);
            break;

        case 'reset':
            Customizer::resetTheme($theme);
            echo json_encode(['ok' => true, 'token' => Customizer::startPreview($theme)]);
            break;

        case 'schema_save':
            $sections = $payload['sections'] ?? [];
            if (!is_array($sections)) {
                $sections = json_decode((string) $sections, true) ?: [];
            }
            Customizer::saveStoredSchema($sections, $theme);
            echo json_encode(['ok' => true, 'stored' => Customizer::getStoredSchema($theme)]);
            break;

        case 'schema_delete':
            Customizer::deleteStoredSchema($theme);
            echo json_encode(['ok' => true]);
            break;

        case 'panel':
            // Re-render the "Customize" tab so newly saved settings appear without a page reload.
            $schema = Customizer::getSchema($theme);
            $values = Customizer::getMods($theme);
            $draft = Customizer::getDraft($theme);
            if (!empty($draft)) {
                $values = array_merge($values, $draft);
            }
            $cssMap = [];
            foreach ($schema['sections'] as $panelSection) {
                foreach ($panelSection['controls'] as $panelControl) {
                    if (($panelControl['css_var'] ?? '') !== '') {
                        $cssMap[$panelControl['id']] = ['var' => $panelControl['css_var'], 'unit' => $panelControl['css_unit']];
                    }
                }
            }
            ob_start();
            require __DIR__ . '/views/customize-panel.php';
            $html = ob_get_clean();
            echo json_encode(['ok' => true, 'html' => $html, 'cssMap' => $cssMap]);
            break;

        case 'preview_token':
            echo json_encode(['ok' => true, 'token' => Customizer::startPreview($theme)]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
