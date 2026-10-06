<?php
declare(strict_types=1);

namespace Core;

/**
 * Class Customizer
 *
 * WordPress-style live customizer engine for Modo CMS.
 *
 * A single, unified schema (Panels > Sections > Controls) is assembled from
 * three cooperating sources, merged in this order (a later source overrides an
 * earlier one when an id collides):
 *
 *   1. The active theme's `theme.json` "customize" block (theme author).
 *   2. Plugins via the `modo_customize_register` hook (code).
 *   3. The `customize_schema` database table (values created in the GUI builder).
 *
 * Published values live in the existing `theme_mods` table. Unsaved ("draft")
 * values used for the live preview are stored per-user in the PHP session and
 * overlaid on top of published values while a valid preview token is present.
 */
final class Customizer
{
    /** Whitelist of supported control types. */
    public const CONTROL_TYPES = [
        'text', 'textarea', 'number', 'range', 'checkbox', 'toggle',
        'select', 'radio', 'color', 'image', 'url', 'email', 'code',
        'heading', 'hr',
    ];

    /** Control types that only render layout in the sidebar and hold no value. */
    public const LAYOUT_TYPES = ['heading', 'hr'];

    private const DB_TABLE = 'customize_schema';
    private const DRAFT_SESSION_KEY = 'modo_customize_draft';
    private const PREVIEW_SESSION_KEY = 'modo_customize_preview';

    /** @var array<string,array> Registered sections keyed by id (from hooks/plugins). */
    private static array $registeredSections = [];

    /** @var array<string,array> Registered controls keyed by id (from hooks/plugins). */
    private static array $registeredControls = [];

    private static bool $codeSourcesLoaded = false;

    /** @var array<string,array{slug:string,mods:array<string,string>}> Per-theme value cache. */
    private static array $valueCache = [];

    private static bool $previewActive = false;

    /** @var array<string,string> Draft overlay applied while previewing. */
    private static array $previewMods = [];

    private static string $previewTheme = '';

    // ---------------------------------------------------------------------
    // Lifecycle
    // ---------------------------------------------------------------------

    /**
     * Boots the customizer: detects an active live-preview request and installs
     * the transient draft overlay so the frontend (and template tags) can read it.
     */
    public static function init(): void
    {
        self::detectPreview();
    }

    /**
     * Resolves the theme being customized. The customizer always targets the
     * currently active theme so the live iframe preview matches the real site.
     */
    public static function activeTheme(): string
    {
        $theme = '';
        if (class_exists('Core\\Router')) {
            $theme = Router::getOption('active_theme', 'default');
        }
        return $theme !== '' ? $theme : 'default';
    }


    // ---------------------------------------------------------------------
    // Schema registration (code / plugins)
    // ---------------------------------------------------------------------

    /**
     * Registers (or updates) a section. Safe to call from `modo_customize_register`.
     *
     * @param array{id:string,title?:string,description?:string,priority?:int} $section
     */
    public static function registerSection(array $section): void
    {
        $normalized = self::normalizeSection($section);
        if ($normalized === null) {
            return;
        }
        self::$registeredSections[$normalized['id']] = $normalized;
    }

    /**
     * Registers (or updates) a control. Safe to call from `modo_customize_register`.
     *
     * @param array<string,mixed> $control
     */
    public static function registerControl(array $control): void
    {
        $normalized = self::normalizeControl($control);
        if ($normalized === null) {
            return;
        }
        self::$registeredControls[$normalized['id']] = $normalized;

        // Ensure the target section exists so the control is never orphaned.
        $sectionId = $normalized['section'] !== '' ? $normalized['section'] : 'general';
        if (!isset(self::$registeredSections[$sectionId])) {
            self::$registeredSections[$sectionId] = self::normalizeSection([
                'id'       => $sectionId,
                'title'    => ucfirst(str_replace(['-', '_'], ' ', $sectionId)),
                'priority' => 100,
            ]);
        }
    }

    /**
     * Fires the registration hook exactly once so plugins can register their
     * sections/controls through Customizer::registerSection()/registerControl().
     */
    private static function loadCodeSources(): void
    {
        if (self::$codeSourcesLoaded) {
            return;
        }
        self::$codeSourcesLoaded = true;

        Hooks::doAction('modo_customize_register');

        // Allow a plugin to inject an entire schema tree via a filter as well.
        $injected = Hooks::applyFilters('modo_customize_schema', ['sections' => []]);
        if (is_array($injected) && !empty($injected['sections'])) {
            foreach ((array) $injected['sections'] as $section) {
                if (!is_array($section)) {
                    continue;
                }
                $normalized = self::normalizeSection($section);
                if ($normalized === null) {
                    continue;
                }
                self::$registeredSections[$normalized['id']] = $normalized;
                foreach ((array) ($section['controls'] ?? []) as $control) {
                    if (is_array($control)) {
                        $control['section'] = $normalized['id'];
                        self::registerControl($control);
                    }
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // Schema assembly
    // ---------------------------------------------------------------------

    /**
     * Builds the unified, ordered schema for a theme.
     *
     * @return array{sections: array<int,array>}
     */
    public static function getSchema(string $theme = ''): array
    {
        $theme = $theme !== '' ? $theme : self::activeTheme();
        self::loadCodeSources();

        $sections = [];
        $controls = [];

        self::ingestInto($sections, $controls, self::themeJsonSchema($theme));
        self::ingestInto($sections, $controls, [
            'sections' => array_values(self::$registeredSections),
        ]);
        self::ingestControlsInto($sections, $controls, array_values(self::$registeredControls));
        self::ingestInto($sections, $controls, self::dbSchema($theme));

        // Order sections and controls by priority.
        $orderedSections = [];
        foreach ($sections as $id => $section) {
            $section['controls'] = [];
            $orderedSections[$id] = $section;
        }

        foreach ($controls as $control) {
            $sectionId = $control['section'];
            if (!isset($orderedSections[$sectionId])) {
                $orderedSections[$sectionId] = self::normalizeSection([
                    'id'       => $sectionId,
                    'title'    => ucfirst(str_replace(['-', '_'], ' ', $sectionId)),
                    'priority' => 100,
                ]);
                $orderedSections[$sectionId]['controls'] = [];
            }
            $orderedSections[$sectionId]['controls'][] = $control;
        }

        uasort($orderedSections, static fn (array $a, array $b): int => ($a['priority'] <=> $b['priority']) ?: strcmp($a['id'], $b['id']));

        foreach ($orderedSections as &$section) {
            usort($section['controls'], static fn (array $a, array $b): int => ($a['priority'] <=> $b['priority']) ?: strcmp($a['id'], $b['id']));
        }
        unset($section);

        return ['sections' => array_values($orderedSections)];
    }

    /**
     * Flattens a nested "sections/controls" tree into the provided maps.
     *
     * @param array<string,array> $sections
     * @param array<string,array> $controls
     */
    private static function ingestInto(array &$sections, array &$controls, array $tree): void
    {
        foreach ((array) ($tree['sections'] ?? []) as $section) {
            if (!is_array($section)) {
                continue;
            }
            $normalized = self::normalizeSection($section);
            if ($normalized === null) {
                continue;
            }
            $sections[$normalized['id']] = $normalized;

            $childControls = [];
            foreach ((array) ($section['controls'] ?? []) as $control) {
                if (!is_array($control)) {
                    continue;
                }
                $control['section'] = $normalized['id'];
                $normalizedControl = self::normalizeControl($control);
                if ($normalizedControl !== null) {
                    $childControls[] = $normalizedControl;
                }
            }
            self::ingestControlsInto($sections, $controls, $childControls);
        }
    }

    /**
     * @param array<string,array> $sections
     * @param array<string,array> $controls
     * @param array<int,array>    $list
     */
    private static function ingestControlsInto(array &$sections, array &$controls, array $list): void
    {
        foreach ($list as $control) {
            if (!is_array($control)) {
                continue;
            }
            $normalized = self::normalizeControl($control);
            if ($normalized === null) {
                continue;
            }
            $controls[$normalized['id']] = $normalized;

            $sectionId = $normalized['section'];
            if ($sectionId !== '' && !isset($sections[$sectionId])) {
                $sections[$sectionId] = self::normalizeSection([
                    'id'       => $sectionId,
                    'title'    => ucfirst(str_replace(['-', '_'], ' ', $sectionId)),
                    'priority' => 100,
                ]);
            }
        }
    }

    /**
     * Reads the active theme's theme.json "customize" block.
     *
     * @return array{sections: array<int,array>}
     */
    private static function themeJsonSchema(string $theme): array
    {
        $theme = basename($theme);
        $path = dirname(__DIR__) . '/themes/' . $theme . '/theme.json';
        if (!is_file($path)) {
            return ['sections' => []];
        }
        $raw = (string) file_get_contents($path);
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['customize']['sections']) || !is_array($data['customize']['sections'])) {
            return ['sections' => []];
        }
        return ['sections' => $data['customize']['sections']];
    }

    /**
     * Reads a theme schema stored by the GUI builder.
     *
     * @return array{sections: array<int,array>}
     */
    private static function dbSchema(string $theme): array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('SELECT schema FROM customize_schema WHERE theme = :t LIMIT 1');
            $stmt->execute([':t' => $theme]);
            $schema = $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return ['sections' => []];
        }

        if (!is_string($schema) || $schema === '') {
            return ['sections' => []];
        }
        $data = json_decode($schema, true);
        if (!is_array($data) || empty($data['sections']) || !is_array($data['sections'])) {
            return ['sections' => []];
        }
        return ['sections' => $data['sections']];
    }


    // ---------------------------------------------------------------------
    // Normalization
    // ---------------------------------------------------------------------

    /**
     * @param array<string,mixed> $section
     * @return array|null
     */
    private static function normalizeSection(array $section): ?array
    {
        $id = self::sanitizeId((string) ($section['id'] ?? ''));
        if ($id === '') {
            return null;
        }

        return [
            'id'          => $id,
            'title'       => (string) ($section['title'] ?? ucfirst(str_replace(['-', '_'], ' ', $id))),
            'description' => (string) ($section['description'] ?? ''),
            'priority'    => isset($section['priority']) ? (int) $section['priority'] : 100,
            'source'      => (string) ($section['source'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $control
     * @return array|null
     */
    private static function normalizeControl(array $control): ?array
    {
        $id = self::sanitizeId((string) ($control['id'] ?? ''));
        if ($id === '') {
            return null;
        }

        $type = (string) ($control['type'] ?? 'text');
        if (!in_array($type, self::CONTROL_TYPES, true)) {
            $type = 'text';
        }

        return [
            'id'          => $id,
            'type'        => $type,
            'label'       => (string) ($control['label'] ?? $id),
            'description' => (string) ($control['description'] ?? ''),
            'section'     => self::sanitizeId((string) ($control['section'] ?? 'general')) ?: 'general',
            'default'     => $control['default'] ?? '',
            'options'     => self::normalizeOptions($control['options'] ?? []),
            'min'         => $control['min'] ?? null,
            'max'         => $control['max'] ?? null,
            'step'        => $control['step'] ?? null,
            'css_var'     => self::sanitizeCssVar((string) ($control['css_var'] ?? '')),
            'css_unit'    => (string) ($control['css_unit'] ?? ''),
            'priority'    => isset($control['priority']) ? (int) $control['priority'] : 100,
            'source'      => (string) ($control['source'] ?? ''),
        ];
    }

    /**
     * @param mixed $options
     * @return array<int,array{value:string,label:string}>
     */
    private static function normalizeOptions(mixed $options): array
    {
        $result = [];
        if (!is_array($options)) {
            return $result;
        }
        foreach ($options as $value => $label) {
            if (is_array($label) && isset($label['value'])) {
                $result[] = [
                    'value' => (string) $label['value'],
                    'label' => (string) ($label['label'] ?? $label['value']),
                ];
            } else {
                $result[] = ['value' => (string) $value, 'label' => (string) $label];
            }
        }
        return $result;
    }

    private static function sanitizeId(string $id): string
    {
        return (string) preg_replace('/[^A-Za-z0-9_\-]/', '', $id);
    }

    private static function sanitizeCssVar(string $var): string
    {
        $var = trim($var);
        if ($var === '') {
            return '';
        }
        $var = ltrim($var, '-');
        return (string) preg_replace('/[^A-Za-z0-9_\-]/', '', $var);
    }


    // ---------------------------------------------------------------------
    // Values (published + draft overlay)
    // ---------------------------------------------------------------------

    /**
     * Returns a control definition by id, or null when unknown.
     */
    public static function getControl(string $id): ?array
    {
        static $cache = [];
        $theme = self::activeTheme();
        $cacheKey = $theme . '|' . $id;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $schema = self::getSchema($theme);
        $found = null;
        foreach ($schema['sections'] as $section) {
            foreach ($section['controls'] as $control) {
                if ($control['id'] === $id) {
                    $found = $control;
                    break 2;
                }
            }
        }
        $cache[$cacheKey] = $found;
        return $found;
    }

    /**
     * Returns all values (defaults merged with published values and the active
     * preview draft overlay).
     *
     * @return array<string,string>
     */
    public static function getMods(string $theme = ''): array
    {
        $theme = $theme !== '' ? $theme : self::activeTheme();
        $schema = self::getSchema($theme);

        $values = [];
        foreach ($schema['sections'] as $section) {
            foreach ($section['controls'] as $control) {
                if (in_array($control['type'], self::LAYOUT_TYPES, true)) {
                    continue;
                }
                $values[$control['id']] = (string) $control['default'];
            }
        }

        foreach (self::publishedValues($theme) as $key => $value) {
            if (array_key_exists($key, $values)) {
                $values[$key] = (string) $value;
            }
        }

        if (self::$previewActive && $theme === self::activeTheme()) {
            foreach (self::$previewMods as $key => $value) {
                if (array_key_exists($key, $values)) {
                    $values[$key] = (string) $value;
                }
            }
        }

        return $values;
    }

    /**
     * Reads a single theme modification value.
     */
    public static function getMod(string $key, mixed $default = null, string $theme = ''): mixed
    {
        $theme = $theme !== '' ? $theme : self::activeTheme();

        // Live preview overlay wins for the active theme.
        if (self::$previewActive && $theme === self::activeTheme() && array_key_exists($key, self::$previewMods)) {
            $value = self::$previewMods[$key];
        } else {
            $published = self::publishedValues($theme);
            if (array_key_exists($key, $published)) {
                $value = $published[$key];
            } else {
                $control = self::getControl($key);
                $value = $control !== null ? $control['default'] : ($default ?? '');
            }
        }

        return Hooks::applyFilters('theme_mod_' . $key, $value);
    }

    /**
     * @return array<string,string>
     */
    private static function publishedValues(string $theme): array
    {
        if (isset(self::$valueCache[$theme])) {
            return self::$valueCache[$theme]['mods'];
        }

        $mods = [];
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare('SELECT mod_key, mod_value FROM theme_mods WHERE theme = :t');
            $stmt->execute([':t' => $theme]);
            foreach ($stmt->fetchAll() as $row) {
                $mods[(string) $row['mod_key']] = (string) $row['mod_value'];
            }
        } catch (\Throwable $e) {
            $mods = [];
        }

        self::$valueCache[$theme] = ['slug' => $theme, 'mods' => $mods];
        return $mods;
    }

    /**
     * Invalidates the cached values for a theme (call after publishing).
     */
    public static function flushValues(?string $theme = null): void
    {
        if ($theme === null) {
            self::$valueCache = [];
            return;
        }
        unset(self::$valueCache[$theme]);
    }


    // ---------------------------------------------------------------------
    // Sanitization
    // ---------------------------------------------------------------------

    /**
     * Sanitizes a raw input value according to its control type.
     *
     * @param array<string,mixed> $control
     */
    public static function sanitizeValue(array $control, mixed $value): string
    {
        $type = (string) ($control['type'] ?? 'text');

        switch ($type) {
            case 'checkbox':
            case 'toggle':
                return (!empty($value) && $value !== '0' && $value !== 'false') ? '1' : '0';

            case 'number':
            case 'range':
                if ($value === '' || $value === null) {
                    return '';
                }
                $number = is_numeric($value) ? $value + 0 : 0;
                if (isset($control['min']) && $control['min'] !== null && $number < (float) $control['min']) {
                    $number = $control['min'] + 0;
                }
                if (isset($control['max']) && $control['max'] !== null && $number > (float) $control['max']) {
                    $number = $control['max'] + 0;
                }
                return (string) $number;

            case 'select':
            case 'radio':
                $allowed = array_map(static fn (array $o): string => $o['value'], $control['options'] ?? []);
                return in_array((string) $value, $allowed, true) ? (string) $value : (string) ($control['default'] ?? '');

            case 'color':
                $value = trim((string) $value);
                if ($value === '') {
                    return '';
                }
                if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value)) {
                    return $value;
                }
                if (preg_match('/^(rgb|rgba|hsl|hsla)\([0-9.,%\s]+\)$/i', $value)) {
                    return $value;
                }
                return (string) ($control['default'] ?? '');

            case 'url':
                $value = trim((string) $value);
                return $value === '' ? '' : (string) filter_var($value, FILTER_SANITIZE_URL);

            case 'email':
                $value = trim((string) $value);
                return ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL)) ? $value : '';

            case 'image':
                // Store a relative path or absolute URL only (no protocol tricks).
                $value = trim((string) $value);
                if ($value === '') {
                    return '';
                }
                if (preg_match('#^(https?://|/)#i', $value)) {
                    return (string) filter_var($value, FILTER_SANITIZE_URL);
                }
                return (string) preg_replace('#[^A-Za-z0-9_\-./]#', '', $value);

            case 'code':
            case 'textarea':
                // Preserve free text; output is escaped per context by the theme.
                return (string) $value;

            case 'text':
            default:
                return trim(self::stripControlChars((string) $value));
        }
    }

    private static function stripControlChars(string $value): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
    }


    // ---------------------------------------------------------------------
    // Draft (live preview, per-user session)
    // ---------------------------------------------------------------------

    /**
     * @return array<string,string>
     */
    public static function getDraft(string $theme = ''): array
    {
        $theme = $theme !== '' ? $theme : self::activeTheme();
        $draft = $_SESSION[self::DRAFT_SESSION_KEY][$theme] ?? [];
        return is_array($draft) ? $draft : [];
    }

    /**
     * Stores a sanitized draft value in the session.
     */
    public static function setDraftValue(string $key, mixed $value, string $theme = ''): void
    {
        $theme = $theme !== '' ? $theme : self::activeTheme();
        $control = self::getControl($key);
        if ($control === null || in_array($control['type'], self::LAYOUT_TYPES, true)) {
            return;
        }
        $sanitized = self::sanitizeValue($control, $value);
        $_SESSION[self::DRAFT_SESSION_KEY][$theme][$key] = $sanitized;
        self::$valueCache = [];
    }

    /**
     * Replaces the whole draft for a theme (used when applying a batch save).
     *
     * @param array<string,mixed> $values
     */
    public static function setDraft(array $values, string $theme = ''): void
    {
        $theme = $theme !== '' ? $theme : self::activeTheme();
        $clean = [];
        foreach ($values as $key => $value) {
            $control = self::getControl((string) $key);
            if ($control === null || in_array($control['type'], self::LAYOUT_TYPES, true)) {
                continue;
            }
            $clean[(string) $key] = self::sanitizeValue($control, $value);
        }
        $_SESSION[self::DRAFT_SESSION_KEY][$theme] = $clean;
        self::$valueCache = [];
    }

    public static function clearDraft(string $theme = ''): void
    {
        $theme = $theme !== '' ? $theme : self::activeTheme();
        unset($_SESSION[self::DRAFT_SESSION_KEY][$theme]);
        self::$valueCache = [];
    }

    // ---------------------------------------------------------------------
    // Publish / reset
    // ---------------------------------------------------------------------

    /**
     * Persists the given raw values into theme_mods after sanitization.
     *
     * @param array<string,mixed> $values
     * @return int Number of stored settings.
     */
    public static function publish(array $values, string $theme = ''): int
    {
        $theme = $theme !== '' ? $theme : self::activeTheme();
        $schema = self::getSchema($theme);

        $known = [];
        foreach ($schema['sections'] as $section) {
            foreach ($section['controls'] as $control) {
                if (!in_array($control['type'], self::LAYOUT_TYPES, true)) {
                    $known[$control['id']] = $control;
                }
            }
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('
            INSERT INTO theme_mods (theme, mod_key, mod_value)
            VALUES (:t, :k, :v)
            ON CONFLICT(theme, mod_key) DO UPDATE SET mod_value = excluded.mod_value
        ');

        $count = 0;
        $db->beginTransaction();
        try {
            foreach ($values as $key => $value) {
                $key = (string) $key;
                if (!isset($known[$key])) {
                    continue;
                }
                $stmt->execute([
                    ':t' => $theme,
                    ':k' => $key,
                    ':v' => self::sanitizeValue($known[$key], $value),
                ]);
                $count++;
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        self::flushValues($theme);
        self::clearDraft($theme);
        return $count;
    }

    /**
     * Removes all published values for a theme.
     */
    public static function resetTheme(string $theme = ''): void
    {
        $theme = $theme !== '' ? $theme : self::activeTheme();
        $db = Database::getConnection();
        $stmt = $db->prepare('DELETE FROM theme_mods WHERE theme = :t');
        $stmt->execute([':t' => $theme]);
        self::flushValues($theme);
        self::clearDraft($theme);
    }


    // ---------------------------------------------------------------------
    // CSS variable rendering (instant live preview)
    // ---------------------------------------------------------------------

    /**
     * Builds a <style> block exposing values of controls that map to a CSS
     * custom property (via the "css_var" key). Used by customizer_css().
     */
    public static function renderCssVars(string $theme = ''): string
    {
        $theme = $theme !== '' ? $theme : self::activeTheme();
        $schema = self::getSchema($theme);
        $values = self::getMods($theme);

        $declarations = [];
        foreach ($schema['sections'] as $section) {
            foreach ($section['controls'] as $control) {
                if ($control['css_var'] === '' || in_array($control['type'], self::LAYOUT_TYPES, true)) {
                    continue;
                }
                $value = (string) ($values[$control['id']] ?? '');
                if ($value === '') {
                    continue;
                }
                if (($control['type'] === 'checkbox' || $control['type'] === 'toggle') && $value !== '1') {
                    continue;
                }
                if ($control['css_unit'] !== '' && is_numeric($value)) {
                    $value .= $control['css_unit'];
                }
                $safeValue = self::sanitizeCssValue($value);
                if ($safeValue === '') {
                    continue;
                }
                $declarations[] = '--' . $control['css_var'] . ': ' . $safeValue . ';';
            }
        }

        if (empty($declarations)) {
            return '';
        }

        return "<style id=\"modo-customizer-vars\">\n:root {\n    " . implode("\n    ", $declarations) . "\n}\n</style>\n";
    }

    private static function sanitizeCssValue(string $value): string
    {
        $value = str_replace(['<', '>', '{', '}', ';', '\\'], '', $value);
        return trim($value);
    }

    // ---------------------------------------------------------------------
    // Live preview token handling
    // ---------------------------------------------------------------------

    /**
     * Issues a fresh preview token for the current user and theme.
     */
    public static function startPreview(string $theme = ''): string
    {
        $theme = $theme !== '' ? $theme : self::activeTheme();
        $token = bin2hex(random_bytes(16));
        $_SESSION[self::PREVIEW_SESSION_KEY] = ['token' => $token, 'theme' => $theme];
        return $token;
    }

    public static function previewToken(): string
    {
        return (string) ($_SESSION[self::PREVIEW_SESSION_KEY]['token'] ?? '');
    }

    public static function isPreviewActive(): bool
    {
        return self::$previewActive;
    }

    /**
     * Explicitly activates the draft overlay for the current request.
     *
     * Used by the frontend settings panel when it is opened directly (e.g. via
     * ?modo_customize=1) without a pre-existing preview token in the URL, so the
     * live page immediately reflects the unsaved draft. Detection via a valid
     * ?modo_preview=<token> always takes precedence and is applied at bootstrap.
     */
    public static function activatePreview(string $theme = ''): void
    {
        if (empty($_SESSION['user_id'])) {
            return;
        }

        if (self::$previewActive) {
            return;
        }

        $theme = $theme !== '' ? $theme : self::activeTheme();

        // Never let the preview leak into caches.
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
        }

        self::$previewActive = true;
        self::$previewTheme = $theme;
        self::$previewMods = self::getDraft($theme);
        self::$valueCache = [];
    }

    /**
     * Detects a ?modo_preview=<token> request, validates it against the session
     * and (when valid) activates the draft overlay so the frontend reflects the
     * unsaved customizer changes.
     */
    private static function detectPreview(): void
    {
        $token = (string) ($_GET['modo_preview'] ?? '');
        if ($token === '') {
            return;
        }

        $session = $_SESSION[self::PREVIEW_SESSION_KEY] ?? null;
        if (!is_array($session) || empty($session['token'])) {
            return;
        }

        // Only authenticated users may preview unsaved changes.
        if (empty($_SESSION['user_id'])) {
            return;
        }

        if (!hash_equals((string) $session['token'], $token)) {
            return;
        }

        $theme = (string) ($session['theme'] ?? self::activeTheme());

        // Never let the preview leak into caches.
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
        }

        self::$previewActive = true;
        self::$previewTheme = $theme;
        self::$previewMods = self::getDraft($theme);
        self::$valueCache = [];
    }

    // ---------------------------------------------------------------------
    // GUI builder persistence (schema CRUD)
    // ---------------------------------------------------------------------

    /**
     * Loads the GUI-authored sections for a theme (raw, editable form).
     *
     * @return array<int,array>
     */
    public static function getStoredSchema(string $theme = ''): array
    {
        $theme = $theme !== '' ? $theme : self::activeTheme();
        $schema = self::dbSchema($theme);
        return $schema['sections'];
    }

    /**
     * Persists GUI-authored sections for a theme.
     *
     * @param array<int,array> $sections
     */
    public static function saveStoredSchema(array $sections, string $theme = ''): void
    {
        $theme = $theme !== '' ? $theme : self::activeTheme();

        $cleanSections = [];
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $normalized = self::normalizeSection($section);
            if ($normalized === null) {
                continue;
            }
            $cleanControls = [];
            foreach ((array) ($section['controls'] ?? []) as $control) {
                if (!is_array($control)) {
                    continue;
                }
                $control['section'] = $normalized['id'];
                $normalizedControl = self::normalizeControl($control);
                if ($normalizedControl !== null) {
                    $cleanControls[] = $normalizedControl;
                }
            }
            $normalized['controls'] = $cleanControls;
            $cleanSections[] = $normalized;
        }

        $payload = json_encode(
            ['sections' => $cleanSections],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        $db = Database::getConnection();
        $stmt = $db->prepare('
            INSERT INTO customize_schema (theme, schema)
            VALUES (:t, :s)
            ON CONFLICT(theme) DO UPDATE SET schema = excluded.schema
        ');
        $stmt->execute([':t' => $theme, ':s' => $payload]);
    }

    public static function deleteStoredSchema(string $theme = ''): void
    {
        $theme = $theme !== '' ? $theme : self::activeTheme();
        $db = Database::getConnection();
        $stmt = $db->prepare('DELETE FROM customize_schema WHERE theme = :t');
        $stmt->execute([':t' => $theme]);
    }
}


