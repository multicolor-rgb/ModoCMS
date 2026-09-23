<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Database;
use Core\Security;
use Core\Router;
use Core\I18n;

Auth::requireCapability('manage_settings');

$db = Database::getConnection();
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token');
    }

    $multilingual = isset($_POST['multilingual_frontend']) ? '1' : '0';

    $settings = [
        'site_title'             => trim($_POST['site_title'] ?? ''),
        'site_description'       => trim($_POST['site_description'] ?? ''),
        'posts_per_page'         => (string)max(1, (int)($_POST['posts_per_page'] ?? 6)),
        'active_theme'           => basename(trim($_POST['active_theme'] ?? 'default')),
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
    
    $saved = true;
}

$themes = array_filter(glob(__DIR__ . '/../themes/*'), 'is_dir');
$basePrefix = class_exists('Core\Router') ? Router::getBaseSubdirectory() : '';

require_once __DIR__ . '/views/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= _e('System Settings') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px;"><?= _e('Configure global parameters, branding, analytics tags, editor settings, and localization') ?></p>
    </div>
</div>

<?php if ($saved): ?>
    <div class="card" style="border-left: 4px solid var(--success); padding: 12px; margin-bottom: 24px;">
        <?= _e('Settings saved successfully.') ?>
    </div>
<?php endif; ?>

<form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">

    <!-- Single Column Full Width Layout -->
    <div style="display: flex; flex-direction: column; gap: 24px; width: 100%;">
        
        <!-- 1. Site Identity & Presentation -->
        <div class="card">
            <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 16px; border-bottom: 1px solid var(--border-subtle); padding-bottom: 10px; color: var(--text-main);">
                <?= _e('General Configuration') ?>
            </h3>

            <div class="form-group">
                <label class="form-label" for="site_title"><?= _e('Site Title') ?></label>
                <input class="form-control" type="text" id="site_title" name="site_title" value="<?= Security::sanitize(Router::getOption('site_title', 'Clean CMS')) ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="site_description"><?= _e('Tagline / Description') ?></label>
                <textarea class="form-control" id="site_description" name="site_description" rows="2"><?= Security::sanitize(Router::getOption('site_description', '')) ?></textarea>
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

<script>
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