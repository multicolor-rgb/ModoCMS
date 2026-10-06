<?php
declare(strict_types=1);

namespace Core;

/**
 * Class AdminBar
 *
 * Renders a WordPress-style floating admin bar on the frontend for logged-in
 * users, exposing the most useful shortcuts (Dashboard, New, Edit this page,
 * Customizer, profile, logout).
 *
 * It hooks into the theme lifecycle hooks (`theme-header` / `theme-footer`) so
 * it works with every theme that calls theme_head()/theme_footer(), and it is
 * never rendered inside the admin area.
 */
final class AdminBar
{
    private static bool $printedHead = false;
    private static bool $printedBar = false;

    /** Height of the bar in pixels (kept in sync with the CSS below). */
    private const HEIGHT = 32;

    public static function init(): void
    {
        Hooks::addAction('theme-header', [self::class, 'renderHead']);
        Hooks::addAction('theme-footer', [self::class, 'render']);
    }

    /**
     * Whether the bar should be displayed for the current request.
     */
    public static function isEnabled(): bool
    {
        if (defined('IN_ADMIN') && IN_ADMIN === true) {
            return false;
        }
        if (!class_exists(Auth::class) || !Auth::check()) {
            return false;
        }
        return Auth::can('manage_pages') || Auth::can('manage_settings');
    }

    /**
     * Resolves an admin URL, honouring a subfolder installation.
     */
    private static function adminUrl(string $path = ''): string
    {
        $base = class_exists('Core\\Router') ? Router::getBaseSubdirectory() : '';
        $base = $base !== '' ? $base : '';
        $path = ltrim($path, '/');
        return $base . '/admin' . ($path !== '' ? '/' . $path : '');
    }

    /**
     * Outputs the stylesheet and the early bootstrap script (runs in <head> to
     * avoid a content jump when the bar pushes the page down).
     */
    public static function renderHead(): void
    {
        if (!self::isEnabled() || self::$printedHead) {
            return;
        }
        self::$printedHead = true;

        $h = self::HEIGHT;
        ?>
<style id="modo-admin-bar-css">
html.modo-has-admin-bar body { margin-top: <?= $h ?>px !important; }
html.modo-has-admin-bar .sticky-top { top: <?= $h ?>px !important; }
#modo-admin-bar {
    position: fixed; top: 0; left: 0; right: 0; height: <?= $h ?>px; z-index: 100000;
    background: #1d2327; color: #c3c4c7;
    font: 13px/<?= $h ?>px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    display: flex; align-items: stretch; box-shadow: 0 1px 0 rgba(0,0,0,.2);
}
#modo-admin-bar a { color: #c3c4c7; text-decoration: none; display: inline-flex; align-items: center; padding: 0 10px; height: 100%; }
#modo-admin-bar a:hover { color: #72aee6; background: #2c3338; }
#modo-admin-bar .mab-brand { font-weight: 700; color: #fff; background: #2271b1; padding: 0 14px; }
#modo-admin-bar .mab-brand:hover { color: #fff; background: #135e96; }
#modo-admin-bar .mab-menu { display: flex; align-items: stretch; }
#modo-admin-bar .mab-spacer { flex: 1; }
#modo-admin-bar .mab-right { display: flex; align-items: stretch; }
#modo-admin-bar .mab-avatar { display: inline-block; width: 18px; height: 18px; border-radius: 50%; background: #2271b1; color: #fff; text-align: center; line-height: 18px; margin-right: 6px; font-size: 11px; font-weight: 700; }
#modo-admin-bar .mab-toggle { background: transparent; border: 0; color: #c3c4c7; cursor: pointer; padding: 0 12px; height: 100%; }
#modo-admin-bar .mab-toggle:hover { color: #72aee6; background: #2c3338; }
#modo-admin-bar-expand {
    position: fixed; top: 0; right: 12px; z-index: 100001; height: <?= $h ?>px; padding: 0 12px;
    background: #1d2327; color: #c3c4c7; border: 0; border-radius: 0 0 4px 4px; cursor: pointer;
    font-size: 13px; display: none;
}
html.modo-admin-bar-collapsed #modo-admin-bar { display: none; }
html.modo-admin-bar-collapsed #modo-admin-bar-expand { display: block; }
@media (max-width: 782px) {
    #modo-admin-bar .mab-menu .mab-hide-sm { display: none; }
}
</style>
<script>
(function () {
    try {
        var collapsed = localStorage.getItem('modo_admin_bar_collapsed') === '1';
        document.documentElement.classList.toggle('modo-admin-bar-collapsed', collapsed);
        if (!collapsed) document.documentElement.classList.add('modo-has-admin-bar');
    } catch (e) {
        document.documentElement.classList.add('modo-has-admin-bar');
    }
})();
</script>
        <?php
    }
    /**
     * Outputs the bar markup and its interaction script (runs in the footer).
     */
    public static function render(): void
    {
        if (!self::isEnabled() || self::$printedBar) {
            return;
        }
        self::$printedBar = true;

        $user = (string) ($_SESSION['user_name'] ?? 'Admin');
        $initial = strtoupper(substr($user, 0, 1));
        $currentPage = \ThemeState::$currentPage ?? null;
        $canPages = Auth::can('manage_pages');
        $canSettings = Auth::can('manage_settings');

        $editUrl = '';
        if ($canPages && is_array($currentPage) && !empty($currentPage['id'])) {
            $editUrl = self::adminUrl('page-edit.php?id=' . (int) $currentPage['id']);
        }

        // Deep link that opens the frontend "Edit template settings" panel on the
        // current page, carrying a fresh live-preview token so the unsaved draft
        // is overlaid on the real site (the page acts as the preview).
        $czUrl = '';
        if ($canSettings) {
            $token = Customizer::previewToken();
            if ($token === '') {
                $token = Customizer::startPreview();
            }
            $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            $czQuery = $_GET;
            $czQuery['modo_customize'] = '1';
            $czQuery['modo_preview'] = $token;
            $czUrl = $currentPath . '?' . http_build_query($czQuery);
        }
        ?>
<div id="modo-admin-bar" role="navigation" aria-label="<?= _e('Admin toolbar') ?>">
    <a class="mab-brand" href="<?= htmlspecialchars(self::adminUrl('index.php'), ENT_QUOTES, 'UTF-8') ?>">Modo CMS</a>
    <nav class="mab-menu">
        <?php if ($canPages): ?>
        <a class="mab-hide-sm" href="<?= htmlspecialchars(self::adminUrl('page-edit.php'), ENT_QUOTES, 'UTF-8') ?>">+ <?= _e('New') ?></a>
        <?php endif; ?>
        <?php if ($editUrl !== ''): ?>
        <a class="mab-hide-sm" href="<?= htmlspecialchars($editUrl, ENT_QUOTES, 'UTF-8') ?>"><?= _e('Edit') ?></a>
        <?php endif; ?>
        <?php if ($czUrl !== ''): ?>
        <a href="<?= htmlspecialchars($czUrl, ENT_QUOTES, 'UTF-8') ?>"><?= _e('Edit template settings') ?></a>
        <?php endif; ?>
    </nav>
    <span class="mab-spacer"></span>
    <div class="mab-right">
        <a href="<?= htmlspecialchars(self::adminUrl('profile.php'), ENT_QUOTES, 'UTF-8') ?>">
            <span class="mab-avatar"><?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?></span>
            <?= htmlspecialchars($user, ENT_QUOTES, 'UTF-8') ?>
        </a>
        <a href="<?= htmlspecialchars(self::adminUrl('logout.php'), ENT_QUOTES, 'UTF-8') ?>"><?= _e('Log out') ?></a>
        <button type="button" class="mab-toggle" id="mab-toggle" title="<?= _e('Collapse') ?>">&#9650;</button>
    </div>
</div>
<button type="button" id="modo-admin-bar-expand" title="<?= _e('Show admin bar') ?>">&#9660;</button>
<script>
(function () {
    function setCollapsed(on) {
        document.documentElement.classList.toggle('modo-admin-bar-collapsed', on);
        document.documentElement.classList.toggle('modo-has-admin-bar', !on);
        try { localStorage.setItem('modo_admin_bar_collapsed', on ? '1' : '0'); } catch (e) {}
    }
    var t = document.getElementById('mab-toggle');
    var e = document.getElementById('modo-admin-bar-expand');
    if (t) t.addEventListener('click', function () { setCollapsed(true); });
    if (e) e.addEventListener('click', function () { setCollapsed(false); });
})();
</script>
        <?php
    }

}
