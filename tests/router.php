<?php
declare(strict_types=1);

/**
 * Router for PHP's built-in server used by the smoke test. It mirrors the
 * production .htaccess: serve real files directly, send everything else to the
 * front controller.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . '/..' . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/../index.php';
