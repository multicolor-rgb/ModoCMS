<?php
declare(strict_types=1);

namespace Core;

/**
 * Class CustomizerPanel
 *
 * Renders the live theme Customizer as a slide-in sidebar directly on the
 * frontend ("Edit template settings"), so administrators can tweak the active
 * theme's settings while looking at the real page instead of an admin iframe.
 *
 * The whole page acts as the live preview: controls mapped to a CSS custom
 * property (via the "css_var" key) update a <style> block instantly, while all
 * other changes reload the page with the session draft overlaid on top of the
 * published values (see Customizer::activatePreview()).
 *
 * Scope: value editing only (the schema builder "Manage settings" stays in the
 * admin at /admin/customize.php). It never renders inside the admin area and
 * requires the "manage_settings" capability.
 */
final class CustomizerPanel
{
    private static bool $printedHead = false;
    private static bool $printedPanel = false;

    /** Query flag that opens the panel. */
    private const OPEN_PARAM = 'modo_customize';

    public static function init(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        // Activate the live draft overlay as early as possible (before the
        // router renders the theme) so both <head> and body reflect the draft.
        if (isset($_GET[self::OPEN_PARAM]) && !Customizer::isPreviewActive()) {
            self::ensurePreviewToken();
            Customizer::activatePreview();
        }

        Hooks::addAction('theme-header', [self::class, 'renderHead']);
        Hooks::addAction('theme-footer', [self::class, 'render']);
    }

    /**
     * Whether the panel may be displayed for the current request.
     */
    public static function isEnabled(): bool
    {
        if (defined('IN_ADMIN') && IN_ADMIN === true) {
            return false;
        }
        if (!class_exists(Auth::class) || !Auth::check()) {
            return false;
        }
        return Auth::can('manage_settings');
    }

    /**
     * Whether the panel should actually be rendered (i.e. it has been opened).
     */
    public static function isOpen(): bool
    {
        return self::isEnabled()
            && (isset($_GET[self::OPEN_PARAM]) || isset($_GET['modo_preview']));
    }

    /**
     * Ensures a live-preview token exists in the session and returns it.
     */
    private static function ensurePreviewToken(): string
    {
        $token = Customizer::previewToken();
        if ($token === '') {
            $token = Customizer::startPreview();
        }
        return $token;
    }

    /**
     * Resolves an admin-relative URL, honouring a subfolder installation.
     */
    private static function adminUrl(string $path = ''): string
    {
        $base = class_exists('Core\\Router') ? Router::getBaseSubdirectory() : '';
        $path = ltrim($path, '/');
        return $base . '/admin' . ($path !== '' ? '/' . $path : '');
    }

    /**
     * Outputs the panel stylesheet (runs in <head>).
     */
    public static function renderHead(): void
    {
        if (!self::isOpen() || self::$printedHead) {
            return;
        }
        self::$printedHead = true;
        ?>
<link rel="stylesheet" href="<?= htmlspecialchars(self::adminUrl('assets/css/customizer-panel.css'), ENT_QUOTES, 'UTF-8') ?>">
        <?php
    }

    /**
     * Outputs the sidebar markup, its boot configuration and interaction script
     * (runs in the footer, after the theme content).
     */
    public static function render(): void
    {
        if (!self::isOpen() || self::$printedPanel) {
            return;
        }
        self::$printedPanel = true;

        $theme  = Customizer::activeTheme();
        $schema = Customizer::getSchema($theme);
        // getMods() already overlays the active draft (preview) for the theme.
        $values = Customizer::getMods($theme);

        // Map of control id => CSS custom property, so the preview updates
        // colours and sizes instantly without a full reload.
        $cssMap = [];
        foreach ($schema['sections'] as $section) {
            foreach ($section['controls'] as $control) {
                if (($control['css_var'] ?? '') !== '') {
                    $cssMap[$control['id']] = ['var' => $control['css_var'], 'unit' => $control['css_unit']];
                }
            }
        }

        $token = self::ensurePreviewToken();
        ?>
<div id="modo-cz-panel" class="modo-cz-panel-root" role="dialog" aria-label="<?= _e('Edit template settings') ?>">
    <header class="modo-czp-topbar">
        <div class="modo-czp-title"><?= _e('Edit template settings') ?></div>
        <div class="modo-czp-actions">
            <span class="modo-cz-status" id="modo-czp-status"></span>
            <button type="button" class="modo-czp-btn" id="modo-czp-reset"><?= _e('Reset') ?></button>
            <button type="button" class="modo-czp-btn modo-czp-btn-primary" id="modo-czp-publish"><?= _e('Publish') ?></button>
            <button type="button" class="modo-czp-close" id="modo-czp-close" title="<?= _e('Close') ?>" aria-label="<?= _e('Close') ?>">&times;</button>
        </div>
    </header>
    <div class="modo-czp-body">
        <?php require __DIR__ . '/../admin/views/customize-panel.php'; ?>
    </div>
</div>
<button type="button" id="modo-czp-reopen" class="modo-czp-reopen" title="<?= _e('Edit template settings') ?>" aria-label="<?= _e('Edit template settings') ?>">&#9881;</button>
<script>
    window.MODO_CUSTOMIZER = {
        token: <?= json_encode($token) ?>,
        theme: <?= json_encode($theme) ?>,
        csrf: <?= json_encode(Security::generateCsrfToken()) ?>,
        apiUrl: <?= json_encode(self::adminUrl('customize-api.php')) ?>,
        uploadUrl: <?= json_encode(self::adminUrl('upload.php')) ?>,
        cssMap: <?= json_encode($cssMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        labels: <?= json_encode([
            'saved' => __('Draft saved'),
            'published' => __('Changes published'),
            'reset' => __('Reset to defaults'),
            'error' => __('Something went wrong'),
            'uploadFailed' => __('File upload failed.'),
            'confirmReset' => __('Reset all customizer values to defaults?'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    };
</script>
<script src="<?= htmlspecialchars(self::adminUrl('assets/js/customizer-panel.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
        <?php
    }
}
