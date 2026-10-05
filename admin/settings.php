<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Database;
use Core\Security;
use Core\Router;
use Core\I18n;
use Core\Sitemap;
use Core\DomainMigration;

Auth::requireCapability('manage_settings');

$db = Database::getConnection();
$saved = false;
$migrationReport = null;
$migrationError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token');
    }

    // ---------------------------------------------------------------------
    // Domain migration tool (rendered as a separate form on this page)
    // ---------------------------------------------------------------------
    if (($_POST['action'] ?? '') === 'domain_migration') {
        $oldDomain = trim($_POST['old_domain'] ?? '');
        $newDomain = trim($_POST['new_domain'] ?? '');
        $includeWww = isset($_POST['include_www']);
        $isPreview = ($_POST['mode'] ?? '') === 'preview';

        try {
            if ($isPreview) {
                $migrationReport = [
                    'mode'  => 'preview',
                    'stats' => DomainMigration::preview($oldDomain, $newDomain, $includeWww),
                ];
            } else {
                $migrationReport = [
                    'mode'  => 'run',
                    'stats' => DomainMigration::migrate($oldDomain, $newDomain, $includeWww),
                ];
            }
        } catch (\Throwable $e) {
            $migrationError = $e->getMessage();
        }
    } else {
        // -------------------------------------------------------------
        // Standard settings save
        // -------------------------------------------------------------
        $multilingual = isset($_POST['multilingual_frontend']) ? '1' : '0';

        $settings = [
            'site_title'             => trim($_POST['site_title'] ?? ''),
            'site_description'       => trim($_POST['site_description'] ?? ''),
            'site_url'               => trim($_POST['site_url'] ?? ''),
            'posts_per_page'         => (string)max(1, (int)($_POST['posts_per_page'] ?? 6)),
            'active_theme'           => basename(trim($_POST['active_theme'] ?? 'default')),
            'homepage_type'          => in_array($_POST['homepage_type'] ?? '', ['page', 'posts'], true) ? $_POST['homepage_type'] : 'page',
            'homepage_page_id'       => (string)(int)($_POST['homepage_page_id'] ?? 0),
            'posts_page_id'          => (string)(int)($_POST['posts_page_id'] ?? 0),
            'multilingual_frontend'  => $multilingual,
            'default_language'       => trim($_POST['default_language'] ?? 'en'),
            'admin_language'         => trim($_POST['admin_language'] ?? 'en'),
            'site_logo'              => trim($_POST['site_logo'] ?? ''),
            'site_favicon'           => trim($_POST['site_favicon'] ?? ''),
            'og_default_image'       => trim($_POST['og_default_image'] ?? ''),
            'og_site_name'           => trim($_POST['og_site_name'] ?? ''),
            'tinymce_preset'         => trim($_POST['tinymce_preset'] ?? 'standard'),
            'tinymce_custom_toolbar' => trim($_POST['tinymce_custom_toolbar'] ?? ''),
            'custom_head_scripts'    => trim($_POST['custom_head_scripts'] ?? ''),
            'custom_footer_scripts'  => trim($_POST['custom_footer_scripts'] ?? '')
        ];

        $stmt = $db->prepare("
            INSERT INTO settings (key, value) 
            VALUES (:k, :v) 
            ON CONFLICT(key) DO UPDATE SET value = :v
        ");

        foreach ($settings as $k => $v) {
            $stmt->execute([':k' => $k, ':v' => $v]);
        }

        if (class_exists('Core\Sitemap')) {
            try {
                Sitemap::generate();
                Sitemap::generateRobotsTxt();
            } catch (\Throwable $e) {}
        }

        $saved = true;
    }
}

$themes = array_filter(glob(__DIR__ . '/../themes/*'), 'is_dir');
$basePrefix = class_exists('Core\Router') ? Router::getBaseSubdirectory() : '';

// Pobieranie opublikowanych stron statycznych do wyboru w ustawieniach
$availablePages = $db->query("
    SELECT id, title, slug, lang 
    FROM pages 
    WHERE type = 'page' AND status = 'published' 
    ORDER BY lang ASC, title ASC
")->fetchAll();

require_once __DIR__ . '/views/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= _e('System Settings') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px;"><?= _e('Configure global parameters, homepage display, analytics tags, editor, and localization') ?></p>
    </div>
</div>

<?php if ($saved): ?>
    <div class="card" style="border-left: 4px solid var(--success, #10b981); background: rgba(16, 185, 129, 0.08); padding: 12px 16px; margin-bottom: 24px; color: #34d399; font-weight: 500;">
        <?= _e('Settings saved successfully.') ?>
    </div>
<?php endif; ?>

<form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">

    <div style="display: flex; flex-direction: column; gap: 24px; width: 100%;">
        
        <!-- 1. Site Identity & Presentation -->
        <div class="card">
            <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 16px; border-bottom: 1px solid var(--border-subtle); padding-bottom: 10px; color: var(--text-main);">
                <?= _e('General Configuration') ?>
            </h3>

            <div class="form-group">
                <label class="form-label" for="site_title"><?= _e('Site Title') ?></label>
                <input class="form-control" type="text" id="site_title" name="site_title" value="<?= Security::sanitize(Router::getOption('site_title', 'Modo CMS')) ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="site_description"><?= _e('Tagline / Description') ?></label>
                <textarea class="form-control" id="site_description" name="site_description" rows="2"><?= Security::sanitize(Router::getOption('site_description', '')) ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label" for="site_url"><?= _e('Site Address (Domain)') ?></label>
                <input class="form-control" type="text" id="site_url" name="site_url" value="<?= Security::sanitize(Router::getOption('site_url', '')) ?>" placeholder="<?= Security::sanitize(Router::getSiteUrl()) ?>">
                <small style="font-size: 11px; color: var(--text-muted); display: block; margin-top: 5px; line-height: 1.4;">
                    <?= _e('Canonical domain used to build absolute URLs (canonical links, OpenGraph, sitemap and robots.txt). Enter just the domain (e.g. https://example.com) and the installation subfolder is added automatically, or the full address including the subfolder (e.g. https://example.com/modocms). Leave empty to auto-detect it from the current request.') ?>
                </small>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label" for="posts_per_page"><?= _e('Posts Per Page') ?></label>
                    <input class="form-control" type="number" id="posts_per_page" name="posts_per_page" min="1" max="50" value="<?= Security::sanitize(Router::getOption('posts_per_page', '6')) ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="active_theme"><?= _e('Active Theme') ?></label>
                    <select class="form-control" name="active_theme" id="active_theme">
                        <?php foreach ($themes as $th): $tName = basename($th); ?>
                            <option value="<?= $tName ?>" <?= Router::getOption('active_theme', 'default') === $tName ? 'selected' : '' ?>>
                                <?= Security::sanitize($tName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Homepage & Posts Reading Settings -->
            <?php 
                $homepageType = Router::getOption('homepage_type', 'page');
                $homePageId = (int)Router::getOption('homepage_page_id', 0);
                $postsPageId = (int)Router::getOption('posts_page_id', 0);
            ?>
            <div style="background: var(--bg-surface, #1e293b); padding: 16px; border-radius: var(--radius-sm, 8px); border: 1px solid var(--border-subtle); margin-top: 10px;">
                <label class="form-label" style="font-weight: 700; color: var(--text-main); margin-bottom: 10px;"><?= _e('Homepage Displays') ?></label>
                
                <div style="display: flex; gap: 20px; margin-bottom: 14px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px;">
                        <input type="radio" name="homepage_type" value="page" <?= $homepageType === 'page' ? 'checked' : '' ?> onchange="toggleHomepageDropdowns()">
                        <?= _e('A static page (select below)') ?>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px;">
                        <input type="radio" name="homepage_type" value="posts" <?= $homepageType === 'posts' ? 'checked' : '' ?> onchange="toggleHomepageDropdowns()">
                        <?= _e('Your latest blog posts') ?>
                    </label>
                </div>

                <div id="static-pages-selection" style="display: <?= $homepageType === 'page' ? 'grid' : 'none' ?>; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" for="homepage_page_id" style="font-size: 12px;"><?= _e('Homepage') ?></label>
                        <select class="form-control" name="homepage_page_id" id="homepage_page_id">
                            <option value="0">&mdash; <?= _e('Default (slug: "home")') ?> &mdash;</option>
                            <?php foreach ($availablePages as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= $homePageId === (int)$p['id'] ? 'selected' : '' ?>>
                                    <?= Security::sanitize($p['title']) ?> (/<?= Security::sanitize($p['slug']) ?>) [<?= strtoupper($p['lang']) ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" for="posts_page_id" style="font-size: 12px;"><?= _e('Posts Page (Blog)') ?></label>
                        <select class="form-control" name="posts_page_id" id="posts_page_id">
                            <option value="0">&mdash; <?= _e('Default (/blog)') ?> &mdash;</option>
                            <?php foreach ($availablePages as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= $postsPageId === (int)$p['id'] ? 'selected' : '' ?>>
                                    <?= Security::sanitize($p['title']) ?> (/<?= Security::sanitize($p['slug']) ?>) [<?= strtoupper($p['lang']) ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Regional & Localization Settings -->
        <div class="card">
            <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 16px; border-bottom: 1px solid var(--border-subtle); padding-bottom: 10px; color: var(--text-main);">
                <?= _e('Language & Regional Settings') ?>
            </h3>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label" for="default_language"><?= _e('Frontend Default Language') ?></label>
                    <select class="form-control" name="default_language" id="default_language">
                        <?php foreach (I18n::getAvailableLanguages() as $code => $name): ?>
                            <option value="<?= $code ?>" <?= Router::getOption('default_language', 'en') === $code ? 'selected' : '' ?>>
                                <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?> (<?= strtoupper($code) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="admin_language"><?= _e('Admin Panel Language') ?></label>
                    <select class="form-control" name="admin_language" id="admin_language">
                        <?php foreach (I18n::getAvailableLanguages() as $code => $name): ?>
                            <option value="<?= $code ?>" <?= Router::getOption('admin_language', 'en') === $code ? 'selected' : '' ?>>
                                <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?> (<?= strtoupper($code) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group" style="padding: 14px; background: var(--bg-surface, #1e293b); border-radius: var(--radius-sm); border: 1px solid var(--border-subtle); margin-top: 4px;">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-weight: 600; color: var(--text-main);">
                    <input type="checkbox" name="multilingual_frontend" value="1" <?= Router::getOption('multilingual_frontend', '0') === '1' ? 'checked' : '' ?>>
                    <?= _e('Enable Multilingual Frontend') ?>
                </label>
                <p style="font-size: 12px; color: var(--text-muted); margin-top: 6px; margin-left: 24px; line-height: 1.4;">
                    <?= _e('When disabled, the frontend routes straight to single-language URLs without language prefix segments.') ?>
                </p>
            </div>
        </div>

        <!-- 3. Branding Images (Logo & Favicon) -->
        <div class="card">
            <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 16px; border-bottom: 1px solid var(--border-subtle); padding-bottom: 10px; color: var(--text-main);">
                <?= _e('Branding Assets') ?>
            </h3>

            <!-- Logo Section -->
            <div class="form-group" style="margin-bottom: 20px;">
                <label class="form-label"><?= _e('Website Logo') ?></label>
                <div style="display: flex; align-items: center; gap: 14px; margin-bottom: 8px;">
                    <div id="logo-preview-box" style="width: 140px; height: 50px; background: var(--bg-surface, #1e293b); border: 1px solid var(--border-subtle); border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; overflow: hidden; padding: 4px;">
                        <?php $currentLogo = Router::getOption('site_logo', ''); ?>
                        <img id="logo-preview-img" src="<?= $currentLogo ? htmlspecialchars($currentLogo, ENT_QUOTES, 'UTF-8') : '' ?>" alt="Logo" style="max-width: 100%; max-height: 100%; object-fit: contain; <?= empty($currentLogo) ? 'display:none;' : '' ?>">
                        <span id="logo-placeholder" style="font-size: 11px; color: var(--text-muted); <?= !empty($currentLogo) ? 'display:none;' : '' ?>"><?= _e('No Logo') ?></span>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <input type="hidden" name="site_logo" id="site_logo" value="<?= htmlspecialchars($currentLogo, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="button" class="btn btn-secondary" style="font-size: 12px;" onclick="triggerUpload('site_logo', 'logo-preview-img', 'logo-placeholder')"><?= _e('Change') ?></button>
                        <button type="button" class="btn btn-danger-ghost" style="font-size: 12px;" onclick="clearAsset('site_logo', 'logo-preview-img', 'logo-placeholder')"><?= _e('Remove') ?></button>
                    </div>
                </div>
                <small style="font-size: 11px; color: var(--text-muted);"><?= _e('Recommended formats: SVG or transparent PNG') ?></small>
            </div>

            <!-- Favicon Section -->
            <div class="form-group">
                <label class="form-label"><?= _e('Favicon (.ico, .png, .svg)') ?></label>
                <div style="display: flex; align-items: center; gap: 14px; margin-bottom: 8px;">
                    <div id="favicon-preview-box" style="width: 44px; height: 44px; background: var(--bg-surface, #1e293b); border: 1px solid var(--border-subtle); border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; overflow: hidden;">
                        <?php $currentFavicon = Router::getOption('site_favicon', ''); ?>
                        <img id="favicon-preview-img" src="<?= $currentFavicon ? htmlspecialchars($currentFavicon, ENT_QUOTES, 'UTF-8') : '' ?>" alt="Favicon" style="width: 24px; height: 24px; object-fit: contain; <?= empty($currentFavicon) ? 'display:none;' : '' ?>">
                        <span id="favicon-placeholder" style="font-size: 10px; color: var(--text-muted); <?= !empty($currentFavicon) ? 'display:none;' : '' ?>"><?= _e('None') ?></span>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <input type="hidden" name="site_favicon" id="site_favicon" value="<?= htmlspecialchars($currentFavicon, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="button" class="btn btn-secondary" style="font-size: 12px;" onclick="triggerUpload('site_favicon', 'favicon-preview-img', 'favicon-placeholder')"><?= _e('Change') ?></button>
                        <button type="button" class="btn btn-danger-ghost" style="font-size: 12px;" onclick="clearAsset('site_favicon', 'favicon-preview-img', 'favicon-placeholder')"><?= _e('Remove') ?></button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. OpenGraph Social Metadata -->
        <div class="card">
            <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 16px; border-bottom: 1px solid var(--border-subtle); padding-bottom: 10px; color: var(--text-main);">
                <?= _e('OpenGraph & Social Sharing') ?>
            </h3>

            <div class="form-group">
                <label class="form-label" for="og_site_name"><?= _e('OG Site Name') ?></label>
                <input class="form-control" type="text" id="og_site_name" name="og_site_name" value="<?= Security::sanitize(Router::getOption('og_site_name', '')) ?>" placeholder="<?= _e('e.g. My Organization Brand') ?>">
            </div>

            <div class="form-group">
                <label class="form-label"><?= _e('Default Social Share Image (OG Image)') ?></label>
                <div style="margin-bottom: 10px;">
                    <?php $currentOgImage = Router::getOption('og_default_image', ''); ?>
                    <div id="og-preview-box" style="width: 100%; max-width: 480px; height: 160px; background: var(--bg-surface, #1e293b); border: 1px solid var(--border-subtle); border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; overflow: hidden; margin-bottom: 10px;">
                        <img id="og-preview-img" src="<?= $currentOgImage ? htmlspecialchars($currentOgImage, ENT_QUOTES, 'UTF-8') : '' ?>" alt="OG Preview" style="width: 100%; height: 100%; object-fit: cover; <?= empty($currentOgImage) ? 'display:none;' : '' ?>">
                        <span id="og-placeholder" style="font-size: 12px; color: var(--text-muted); <?= !empty($currentOgImage) ? 'display:none;' : '' ?>"><?= _e('No Social Card Configured') ?></span>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <input type="hidden" name="og_default_image" id="og_default_image" value="<?= htmlspecialchars($currentOgImage, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="button" class="btn btn-secondary" style="font-size: 12px;" onclick="triggerUpload('og_default_image', 'og-preview-img', 'og-placeholder')"><?= _e('Select Image') ?></button>
                        <button type="button" class="btn btn-danger-ghost" style="font-size: 12px;" onclick="clearAsset('og_default_image', 'og-preview-img', 'og-placeholder')"><?= _e('Remove') ?></button>
                    </div>
                </div>
                <small style="font-size: 11px; color: var(--text-muted);"><?= _e('Recommended dimension: 1200x630px JPG or PNG') ?></small>
            </div>
        </div>

        <!-- 5. Content Editor (TinyMCE) Toolbar Configuration -->
        <div class="card">
            <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 16px; border-bottom: 1px solid var(--border-subtle); padding-bottom: 10px; color: var(--text-main);">
                <?= _e('WYSIWYG Editor Configuration') ?>
            </h3>

            <?php 
                $currentPreset = Router::getOption('tinymce_preset', 'standard'); 
                $currentCustom = Router::getOption('tinymce_custom_toolbar', '');
            ?>

            <div class="form-group">
                <label class="form-label" for="tinymce_preset"><?= _e('Toolbar Preset') ?></label>
                <select class="form-control" name="tinymce_preset" id="tinymce_preset">
                    <option value="basic" <?= $currentPreset === 'basic' ? 'selected' : '' ?>>
                        <?= _e('Basic (Headings, bold, italic, lists, link)') ?>
                    </option>
                    <option value="standard" <?= $currentPreset === 'standard' ? 'selected' : '' ?>>
                        <?= _e('Standard (Headings, formatting, lists, media, table, code, fullscreen)') ?>
                    </option>
                    <option value="advanced" <?= $currentPreset === 'advanced' ? 'selected' : '' ?>>
                        <?= _e('Advanced (Full dual-row toolbar, colors, sub/superscript, media, tables)') ?>
                    </option>
                    <option value="custom" <?= $currentPreset === 'custom' ? 'selected' : '' ?>>
                        <?= _e('Custom Toolbar Configuration') ?>
                    </option>
                </select>
            </div>

            <div class="form-group" id="custom-toolbar-group" style="<?= $currentPreset === 'custom' ? '' : 'display: none;' ?>">
                <label class="form-label" for="tinymce_custom_toolbar"><?= _e('Custom Toolbar String') ?></label>
                <textarea class="form-control" id="tinymce_custom_toolbar" name="tinymce_custom_toolbar" rows="3" placeholder="undo redo | blocks | bold italic | alignleft aligncenter | bullist numlist | link mediamanager | code"><?= Security::sanitize($currentCustom) ?></textarea>
                <small style="font-size: 11px; color: var(--text-muted); display: block; margin-top: 4px; line-height: 1.4;">
                    <?= _e('Use pipe (|) to group items. Available tokens: undo, redo, blocks, bold, italic, underline, strikethrough, alignleft, aligncenter, alignright, alignjustify, bullist, numlist, outdent, indent, link, image, mediamanager, table, forecolor, backcolor, removeformat, code, fullscreen.') ?>
                </small>
            </div>
        </div>

        <!-- 6. Custom Scripts & Tracking (Google Analytics, Search Console, Pixels) -->
        <div class="card">
            <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 16px; border-bottom: 1px solid var(--border-subtle); padding-bottom: 10px; color: var(--text-main);">
                <?= _e('Custom Scripts & Tracking (SEO / Analytics)') ?>
            </h3>

            <div class="form-group">
                <label class="form-label" for="custom_head_scripts">
                    <?= _e('Header Scripts (Inside <head>)') ?>
                </label>
                <textarea class="form-control" id="custom_head_scripts" name="custom_head_scripts" rows="6" style="font-family: monospace; font-size: 12px; line-height: 1.4;" placeholder="<!-- Google tag (gtag.js) -->&#10;<script async src=&quot;https://www.googletagmanager.com/gtag/js?id=G-XXXXX&quot;></script>&#10;<meta name=&quot;google-site-verification&quot; content=&quot;...&quot; />"><?= htmlspecialchars(Router::getOption('custom_head_scripts', ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                <small style="font-size: 11px; color: var(--text-muted); display: block; margin-top: 5px; line-height: 1.4;">
                    <?= _e('Injected before </head>. Suitable for Google Analytics, Google Tag Manager, Google Search Console meta verification tags, and custom CSS.') ?>
                </small>
            </div>

            <div class="form-group" style="margin-top: 16px;">
                <label class="form-label" for="custom_footer_scripts">
                    <?= _e('Footer Scripts (Before </body>)') ?>
                </label>
                <textarea class="form-control" id="custom_footer_scripts" name="custom_footer_scripts" rows="5" style="font-family: monospace; font-size: 12px; line-height: 1.4;" placeholder="<script>&#10;  // Custom tracking or chat widget&#10;</script>"><?= htmlspecialchars(Router::getOption('custom_footer_scripts', ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                <small style="font-size: 11px; color: var(--text-muted); display: block; margin-top: 5px; line-height: 1.4;">
                    <?= _e('Injected immediately before </body>. Suitable for live chat scripts, conversion tracking, or deferred JavaScript.') ?>
                </small>
            </div>
        </div>

        <!-- Submit Button Card -->
        <div class="card" style="display: flex; justify-content: flex-end;">
            <button type="submit" class="btn btn-primary" style="padding: 10px 28px; font-size: 14px;">
                <?= _e('Save Configuration') ?>
            </button>
        </div>

    </div>

    <!-- Hidden Generic File Input for AJAX Uploads -->
    <input type="file" id="settings-file-picker" accept="image/*" style="display: none;">
</form>
<!-- ===================== Domain Migration Tool ===================== -->
<div class="card" style="margin-top: 24px;">
    <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 16px; border-bottom: 1px solid var(--border-subtle); padding-bottom: 10px; color: var(--text-main);">
        <?= _e('Domain Migration') ?>
    </h3>
    <p style="font-size: 12px; color: var(--text-muted); line-height: 1.5; margin-bottom: 16px;">
        <?= _e('Replace every occurrence of an old domain with a new one inside pages, posts, menus, custom fields, settings and theme options. Ideal after moving the site, e.g. from localhost to a live domain. The subfolder may be included, e.g. old localhost/modocms to new example.com.') ?>
    </p>

    <?php if ($migrationError !== ''): ?>
        <div class="card" style="border-left: 4px solid var(--danger, #ef4444); background: rgba(239, 68, 68, 0.08); padding: 12px 16px; margin-bottom: 16px; color: #f87171; font-weight: 500;">
            <?= htmlspecialchars($migrationError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($migrationReport !== null): ?>
        <?php
            $totalAffected = array_sum($migrationReport['stats']);
            $tableLabels = [
                'pages'      => __('Pages & Posts'),
                'page_meta'  => __('Custom Fields'),
                'menu_items' => __('Menus'),
                'settings'   => __('Settings'),
                'theme_mods' => __('Theme Options'),
            ];
        ?>
        <div class="card" style="border-left: 4px solid <?= $migrationReport['mode'] === 'run' ? 'var(--success, #10b981)' : 'var(--primary, #6366f1)' ?>; background: rgba(99, 102, 241, 0.06); padding: 14px 16px; margin-bottom: 16px;">
            <strong><?= $migrationReport['mode'] === 'run' ? _e('Domain migration completed.') : _e('Preview result (nothing was changed yet):') ?></strong>
            <?php if ($totalAffected === 0): ?>
                <p style="margin-top: 8px; font-size: 13px;"><?= _e('No occurrences found.') ?></p>
            <?php else: ?>
                <ul style="margin: 10px 0 0 0; padding-left: 18px; font-size: 13px; line-height: 1.7;">
                    <?php foreach ($migrationReport['stats'] as $table => $count): ?>
                        <li><?= htmlspecialchars($tableLabels[$table] ?? $table, ENT_QUOTES, 'UTF-8') ?>: <strong><?= (int)$count ?></strong></li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($migrationReport['mode'] === 'preview'): ?>
                    <p style="margin-top: 10px; font-size: 12px; color: var(--text-muted);"><?= _e('Use the "Run Migration" button below to apply these changes.') ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
        <input type="hidden" name="action" value="domain_migration">

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <div class="form-group">
                <label class="form-label" for="old_domain"><?= _e('Old Domain') ?></label>
                <input class="form-control" type="text" id="old_domain" name="old_domain" placeholder="localhost:8000" value="<?= Security::sanitize($_POST['old_domain'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="new_domain"><?= _e('New Domain') ?></label>
                <input class="form-control" type="text" id="new_domain" name="new_domain" placeholder="example.com" value="<?= Security::sanitize($_POST['new_domain'] ?? '') ?>" required>
            </div>
        </div>

        <div class="form-group" style="padding: 12px 14px; background: var(--bg-surface, #1e293b); border-radius: var(--radius-sm); border: 1px solid var(--border-subtle);">
            <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; font-size: 13px; font-weight: 600; color: var(--text-main);">
                <input type="checkbox" name="include_www" value="1" <?= isset($_POST['include_www']) ? 'checked' : '' ?>>
                <?= _e('Also migrate www / non-www variants') ?>
            </label>
            <p style="font-size: 11px; color: var(--text-muted); margin-top: 6px; margin-left: 24px; line-height: 1.4;">
                <?= _e('Enable this to also rewrite links that use the opposite www. prefix.') ?>
            </p>
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <button type="submit" name="mode" value="preview" class="btn btn-secondary"><?= _e('Preview Changes') ?></button>
            <button type="submit" name="mode" value="run" class="btn btn-primary"
                    onclick="return confirm('<?= _e('This will permanently rewrite the selected content. Continue?') ?>');">
                <?= _e('Run Migration') ?>
            </button>
        </div>
    </form>
</div>



<script>
function toggleHomepageDropdowns() {
    const isPage = document.querySelector('input[name="homepage_type"]:checked')?.value === 'page';
    const box = document.getElementById('static-pages-selection');
    if (box) {
        box.style.display = isPage ? 'grid' : 'none';
    }
}

let currentTargetField = null;
let currentPreviewImg = null;
let currentPlaceholder = null;

const filePicker = document.getElementById('settings-file-picker');
const presetSelect = document.getElementById('tinymce_preset');
const customToolbarGroup = document.getElementById('custom-toolbar-group');

if (presetSelect && customToolbarGroup) {
    presetSelect.addEventListener('change', () => {
        if (presetSelect.value === 'custom') {
            customToolbarGroup.style.display = 'block';
        } else {
            customToolbarGroup.style.display = 'none';
        }
    });
}

function triggerUpload(fieldId, previewImgId, placeholderId) {
    currentTargetField = document.getElementById(fieldId);
    currentPreviewImg = document.getElementById(previewImgId);
    currentPlaceholder = document.getElementById(placeholderId);
    filePicker.value = '';
    filePicker.click();
}

function clearAsset(fieldId, previewImgId, placeholderId) {
    document.getElementById(fieldId).value = '';
    const img = document.getElementById(previewImgId);
    const placeholder = document.getElementById(placeholderId);
    img.src = '';
    img.style.display = 'none';
    placeholder.style.display = 'block';
}

filePicker.addEventListener('change', () => {
    if (!filePicker.files.length || !currentTargetField) return;

    const formData = new FormData();
    formData.append('file', filePicker.files[0]);

    fetch('upload.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.location) {
            currentTargetField.value = data.location;
            currentPreviewImg.src = data.location;
            currentPreviewImg.style.display = 'block';
            currentPlaceholder.style.display = 'none';
        } else {
            alert(data.error || '<?= _e('File upload failed.') ?>');
        }
    })
    .catch(() => {
        alert('<?= _e('Network error occurred while uploading.') ?>');
    });
});
</script>

<?php require_once __DIR__ . '/views/footer.php'; ?>