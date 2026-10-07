<?php
declare(strict_types=1);

namespace Core;

/**
 * Class CssFramework
 *
 * Central registry and resolver for the bundled CSS grid / framework
 * stylesheets. Everything ships locally (no CDN): for every supported
 * framework we bundle a lightweight, grid-only stylesheet that reproduces the
 * framework's grid class names.
 *
 * A single setting ("css_grid") selects the active framework. The very same
 * stylesheet is then linked both on the frontend (<head>) and inside the
 * TinyMCE content editor ("content_css"), so a grid authored in the editor
 * renders identically on the published site.
 *
 * The grid token map exposed through gridConfig() drives the "Insert Grid"
 * TinyMCE button (admin/assets/js/grid-editor.js) which emits framework
 * correct markup.
 */
final class CssFramework
{
    /** Settings key holding the active framework id. */
    public const OPTION = 'css_grid';

    /** The "no framework / do not inject" framework id. */
    public const NONE = 'none';

    private static bool $booted = false;

    /**
     * Registry of supported frameworks.
     *
     * Each entry:
     *  - label      : Human readable name.
     *  - version    : Informational version of the reproduced grid.
     *  - css        : Relative (project root) path to the bundled stylesheet.
     *  - container  : Container class (or '' when the framework has none).
     *  - row        : Class applied to the row/grid element.
     *  - col        : Base class applied to every column element.
     *  - count      : Column-count class prefix for an explicit width
     *                 ('' when the framework auto-distributes).
     *  - breakpoints: Responsive breakpoint keys.
     *  - cols       : Number of columns of the grid system (0 = auto).
     *  - mode       : Markup strategy identifier used by the editor builder.
     *
     * @var array<string,array<string,mixed>>
     */
    private static array $frameworks = [
        'none' => [
            'label' => 'None', 'version' => '', 'css' => '',
            'container' => '', 'row' => '', 'col' => '', 'count' => '',
            'breakpoints' => [], 'cols' => 0, 'mode' => 'none',
        ],
        'bootstrap' => [
            'label' => 'Bootstrap 5', 'version' => '5.3',
            'css' => 'assets/grids/bootstrap/grid.css',
            'container' => 'container', 'row' => 'row', 'col' => 'col',
            'count' => 'col', 'breakpoints' => ['sm', 'md', 'lg', 'xl', 'xxl'],
            'cols' => 12, 'mode' => 'bootstrap',
        ],
        'bulma' => [
            'label' => 'Bulma', 'version' => '1.0',
            'css' => 'assets/grids/bulma/grid.css',
            'container' => 'container', 'row' => 'columns', 'col' => 'column',
            'count' => 'is', 'breakpoints' => ['tablet', 'desktop', 'widescreen'],
            'cols' => 12, 'mode' => 'bulma',
        ],
        'pico' => [
            'label' => 'Pico CSS', 'version' => '2.0',
            'css' => 'assets/grids/pico/grid.css',
            'container' => 'container', 'row' => 'grid', 'col' => '',
            'count' => '', 'breakpoints' => [], 'cols' => 0, 'mode' => 'pico',
        ],
        'uikit' => [
            'label' => 'UIkit', 'version' => '3.0',
            'css' => 'assets/grids/uikit/grid.css',
            'container' => 'uk-container', 'row' => 'uk-grid', 'col' => '',
            'count' => 'uk-width', 'breakpoints' => ['s', 'm', 'l', 'xl'],
            'cols' => 0, 'mode' => 'uikit',
        ],
        'foundation' => [
            'label' => 'Foundation', 'version' => '6.8 (XY Grid)',
            'css' => 'assets/grids/foundation/grid.css',
            'container' => 'grid-container', 'row' => 'grid-x', 'col' => 'cell',
            'count' => '', 'breakpoints' => ['small', 'medium', 'large'],
            'cols' => 12, 'mode' => 'foundation',
        ],
        'tailwind' => [
            'label' => 'Tailwind CSS', 'version' => '3.0',
            'css' => 'assets/grids/tailwind/grid.css',
            'container' => 'container', 'row' => 'grid', 'col' => '',
            'count' => 'grid-cols', 'breakpoints' => ['sm', 'md', 'lg', 'xl'],
            'cols' => 12, 'mode' => 'tailwind',
        ],
        'modo' => [
            'label' => 'Modo Grid', 'version' => '1.0',
            'css' => 'assets/grids/modo/grid.css',
            'container' => 'modo-container', 'row' => 'modo-grid', 'col' => '',
            'count' => '', 'breakpoints' => ['sm', 'md', 'lg'],
            'cols' => 12, 'mode' => 'modo',
        ],
        'custom' => [
            'label' => 'Custom', 'version' => '', 'css' => '',
            'container' => '', 'row' => '', 'col' => '', 'count' => '',
            'breakpoints' => [], 'cols' => 0, 'mode' => 'custom',
        ],
    ];

    /**
     * Boots the module: registers the frontend <head> injection.
     */
    public static function init(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        if (defined('IN_ADMIN') && IN_ADMIN === true) {
            return; // Admin output is handled explicitly by the editor pages.
        }

        Hooks::addAction('theme_head', [self::class, 'headAssets'], 5);
    }

    // ---------------------------------------------------------------------
    // Registry
    // ---------------------------------------------------------------------

    /**
     * Returns the full framework registry.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        return self::$frameworks;
    }

    /**
     * Returns a single framework definition (or null when unknown).
     *
     * @return array<string,mixed>|null
     */
    public static function get(string $id): ?array
    {
        return self::$frameworks[$id] ?? null;
    }

    /**
     * Resolves the currently selected framework id (validated against the
     * registry, defaulting to "none").
     */
    public static function active(): string
    {
        $id = class_exists(Router::class) ? Router::getOption(self::OPTION, self::NONE) : self::NONE;
        return isset(self::$frameworks[$id]) ? $id : self::NONE;
    }

    /**
     * Whether a real framework is selected.
     */
    public static function isEnabled(): bool
    {
        return self::active() !== self::NONE;
    }

    /**
     * Whether the framework CSS should be emitted for the given scope.
     *
     * @param string $scope "frontend" or "editor".
     */
    public static function enabledFor(string $scope): bool
    {
        if (!self::isEnabled()) {
            return false;
        }
        $key = $scope === 'frontend' ? 'css_grid_inject_frontend' : 'css_grid_inject_editor';
        return Router::getOption($key, '1') === '1';
    }

    /**
     * Returns the installed base subdirectory (respecting subfolder installs).
     */
    private static function baseSubdir(): string
    {
        return class_exists(Router::class) ? rtrim(Router::getBaseSubdirectory(), '/') : '';
    }

    /**
     * Resolves a project-relative CSS path to a root-relative public URL.
     */
    private static function url(string $relative): string
    {
        return self::baseSubdir() . '/' . ltrim($relative, '/');
    }

    // ---------------------------------------------------------------------
    // Assets
    // ---------------------------------------------------------------------

    /**
     * Returns the public URLs of the active framework's bundled stylesheets.
     *
     * @return array<int,string>
     */
    public static function cssUrls(): array
    {
        $id = self::active();
        if ($id === self::NONE || $id === 'custom') {
            return [];
        }
        $css = (string) (self::$frameworks[$id]['css'] ?? '');
        return $css !== '' ? [self::url($css)] : [];
    }

    /**
     * Returns the custom grid CSS supplied by the administrator (Settings).
     */
    public static function customCss(): string
    {
        return (string) Router::getOption('css_grid_custom_css', '');
    }

    /**
     * Emits the frontend <head> assets (linked on the theme_head hook).
     */
    public static function headAssets(): void
    {
        if (!self::enabledFor('frontend')) {
            return;
        }

        foreach (self::cssUrls() as $url) {
            echo '<link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
        }

        $custom = self::customCss();
        if ($custom !== '') {
            echo '<style id="modo-grid-custom-css">' . $custom . '</style>' . PHP_EOL;
        }
    }

    /**
     * Returns the stylesheet URLs that must be loaded inside the TinyMCE
     * editor canvas so its grid markup matches the frontend.
     *
     * @return array<int,string>
     */
    public static function editorContentCss(): array
    {
        if (!self::enabledFor('editor')) {
            return [];
        }
        return self::cssUrls();
    }

    /**
     * Returns the custom CSS that should be injected into the editor canvas.
     */
    public static function editorContentStyle(): string
    {
        return self::enabledFor('editor') ? self::customCss() : '';
    }

    // ---------------------------------------------------------------------
    // Editor integration
    // ---------------------------------------------------------------------

    /**
     * Builds the JSON payload consumed by admin/assets/js/grid-editor.js.
     *
     * @return array<string,mixed>
     */
    public static function gridConfig(): array
    {
        $id = self::active();
        $fw = self::$frameworks[$id];

        return [
            'id'          => $id,
            'label'       => (string) $fw['label'],
            'mode'        => (string) $fw['mode'],
            'container'   => (string) $fw['container'],
            'row'         => (string) $fw['row'],
            'col'         => (string) $fw['col'],
            'count'       => (string) $fw['count'],
            'breakpoints' => array_values((array) $fw['breakpoints']),
            'cols'        => (int) $fw['cols'],
            'labels'      => [
                'title'      => __('Insert Grid / Columns'),
                'columns'    => __('Columns'),
                'breakpoint' => __('Responsive breakpoint'),
                'container'  => __('Wrap in container'),
                'content'    => __('Column content (one line per column)'),
                'item'       => __('Column content'),
                'insert'     => __('Insert'),
                'cancel'     => __('Cancel'),
                'none'       => __('No grid framework is selected in Settings.'),
            ],
        ];
    }
}
