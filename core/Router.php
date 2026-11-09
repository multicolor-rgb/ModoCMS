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

    /**
     * Backwards-compatible entry point. Tracking itself lives in
     * Core\Analytics so that index.php can record the visit before the
     * full-page cache serves a stored response.
     */
    public static function trackVisit(): void {
        Analytics::track();
    }

    private function renderBlogArchive(\PDO $database, string $themeDirectory, string $activeLocale, string $title = '', bool $isHomepage = false): void {
        $currentPageNumber = max(1, (int)($_GET['page'] ?? 1));
        $postsPerPageLimit = (int)self::getOption('posts_per_page', '6');
        $queryOffset = ($currentPageNumber - 1) * $postsPerPageLimit;

        // Licznik wpisów
        $countStmt = $database->prepare("SELECT COUNT(*) FROM pages WHERE type = 'post' AND status = 'published' AND deleted_at IS NULL AND (published_at IS NULL OR published_at <= datetime('now')) AND (lang = :lang OR lang = '')");
        $countStmt->execute([':lang' => $activeLocale]);
        $totalPostsCount = (int)$countStmt->fetchColumn();

        // Pobranie wpisów
        $postsStmt = $database->prepare("
            SELECT p.*, u.username as author_name 
            FROM pages p 
            LEFT JOIN users u ON p.author_id = u.id 
            WHERE p.type = 'post' AND p.status = 'published' AND p.deleted_at IS NULL AND (p.published_at IS NULL OR p.published_at <= datetime('now')) AND (p.lang = :lang OR p.lang = '')
            ORDER BY " . ContentOrder::postsOrder('p', 'created_at') . "
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
            'title' => self::formatDocumentTitle($archiveTitle, $isHomepage),
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

    /**
     * Renders the search results page using the theme's search.php when it
     * exists, otherwise the regular archive template.
     */
    private function renderSearch(\PDO $database, string $themeDirectory, string $activeLocale, string $query, int $pageNumber): void {
        $query = trim($query);
        $pageNumber = max(1, $pageNumber);
        $perPage = max(1, (int)self::getOption('posts_per_page', '6'));
        $offset = ($pageNumber - 1) * $perPage;

        $params = [':lang' => $activeLocale];
        $where = "p.status = 'published' AND p.deleted_at IS NULL AND (p.published_at IS NULL OR p.published_at <= datetime('now')) AND (p.lang = :lang OR p.lang = '')";
        if ($query !== '') {
            $where .= " AND (p.title LIKE :q OR p.content LIKE :q)";
            $params[':q'] = '%' . $query . '%';
        } else {
            $where .= ' AND 1 = 0';
        }

        $count = $database->prepare("SELECT COUNT(*) FROM pages p WHERE {$where}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();

        $stmt = $database->prepare("
            SELECT p.*, u.username AS author_name
            FROM pages p
            LEFT JOIN users u ON p.author_id = u.id
            WHERE {$where}
            ORDER BY p.updated_at DESC, p.id DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, \PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        global $clean_posts_iterator;
        $clean_posts_iterator = new \ArrayIterator($stmt->fetchAll());

        $title = $query !== '' ? sprintf(__('Search results for "%s"'), $query) : __('Search');
        \ThemeState::$seoPayload = [
            'title' => self::formatDocumentTitle($title, false),
            'description' => self::getOption('site_description', ''),
            'og_image' => '',
        ];

        $template = file_exists($themeDirectory . 'search.php')
            ? 'search.php'
            : (file_exists($themeDirectory . 'archive.php') ? 'archive.php' : 'index.php');

        View::render($themeDirectory . $template, [
            'currentPage' => $pageNumber,
            'totalPages' => (int)ceil($total / $perPage),
            'archiveTitle' => $title,
            'searchQuery' => $query,
        ]);
    }

    /**
     * Renders a saved document for a valid preview token, regardless of its
     * status. The content is never cached and is marked noindex.
     */
    private static function renderPreviewPage(int $pageId): void {
        $database = Database::getConnection();
        $stmt = $database->prepare("
            SELECT p.*, u.username AS author_name
            FROM pages p
            LEFT JOIN users u ON p.author_id = u.id
            WHERE p.id = :id AND p.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([':id' => $pageId]);
        $page = $stmt->fetch();

        if (!$page) {
            http_response_code(404);
            $theme = basename(self::getOption('active_theme', 'default'));
            $themeDirectory = __DIR__ . '/../themes/' . $theme . '/';
            if (is_file($themeDirectory . '404.php')) {
                View::render($themeDirectory . '404.php');
            } else {
                echo '<h1>404 - Not found</h1>';
            }
            return;
        }

        if (!empty($page['lang'])) {
            I18n::setLocale((string)$page['lang']);
        }

        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store, private');

        \ThemeState::$currentPage = $page;
        \ThemeState::$seoPayload = [
            'title' => self::formatDocumentTitle((string)$page['title'], false),
            'description' => (string)($page['meta_description'] ?? ''),
            'og_image' => (string)($page['featured_image'] ?? ''),
        ];

        $theme = basename(self::getOption('active_theme', 'default'));
        $themeDirectory = __DIR__ . '/../themes/' . $theme . '/';
        if ((string)$page['type'] === 'post') {
            $template = is_file($themeDirectory . 'single.php') ? 'single.php' : 'page.php';
        } else {
            $template = is_file($themeDirectory . 'page.php') ? 'page.php' : 'index.php';
        }

        if (!is_file($themeDirectory . $template)) {
            http_response_code(404);
            echo '<h1>404 - Not found</h1>';
            return;
        }

        View::render($themeDirectory . $template);
    }

    /**
     * Whether the current frontend request has to be answered with the 503
     * maintenance page. Administrators, the admin area and the addresses listed
     * in "maintenance_allowed_ips" are never affected.
     */
    public static function maintenanceActive(): bool {
        // The updater briefly closes the public site while it replaces files.
        // A stale flag is ignored by Updater::isUpdating() automatically.
        $updating = class_exists(Updater::class) && Updater::isUpdating();

        if (!$updating && !Config::getBool('maintenance_mode', false)) {
            return false;
        }

        if (defined('IN_ADMIN') && IN_ADMIN === true) {
            return false;
        }

        $remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($remoteAddress !== '') {
            foreach (Config::getList('maintenance_allowed_ips') as $allowedEntry) {
                if (self::addressMatches($remoteAddress, $allowedEntry)) {
                    return false;
                }
            }
        }

        // Signed in editors may preview the site while it is closed.
        if (class_exists(Auth::class) && Auth::check() && Auth::can('manage_settings')) {
            return false;
        }

        return true;
    }

    /**
     * Whether a client address is covered by one allow-list entry. Three forms
     * are accepted so an entry copied from a firewall or provider works as-is:
     * a single address (203.0.113.7), an address prefix (203.0.113.) or a CIDR
     * range (203.0.113.0/24, 2001:db8::/32).
     */
    /**
     * SQL fragment that hides drafts and entries scheduled for the future.
     *
     * @param string $alias Optional table alias, e.g. "p".
     */
    public static function publishedCondition(string $alias = ''): string {
        $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';

        return $prefix . "status = 'published' AND " . $prefix . "deleted_at IS NULL AND (" . $prefix . "published_at IS NULL OR " . $prefix . "published_at <= datetime('now'))";
    }

    public static function addressMatches(string $address, string $entry): bool {
        $entry = trim($entry);
        $address = trim($address);
        if ($entry === '' || $address === '') {
            return false;
        }

        if (preg_match('#^([^/]+)/([0-9]{1,3})$#', $entry, $matches) === 1) {
            $subnet = @inet_pton($matches[1]);
            $client = @inet_pton($address);
            $bits = (int)$matches[2];

            if ($subnet === false || $client === false || strlen($subnet) !== strlen($client)) {
                return false;
            }
            if ($bits > strlen($subnet) * 8) {
                return false;
            }

            $wholeBytes = intdiv($bits, 8);
            if ($wholeBytes > 0 && substr($client, 0, $wholeBytes) !== substr($subnet, 0, $wholeBytes)) {
                return false;
            }

            $remainingBits = $bits % 8;
            if ($remainingBits === 0) {
                return true;
            }

            $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

            return (ord($client[$wholeBytes]) & $mask) === (ord($subnet[$wholeBytes]) & $mask);
        }

        // A complete single address must match exactly; only an entry ending
        // with a dot is a literal prefix (e.g. "203.0.113."). This prevents an
        // allow-listed 203.0.113.7 from also admitting 203.0.113.70.
        if (filter_var($entry, FILTER_VALIDATE_IP) !== false) {
            $client = @inet_pton($address);
            $single = @inet_pton($entry);
            return $client !== false && $single !== false && $client === $single;
        }

        if (str_ends_with($entry, '.')) {
            return str_starts_with($address, $entry);
        }

        return $address === $entry;
    }

    /**
     * Sends the maintenance notice: the theme's maintenance.php when present,
     * otherwise a self-contained page. Always answers 503 + Retry-After so
     * crawlers treat the outage as temporary.
     */
    public static function renderMaintenance(): void {
        $retryAfter = max(60, Config::getInt('maintenance_retry_after', 3600));
        $message = Config::getString('maintenance_message', '');

        http_response_code(503);
        header('Retry-After: ' . $retryAfter);
        header('X-Robots-Tag: noindex');
        header('Content-Type: text/html; charset=utf-8');

        $activeTheme = basename(self::getOption('active_theme', 'default'));
        $themeDirectory = __DIR__ . '/../themes/' . $activeTheme . '/';

        if (is_file($themeDirectory . 'maintenance.php')) {
            \ThemeState::$seoPayload = [
                'title' => self::formatDocumentTitle(__('Maintenance'), false),
                'description' => $message !== '' ? $message : __('The site is temporarily unavailable.'),
                'og_image' => '',
            ];
            View::render($themeDirectory . 'maintenance.php', ['maintenanceMessage' => $message]);
            return;
        }

        $siteName = self::getOption('site_name', 'ModoCMS');
        $headline = __('Maintenance');
        $notice = $message !== '' ? $message : __('The site is temporarily unavailable. Please come back soon.');
        $minutes = (int)ceil($retryAfter / 60);
        $hint = sprintf(__('Expected back in about %d minutes.'), $minutes);

        echo '<!DOCTYPE html><html lang="' . htmlspecialchars(I18n::getLocale(), ENT_QUOTES, 'UTF-8') . '"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . htmlspecialchars($headline . ' - ' . $siteName, ENT_QUOTES, 'UTF-8') . '</title>'
            . '<style>body{margin:0;font:16px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh}'
            . 'main{max-width:560px;margin:24px;padding:32px;background:#1e293b;border:1px solid #334155;border-radius:16px;text-align:center}'
            . 'h1{margin:0 0 12px;font-size:22px}p{margin:0 0 8px;color:#cbd5e1}small{color:#94a3b8}</style></head><body><main>'
            . '<h1>' . htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') . '</h1>'
            . '<p>' . nl2br(htmlspecialchars($notice, ENT_QUOTES, 'UTF-8')) . '</p>'
            . '<small>' . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '</small>'
            . '</main></body></html>';
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

        // Plain HTTP requests are redirected to HTTPS when modoconfig.php asks for it.
        if (Config::getBool('require_https', false) && !Config::requestIsHttps()) {
            $host = Config::requestHost();
            if ($host !== '') {
                $target = 'https://' . $host . str_replace(["\r", "\n", ' '], '', (string)($_SERVER['REQUEST_URI'] ?? '/'));
                header('Location: ' . $target, true, 301);
                return;
            }
        }

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

            // modoconfig.php may replace the generated rules (e.g. to hide the
            // sitemap of a staging copy from search engines).
            $robotsOverride = Config::getString('robots_txt', '');
            if ($robotsOverride !== '') {
                echo str_replace(["\r\n", "\r"], "\n", $robotsOverride) . "\n";
                return;
            }

            $siteUrl = self::getSiteUrl();

            echo "User-agent: *\n";
            echo "Allow: /\n";
            echo "Disallow: /admin/\n";
            echo "Disallow: /core/\n";
            echo "Disallow: /uploads/cache/\n\n";
            echo "Sitemap: " . $siteUrl . "/sitemap.xml\n";
            return;
        }

        // RSS feed (/feed.xml and /{lang}/feed.xml).
        if (preg_match('#^/(?:([a-z0-9_-]+)/)?feed\.xml$#i', $normalizedPath, $feedMatch) === 1) {
            $feedLocale = I18n::getDefaultLocale();
            $feedSegment = strtolower((string)($feedMatch[1] ?? ''));
            if ($feedSegment !== '' && array_key_exists($feedSegment, I18n::getAvailableLanguages())) {
                $feedLocale = $feedSegment;
                I18n::setLocale($feedLocale);
            }
            header('Content-Type: application/rss+xml; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            echo Feed::render($feedLocale);
            return;
        }

        // Signed preview links for drafts and scheduled content.
        if ($normalizedPath === '/preview') {
            $token = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
            $previewId = Preview::validate($token);
            if ($previewId === null) {
                http_response_code(403);
                header('Content-Type: text/html; charset=utf-8');
                echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Preview expired</title></head><body style="font-family:system-ui;padding:40px"><h1>Preview link expired</h1><p>Ask an administrator for a new preview link.</p></body></html>';
                return;
            }
            self::renderPreviewPage($previewId);
            return;
        }

        // Maintenance mode: visitors get a 503 page, the admin area and the
        // allow-listed addresses keep working while the site is being updated.
        if (self::maintenanceActive()) {
            self::renderMaintenance();
            return;
        }

        // Analytics are recorded in index.php before the full-page cache runs.

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

        // Site search (/search?q=... and /{lang}/search?q=...).
        if (isset($pathSegments[0]) && $pathSegments[0] === 'search') {
            $this->renderSearch($database, $themeDirectory, $activeLocale, (string)($_GET['q'] ?? ''), (int)($_GET['page'] ?? 1));
            return;
        }

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
                WHERE pt.tag_id = :tid AND p.type = 'post' AND p.status = 'published' AND p.deleted_at IS NULL AND (p.published_at IS NULL OR p.published_at <= datetime('now')) AND (p.lang = :lang OR p.lang = '')
            ");
            $countStmt->execute([':tid' => $tag['id'], ':lang' => $activeLocale]);
            $totalPostsCount = (int)$countStmt->fetchColumn();

            $postsStmt = $database->prepare("
                SELECT p.*, u.username as author_name 
                FROM pages p
                JOIN page_tags pt ON pt.page_id = p.id
                LEFT JOIN users u ON p.author_id = u.id
                WHERE pt.tag_id = :tid AND p.type = 'post' AND p.status = 'published' AND p.deleted_at IS NULL AND (p.published_at IS NULL OR p.published_at <= datetime('now')) AND (p.lang = :lang OR p.lang = '')
                ORDER BY " . ContentOrder::postsOrder('p', 'created_at') . "
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
                'title' => self::formatDocumentTitle(__('Tag') . ': ' . $tag['name'], false),
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

        // 1b. Category Archive (/blog/category/{slug} lub /{postsSlug}/category/{slug})
        if (isset($pathSegments[0]) && ($pathSegments[0] === 'blog' || $pathSegments[0] === $postsSlug)
            && isset($pathSegments[1]) && $pathSegments[1] === 'category' && !empty($pathSegments[2])) {

            $categorySlug = $pathSegments[2];
            $categoryStmt = $database->prepare("SELECT * FROM categories WHERE slug = :s LIMIT 1");
            $categoryStmt->execute([':s' => $categorySlug]);
            $category = $categoryStmt->fetch();

            if (!$category) {
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
                JOIN page_categories pc ON pc.page_id = p.id
                WHERE pc.category_id = :cid AND p.type = 'post' AND p.status = 'published' AND p.deleted_at IS NULL AND (p.published_at IS NULL OR p.published_at <= datetime('now')) AND (p.lang = :lang OR p.lang = '')
            ");
            $countStmt->execute([':cid' => $category['id'], ':lang' => $activeLocale]);
            $totalPostsCount = (int)$countStmt->fetchColumn();

            $postsStmt = $database->prepare("
                SELECT p.*, u.username as author_name 
                FROM pages p
                JOIN page_categories pc ON pc.page_id = p.id
                LEFT JOIN users u ON p.author_id = u.id
                WHERE pc.category_id = :cid AND p.type = 'post' AND p.status = 'published' AND p.deleted_at IS NULL AND (p.published_at IS NULL OR p.published_at <= datetime('now')) AND (p.lang = :lang OR p.lang = '')
                ORDER BY " . ContentOrder::postsOrder('p', 'created_at') . "
                LIMIT :lim OFFSET :off
            ");
            $postsStmt->bindValue(':cid', $category['id'], \PDO::PARAM_INT);
            $postsStmt->bindValue(':lang', $activeLocale);
            $postsStmt->bindValue(':lim', $postsPerPageLimit, \PDO::PARAM_INT);
            $postsStmt->bindValue(':off', $queryOffset, \PDO::PARAM_INT);
            $postsStmt->execute();

            global $clean_posts_iterator;
            $clean_posts_iterator = new \ArrayIterator($postsStmt->fetchAll());

            $categoryDescription = trim((string)($category['description'] ?? ''));
            if ($categoryDescription === '') {
                $categoryDescription = sprintf(__('Articles in the %s category'), $category['name']);
            }

            \ThemeState::$seoPayload = [
                'title' => self::formatDocumentTitle(__('Category') . ': ' . $category['name'], false),
                'description' => $categoryDescription,
                'og_image' => ''
            ];

            View::render($themeDirectory . 'archive.php', [
                'currentPage' => $currentPageNumber,
                'totalPages' => (int)ceil($totalPostsCount / $postsPerPageLimit),
                'archiveTitle' => __('Category') . ': ' . $category['name']
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
                $this->renderBlogArchive($database, $themeDirectory, $activeLocale, '', true);
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
                      AND p.status = 'published' AND p.deleted_at IS NULL AND (p.published_at IS NULL OR p.published_at <= datetime('now'))
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
                      AND p.status = 'published' AND p.deleted_at IS NULL AND (p.published_at IS NULL OR p.published_at <= datetime('now'))
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
                    WHERE p.type = 'page' AND p.status = 'published' AND p.deleted_at IS NULL
                      AND (p.published_at IS NULL OR p.published_at <= datetime('now'))
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
                      AND p.status = 'published' AND p.deleted_at IS NULL AND (p.published_at IS NULL OR p.published_at <= datetime('now'))
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
                      AND p.status = 'published' AND p.deleted_at IS NULL AND (p.published_at IS NULL OR p.published_at <= datetime('now'))
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
                'title' => !empty($documentRecord['meta_title']) ? $documentRecord['meta_title'] : self::formatDocumentTitle($documentRecord['title'], empty($pathSegments)),
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

        // 3.5 Trwałe przekierowania (301) — automatyczna obsługa zmian sluga / rodzica
        if (class_exists('Core\\Redirects')) {
            $redirect = \Core\Redirects::find($normalizedPath);
            if ($redirect) {
                \Core\Redirects::hit((int)$redirect['id']);
                $toPath = (string)$redirect['to_path'];
                $target = rtrim(self::getSiteUrl(), '/') . ($toPath === '/' ? '/' : $toPath);
                header('Location: ' . $target, true, 301);
                return;
            }
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
     * Builds the document <title> from the configured title-format settings.
     *
     * Two independent formats are supported (homepage vs. every other page)
     * and a configurable separator. Returns a raw string (no HTML entities) so
     * that the escaping performed in theme_head() renders it correctly.
     *
     * Formats:
     *  - site_only : site name only
     *  - page_only : page name only
     *  - page_site : page name + separator + site name (default)
     *  - site_page : site name + separator + page name
     */
    public static function formatDocumentTitle(string $pageTitle, bool $isHomepage = false): string {
        $siteTitle = trim(self::getOption('site_title', 'Modo CMS'));
        $pageTitle = trim($pageTitle);

        if ($pageTitle === '') {
            return $siteTitle;
        }
        if ($siteTitle === '') {
            return $pageTitle;
        }

        $separator = trim(self::getOption('title_separator', '|'));
        if ($separator === '') {
            $separator = '|';
        }

        $formatKey = $isHomepage ? 'homepage_title_format' : 'subpage_title_format';
        $format = self::getOption($formatKey, 'page_site');

        return match ($format) {
            'site_only' => $siteTitle,
            'page_only' => $pageTitle,
            'site_page' => $siteTitle . ' ' . $separator . ' ' . $pageTitle,
            default     => $pageTitle . ' ' . $separator . ' ' . $siteTitle,
        };
    }

    /**
     * Resolves the canonical site base URL (scheme + host + optional subfolder).
     *
     * When the "site_url" setting is configured it takes precedence; otherwise
     * the value is auto-detected from the current request so the CMS keeps
     * working out of the box without any manual configuration.
     */
    public static function getSiteUrl(): string {
        $configured = rtrim(trim(self::getOption('site_url', '')), '/');
        if ($configured !== '') {
            // If the configured value already contains a path (subfolder), trust it verbatim.
            $path = parse_url($configured, PHP_URL_PATH);
            if ($path !== null && $path !== '' && $path !== '/') {
                return $configured;
            }

            // Origin-only: respect the actual installation subfolder (root or /subfolder).
            return rtrim($configured . self::getBaseSubdirectory(), '/');
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

        $scheme = Config::requestIsHttps() ? 'https' : 'http';
        $requestedHost = Config::requestHost();

        // HTTP_HOST is attacker-controlled. Only honour it when it agrees with
        // the server-configured name (or an explicit trusted-host list); all
        // absolute URLs (password-reset mail, canonical tags, sitemap) must not
        // be redirectable to an arbitrary domain.
        $serverName = strtolower(trim((string)($_SERVER['SERVER_NAME'] ?? '')));
        if ($serverName !== '') {
            $requestedName = strtolower((string)preg_replace('/:\d+$/', '', $requestedHost));
            $trusted = array_map('strtolower', Config::getList('trusted_hosts'));

            $matches = $requestedName !== '' && (
                $requestedName === $serverName
                || $requestedName === 'www.' . $serverName
                || $serverName === 'www.' . $requestedName
                || in_array($requestedName, $trusted, true)
            );

            return $scheme . '://' . ($matches ? $requestedHost : $serverName);
        }

        return $scheme . '://' . ($requestedHost !== '' ? $requestedHost : 'localhost');
    }

    /**
     * Resolves the base slug (parent page) used for the blog archive and single post permalinks.
     * Falls back to the virtual "blog" route when no posts page is configured.
     */
    public static function getPostsPageSlug(): string {
        $configuredPostsId = (int)self::getOption('posts_page_id', '0');
        if ($configuredPostsId > 0) {
            $database = Database::getConnection();
            $statement = $database->prepare("SELECT slug FROM pages WHERE id = :pid AND deleted_at IS NULL LIMIT 1");
            $statement->execute([':pid' => $configuredPostsId]);
            $resolvedSlug = $statement->fetchColumn();
            if ($resolvedSlug !== false && $resolvedSlug !== null && $resolvedSlug !== '') {
                return (string)$resolvedSlug;
            }
        }

        return 'blog';
    }
}