<?php
declare(strict_types=1);

use Core\Hooks;
use Core\Router;
use Core\Database;
use Core\I18n;

/**
 * Global state registry for active document, loop cursor, and SEO payload.
 */
class ThemeState {
    public static ?array $currentPage = null;
    public static ?array $currentLoopPost = null;
    public static array $seoPayload = [];
}

// Media & URL Normalization
/**
 * Normalizes an uploaded media path to always include the base subfolder.
 */
function resolve_media_url(string $path): string {
    if ($path === '' || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }

    $basePrefix = class_exists('Core\Router') ? Router::getBaseSubdirectory() : '';

    // Strip existing base prefix if already stored in database
    if ($basePrefix !== '' && str_starts_with($path, $basePrefix . '/')) {
        $path = substr($path, strlen($basePrefix));
    }

    $cleanPath = ltrim($path, '/');
    return ($basePrefix !== '' ? $basePrefix : '') . '/' . $cleanPath;
}

// Site Details
function site_title(bool $echo = true): string {
    $val = Hooks::applyFilters('site_title', Router::getOption('site_title', 'Clean CMS'));
    if ($echo) echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
    return $val;
}

function site_desc(bool $echo = true): string {
    $val = Hooks::applyFilters('site_description', Router::getOption('site_description', ''));
    if ($echo) echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
    return $val;
}

function site_logo(bool $echo = true): string {
    $logo = Router::getOption('site_logo', '');
    $html = '';

    if ($logo !== '') {
        $logoUrl = resolve_media_url($logo);
        $html = '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars(site_title(false), ENT_QUOTES, 'UTF-8') . '" class="site-logo">';
    } else {
        $html = htmlspecialchars(site_title(false), ENT_QUOTES, 'UTF-8');
    }

    if ($echo) echo $html;
    return $html;
}

function site_url(string $path = '', bool $echo = true): string {
    // Utilize Router helper to detect installation subfolder
    $basePrefix = class_exists('Core\Router') ? Router::getBaseSubdirectory() : '';

    $langPrefix = '';
    if (Router::getOption('multilingual_frontend', '0') === '1') {
        $cur = I18n::getLocale();
        $def = I18n::getDefaultLocale();
        if ($cur !== $def) {
            $langPrefix = '/' . $cur;
        }
    }

    $cleanPath = ltrim($path, '/');
    $url = $basePrefix . $langPrefix . ($cleanPath !== '' ? '/' . $cleanPath : '');
    $url = $url === '' ? '/' : $url;

    if ($echo) echo htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    return $url;
}

// Current Page / Single Post Details
function page_title(bool $echo = true): string {
    $val = ThemeState::$currentPage['title'] ?? '';
    $val = Hooks::applyFilters('the_title', $val);
    if ($echo) echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
    return $val;
}

function page_content(bool $echo = true): string {
    $val = ThemeState::$currentPage['content'] ?? '';
    $val = Hooks::applyFilters('the_content', $val);
    if ($echo) echo $val;
    return $val;
}

function page_date(string $format = 'd.m.Y', bool $echo = true): string {
    $raw = ThemeState::$currentPage['created_at'] ?? 'now';
    $val = date($format, strtotime($raw));
    $val = Hooks::applyFilters('page_date', $val);
    if ($echo) echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
    return $val;
}

function page_author(bool $echo = true): string {
    $val = ThemeState::$currentPage['author_name'] ?? __('Editorial');
    $val = Hooks::applyFilters('page_author', $val);
    if ($echo) echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
    return $val;
}

function has_image(): bool {
    return !empty(ThemeState::$currentPage['featured_image']);
}

function page_image(bool $echo = true): string {
    $rawVal = ThemeState::$currentPage['featured_image'] ?? '';
    $val = resolve_media_url($rawVal);
    $val = Hooks::applyFilters('featured_image_url', $val);
    if ($echo) echo htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
    return $val;
}

/**
 * Resolves full hierarchical URL for the current or specified page.
 */
function page_url(?array $page = null, bool $echo = true): string {
    $targetPage = $page ?? ThemeState::$currentPage;
    if (!$targetPage) return site_url('', $echo);

    // Root homepage alias check
    if (($targetPage['slug'] ?? '') === 'home' && (int)($targetPage['parent_id'] ?? 0) === 0) {
        return site_url('', $echo);
    }

    // Flat posts do not have parent hierarchy
    if (($targetPage['type'] ?? '') === 'post') {
        return site_url($targetPage['slug'], $echo);
    }

    $segments = [];
    if (($targetPage['slug'] ?? '') !== 'home') {
        $segments[] = $targetPage['slug'];
    }

    $parentId = (int)($targetPage['parent_id'] ?? 0);

    if ($parentId > 0) {
        $db = Database::getConnection();
        while ($parentId > 0) {
            $stmt = $db->prepare("SELECT slug, parent_id FROM pages WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $parentId]);
            $parent = $stmt->fetch();
            if (!$parent) break;

            // Never include home in URL path segments
            if ($parent['slug'] !== 'home') {
                array_unshift($segments, $parent['slug']);
            }
            $parentId = (int)$parent['parent_id'];
        }
    }

    $fullPath = implode('/', $segments);
    return site_url($fullPath, $echo);
}

/**
 * Resolves full hierarchical path for a page slug taking parent_id into account.
 */
function resolve_page_hierarchy_path(string $slug): string {
    $cleanSlug = trim($slug, '/');
    if ($cleanSlug === '' || $cleanSlug === 'home') {
        return '';
    }

    $db = Database::getConnection();
    $stmt = $db->prepare("SELECT id, slug, parent_id FROM pages WHERE slug = :s LIMIT 1");
    $stmt->execute([':s' => $cleanSlug]);
    $page = $stmt->fetch();

    if (!$page || empty($page['parent_id'])) {
        return $cleanSlug;
    }

    $segments = [$page['slug']];
    $parentId = (int)$page['parent_id'];

    while ($parentId > 0) {
        $parentStmt = $db->prepare("SELECT id, slug, parent_id FROM pages WHERE id = :id LIMIT 1");
        $parentStmt->execute([':id' => $parentId]);
        $parent = $parentStmt->fetch();
        if (!$parent) break;

        // Skip home slug from parent chain
        if ($parent['slug'] !== 'home') {
            array_unshift($segments, $parent['slug']);
        }
        $parentId = (int)$parent['parent_id'];
    }

    return implode('/', $segments);
}

// Loop Functions
function have_posts(): bool {
    global $clean_posts_iterator;
    return isset($clean_posts_iterator) && $clean_posts_iterator->valid();
}

function the_post(): void {
    global $clean_posts_iterator;
    ThemeState::$currentLoopPost = $clean_posts_iterator->current();
    ThemeState::$currentPage = ThemeState::$currentLoopPost;
    $clean_posts_iterator->next();
}

function post_title(bool $echo = true): string {
    return page_title($echo);
}

function post_url(bool $echo = true): string {
    return page_url(ThemeState::$currentLoopPost, $echo);
}

function post_image(bool $echo = true): string {
    return page_image($echo);
}

function post_excerpt(int $limit = 140, bool $echo = true): string {
    $post = ThemeState::$currentLoopPost ?? [];
    if (!empty($post['meta_description'])) {
        $excerpt = $post['meta_description'];
    } else {
        $clean = strip_tags($post['content'] ?? '');
        $excerpt = mb_strlen($clean) > $limit ? mb_substr($clean, 0, $limit) . '...' : $clean;
    }
    $excerpt = Hooks::applyFilters('the_excerpt', $excerpt);
    if ($echo) echo htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8');
    return $excerpt;
}

// Tag Management Functions
function get_post_tags(?int $pageId = null): array {
    $id = $pageId ?? (ThemeState::$currentPage['id'] ?? null);
    if (!$id) return [];

    $db = Database::getConnection();
    $stmt = $db->prepare("
        SELECT t.id, t.name, t.slug 
        FROM tags t
        JOIN page_tags pt ON pt.tag_id = t.id
        WHERE pt.page_id = :pid
        ORDER BY t.name ASC
    ");
    $stmt->execute([':pid' => $id]);
    return $stmt->fetchAll();
}

function has_tags(?int $pageId = null): bool {
    $tags = get_post_tags($pageId);
    return !empty($tags);
}

function post_tags(?int $pageId = null, string $cssClass = 'post-tags', bool $echo = true): string {
    $tags = get_post_tags($pageId);
    if (empty($tags)) return '';

    $html = '<div class="' . htmlspecialchars($cssClass, ENT_QUOTES, 'UTF-8') . '">';
    foreach ($tags as $tag) {
        $url = site_url('blog/tag/' . $tag['slug'], false);
        $html .= '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="tag-badge">#' . htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8') . '</a> ';
    }
    $html .= '</div>';

    if ($echo) echo $html;
    return $html;
}

// Navigation & Language Switcher
function menu(string $slug, string $cssClass = 'nav-menu'): void {
    $db = Database::getConnection();
    $stmt = $db->prepare("SELECT id FROM menus WHERE slug = :s LIMIT 1");
    $stmt->execute([':s' => $slug]);
    $menuId = $stmt->fetchColumn();
    if (!$menuId) return;

    $stmt = $db->prepare("SELECT * FROM menu_items WHERE menu_id = :m ORDER BY parent_id ASC, sort_order ASC, id ASC");
    $stmt->execute([':m' => $menuId]);
    $items = $stmt->fetchAll();
    if (empty($items)) return;

    $items = Hooks::applyFilters('clean_menu_raw_items', $items, $slug);

    $tree = [];
    $lookup = [];
    foreach ($items as $item) {
        $item['children'] = [];
        $lookup[$item['id']] = $item;
    }
    foreach ($lookup as $id => &$item) {
        if ($item['parent_id'] && isset($lookup[$item['parent_id']])) {
            $lookup[$item['parent_id']]['children'][] = &$item;
        } else {
            $tree[] = &$item;
        }
    }
    unset($item);

    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $basePrefix = class_exists('Core\Router') ? Router::getBaseSubdirectory() : '';

    $renderTree = function(array $nodes, bool $isSub = false) use (&$renderTree, $cssClass, $uri, $basePrefix) {
        $classAttr = $isSub ? 'class="sub-menu"' : 'class="' . htmlspecialchars($cssClass, ENT_QUOTES, 'UTF-8') . '"';
        echo "<ul {$classAttr}>";
        foreach ($nodes as $node) {
            $hasChild = !empty($node['children']);
            $rawUrl = $node['url'];

            // External URLs vs internal page URLs
            if (str_starts_with($rawUrl, 'http://') || str_starts_with($rawUrl, 'https://') || str_starts_with($rawUrl, '#')) {
                $targetUrl = $rawUrl;
            } else {
                // Automatically resolve full hierarchical path if it's an internal slug
                $resolvedPath = resolve_page_hierarchy_path($rawUrl);
                $targetUrl = site_url($resolvedPath, false);
            }

            // Detect active link state
            $active = ($targetUrl === $uri || ($targetUrl !== '/' && $targetUrl !== ($basePrefix . '/') && str_starts_with((string)$uri, rtrim($targetUrl, '/') . '/')));

            $cls = [];
            if ($hasChild) $cls[] = 'has-children';
            if ($active) $cls[] = 'current-menu-item';
            $clsStr = !empty($cls) ? ' class="' . implode(' ', $cls) . '"' : '';

            echo "<li{$clsStr}>";
            echo '<a href="' . htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8') . '" target="' . htmlspecialchars($node['target'] ?? '_self', ENT_QUOTES, 'UTF-8') . '">';
            echo htmlspecialchars($node['title'], ENT_QUOTES, 'UTF-8');
            echo '</a>';
            if ($hasChild) $renderTree($node['children'], true);
            echo "</li>";
        }
        echo "</ul>";
    };

    Hooks::doAction('before_render_menu', $slug);
    $renderTree($tree);
    Hooks::doAction('after_render_menu', $slug);
}

function lang_switch(string $cssClass = 'lang-switcher'): void {
    if (Router::getOption('multilingual_frontend', '0') !== '1') return;

    $cur = I18n::getLocale();
    $def = I18n::getDefaultLocale();
    $available = I18n::getAvailableLanguages();
    $group = ThemeState::$currentPage['translation_group'] ?? null;
    $translations = [];

    // Unified subfolder prefix
    $basePrefix = class_exists('Core\Router') ? Router::getBaseSubdirectory() : '';

    if ($group) {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT slug, lang FROM pages WHERE translation_group = :g AND status = 'published'");
        $stmt->execute([':g' => $group]);
        while ($r = $stmt->fetch()) {
            $translations[$r['lang']] = $r['slug'];
        }
    }

    echo '<div class="' . htmlspecialchars($cssClass, ENT_QUOTES, 'UTF-8') . '">';
    foreach ($available as $code => $label) {
        $isCur = ($code === $cur);
        $prefix = ($code === $def) ? '' : '/' . $code;
        if (isset($translations[$code])) {
            $slugPart = ($translations[$code] === 'home') ? '' : '/' . $translations[$code];
            $url = $basePrefix . $prefix . $slugPart;
            $url = $url === '' ? '/' : $url;
        } else {
            $url = $basePrefix . ($prefix ?: '/');
        }

        $activeAttr = $isCur ? ' class="active" style="font-weight:bold;"' : '';
        echo '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"' . $activeAttr . '>';
        echo htmlspecialchars(strtoupper($code), ENT_QUOTES, 'UTF-8');
        echo '</a> ';
    }
    echo '</div>';
}

function theme_head(): void {
    $seo = ThemeState::$seoPayload;
    $title = $seo['title'] ?? Router::getOption('site_title', 'Clean CMS');
    $desc = $seo['description'] ?? Router::getOption('site_description', '');

    // Resolve OpenGraph Image (page featured image or fallback global setting)
    $img = $seo['og_image'] ?? '';
    if ($img === '') {
        $img = Router::getOption('og_default_image', '');
    }

    if ($img !== '') {
        $img = resolve_media_url($img);
        if (!str_starts_with($img, 'http://') && !str_starts_with($img, 'https://')) {
            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $img = $scheme . '://' . $host . $img;
        }
    }

    // Resolve Favicon
    $favicon = Router::getOption('site_favicon', '');
    $ogSiteName = Router::getOption('og_site_name', Router::getOption('site_title', 'Clean CMS'));

    // Output metadata
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>' . PHP_EOL;

    if ($favicon !== '') {
        $faviconUrl = resolve_media_url($favicon);
        echo '<link rel="icon" href="' . htmlspecialchars($faviconUrl, ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
    }

    if ($desc !== '') {
        echo '<meta name="description" content="' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
        echo '<meta property="og:description" content="' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
    }

    echo '<meta property="og:title" content="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
    echo '<meta property="og:type" content="website">' . PHP_EOL;

    if ($ogSiteName !== '') {
        echo '<meta property="og:site_name" content="' . htmlspecialchars($ogSiteName, ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
    }

    if ($img !== '') {
        echo '<meta property="og:image" content="' . htmlspecialchars($img, ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
    }

    Hooks::doAction('theme_head');
}

function theme_footer(): void {
    Hooks::doAction('theme_footer');
}