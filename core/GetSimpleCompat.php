<?php
declare(strict_types=1);

namespace Core {
    /**
     * Modo CMS - GetSimple CMS Compatibility Registry
     */
    class GSRegistry {
        public static array $registeredPlugins = [];
        public static array $adminSidebarItems = [];
        public static array $i18nStrings = [];
    }
}

namespace {
    // 1. Define GetSimple environment constants
    if (!defined('IN_GS')) define('IN_GS', true);
    if (!defined('GSVERSION')) define('GSVERSION', '3.3.16');
    if (!defined('GSPLUGINPATH')) define('GSPLUGINPATH', dirname(__DIR__) . '/plugins/');

    if (!defined('GSDATAOTHERPATH')) {
        $legacyOther = dirname(__DIR__) . '/data/other/';
        if (!is_dir($legacyOther)) @mkdir($legacyOther, 0775, true);
        define('GSDATAOTHERPATH', $legacyOther);
    }

    if (!defined('GSDATAPAGESPATH')) {
        $legacyPages = dirname(__DIR__) . '/data/pages/';
        if (!is_dir($legacyPages)) @mkdir($legacyPages, 0775, true);
        define('GSDATAPAGESPATH', $legacyPages);
    }

    // 2. Global URL variable frequently used by GetSimple scripts
    $baseSubdir = class_exists('\Core\Router') ? \Core\Router::getBaseSubdirectory() : '';
    $GLOBALS['SITEURL'] = ($baseSubdir !== '' ? rtrim($baseSubdir, '/') : '') . '/';

    // =========================================================================
    // Plugin Registration & Admin Hooks
    // =========================================================================

    if (!function_exists('register_plugin')) {
        function register_plugin(
            string $id, 
            string $name, 
            string $version, 
            string $author, 
            string $author_url, 
            string $description, 
            string $page_type = '', 
            string $load_function = ''
        ): void {
            \Core\GSRegistry::$registeredPlugins[$id] = [
                'id'        => $id,
                'name'      => $name,
                'version'   => $version,
                'author'    => $author,
                'url'       => $author_url,
                'desc'      => $description,
                'page_type' => $page_type,
                'load_func' => $load_function
            ];

            if (!empty($load_function) && is_callable($load_function)) {
                \Core\Hooks::addAction('admin_plugin_view_' . $id, $load_function);
            }
        }
    }

    if (!function_exists('createSideMenu')) {
        function createSideMenu(string $id, string $title, string $action = ''): void {
            foreach (\Core\GSRegistry::$adminSidebarItems as $item) {
                if ($item['id'] === $id && $item['action'] === ($action ?: $id)) {
                    return;
                }
            }

            \Core\GSRegistry::$adminSidebarItems[] = [
                'id'     => $id,
                'title'  => $title,
                'action' => $action ?: $id,
            ];
        }
    }

    if (!function_exists('create_side_menu')) {
        function create_side_menu(string $id, string $title, string $action = ''): void {
            createSideMenu($id, $title, $action);
        }
    }

    if (!function_exists('createNavTab')) {
        function createNavTab(string $name, string $plugin, string $label, string $action = ''): void {
            createSideMenu($plugin, $label, $action);
        }
    }

    if (!function_exists('render_admin_plugin_navigation')) {
        function render_admin_plugin_navigation(string $cssItemClass = 'nav-link'): void {
            $currentId = $_GET['id'] ?? '';
            $currentAction = $_GET['action'] ?? '';

            foreach (\Core\GSRegistry::$adminSidebarItems as $item) {
                $isActive = ($currentId === $item['id']) || ($currentAction !== '' && $currentAction === $item['action']);
                $url = 'plugins.php?id=' . urlencode($item['id']);
                if ($item['action'] !== '' && $item['action'] !== $item['id']) {
                    $url .= '&action=' . urlencode($item['action']);
                }

                $activeClass = $isActive ? ' active' : '';
                echo '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="' . htmlspecialchars($cssItemClass, ENT_QUOTES, 'UTF-8') . $activeClass . '">';
                echo '<span class="nav-icon">&bull;</span>';
                echo '<span class="nav-label">' . htmlspecialchars(i18n_r($item['title']), ENT_QUOTES, 'UTF-8') . '</span>';
                echo '</a>' . PHP_EOL;
            }

            \Core\Hooks::doAction('pages-sidebar');
            \Core\Hooks::doAction('plugins-sidebar');
            \Core\Hooks::doAction('settings-sidebar');
            \Core\Hooks::doAction('theme-sidebar');
            \Core\Hooks::doAction('nav-tab');
        }
    }

    // =========================================================================
    // Actions & Filters Bridge
    // =========================================================================

    if (!function_exists('add_action')) {
        function add_action(string $hook, callable $function, array $args = []): void {
            $mappedHook = match ($hook) {
                'theme-header'      => 'theme_head',
                'theme-footer'      => 'theme_footer',
                'header'            => (defined('IN_ADMIN') && IN_ADMIN) ? 'admin_head' : 'theme_head',
                'footer'            => (defined('IN_ADMIN') && IN_ADMIN) ? 'admin_footer' : 'theme_footer',
                'index-pretemplate' => 'plugins_loaded',
                'edit-extras'       => 'admin_page_edit_bottom',
                'content-top'       => 'before_the_content',
                'content-bottom'    => 'after_the_content',
                default             => $hook,
            };

            \Core\Hooks::addAction($mappedHook, function (...$params) use ($function, $args) {
                $callArgs = !empty($params) ? $params : $args;
                call_user_func_array($function, $callArgs);
            });
        }
    }

    if (!function_exists('exec_action')) {
        function exec_action(string $hook, ...$args): void {
            \Core\Hooks::doAction($hook, ...$args);
        }
    }

    if (!function_exists('add_filter')) {
        function add_filter(string $filter, callable $function, array $args = []): void {
            $mappedFilter = match ($filter) {
                'content' => 'the_content',
                'title'   => 'the_title',
                'excerpt' => 'the_excerpt',
                default   => $filter,
            };

            \Core\Hooks::addFilter($mappedFilter, function ($value, ...$params) use ($function) {
                return call_user_func($function, $value);
            });
        }
    }

    if (!function_exists('exec_filter')) {
        function exec_filter(string $filter, mixed $value, ...$args): mixed {
            return \Core\Hooks::applyFilters($filter, $value, ...$args);
        }
    }

    // =========================================================================
    // i18n & Localization Bridge
    // =========================================================================

    if (!function_exists('i18n_merge')) {
        function i18n_merge(string $plugin, string $language = 'en'): bool {
            $activeLang = class_exists('\Core\I18n') ? \Core\I18n::getLocale() : 'en';

            $candidates = [
                GSPLUGINPATH . $plugin . '/lang/',
                GSPLUGINPATH . $plugin . '/' . $plugin . '/lang/'
            ];

            foreach ($candidates as $langDir) {
                if (!is_dir($langDir)) continue;

                $targetFile = $langDir . $activeLang . '.php';
                if (!file_exists($targetFile)) $targetFile = $langDir . 'en_US.php';
                if (!file_exists($targetFile)) $targetFile = $langDir . 'en.php';

                if (file_exists($targetFile)) {
                    $i18n = [];
                    include $targetFile;
                    foreach ($i18n as $key => $val) {
                        \Core\GSRegistry::$i18nStrings[$plugin . '/' . $key] = $val;
                    }
                    return true;
                }
            }
            return false;
        }
    }

    if (!function_exists('i18n_r')) {
        function i18n_r(string $key): string {
            if (isset(\Core\GSRegistry::$i18nStrings[$key])) {
                return (string)\Core\GSRegistry::$i18nStrings[$key];
            }

            $parts = explode('/', $key);
            $cleanKey = end($parts);
            return function_exists('__') ? __($cleanKey) : $key;
        }
    }

    if (!function_exists('i18n')) {
        function i18n(string $key): void {
            echo htmlspecialchars(i18n_r($key), ENT_QUOTES, 'UTF-8');
        }
    }

    // =========================================================================
    // Path, URL & Theme Helpers
    // =========================================================================

    if (!function_exists('get_site_url')) {
        function get_site_url(bool $echo = false): string {
            $url = $GLOBALS['SITEURL'] ?? (function_exists('site_url') ? site_url('', false) : '/');
            $full = rtrim($url, '/') . '/';
            if ($echo) echo $full;
            return $full;
        }
    }

    if (!function_exists('find_url')) {
        function find_url(string $slug, string $parent = ''): string {
            $path = ($parent !== '' ? $parent . '/' : '') . $slug;
            return function_exists('site_url') ? site_url($path, false) : '/' . $path;
        }
    }

    if (!function_exists('get_header')) {
        function get_header(): void {
            if (function_exists('theme_head')) theme_head();
        }
    }

    if (!function_exists('get_footer')) {
        function get_footer(): void {
            if (function_exists('theme_footer')) theme_footer();
        }
    }

    if (!function_exists('get_page_slug')) {
        function get_page_slug(bool $echo = false): string {
            $slug = \ThemeState::$currentPage['slug'] ?? '';
            if ($echo) echo htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
            return $slug;
        }
    }

    if (!function_exists('get_page_content')) {
        function get_page_content(): string {
            return function_exists('page_content') ? page_content(false) : '';
        }
    }

    // =========================================================================
    // Data & XML Helpers
    // =========================================================================

    if (!function_exists('getXML')) {
        function getXML(string $file): ?\SimpleXMLElement {
            if (!file_exists($file)) return null;
            $content = file_get_contents($file);
            return $content !== false ? @simplexml_load_string($content) : null;
        }
    }

    if (!function_exists('XMLsave')) {
        function XMLsave(\SimpleXMLElement $xml, string $file): bool {
            $dir = dirname($file);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            return (bool)$xml->asXML($file);
        }
    }
}