<?php
declare(strict_types=1);

require_once __DIR__ . '/core/bootstrap.php';

// Live theme customizer: the panel and the preview live in separate documents,
// so only the preview iframe is refreshed while editing (the sidebar keeps its
// state). Serve the shell before analytics/routing/cache are involved.
if (class_exists(\Core\CustomizerPanel::class) && \Core\CustomizerPanel::renderShellIfRequested()) {
    exit;
}

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
