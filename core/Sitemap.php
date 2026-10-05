<?php
declare(strict_types=1);

namespace Core;

/**
 * Class Sitemap
 * Generates and updates Google-compliant XML sitemaps for Modo CMS.
 */
final class Sitemap {


/**
 * Creates or updates robots.txt file in the root directory.
 */
public static function generateRobotsTxt(): bool {
    $siteUrl = Router::getSiteUrl();

    $robotsContent = "User-agent: *\n" .
                     "Allow: /\n" .
                     "Disallow: /admin/\n" .
                     "Disallow: /core/\n" .
                     "Disallow: /uploads/cache/\n\n" .
                     "Sitemap: " . $siteUrl . "/sitemap.xml\n";

    $robotsPath = dirname(__DIR__) . '/robots.txt';
    return @file_put_contents($robotsPath, $robotsContent) !== false;
}

    /**
     * Builds the full XML sitemap content and saves it to root sitemap.xml
     */
    public static function generate(): string {
        $db = Database::getConnection();
        $isMultilingual = Router::getOption('multilingual_frontend', '0') === '1';
        $defaultLang = I18n::getDefaultLocale();

        // Calculate site base URL (honours the configured canonical domain)
        $siteUrl = Router::getSiteUrl();

        // Fetch all published pages and posts
        $stmt = $db->query("
            SELECT id, parent_id, slug, type, status, lang, updated_at, created_at 
            FROM pages 
            WHERE status = 'published' 
            ORDER BY parent_id ASC, id ASC
        ");
        $allPages = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Build lookup map for fast nested path resolution
        $pageLookup = [];
        foreach ($allPages as $p) {
            $pageLookup[(int)$p['id']] = $p;
        }

        // Start XML building
        $xml = [];
        $xml[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        // 1. Homepage & Root URLs
        $homeUrl = $siteUrl . '/';
        $xml[] = self::buildUrlNode($homeUrl, date('Y-m-d'), 'daily', '1.0');

        // Main Blog Index URL (uses the configured posts page slug)
        $postsSlug = Router::getPostsPageSlug();
        $configuredPostsId = (int)Router::getOption('posts_page_id', '0');
        $blogPrefix = ($isMultilingual ? '/' . $defaultLang : '') . '/' . $postsSlug;
        $xml[] = self::buildUrlNode($siteUrl . $blogPrefix, date('Y-m-d'), 'daily', '0.8');

        // 2. Iterate pages and blog posts
        foreach ($allPages as $page) {
            if ($page['slug'] === 'home') {
                continue; // Already covered
            }

            // The configured posts (blog) page is already emitted above as the blog index
            if ($page['type'] === 'page' && (int)$page['id'] === $configuredPostsId) {
                continue;
            }

            $date = !empty($page['updated_at']) ? date('Y-m-d', strtotime($page['updated_at'])) : date('Y-m-d', strtotime($page['created_at']));
            $langPrefix = ($isMultilingual && $page['lang']) ? '/' . $page['lang'] : '';

            if ($page['type'] === 'post') {
                // Post URL: /lang/{postsSlug}/blog-post-slug
                $url = $siteUrl . $langPrefix . '/' . rawurlencode($postsSlug) . '/' . rawurlencode($page['slug']);
                $xml[] = self::buildUrlNode($url, $date, 'weekly', '0.7');
            } else {
                // Hierarchical Page URL: /lang/parent/child
                $path = self::resolvePagePath((int)$page['id'], $pageLookup);
                $url = $siteUrl . $langPrefix . '/' . $path;
                $priority = ($page['parent_id'] == 0) ? '0.8' : '0.6';
                $xml[] = self::buildUrlNode($url, $date, 'weekly', $priority);
            }
        }

        // 3. Blog Tag Archives
        $tagStmt = $db->query("
            SELECT DISTINCT t.slug, MAX(p.updated_at) as last_mod
            FROM tags t
            JOIN page_tags pt ON pt.tag_id = t.id
            JOIN pages p ON p.id = pt.page_id
            WHERE p.status = 'published'
            GROUP BY t.id
        ");
        while ($tag = $tagStmt->fetch(\PDO::FETCH_ASSOC)) {
            $tagDate = !empty($tag['last_mod']) ? date('Y-m-d', strtotime($tag['last_mod'])) : date('Y-m-d');
            $tagUrl = $siteUrl . ($isMultilingual ? '/' . $defaultLang : '') . '/' . rawurlencode($postsSlug) . '/tag/' . rawurlencode($tag['slug']);
            $xml[] = self::buildUrlNode($tagUrl, $tagDate, 'weekly', '0.5');
        }

        $xml[] = '</urlset>';
        $content = implode("\n", $xml);

        // Save physical file to root directory for high-performance direct web server hits
        $savePath = dirname(__DIR__) . '/sitemap.xml';
        @file_put_contents($savePath, $content);

        return $content;
    }

    /**
     * Helper to assemble a single <url> entry
     */
    private static function buildUrlNode(string $loc, string $lastmod, string $changefreq, string $priority): string {
        return "  <url>\n" .
               "    <loc>" . htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') . "</loc>\n" .
               "    <lastmod>{$lastmod}</lastmod>\n" .
               "    <changefreq>{$changefreq}</changefreq>\n" .
               "    <priority>{$priority}</priority>\n" .
               "  </url>";
    }

    /**
     * Resolves ancestor chain into URL path (e.g. "company/about-us")
     */
    private static function resolvePagePath(int $pageId, array $lookup): string {
        $segments = [];
        $curr = $pageId;
        $visited = [];
        $limit = 20;

        while ($curr > 0 && isset($lookup[$curr]) && !in_array($curr, $visited, true) && $limit-- > 0) {
            $visited[] = $curr;
            if ($lookup[$curr]['slug'] !== 'home') {
                array_unshift($segments, $lookup[$curr]['slug']);
            }
            $curr = (int)($lookup[$curr]['parent_id'] ?? 0);
        }

        return implode('/', array_map('rawurlencode', $segments));
    }
}