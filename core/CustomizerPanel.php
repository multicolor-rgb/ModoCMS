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
 * The page acts as the live preview inside an iframe that is rendered next to
 * the panel. Controls mapped to a CSS custom property (via the "css_var" key)
 * update a <style> block inside that frame instantly, while the remaining ones
 * reload only the frame with the session draft overlaid on top of the published
 * values (see Customizer::activatePreview()). The panel itself is never
 * re-rendered during editing, so its scroll position, open sections and focus
 * survive every preview refresh. The reload happens only after editing is
 * committed (field blur, switch/select change).
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

    /** Query flag used by the iframe that renders the live preview. */
    private const FRAME_PARAM = 'modo_frame';

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

        // The iframe only renders the theme. The panel lives in the parent
        // document (see renderShellIfRequested()).
        if (self::isFrameRequest()) {
            Hooks::addAction('theme-footer', [self::class, 'renderFrameBridge']);
            return;
        }

        Hooks::addAction('theme-header', [self::class, 'renderHead']);
        Hooks::addAction('theme-footer', [self::class, 'render']);
    }

    /**
     * Whether the current request renders the preview frame rather than the
     * top-level customizer shell.
     */
    public static function isFrameRequest(): bool
    {
        return isset($_GET[self::FRAME_PARAM]);
    }

    /**
     * Renders the customizer shell (panel + preview iframe) when the panel is
     * open, then tells the caller to stop the normal frontend request.
     *
     * The shell keeps the sidebar in a separate document from the preview, so
     * refreshing the preview never rebuilds the panel.
     */
    public static function renderShellIfRequested(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return false;
        }
        if (!self::isEnabled() || self::isFrameRequest()) {
            return false;
        }
        if (!isset($_GET[self::OPEN_PARAM]) && !isset($_GET['modo_preview'])) {
            return false;
        }

        self::ensurePreviewToken();
        self::renderShell();

        return true;
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
            && !self::isFrameRequest()
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
     * Builds the URL loaded by the preview iframe: the current frontend URL
     * with the customizer flags, a valid preview token and the frame marker.
     */
    private static function frameUrl(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        $query = $_GET;
        $query[self::OPEN_PARAM] = '1';
        $query[self::FRAME_PARAM] = '1';
        $query['modo_preview'] = self::ensurePreviewToken();

        return $path . '?' . http_build_query($query);
    }

    /**
     * Outputs the standalone customizer document: the settings panel plus the
     * live preview iframe. The panel and the preview live in separate documents,
     * so refreshing the preview never re-renders the sidebar.
     */
    private static function renderShell(): void
    {
        $frameUrl = self::frameUrl();
        $title = __('Edit template settings');
        $locale = class_exists(I18n::class) ? I18n::getLocale() : 'en';
        $cssUrl = self::assetUrl('assets/css/customizer-panel.css');
        ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($locale, ENT_QUOTES, 'UTF-8') ?>" class="modo-cz-shell">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($cssUrl, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="modo-cz-shell-body">
    <iframe id="modo-czp-frame" class="modo-czp-frame" src="<?= htmlspecialchars($frameUrl, ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"></iframe>
    <?php self::render(); ?>
</body>
</html>
        <?php
    }

    /**
     * Keeps the customizer parameters on links and forms used inside the preview
     * frame, so navigating the preview never drops the draft overlay.
     */
    public static function renderFrameBridge(): void
    {
        if (!self::isFrameRequest() || !Customizer::isPreviewActive()) {
            return;
        }

        $params = [
            self::OPEN_PARAM => '1',
            self::FRAME_PARAM  => '1',
            'modo_preview'     => self::ensurePreviewToken(),
        ];
        ?>
<script>
(function () {
    'use strict';

    var PARAMS = <?= json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function withPreviewParams(rawUrl) {
        try {
            var url = new URL(rawUrl, window.location.href);
            if (url.origin !== window.location.origin) return rawUrl;
            if (url.pathname.indexOf('/admin/') !== -1) return rawUrl;
            Object.keys(PARAMS).forEach(function (key) {
                url.searchParams.set(key, PARAMS[key]);
            });
            return url.toString();
        } catch (e) {
            return rawUrl;
        }
    }

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0) return;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        var link = event.target.closest ? event.target.closest('a[href]') : null;
        if (!link || link.target === '_blank' || link.hasAttribute('download')) return;

        var href = link.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#' || /^(mailto|tel|javascript):/i.test(href)) return;

        link.href = withPreviewParams(link.href);
    }, true);

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || !form.action) return;

        if (String(form.method).toLowerCase() === 'get') {
            Object.keys(PARAMS).forEach(function (key) {
                var input = form.querySelector('input[name="' + key + '"]');
                if (!input) {
                    input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = key;
                    form.appendChild(input);
                }
                input.value = PARAMS[key];
            });
            return;
        }

        form.action = withPreviewParams(form.action);
    }, true);
})();
</script>
        <?php
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
     * Resolves an admin asset URL and appends a cache-busting version so panel
     * updates are picked up without a hard refresh.
     */
    private static function assetUrl(string $path): string
    {
        $url = self::adminUrl($path);
        $file = dirname(__DIR__) . '/admin/' . ltrim($path, '/');
        $mtime = is_file($file) ? (int) filemtime($file) : 0;
        return $mtime > 0 ? $url . '?v=' . $mtime : $url;
    }

    /**
     * Lists image files available in the media library (uploads directory).
     *
     * @return array<int,array{name:string,url:string,path:string,folder:string}>
     */
    private static function mediaItems(): array
    {
        return array_map(
            static fn (array $item): array => [
                'name'   => $item['name'],
                'url'    => $item['url'],
                'path'   => $item['path'],
                'folder' => $item['folder'],
            ],
            MediaLibrary::listImages()
        );
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
<link rel="stylesheet" href="<?= htmlspecialchars(self::assetUrl('assets/css/customizer-panel.css'), ENT_QUOTES, 'UTF-8') ?>">
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
        $mediaItems = self::mediaItems();
        $mediaFolders = MediaLibrary::folders();
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

<div id="modo-cz-media-modal" class="modo-cz-media-modal" hidden>
    <div class="modo-cz-media-dialog" role="dialog" aria-modal="true" aria-labelledby="modo-cz-media-title">
        <header class="modo-cz-media-head">
            <h3 id="modo-cz-media-title"><?= _e('Select Media from Library') ?></h3>
            <button type="button" class="modo-cz-media-close" data-media-close aria-label="<?= _e('Close') ?>">&times;</button>
        </header>
        <div class="modo-cz-media-body">
            <?php if (!empty($mediaItems)): ?>
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 14px; flex-wrap: wrap;">
                    <label for="modo-cz-media-folder-filter" style="font-size: 12px; color: var(--text-muted);"><?= _e('Folder') ?></label>
                    <select id="modo-cz-media-folder-filter" class="form-control" style="max-width: 240px; padding: 6px 10px; font-size: 13px;">
                        <option value="__all__"><?= _e('All files') ?></option>
                        <option value=""><?= _e('Root') ?></option>
                        <?php foreach ($mediaFolders as $folder): ?>
                            <option value="<?= htmlspecialchars((string) $folder['name'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars((string) $folder['name'], ENT_QUOTES, 'UTF-8') ?> (<?= (int) $folder['count'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="modo-cz-media-grid" id="modo-cz-media-grid">
                <?php foreach ($mediaItems as $media): ?>
                    <button type="button" class="modo-cz-media-item" data-media-url="<?= htmlspecialchars($media['url'], ENT_QUOTES, 'UTF-8') ?>" data-folder="<?= htmlspecialchars((string) ($media['folder'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        <img src="<?= htmlspecialchars(\Core\MediaLibrary::thumbnailUrl((string) $media['path'], 160), ENT_QUOTES, 'UTF-8') ?>" srcset="<?= htmlspecialchars(\Core\MediaLibrary::thumbnailUrl((string) $media['path'], 160), ENT_QUOTES, 'UTF-8') ?> 1x, <?= htmlspecialchars(\Core\MediaLibrary::thumbnailUrl((string) $media['path'], 240), ENT_QUOTES, 'UTF-8') ?> 2x" alt="<?= htmlspecialchars($media['name'], ENT_QUOTES, 'UTF-8') ?>" loading="lazy" decoding="async">
                        <span class="modo-cz-media-name"><?= htmlspecialchars($media['name'], ENT_QUOTES, 'UTF-8') ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
            <p class="modo-cz-media-empty" id="modo-cz-media-empty"<?= empty($mediaItems) ? '' : ' hidden' ?>>
                <?= _e('No uploaded images found in the library.') ?>
            </p>
        </div>
        <footer class="modo-cz-media-foot">
            <button type="button" class="modo-czp-btn" data-media-upload><?= _e('Upload') ?></button>
            <button type="button" class="modo-czp-btn" data-media-close><?= _e('Close') ?></button>
        </footer>
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
            'uploading' => __('Uploading...'),
            'confirmReset' => __('Reset all customizer values to defaults?'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    };
</script>
<script src="<?= htmlspecialchars(self::assetUrl('assets/js/customizer-panel.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
        <?php
    }
}
