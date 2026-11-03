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

    /**
     * Returns the active grid CSS (bundled stylesheet + custom additions)
     * scoped under a selector, so the grid classes only affect the given
     * element instead of leaking into the surrounding admin UI.
     *
     * Editor.js renders inline (no iframe like TinyMCE), hence the need for
     * this scoped variant.
     *
     * @param string $scope CSS selector, e.g. "#editorjs".
     */
    public static function editorScopedCss(string $scope = '#editorjs'): string
    {
        if (!self::enabledFor('editor')) {
            return '';
        }

        $scope = trim($scope);
        if ($scope === '') {
            return '';
        }

        $css = '';
        $root = dirname(__DIR__);
        $framework = self::get(self::active());

        if ($framework !== null && (string) $framework['css'] !== '') {
            $file = $root . '/' . ltrim((string) $framework['css'], '/');
            if (is_file($file)) {
                $contents = file_get_contents($file);
                if ($contents !== false) {
                    $css .= $contents . "\n";
                }
            }
        }

        $css .= self::customCss();
        if (trim($css) === '') {
            return '';
        }

        return self::scopeCss($css, $scope);
    }

    /**
     * Scopes every style rule under a selector.
     *
     * Root-level selectors (:root, html, body) map directly onto the scope,
     * while their descendants become "$scope X". Conditional at-rules such as
     * @media are processed recursively; @keyframes and @font-face are left
     * untouched so their inner selectors stay valid.
     *
     * @param string $css   Stylesheet contents.
     * @param string $scope CSS selector, e.g. "#editorjs".
     */
    public static function scopeCss(string $css, string $scope): string
    {
        $scope = trim($scope);
        if ($scope === '' || trim($css) === '') {
            return '';
        }

        $css = self::stripCssComments($css);

        return trim(self::scopeCssRules($css, $scope));
    }

    /**
     * Recursively walks top-level rules and scopes their selectors.
     */
    private static function scopeCssRules(string $css, string $scope): string
    {
        $output = '';
        $length = strlen($css);
        $offset = 0;

        while ($offset < $length) {
            $delimiter = self::nextTopLevelDelimiter($css, $offset);
            if ($delimiter === null) {
                $output .= substr($css, $offset);
                break;
            }

            [$position, $character] = $delimiter;
            $prelude = trim(substr($css, $offset, $position - $offset));

            if ($character === ';') {
                $output .= $prelude !== '' ? $prelude . ';' : ';';
                $offset = $position + 1;
                continue;
            }

            $close = self::findMatchingBrace($css, $position);
            if ($close === null) {
                $output .= substr($css, $offset);
                break;
            }

            $block = substr($css, $position + 1, $close - $position - 1);

            if ($prelude === '') {
                $output .= '{' . $block . '}';
            } elseif (str_starts_with($prelude, '@')) {
                $output .= self::isScopableAtRule($prelude)
                    ? $prelude . '{' . self::scopeCssRules($block, $scope) . '}'
                    : $prelude . '{' . $block . '}';
            } else {
                $output .= self::scopeCssSelectorList($prelude, $scope) . '{' . $block . '}';
            }

            $offset = $close + 1;
        }

        return $output;
    }

    /**
     * Finds the next top-level "{" or ";" outside strings, parentheses and
     * attribute selectors.
     *
     * @return array{0:int,1:string}|null
     */
    private static function nextTopLevelDelimiter(string $css, int $offset): ?array
    {
        $length = strlen($css);
        $quote = '';
        $escaped = false;
        $parentheses = 0;
        $brackets = 0;

        for ($i = $offset; $i < $length; $i++) {
            $character = $css[$i];

            if ($quote !== '') {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === $quote) {
                    $quote = '';
                }
                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
                continue;
            }
            if ($character === '(') {
                $parentheses++;
                continue;
            }
            if ($character === ')') {
                $parentheses = max(0, $parentheses - 1);
                continue;
            }
            if ($character === '[') {
                $brackets++;
                continue;
            }
            if ($character === ']') {
                $brackets = max(0, $brackets - 1);
                continue;
            }
            if ($parentheses === 0 && $brackets === 0 && ($character === '{' || $character === ';')) {
                return [$i, $character];
            }
        }

        return null;
    }

    /**
     * Returns the index of the "}" matching the "{" at $open.
     */
    private static function findMatchingBrace(string $css, int $open): ?int
    {
        $length = strlen($css);
        $depth = 0;
        $quote = '';
        $escaped = false;

        for ($i = $open; $i < $length; $i++) {
            $character = $css[$i];

            if ($quote !== '') {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === $quote) {
                    $quote = '';
                }
                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
                continue;
            }
            if ($character === '{') {
                $depth++;
                continue;
            }
            if ($character === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Whether an at-rule contains regular style rules that must be scoped.
     */
    private static function isScopableAtRule(string $prelude): bool
    {
        return preg_match(
            '/^@(?:media|supports|container|layer|document|-moz-document|scope|starting-style)\b/i',
            $prelude
        ) === 1;
    }

    /**
     * Scopes a comma separated selector list.
     */
    private static function scopeCssSelectorList(string $selectors, string $scope): string
    {
        $mapped = [];
        foreach (self::splitTopLevelSelectors($selectors) as $selector) {
            $selector = trim($selector);
            if ($selector === '') {
                continue;
            }
            $mapped[] = self::scopeCssSelector($selector, $scope);
        }

        return implode(', ', $mapped);
    }

    /**
     * Splits a selector list without breaking commas inside :is(), :where()
     * or attribute values.
     *
     * @return array<int,string>
     */
    private static function splitTopLevelSelectors(string $selectors): array
    {
        $result = [];
        $buffer = '';
        $length = strlen($selectors);
        $quote = '';
        $escaped = false;
        $parentheses = 0;
        $brackets = 0;

        for ($i = 0; $i < $length; $i++) {
            $character = $selectors[$i];

            if ($quote !== '') {
                $buffer .= $character;
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === $quote) {
                    $quote = '';
                }
                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
                $buffer .= $character;
                continue;
            }
            if ($character === '(') {
                $parentheses++;
            } elseif ($character === ')') {
                $parentheses = max(0, $parentheses - 1);
            } elseif ($character === '[') {
                $brackets++;
            } elseif ($character === ']') {
                $brackets = max(0, $brackets - 1);
            }

            if ($character === ',' && $parentheses === 0 && $brackets === 0) {
                $result[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $character;
        }

        $result[] = $buffer;

        return $result;
    }

    /**
     * Maps one selector onto the scope.
     */
    private static function scopeCssSelector(string $selector, string $scope): string
    {
        if (in_array(strtolower($selector), [':root', 'html', 'body'], true)) {
            return $scope;
        }

        if (preg_match('/^(?::root|html|body)([\s>+~].*)$/is', $selector, $match) === 1) {
            return $scope . $match[1];
        }

        if (preg_match('/^(?::root|html|body)([.#\[\]:].*)$/is', $selector, $match) === 1) {
            return $scope . $match[1];
        }

        return $scope . ' ' . $selector;
    }

    /**
     * Removes CSS comments before parsing.
     */
    private static function stripCssComments(string $css): string
    {
        return preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
    }

    // ---------------------------------------------------------------------
    // Grid markup
    // ---------------------------------------------------------------------

    /**
     * Builds framework correct grid markup. Mirrors the client side builder in
     * admin/assets/js/grid-editor.js so grids authored in TinyMCE and in
     * Editor.js render identically.
     *
     * @param array<string,mixed> $spec {
     *     framework  : framework id (defaults to the active one)
     *     columns    : number of columns
     *     breakpoint : responsive breakpoint key ('' = all sizes)
     *     container  : wrap in the framework container
     *     contents   : one HTML / plain text fragment per column
     * }
     */
    public static function gridMarkup(array $spec): string
    {
        $id = (string) ($spec['framework'] ?? '');
        $fw = $id !== '' ? self::get($id) : self::get(self::active());
        if ($fw === null) {
            return '';
        }

        $mode = (string) $fw['mode'];
        if ($mode === 'none' || $mode === 'custom') {
            return '';
        }

        $columns = max(1, (int) ($spec['columns'] ?? 2));
        $bp = (string) ($spec['breakpoint'] ?? '');
        $contents = array_values((array) ($spec['contents'] ?? []));
        $width = max(1, (int) round(12 / $columns));

        switch ($mode) {
            case 'bootstrap':
                $colOpen = '<div class="' . ($bp !== '' ? 'col-' . $bp . '-' . $width : 'col-' . $width) . '">';
                $rowOpen = '<div class="row">';
                break;
            case 'bulma':
                $colOpen = '<div class="' . ($columns > 1
                    ? 'column is-' . $width . ($bp !== '' ? '-' . $bp : '')
                    : 'column') . '">';
                $rowOpen = '<div class="columns' . ($bp !== '' ? ' is-multiline' : '') . '">';
                break;
            case 'foundation':
                $colOpen = '<div class="cell '
                    . (($bp !== '' && $bp !== 'small') ? $bp . '-' . $width : 'small-' . $width) . '">';
                $rowOpen = '<div class="grid-x grid-padding-x">';
                break;
            case 'modo':
                $colOpen = '<div class="' . ($bp !== '' ? 'modo-col-' . $bp . '-' . $width : 'modo-col-' . $width) . '">';
                $rowOpen = '<div class="modo-grid">';
                break;
            case 'pico':
                $colOpen = '<div>';
                $rowOpen = '<div class="grid" data-cols="' . $columns . '">';
                break;
            case 'uikit':
                $colOpen = '<div>';
                $rowOpen = '<div class="uk-grid uk-child-width-1-' . $columns . ($bp !== '' ? '@' . $bp : '') . '">';
                break;
            case 'tailwind':
                $colOpen = '<div>';
                $rowOpen = $bp !== ''
                    ? '<div class="grid grid-cols-1 gap-4 ' . $bp . ':grid-cols-' . $columns . '">'
                    : '<div class="grid grid-cols-' . $columns . ' gap-4">';
                break;
            default:
                $colOpen = '<div class="grid-col">';
                $rowOpen = '<div class="grid-row">';
        }

        $cells = [];
        for ($i = 0; $i < $columns; $i++) {
            $inner = trim((string) ($contents[$i] ?? ''));
            $cells[] = $colOpen . ($inner === '' ? '&nbsp;' : $inner) . '</div>';
        }

        $containerOpen = '';
        $containerClose = '';
        if ((string) $fw['container'] !== '' && !empty($spec['container'])) {
            $containerOpen = '<div class="' . $fw['container'] . '">' . "\n";
            $containerClose = "\n</div>";
        }

        return $containerOpen . $rowOpen . "\n" . implode("\n", $cells) . "\n</div>" . $containerClose;
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
                'error'      => __('Could not open the grid dialog. Check the browser console for details.'),
            ],
        ];
    }
}
