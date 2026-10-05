<?php
declare(strict_types=1);

namespace Core;

/**
 * Class Router
 * Handles request dispatching, subfolder detection, dynamic homepage/blog resolution, and multilingual routing.
 */
final class Router {
    private array $registeredRoutes = [];

    public function get(string $path, callable $callback): void {
        $this->registeredRoutes['GET'][$path] = $callback;
    }

    public function post(string $path, callable $callback): void {
        $this->registeredRoutes['POST'][$path] = $callback;
    }

    public static function getBaseSubdirectory(): string {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $baseDir = str_replace('\\', '/', dirname($scriptName));
        $baseDir = ($baseDir === '/' || $baseDir === '.') ? '' : rtrim($baseDir, '/');

        if (str_ends_with($baseDir, '/admin')) {
            $baseDir = substr($baseDir, 0, -strlen('/admin'));
        }

        // Fallback: when SCRIPT_NAME is empty or unreliable (e.g. PHP built-in server,
        // non-standard FPM setups), derive the base from the filesystem location.
        if ($baseDir === '' && !empty($_SERVER['SCRIPT_FILENAME']) && !empty($_SERVER['DOCUMENT_ROOT'])) {
            $docRoot = str_replace('\\', '/', realpath((string)$_SERVER['DOCUMENT_ROOT']) ?: (string)$_SERVER['DOCUMENT_ROOT']);
            $scriptFile = str_replace('\\', '/', realpath((string)$_SERVER['SCRIPT_FILENAME']) ?: (string)$_SERVER['SCRIPT_FILENAME']);
            $scriptDir = str_replace('\\', '/', dirname($scriptFile));

            if (str_ends_with($scriptDir, '/admin')) {
                $scriptDir = substr($scriptDir, 0, -strlen('/admin'));
            }

            if ($docRoot !== '' && str_starts_with($scriptDir, $docRoot)) {
                $relative = substr($scriptDir, strlen($docRoot));
                $baseDir = ($relative === '' || $relative === '/') ? '' : rtrim($relative, '/');
            }
        }

        return $baseDir;
    }

    public static function trackVisit(): void {
        if (defined('IN_ADMIN')) {
            return;
        }

        $rawUri = $_SERVER['REQUEST_URI'] ?? '/';
        $uriPath = parse_url($rawUri, PHP_URL_PATH) ?: '/';

        if (str_starts_with($uriPath, '/admin')) {
            return;
        }

        $extension = strtolower(pathinfo($uriPath, PATHINFO_EXTENSION));
        if (in_array($extension, ['css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'woff', 'woff2', 'map', 'txt', 'xml'], true)) {
            return;
        }

        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (preg_match('/bot|crawl|slurp|spider|mediapartners/i', $userAgent)) {
            return;
        }

        try {
            $db = Database::getConnection();
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $ipHash = hash('sha256', $ipAddress . 'modocms_salt');

            $checkStmt = $db->prepare("
                SELECT id 
                FROM visits 
                WHERE ip_hash = :ip 
                  AND visited_at >= datetime('now', '-24 hours') 
                LIMIT 1
            ");
            $checkStmt->execute([':ip' => $ipHash]);

            if ($checkStmt->fetchColumn()) {
                return;
            }

            $insertStmt = $db->prepare("
                INSERT INTO visits (path, ip_hash, user_agent, visited_at)
                VALUES (:path, :ip, :ua, datetime('now'))
            ");
            $insertStmt->execute([
                ':path' => $uriPath,
                ':ip'   => $ipHash,
                ':ua'   => substr($userAgent, 0, 255)
            ]);
        } catch (\Throwable $e) {}
    }

    private function renderBlogArchive(\PDO $database, string $themeDirectory, string $activeLocale, string $title = ''): void {
        $currentPageNumber = max(1, (int)($_GET['page'] ?? 1));
        $postsPerPageLimit = (int)self::getOption('posts_per_page', '6');
        $queryOffset = ($currentPageNumber - 1) * $postsPerPageLimit;

        // Licznik wpisów
        $countStmt = $database->prepare("SELECT COUNT(*) FROM pages WHERE type = 'post' AND status = 'published' AND (lang = :lang OR lang = '')");
        $countStmt->execute([':lang' => $activeLocale]);
        $totalPostsCount = (int)$countStmt->fetchColumn();

        // Pobranie wpisów
        $postsStmt = $database->prepare("
            SELECT p.*, u.username as author_name 
            FROM pages p 
            LEFT JOIN users u ON p.author_id = u.id 
            WHERE p.type = 'post' AND p.status = 'published' AND (p.lang = :lang OR p.lang = '')
            ORDER BY p.created_at DESC 
            LIMIT :lim OFFSET :off
        ");
        $postsStmt->bindValue(':lang', $activeLocale);
        $postsStmt->bindValue(':lim', $postsPerPageLimit, \PDO::PARAM_INT);
        $postsStmt->bindValue(':off', $queryOffset, \PDO::PARAM_INT);
        $postsStmt->execute();

        global $clean_posts_iterator;
        $clean_posts_iterator = new \ArrayIterator($postsStmt->fetchAll());

        $archiveTitle = $title !== '' ? $title : __('Blog');

        \ThemeState::$seoPayload = [
            'title' => $archiveTitle . ' &bull; ' . self::getOption('site_title', 'Modo CMS'),
            'description' => self::getOption('site_description', ''),
            'og_image' => ''
        ];

        $templateFile = file_exists($themeDirectory . 'archive.php') ? 'archive.php' : (file_exists($themeDirectory . 'index.php') ? 'index.php' : 'page.php');
        View::render($themeDirectory . $templateFile, [
            'currentPage' => $currentPageNumber,
            'totalPages' => (int)ceil($totalPostsCount / $postsPerPageLimit),
            'archiveTitle' => $archiveTitle
        ]);
    }

    public function dispatch(): void {
        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        $baseDir = self::getBaseSubdirectory();
        $normalizedPath = $requestUri;

        if ($baseDir !== '' && str_starts_with($normalizedPath, $baseDir)) {
            $normalizedPath = substr($normalizedPath, strlen($baseDir));
        }
        $normalizedPath = '/' . trim((string)$normalizedPath, '/');

        // Dynamic Sitemap.xml
        if ($normalizedPath === '/sitemap.xml') {
            header('Content-Type: application/xml; charset=utf-8');
            header('X-Robots-Tag: noindex');
            if (class_exists('Core\Sitemap')) {
                echo Sitemap::generate();
            }
            return;
        }

        // Dynamic Robots.txt
        if ($normalizedPath === '/robots.txt') {
            header('Content-Type: text/plain; charset=utf-8');
            $siteUrl = self::getSiteUrl();

            echo "User-agent: *\n";
            echo "Allow: /\n";
            echo "Disallow: /admin/\n";
            echo "Disallow: /core/\n";
            echo "Disallow: /uploads/cache/\n\n";
            echo "Sitemap: " . $siteUrl . "/sitemap.xml\n";
            return;
        }

        self::trackVisit();

        if (isset($this->registeredRoutes[$requestMethod][$normalizedPath])) {
            call_user_func($this->registeredRoutes[$requestMethod][$normalizedPath]);
            return;
        }

        $isMultilingual = self::getOption('multilingual_frontend', '0') === '1';
        $pathSegments = array_values(array_filter(explode('/', trim($normalizedPath, '/'))));
        $defaultLocale = I18n::getDefaultLocale();
        $activeLocale = $defaultLocale;

        // Wykrywanie prefiksu języka w URL
        if ($isMultilingual && !empty($pathSegments[0])) {
            $supportedLanguages = I18n::getAvailableLanguages();
            if (array_key_exists($pathSegments[0], $supportedLanguages)) {
                $activeLocale = array_shift($pathSegments);
                I18n::setLocale($activeLocale);
            } else {
                I18n::setLocale($defaultLocale);
            }
        } else {
            I18n::setLocale($defaultLocale);
            $activeLocale = $defaultLocale;
        }

        $database = Database::getConnection();
        $activeTheme = basename(self::getOption('active_theme', 'default'));
        $themeDirectory = __DIR__ . '/../themes/' . $activeTheme . '/';

        // Odczyt ustawień strony głównej i bloga
        $homepageType = self::getOption('homepage_type', 'page');
        $configuredHomeId = (int)self::getOption('homepage_page_id', '0');

        // Dynamiczny slug bazowy dla bloga (strona nadrzędna wpisów)
        $postsSlug = self::getPostsPageSlug();

        // 1. Tag Archive (/blog/tag/{slug} lub /{postsSlug}/tag/{slug})
        if (isset($pathSegments[0]) && ($pathSegments[0] === 'blog' || $pathSegments[0] === $postsSlug) 
            && isset($pathSegments[1]) && $pathSegments[1] === 'tag' && !empty($pathSegments[2])) {
            
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
                WHERE pt.tag_id = :tid AND p.type = 'post' AND p.status = 'published' AND (p.lang = :lang OR p.lang = '')
            ");
            $countStmt->execute([':tid' => $tag['id'], ':lang' => $activeLocale]);
            $totalPostsCount = (int)$countStmt->fetchColumn();

            $postsStmt = $database->prepare("
                SELECT p.*, u.username as author_name 
                FROM pages p
                JOIN page_tags pt ON pt.page_id = p.id
                LEFT JOIN users u ON p.author_id = u.id
                WHERE pt.tag_id = :tid AND p.type = 'post' AND p.status = 'published' AND (p.lang = :lang OR p.lang = '')
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
                'title' => __('Tag') . ': ' . htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8') . ' &bull; ' . self::getOption('site_title', 'Modo CMS'),
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

        // 2. Archiwum bloga (/blog lub /{postsSlug})
        if (!empty($pathSegments[0]) && ($pathSegments[0] === 'blog' || $pathSegments[0] === $postsSlug) && count($pathSegments) === 1) {
            $this->renderBlogArchive($database, $themeDirectory, $activeLocale);
            return;
        }

        // 3. Rozpoznawanie strony głównej i hierarchii
        $documentRecord = null;

        if (empty($pathSegments)) {
            // STRONA GŁÓWNA (/)

            // Przypadek A: Na stronie głównej wyświetlaj najnowsze wpisy
            if ($homepageType === 'posts') {
                $this->renderBlogArchive($database, $themeDirectory, $activeLocale);
                return;
            }

            // Przypadek B: Na stronie głównej wyświetlaj wskazaną stronę statyczną
            if ($configuredHomeId > 0) {
                // Najpierw szukamy dokładnie według ID lub powiązania językowego
                $stmt = $database->prepare("
                    SELECT p.*, u.username as author_name 
                    FROM pages p 
                    LEFT JOIN users u ON p.author_id = u.id 
                    WHERE (p.id = :hid OR p.translation_group = (SELECT translation_group FROM pages WHERE id = :hid2))
                      AND (p.lang = :lang OR p.lang = '')
                      AND p.status = 'published' 
                    LIMIT 1
                ");
                $stmt->execute([':hid' => $configuredHomeId, ':hid2' => $configuredHomeId, ':lang' => $activeLocale]);
                $documentRecord = $stmt->fetch();
            }

            // Fallback 1: Szukaj strony ze slugiem 'home' dla aktualnego języka
            if (!$documentRecord) {
                $stmt = $database->prepare("
                    SELECT p.*, u.username as author_name 
                    FROM pages p 
                    LEFT JOIN users u ON p.author_id = u.id 
                    WHERE p.slug = 'home' 
                      AND (p.lang = :lang OR p.lang = '') 
                      AND p.status = 'published' 
                    LIMIT 1
                ");
                $stmt->execute([':lang' => $activeLocale]);
                $documentRecord = $stmt->fetch();
            }

            // Fallback 2: Dowolna pierwsza opublikowana strona statyczna
            if (!$documentRecord) {
                $stmt = $database->prepare("
                    SELECT p.*, u.username as author_name 
                    FROM pages p 
                    LEFT JOIN users u ON p.author_id = u.id 
                    WHERE p.type = 'page' AND p.status = 'published' 
                    ORDER BY p.id ASC 
                    LIMIT 1
                ");
                $stmt->execute();
                $documentRecord = $stmt->fetch();
            }
        } else {
            // Podstrony (/uslugi, /o-nas ...)
            $parentId = 0;
            $resolvedNode = null;

            foreach ($pathSegments as $slug) {
                $stmt = $database->prepare("
                    SELECT p.*, u.username as author_name 
                    FROM pages p 
                    LEFT JOIN users u ON p.author_id = u.id 
                    WHERE p.slug = :s 
                      AND p.type = 'page' 
                      AND p.parent_id = :pid 
                      AND (p.lang = :lang OR p.lang = '') 
                      AND p.status = 'published' 
                    LIMIT 1
                ");
                $stmt->execute([':s' => $slug, ':pid' => $parentId, ':lang' => $activeLocale]);
                $node = $stmt->fetch();

                if (!$node) {
                    $resolvedNode = null;
                    break;
                }

                $parentId = (int)$node['id'];
                $resolvedNode = $node;
            }

            $documentRecord = $resolvedNode;

            // Pojedynczy wpis pod stroną bloga (/{postsSlug}/{postSlug} lub /blog/{postSlug}).
            // Wpisy są dostępne wyłącznie pod slugiem strony nadrzędnej bloga.
            if (!$documentRecord && count($pathSegments) === 2 && ($pathSegments[0] === 'blog' || $pathSegments[0] === $postsSlug)) {
                $postStmt = $database->prepare("
                    SELECT p.*, u.username as author_name 
                    FROM pages p 
                    LEFT JOIN users u ON p.author_id = u.id 
                    WHERE p.slug = :s 
                      AND p.type = 'post' 
                      AND (p.lang = :lang OR p.lang = '') 
                      AND p.status = 'published' 
                    LIMIT 1
                ");
                $postStmt->execute([':s' => $pathSegments[1], ':lang' => $activeLocale]);
                $documentRecord = $postStmt->fetch() ?: null;
            }
        }

        // Renderowanie znalezionego dokumentu
        if ($documentRecord) {
            \ThemeState::$currentPage = $documentRecord;
            \ThemeState::$seoPayload = [
                'title' => !empty($documentRecord['meta_title']) ? $documentRecord['meta_title'] : $documentRecord['title'] . ' &bull; ' . self::getOption('site_title', 'Modo CMS'),
                'description' => !empty($documentRecord['meta_description']) ? $documentRecord['meta_description'] : self::getOption('site_description', ''),
                'og_image' => $documentRecord['featured_image'] ?? ''
            ];

            // Dobór odpowiedniego pliku szablonu
            $templateFileName = 'page.php';
            if ($documentRecord['type'] === 'post') {
                $templateFileName = file_exists($themeDirectory . 'single.php') ? 'single.php' : 'page.php';
            } elseif (empty($pathSegments) && file_exists($themeDirectory . 'front-page.php')) {
                // Dedykowany szablon front-page.php jeśli istnieje w motywie
                $templateFileName = 'front-page.php';
            } elseif (!file_exists($themeDirectory . 'page.php')) {
                $templateFileName = 'index.php';
            }

            View::render($themeDirectory . $templateFileName);
            return;
        }

        // 4. Błąd 404
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

    /**
     * Resolves the canonical site base URL (scheme + host + optional subfolder).
     *
     * When the "site_url" setting is configured it takes precedence; otherwise
     * the value is auto-detected from the current request so the CMS keeps
     * working out of the box without any manual configuration.
     */
    public static function getSiteUrl(): string {
        $configured = trim(self::getOption('site_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return rtrim(self::getSiteOrigin() . self::getBaseSubdirectory(), '/');
    }

    /**
     * Resolves only the scheme + host (origin) of the site, honouring the
     * configured "site_url" domain when present.
     */
    public static function getSiteOrigin(): string {
        $configured = trim(self::getOption('site_url', ''));
        if ($configured !== '') {
            $parts = parse_url($configured);
            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                $origin = $parts['scheme'] . '://' . $parts['host'];
                if (!empty($parts['port'])) {
                    $origin .= ':' . $parts['port'];
                }
                return $origin;
            }
        }

        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' && $_SERVER['HTTPS'] !== '') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        return $scheme . '://' . $host;
    }

    /**
     * Resolves the base slug (parent page) used for the blog archive and single post permalinks.
     * Falls back to the virtual "blog" route when no posts page is configured.
     */
    public static function getPostsPageSlug(): string {
        $configuredPostsId = (int)self::getOption('posts_page_id', '0');
        if ($configuredPostsId > 0) {
            $database = Database::getConnection();
            $statement = $database->prepare("SELECT slug FROM pages WHERE id = :pid LIMIT 1");
            $statement->execute([':pid' => $configuredPostsId]);
            $resolvedSlug = $statement->fetchColumn();
            if ($resolvedSlug !== false && $resolvedSlug !== null && $resolvedSlug !== '') {
                return (string)$resolvedSlug;
            }
        }

        return 'blog';
    }
}