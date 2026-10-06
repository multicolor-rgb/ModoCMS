<?php
declare(strict_types=1);

 
// Internal lightweight analytics tracking
try {
    $db = \Core\Database::getConnection();
    $rawIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    // Daily rotated IP hash for privacy and GDPR compliance
    $ipHash = hash('sha256', $rawIp . date('Y-m-d'));
    $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 150);

    // Track only unique path visits per day per IP hash
    $chk = $db->prepare("SELECT id FROM visits WHERE ip_hash = :h AND path = :p AND visited_at = DATE('now') LIMIT 1");
    $chk->execute([':h' => $ipHash, ':p' => $reqPath]);

    if (!$chk->fetchColumn()) {
        $ins = $db->prepare("INSERT INTO visits (path, ip_hash, user_agent, visited_at) VALUES (:p, :h, :ua, DATE('now'))");
        $ins->execute([':p' => $reqPath, ':h' => $ipHash, ':ua' => $ua]);
    }
} catch (\Throwable $e) {
    // Silent fallback: never block frontend rendering on analytics error
}


require_once __DIR__ . '/core/bootstrap.php';

// Full-page cache: serve a cached copy to anonymous visitors when enabled.
if (class_exists('\\Core\\PageCache')) {
    \Core\PageCache::maybeServe();
}

$router = new \Core\Router();
$router->dispatch();
