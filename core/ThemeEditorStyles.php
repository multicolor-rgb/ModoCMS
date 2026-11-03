<?php
declare(strict_types=1);

namespace Core;

/**
 * Class ThemeEditorStyles
 *
 * Optional typography for the content editor canvas, authored by a theme.
 *
 * A theme opts in simply by shipping:
 *
 *     themes/<theme>/assets/css/editor.css
 *
 * When the file does not exist every method returns an empty value, so both
 * TinyMCE and Editor.js keep their current behaviour. There is no fallback to
 * the theme's frontend stylesheet and no setting in the admin panel.
 *
 * Optional metadata is read from theme.json:
 *
 *     "editor": {
 *         "body_class": "prose",
 *         "content_style": ":root { ... }"
 *     }
 *
 * TinyMCE receives the stylesheet via "content_css" and the custom properties
 * via "content_style"; Editor.js renders inline, so the same CSS is scoped to
 * the canvas and injected as a <style> block.
 */
final class ThemeEditorStyles
{
    /** Theme-relative path of the optional editor stylesheet. */
    private const RELATIVE_PATH = 'assets/css/editor.css';

    private static ?string $activeThemeCache = null;

    /** @var array<string,string|null> Resolved editor.css path per theme. */
    private static array $pathCache = [];

    /** @var array<string,array<string,mixed>> Decoded theme.json per theme. */
    private static array $manifestCache = [];

    // ---------------------------------------------------------------------
    // Detection
    // ---------------------------------------------------------------------

    /**
     * Returns the sanitized id of the active theme.
     */
    public static function activeTheme(): string
    {
        if (self::$activeThemeCache !== null) {
            return self::$activeThemeCache;
        }

        $theme = class_exists(Router::class)
            ? Router::getOption('active_theme', 'default')
            : 'default';
        $theme = basename(str_replace('\\', '/', trim($theme)));
        if ($theme === '' || $theme === '.' || $theme === '..') {
            $theme = 'default';
        }

        return self::$activeThemeCache = $theme;
    }

    /**
     * Returns the absolute path of the active theme's editor.css, or null
     * when the theme does not provide one.
     */
    public static function path(): ?string
    {
        $theme = self::activeTheme();
        if (array_key_exists($theme, self::$pathCache)) {
            return self::$pathCache[$theme];
        }

        $path = dirname(__DIR__) . '/themes/' . $theme . '/' . self::RELATIVE_PATH;
        return self::$pathCache[$theme] = is_file($path) ? $path : null;
    }

    /**
     * Whether the active theme ships an editor stylesheet.
     */
    public static function enabled(): bool
    {
        return self::path() !== null;
    }

    /**
     * Returns the public URL of the active theme's editor.css (with a
     * filemtime cache buster), or null when the file does not exist.
     */
    public static function url(): ?string
    {
        $path = self::path();
        if ($path === null) {
            return null;
        }

        $base = class_exists(Router::class)
            ? rtrim(Router::getBaseSubdirectory(), '/')
            : '';
        $relative = 'themes/' . self::activeTheme() . '/' . self::RELATIVE_PATH;
        $mtime = @filemtime($path);

        return $base . '/' . ltrim($relative, '/')
            . ($mtime !== false ? '?v=' . $mtime : '');
    }

    // ---------------------------------------------------------------------
    // TinyMCE payload
    // ---------------------------------------------------------------------

    /**
     * Stylesheet URLs for TinyMCE's "content_css" option.
     *
     * @return array<int,string>
     */
    public static function contentCss(): array
    {
        $url = self::url();
        return $url !== null ? [$url] : [];
    }

    /**
     * Optional class applied to the TinyMCE canvas body.
     *
     * The theme opts in through "editor.body_class" in theme.json. The value
     * is intentionally empty by default (no forced "prose").
     */
    public static function bodyClass(): string
    {
        if (!self::enabled()) {
            return '';
        }

        $editor = self::manifest()['editor'] ?? null;
        if (!is_array($editor)) {
            return '';
        }
        $raw = $editor['body_class'] ?? '';
        if (!is_string($raw)) {
            return '';
        }

        $classes = [];
        foreach (preg_split('/\s+/', trim($raw)) ?: [] as $class) {
            if ($class !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $class) === 1) {
                $classes[] = $class;
            }
        }

        return implode(' ', array_values(array_unique($classes)));
    }

    /**
     * Inline CSS for TinyMCE's "content_style" option.
     *
     * Customizer variables come first so values published in the customizer
     * override the defaults declared inside editor.css.
     */
    public static function contentStyle(): string
    {
        if (!self::enabled()) {
            return '';
        }

        $parts = [];
        $variables = self::customizerVariables();
        if ($variables !== []) {
            $parts[] = ":root {\n    " . implode("\n    ", $variables) . "\n}";
        }

        $contentStyle = self::editorOption('content_style');
        if ($contentStyle !== '') {
            $parts[] = $contentStyle;
        }

        return implode("\n", $parts);
    }

    // ---------------------------------------------------------------------
    // Editor.js payload
    // ---------------------------------------------------------------------

    /**
     * Returns editor.css scoped under $scope (e.g. "#editorjs").
     *
     * Relative URLs (including @import) are resolved against editor.css so
     * they keep working after the CSS is inlined into the admin page. The
     * customizer variables are appended last, so their values override the
     * defaults from the stylesheet.
     */
    public static function scopedCss(string $scope): string
    {
        $path = self::path();
        $scope = trim($scope);
        if ($path === null || $scope === '') {
            return '';
        }

        $css = @file_get_contents($path);
        if ($css === false || trim($css) === '') {
            return '';
        }

        $basePath = dirname($path);
        $css = self::stripComments($css);

        // @import rules must stay at the top of the <style> block.
        $imports = [];
        $css = preg_replace_callback(
            '/@import\s+(?:url\(\s*)?([\'"]?)([^\'"\)\s]+)\1\s*\)?([^;]*);/i',
            static function (array $match) use (&$imports, $basePath): string {
                $imports[] = self::rewriteImport($match[0], $basePath);
                return '';
            },
            $css
        ) ?? $css;

        $css = self::rewriteUrls($css, $basePath);
        $scoped = CssFramework::scopeCss($css, $scope);

        $variables = self::customizerVariables();
        $scopedVariables = '';
        if ($variables !== []) {
            $scopedVariables = CssFramework::scopeCss(
                ":root {\n    " . implode("\n    ", $variables) . "\n}",
                $scope
            );
        }

        $parts = array_filter([
            implode("\n", $imports),
            trim($scoped),
            trim($scopedVariables),
        ], static fn (string $part): bool => $part !== '');

        return implode("\n", $parts);
    }

    // ---------------------------------------------------------------------
    // Theme manifest
    // ---------------------------------------------------------------------

    /**
     * Reads (and caches) theme.json.
     *
     * Returns an empty array when editor.css is missing or the manifest is not
     * valid, so editor-specific metadata never leaks into a theme without the
     * opt-in stylesheet.
     *
     * @return array<string,mixed>
     */
    public static function manifest(): array
    {
        $theme = self::activeTheme();
        if (array_key_exists($theme, self::$manifestCache)) {
            return self::$manifestCache[$theme];
        }

        if (!self::enabled()) {
            return self::$manifestCache[$theme] = [];
        }

        $path = dirname(__DIR__) . '/themes/' . $theme . '/theme.json';
        if (!is_file($path)) {
            return self::$manifestCache[$theme] = [];
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return self::$manifestCache[$theme] = [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return self::$manifestCache[$theme] = [];
        }

        return self::$manifestCache[$theme] = $data;
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Reads a string from the theme.json "editor" block.
     */
    private static function editorOption(string $key): string
    {
        if (!self::enabled()) {
            return '';
        }

        $editor = self::manifest()['editor'] ?? null;
        if (!is_array($editor)) {
            return '';
        }

        $value = $editor[$key] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    /**
     * Returns the customizer CSS custom properties for the active theme.
     *
     * @return array<int,string>
     */
    private static function customizerVariables(): array
    {
        if (!class_exists(Customizer::class)) {
            return [];
        }

        return Customizer::cssVariables(self::activeTheme());
    }

    /**
     * Removes CSS comments before the stylesheet is parsed.
     */
    private static function stripComments(string $css): string
    {
        return preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
    }

    /**
     * Rewrites an @import statement, including the quoted form without url().
     */
    private static function rewriteImport(string $statement, string $basePath): string
    {
        $statement = self::rewriteUrls($statement, $basePath);

        return preg_replace_callback(
            '/@import\s+([\'"])([^\'"]+)\1/i',
            static function (array $match) use ($basePath): string {
                $url = trim($match[2]);
                if ($url === '' || self::isAbsoluteUrl($url)) {
                    return $match[0];
                }

                return '@import ' . $match[1] . self::absoluteUrl($url, $basePath) . $match[1];
            },
            $statement
        ) ?? $statement;
    }

    /**
     * Rewrites relative url(...) references to public theme URLs.
     */
    private static function rewriteUrls(string $css, string $basePath): string
    {
        return preg_replace_callback(
            '#url\(\s*([\'"]?)([^\'")]+)\1\s*\)#i',
            static function (array $match) use ($basePath): string {
                $url = trim($match[2]);
                if ($url === '' || self::isAbsoluteUrl($url)) {
                    return $match[0];
                }

                $quote = $match[1] !== '' ? $match[1] : '"';
                return 'url(' . $quote . self::absoluteUrl($url, $basePath) . $quote . ')';
            },
            $css
        ) ?? $css;
    }

    /**
     * Whether a URL should be left untouched (absolute, data URI, fragment).
     */
    private static function isAbsoluteUrl(string $url): bool
    {
        if ($url[0] === '#' || $url[0] === '/' || str_starts_with($url, 'var(')) {
            return true;
        }

        return preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1;
    }

    /**
     * Resolves a path relative to editor.css into a root-relative URL.
     */
    private static function absoluteUrl(string $relative, string $basePath): string
    {
        $suffix = '';
        if (preg_match('/^([^?#]*)(.*)$/s', $relative, $match) === 1) {
            $relative = $match[1];
            $suffix = $match[2];
        }

        if ($relative === '') {
            return $suffix;
        }
        if (str_starts_with($relative, '/')) {
            return $relative . $suffix;
        }

        $file = $basePath . '/' . $relative;
        $real = realpath($file);
        $absolute = $real !== false
            ? str_replace('\\', '/', $real)
            : self::normalizePath(str_replace('\\', '/', $file));

        $root = str_replace('\\', '/', dirname(__DIR__));
        if (!str_starts_with($absolute, $root . '/')) {
            return $relative . $suffix;
        }

        $base = class_exists(Router::class)
            ? rtrim(Router::getBaseSubdirectory(), '/')
            : '';
        $publicPath = substr($absolute, strlen($root) + 1);

        return $base . '/' . ltrim($publicPath, '/') . $suffix;
    }

    /**
     * Normalizes "." and ".." segments in an absolute filesystem path.
     */
    private static function normalizePath(string $path): string
    {
        $prefix = '';
        if (str_starts_with($path, '/')) {
            $prefix = '/';
            $path = ltrim($path, '/');
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $prefix . implode('/', $segments);
    }
}
