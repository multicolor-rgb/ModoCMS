<?php
declare(strict_types=1);

/**
 * HTTP cron entry point. Use a system cron job where possible:
 *   php /path/to/tools/cron.php
 *
 * For shared hosting, call:
 *   https://example.com/cron.php?token=<data/cron.key>
 */

require_once __DIR__ . '/core/bootstrap.php';

use Core\Scheduler;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$token = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
if (!Scheduler::verifyToken($token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid cron token.']);
    exit;
}

try {
    echo json_encode(['ok' => true, 'result' => Scheduler::run()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Scheduled tasks failed.']);
}
