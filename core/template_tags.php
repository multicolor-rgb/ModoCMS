<?php
declare(strict_types=1);

use Core\Database;
use Core\Hooks;
use Core\Router;
use Core\Security;
use Core\I18n;

/**
 * Modo CMS — Theme Template Tags.
 *
 * These helpers extend the theme API. Every function is wrapped in a
 * function_exists() guard so this file can be loaded safely next to
 * GetSimpleCompat.php and frontend.php (which already define get_header(),
 * get_footer(), get_site_url() and theme_footer()).
 */

/**
 * Executes head hooks (plugins, injected assets).
 */
if (!function_exists('get_header')) {
    function get_header(): void {
        Hooks::doAction('theme-header');
    }
}

/**
 * Executes footer hooks (scripts, analytics, plugin modules).
 */
if (!function_exists('get_footer')) {
    function get_footer(): void {
        Hooks::doAction('theme-footer');
    }
}

/**
 * Outputs the sanitized site title.
 */
if (!function_exists('get_site_title')) {
    function get_site_title(): string {
        return Security::sanitize(Router::getOption('site_title', 'Modo CMS'));
    }
}

/**
 * Outputs the sanitized site description/tagline.
 */
if (!function_exists('get_site_description')) {
    function get_site_description(): string {
        return Security::sanitize(Router::getOption('site_description', ''));
    }
}

/**
 * Outputs the absolute site URL (with trailing slash).
 */
if (!function_exists('get_site_url')) {
    function get_site_url(): string {
        return site_url('', false);
    }
}

/**
 * Resolves the active theme directory URL (base subfolder aware).
 */
if (!function_exists('get_theme_url')) {
    function get_theme_url(): string {
        $theme = Router::getOption('active_theme', 'default');
        $base = class_exists('Core\\Router') ? Router::getBaseSubdirectory() : '';
        return rtrim($base, '/') . '/themes/' . $theme;
    }
}

/**
 * Renders an accessible Bootstrap navigation bar built from top-level pages.
 * Outputs <li class="nav-item"><a class="nav-link"> items, ready for .navbar-nav.
 */
if (!function_exists('get_theme_menu')) {
    function get_theme_menu(string $ulClass = 'navbar-nav ms-auto mb-2 mb-lg-0'): void {
        $db = Database::getConnection();
        $currentLang = I18n::getLocale();

        $stmt = $db->prepare("
            SELECT title, slug
            FROM pages
            WHERE type = 'page' AND status = 'published' AND parent_id = 0
              AND (lang = :lg OR lang = '')
            ORDER BY id ASC
        ");
        $stmt->execute([':lg' => $currentLang]);
        $pages = $stmt->fetchAll();

        $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $basePrefix = class_exists('Core\\Router') ? Router::getBaseSubdirectory() : '';
        $homePath = $basePrefix === '' ? '/' : $basePrefix . '/';

        echo '<ul class="' . htmlspecialchars($ulClass, ENT_QUOTES, 'UTF-8') . '">';

        // Home link
        $homeActive = (rtrim($currentPath, '/') === rtrim($homePath, '/')) ? ' active' : '';
        echo '<li class="nav-item"><a class="nav-link' . $homeActive . '" href="' . htmlspecialchars(site_url('', false), ENT_QUOTES, 'UTF-8') . '">' . _e('Home') . '</a></li>';

        foreach ($pages as $p) {
            $url = page_url($p, false);
            $isActive = (rtrim($currentPath, '/') === rtrim($url, '/')) ? ' active' : '';
            echo '<li class="nav-item">';
            echo '<a class="nav-link' . $isActive . '" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . Security::sanitize($p['title']) . '</a>';
            echo '</li>';
        }

        echo '</ul>';
    }
}

/**
 * Fetches recent published blog posts for listings/sidebars.
 */
if (!function_exists('get_recent_posts')) {
    function get_recent_posts(int $limit = 6): array {
        $db = Database::getConnection();
        $currentLang = I18n::getLocale();

        $stmt = $db->prepare("
            SELECT p.*, u.username as author_name
            FROM pages p
            LEFT JOIN users u ON p.author_id = u.id
            WHERE p.type = 'post' AND p.status = 'published'
              AND (p.lang = :lg OR p.lang = '')
            ORDER BY p.created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':lg', $currentLang, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}

/**
 * Outputs header scripts (settings + plugin hooks).
 */
if (!function_exists('theme_header')) {
    function theme_header(): void {
        $headScripts = Router::getOption('custom_head_scripts', '');
        if ($headScripts !== '') {
            echo $headScripts . "\n";
        }

        if (class_exists('Hooks')) {
            Hooks::doAction('theme-header');
        }
    }
}

/**
 * Outputs footer scripts (settings + plugin hooks).
 */
if (!function_exists('theme_footer')) {
    function theme_footer(): void {
        $footerScripts = Router::getOption('custom_footer_scripts', '');
        if ($footerScripts !== '') {
            echo $footerScripts . "\n";
        }

        if (class_exists('Hooks')) {
            Hooks::doAction('theme-footer');
        }
    }
}

/**
 * Resolves a live-customizer theme modification (theme_mods) value.
 *
 * Values published from the Customizer are read here; while a live preview
 * session is active the unsaved draft overlay is returned instead.
 */
if (!function_exists('get_theme_mod')) {
    function get_theme_mod(string $key, mixed $default = ''): mixed {
        if (!class_exists('Core\\Customizer')) {
            return $default;
        }
        return \Core\Customizer::getMod($key, $default);
    }
}

/**
 * Echoes a sanitized live-customizer value (escaped for HTML output).
 */
if (!function_exists('the_theme_mod')) {
    function the_theme_mod(string $key, mixed $default = ''): void {
        $value = get_theme_mod($key, $default);
        echo htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Outputs the CSS custom properties generated from the customizer controls that
 * declare a "css_var" mapping. Instant, hook-free live updates for colours,
 * sizes, etc. Safe to call inside <head>.
 */
if (!function_exists('customizer_css')) {
    function customizer_css(): void {
        if (!class_exists('Core\\Customizer')) {
            return;
        }
        echo \Core\Customizer::renderCssVars();
    }
}