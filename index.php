<?php
declare(strict_types=1);

require_once __DIR__ . '/core/bootstrap.php';

// Internal analytics: track the request before the full-page cache can answer
// it, so cached pages still produce a page view. Analytics::track() is silent
// and honours the analytics_enabled switch from modoconfig.php.
\Core\Analytics::track();

// Full-page cache: serve a cached copy to anonymous visitors when enabled.
if (class_exists('\\Core\\PageCache')) {
    \Core\PageCache::maybeServe();
}

$router = new \Core\Router();
$router->dispatch();
