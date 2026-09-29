<?php
declare(strict_types=1);

use Core\Database;
use Core\Hooks;
use Core\Router;
use Core\Security;
use Core\I18n;

/**
 * Executes head hooks (meta tags, styles, plugin assets).
 */
function get_header(): void {
    Hooks::doAction('theme-header');
}

/**
 * Executes footer hooks (scripts, analytics, plugin modules).
 */
function get_footer(): void {
    Hooks::doAction('theme-footer');
}

/**
 * Outputs site title.
 */
function get_site_title(): string {
    return Security::sanitize(Router::getOption('site_title', 'Modo CMS'));
}

/**
 * Outputs site description/tagline.
 */
function get_site_description(): string {
    return Security::sanitize(Router::getOption('site_description', ''));
}

/**
 * Outputs absolute site URL.
 */
function get_site_url(): string {
    return site_url('', false);
}

/**
 * Outputs active theme directory URL.
 */
function get_theme_url(): string {
    $theme = Router::getOption('active_theme', 'modern-bootstrap');
    $base = class_exists('Core\Router') ? Router::getBaseSubdirectory() : '';
    return rtrim($base, '/') . '/themes/' . $theme;
}

/**
 * Generates an accessible Bootstrap navigation bar.
 */
function get_theme_menu(string $ulClass = 'navbar-nav ms-auto mb-2 mb-lg-0'): void {
    $db = Database::getConnection();
    $currentLang = I18n::getLocale();

    $stmt = $db->prepare("
        SELECT title, slug 
        FROM pages 
        WHERE type = 'page' AND status = 'published' AND parent_id = 0 AND lang = :lg 
        ORDER BY id ASC
    ");
    $stmt->execute([':lg' => $currentLang]);
    $pages = $stmt->fetchAll();

    echo '<ul class="' . htmlspecialchars($ulClass, ENT_QUOTES, 'UTF-8') . '">';
    
    // Home link
    $homeActive = empty($_GET['slug']) ? ' active' : '';
    echo '<li class="nav-item"><a class="nav-link' . $homeActive . '" href="' . get_site_url() . '">' . _e('Home') . '</a></li>';

    foreach ($pages as $p) {
        $isActive = (isset($_GET['slug']) && $_GET['slug'] === $p['slug']) ? ' active' : '';
        $url = page_url($p, false);
        echo '<li class="nav-item">';
        echo '<a class="nav-link' . $isActive . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . Security::sanitize($p['title']) . '</a>';
        echo '</li>';
    }

    echo '</ul>';
}

/**
 * Fetches recent blog posts for listing/sidebar.
 */
function get_recent_posts(int $limit = 6): array {
    $db = Database::getConnection();
    $currentLang = I18n::getLocale();

    $stmt = $db->prepare("
        SELECT * FROM pages 
        WHERE type = 'post' AND status = 'published' AND lang = :lg 
        ORDER BY created_at DESC 
        LIMIT :limit
    ");
    $stmt->bindValue(':lg', $currentLang, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}


/**
 * Outputs header scripts (Google Analytics, GTM, verification meta tags) and triggers the 'theme-header' action hook.
 */
function theme_header(): void {
    // 1. Injected custom scripts configured in Settings
    $headScripts = \Core\Router::getOption('custom_head_scripts', '');
    if (!empty($headScripts)) {
        echo $headScripts . "\n";
    }

    // 2. Action hook for plugins and modules
    if (class_exists('Hooks')) {
        \Hooks::doAction('theme-header');
    }
}

/**
 * Outputs footer scripts (live chat widgets, remarketing, conversion tracking) and triggers the 'theme-footer' action hook.
 */
function theme_footer(): void {
    // 1. Injected custom scripts configured in Settings
    $footerScripts = \Core\Router::getOption('custom_footer_scripts', '');
    if (!empty($footerScripts)) {
        echo $footerScripts . "\n";
    }

    // 2. Action hook for plugins and modules
    if (class_exists('Hooks')) {
        \Hooks::doAction('theme-footer');
    }
}