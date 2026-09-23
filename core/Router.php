<?php
declare(strict_types=1);

namespace Core;

/**
 * Class Router
 * Handles request dispatching, subfolder detection, nested page hierarchies, blog tags, traffic tracking, and multilingual routing.
 */
final class Router {
    private array $registeredRoutes = [];

    public function get(string $path, callable $callback): void {
        $this->registeredRoutes['GET'][$path] = $callback;
    }

    public function post(string $path, callable $callback): void {
        $this->registeredRoutes['POST'][$path] = $callback;
    }

    /**
     * Resolves the base installation subfolder (e.g. "/cleancms" or empty string if in root).
     */
    public static function getBaseSubdirectory(): string {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $baseDir = str_replace('\\', '/', dirname($scriptName));
        $baseDir = ($baseDir === '/' || $baseDir === '.') ? '' : rtrim($baseDir, '/');

        // Strip /admin suffix if executing inside admin directory
        if (str_ends_with($baseDir, '/admin')) {
            $baseDir = substr($baseDir, 0, -strlen('/admin'));
        }

        return $baseDir;
    }

    /**
     * Logs visitor traffic to SQLite 'visits' table.
     * Enforces strict rate limit: maximum 1 counted visit per unique IP hash every 24 hours.
     */
    public static function trackVisit(): void {
        if (defined('IN_ADMIN')) {
            return;
        }

        $rawUri = $_SERVER['REQUEST_URI'] ?? '/';
        $uriPath = parse_url($rawUri, PHP_URL_PATH) ?: '/';

        // Ignore admin routes
        if (str_starts_with($uriPath, '/admin')) {
            return;
        }

        // Ignore static assets
        $extension = strtolower(pathinfo($uriPath, PATHINFO_EXTENSION));
        if (in_array($extension, ['css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'woff', 'woff2', 'map', 'txt'], true)) {
            return;
        }

        // Ignore web crawlers and automated bots
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (preg_match('/bot|crawl|slurp|spider|mediapartners/i', $userAgent)) {
            return;
        }

        try {
            $db = Database::getConnection();

            // Stała sól aplikacji do haszowania IP (zachowuje spójność hasha w oknie 24h bez ujawniania surowego IP)
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $ipHash = hash('sha256', $ipAddress . 'clean_cms_salt');

            // Sprawdzenie, czy ten adres IP został już zarejestrowany w ciągu ostatnich 24 godzin
            $checkStmt = $db->prepare("
                SELECT id 
                FROM visits 
                WHERE ip_hash = :ip 
                  AND visited_at >= datetime('now', '-24 hours') 
                LIMIT 1
            ");
            $checkStmt->execute([':ip' => $ipHash]);

            // Jeśli użytkownik już był w ciągu 24h, nie naliczaj kolejnej wizyty
            if ($checkStmt->fetchColumn()) {
                return;
            }

            // Pierwsza wizyta tego IP w ciągu ostatnich 24h — zapisujemy z pełnym znacznikiem czasu
            $insertStmt = $db->prepare("
                INSERT INTO visits (path, ip_hash, user_agent, visited_at)
                VALUES (:path, :ip, :ua, datetime('now'))
            ");
            $insertStmt->execute([
                ':path' => $uriPath,
                ':ip'   => $ipHash,
                ':ua'   => substr($userAgent, 0, 255)
            ]);
        } catch (\Throwable $e) {
            // Ciche zignorowanie błędu, by nie przerywać renderowania strony
        }
    }

    public function dispatch(): void {
        // Track valid visitor views
        self::trackVisit();

        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        // Strip subfolder prefix dynamically
        $baseDir = self::getBaseSubdirectory();
        $normalizedPath = $requestUri;
        if ($baseDir !== '' && str_starts_with($normalizedPath, $baseDir)) {
            $normalizedPath = substr($normalizedPath, strlen($baseDir));
        }
        $normalizedPath = '/' . trim((string)$normalizedPath, '/');

        // Execute directly registered custom routes
        if (isset($this->registeredRoutes[$requestMethod][$normalizedPath])) {
            call_user_func($this->registeredRoutes[$requestMethod][$normalizedPath]);
            return;
        }

        $isMultilingual = self::getOption('multilingual_frontend', '0') === '1';
        $pathSegments = array_values(array_filter(explode('/', trim($normalizedPath, '/'))));
        $defaultLocale = I18n::getDefaultLocale();
        $activeLocale = $defaultLocale;

        // Detect language prefix if multilingual is enabled
        if ($isMultilingual) {
            $supportedLanguages = I18n::getAvailableLanguages();
            if (!empty($pathSegments[0]) && array_key_exists($pathSegments[0], $supportedLanguages)) {
                $activeLocale = array_shift($pathSegments);
                I18n::setLocale($activeLocale);
            }
        } else {
            I18n::setLocale($defaultLocale);
            $activeLocale = $defaultLocale;
        }

        $database = Database::getConnection();
        $activeTheme = basename(self::getOption('active_theme', 'default'));
        $themeDirectory = __DIR__ . '/../themes/' . $activeTheme . '/';

        // 1. Handle Blog Tag Archive (/blog/tag/{slug})
        if (isset($pathSegments[0]) && $pathSegments[0] === 'blog' && isset($pathSegments[1]) && $pathSegments[1] === 'tag' && !empty($pathSegments[2])) {
            $tagSlug = $pathSegments[2];
            $tagStmt = $database->prepare("SELECT * FROM tags WHERE slug = :s LIMIT 1");
            $tagStmt->execute([':s' => $tagSlug]);
            $tag = $tagStmt->fetch();

            if (!$tag) {
                http_response_code(404);
                if (file_exists($themeDirectory . '404.php')) View::render($themeDirectory . '404.php');
                return;
            }

            $currentPageNumber = max(1, (int)($_GET['page'] ?? 1));
            $postsPerPageLimit = (int)self::getOption('posts_per_page', '6');
            $queryOffset = ($currentPageNumber - 1) * $postsPerPageLimit;

            $countStmt = $database->prepare("
                SELECT COUNT(p.id) 
                FROM pages p
                JOIN page_tags pt ON pt.page_id = p.id
                WHERE pt.tag_id = :tid AND p.type = 'post' AND p.status = 'published' AND p.lang = :lang
            ");
            $countStmt->execute([':tid' => $tag['id'], ':lang' => $activeLocale]);
            $totalPostsCount = (int)$countStmt->fetchColumn();

            $postsStmt = $database->prepare("
                SELECT p.*, u.username as author_name 
                FROM pages p
                JOIN page_tags pt ON pt.page_id = p.id
                LEFT JOIN users u ON p.author_id = u.id
                WHERE pt.tag_id = :tid AND p.type = 'post' AND p.status = 'published' AND p.lang = :lang
                ORDER BY p.created_at DESC 
                LIMIT :lim OFFSET :off
            ");
            $postsStmt->bindValue(':tid', $tag['id'], \PDO::PARAM_INT);
            $postsStmt->bindValue(':lang', $activeLocale);
            $postsStmt->bindValue(':lim', $postsPerPageLimit, \PDO::PARAM_INT);
            $postsStmt->bindValue(':off', $queryOffset, \PDO::PARAM_INT);
            $postsStmt->execute();

            global $clean_posts_iterator;
            $clean_posts_iterator = new \ArrayIterator($postsStmt->fetchAll());

            \ThemeState::$seoPayload = [
                'title' => __('Tag') . ': ' . htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8') . ' &bull; ' . self::getOption('site_title'),
                'description' => sprintf(__('Articles tagged with %s'), $tag['name']),
                'og_image' => ''
            ];

            View::render($themeDirectory . 'archive.php', [
                'currentPage' => $currentPageNumber,
                'totalPages' => (int)ceil($totalPostsCount / $postsPerPageLimit),
                'archiveTitle' => __('Tag') . ': ' . $tag['name']
            ]);
            return;
        }

        // 2. Handle Main Blog Archive (/blog)
        if (!empty($pathSegments[0]) && $pathSegments[0] === 'blog' && count($pathSegments) === 1) {
            $currentPageNumber = max(1, (int)($_GET['page'] ?? 1));
            $postsPerPageLimit = (int)self::getOption('posts_per_page', '6');
            $queryOffset = ($currentPageNumber - 1) * $postsPerPageLimit;

            $countStmt = $database->prepare("SELECT COUNT(*) FROM pages WHERE type = 'post' AND status = 'published' AND lang = :lang");
            $countStmt->execute([':lang' => $activeLocale]);
            $totalPostsCount = (int)$countStmt->fetchColumn();

            $postsStmt = $database->prepare("
                SELECT p.*, u.username as author_name 
                FROM pages p 
                LEFT JOIN users u ON p.author_id = u.id 
                WHERE p.type = 'post' AND p.status = 'published' AND p.lang = :lang 
                ORDER BY p.created_at DESC 
                LIMIT :lim OFFSET :off
            ");
            $postsStmt->bindValue(':lang', $activeLocale);
            $postsStmt->bindValue(':lim', $postsPerPageLimit, \PDO::PARAM_INT);
            $postsStmt->bindValue(':off', $queryOffset, \PDO::PARAM_INT);
            $postsStmt->execute();

            global $clean_posts_iterator;
            $clean_posts_iterator = new \ArrayIterator($postsStmt->fetchAll());

            \ThemeState::$seoPayload = [
                'title' => __('Blog') . ' &bull; ' . self::getOption('site_title'),
                'description' => self::getOption('site_description'),
                'og_image' => ''
            ];

            View::render($themeDirectory . 'archive.php', [
                'currentPage' => $currentPageNumber,
                'totalPages' => (int)ceil($totalPostsCount / $postsPerPageLimit),
                'archiveTitle' => __('Blog')
            ]);
            return;
        }

        // 3. Resolve Hierarchical Pages & Single Posts
        $documentRecord = null;
        if (empty($pathSegments)) {
            // Homepage resolution
            $stmt = $database->prepare("SELECT p.*, u.username as author_name FROM pages p LEFT JOIN users u ON p.author_id = u.id WHERE p.slug = 'home' AND p.lang = :lang AND p.status = 'published' LIMIT 1");
            $stmt->execute([':lang' => $activeLocale]);
            $documentRecord = $stmt->fetch();
        } else {
            // Traverse parent-child hierarchy
            $parentId = 0;
            $resolvedNode = null;

            foreach ($pathSegments as $index => $slug) {
                $isLast = ($index === count($pathSegments) - 1);

                $stmt = $database->prepare("SELECT p.*, u.username as author_name FROM pages p LEFT JOIN users u ON p.author_id = u.id WHERE p.slug = :s AND p.parent_id = :pid AND p.lang = :lang AND p.status = 'published' LIMIT 1");
                $stmt->execute([':s' => $slug, ':pid' => $parentId, ':lang' => $activeLocale]);
                $node = $stmt->fetch();

                // If not matched by page hierarchy and it is a single post at the root level
                if (!$node && $index === 0 && $isLast) {
                    $postStmt = $database->prepare("SELECT p.*, u.username as author_name FROM pages p LEFT JOIN users u ON p.author_id = u.id WHERE p.slug = :s AND p.type = 'post' AND p.lang = :lang AND p.status = 'published' LIMIT 1");
                    $postStmt->execute([':s' => $slug, ':lang' => $activeLocale]);
                    $node = $postStmt->fetch();
                }

                if (!$node) {
                    $resolvedNode = null;
                    break;
                }

                $parentId = (int)$node['id'];
                $resolvedNode = $node;
            }

            $documentRecord = $resolvedNode;
        }

        // Render document if resolved
        if ($documentRecord) {
            \ThemeState::$currentPage = $documentRecord;
            \ThemeState::$seoPayload = [
                'title' => !empty($documentRecord['meta_title']) ? $documentRecord['meta_title'] : $documentRecord['title'] . ' &bull; ' . self::getOption('site_title'),
                'description' => !empty($documentRecord['meta_description']) ? $documentRecord['meta_description'] : self::getOption('site_description'),
                'og_image' => $documentRecord['featured_image'] ?? ''
            ];

            $templateFileName = ($documentRecord['type'] === 'post' && file_exists($themeDirectory . 'single.php')) ? 'single.php' : 'page.php';
            View::render($themeDirectory . $templateFileName);
            return;
        }

        // 4. Fallback 404
        http_response_code(404);
        \ThemeState::$seoPayload = ['title' => __('Page not found.'), 'description' => '', 'og_image' => ''];

        if (file_exists($themeDirectory . '404.php')) {
            View::render($themeDirectory . '404.php');
        } else {
            echo "<h1>404 - " . htmlspecialchars(__('Page not found.'), ENT_QUOTES, 'UTF-8') . "</h1>";
        }
    }

    public static function getOption(string $configurationKey, string $defaultFallback = ''): string {
        $database = Database::getConnection();
        $statement = $database->prepare("SELECT value FROM settings WHERE key = :key LIMIT 1");
        $statement->execute([':key' => $configurationKey]);
        $resultRow = $statement->fetch();

        return $resultRow ? (string)$resultRow['value'] : $defaultFallback;
    }
}