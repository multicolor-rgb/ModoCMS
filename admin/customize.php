<?php
declare(strict_types=1);

define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Security;
use Core\Router;
use Core\Customizer;

Auth::requireCapability('manage_settings');

// Fresh live-preview token for this session (validated on the frontend).
$previewToken = Customizer::startPreview();
$theme = Customizer::activeTheme();

$schema = Customizer::getSchema($theme);
$values = Customizer::getMods($theme);

// Prefill the form from the session draft (if any) so a page reload keeps the
// unsaved selections, matching what the live preview iframe already reflects.
$draft = Customizer::getDraft($theme);
if (!empty($draft)) {
    $values = array_merge($values, $draft);
}

$storedSchema = Customizer::getStoredSchema($theme);

// Map of control id => CSS custom property, so the preview can update colours
// and sizes instantly without a full reload.
$cssMap = [];
foreach ($schema['sections'] as $section) {
    foreach ($section['controls'] as $control) {
        if (($control['css_var'] ?? '') !== '') {
            $cssMap[$control['id']] = ['var' => $control['css_var'], 'unit' => $control['css_unit']];
        }
    }
}

$base = Router::getBaseSubdirectory();
$previewUrl = ($base !== '' ? $base : '') . '/?modo_preview=' . urlencode($previewToken);

require_once __DIR__ . '/views/header.php';

?>
<link rel="stylesheet" href="assets/css/customizer.css">
<div class="modo-customizer" id="modo-customizer">
    <header class="modo-cz-topbar">
        <div class="modo-cz-topbar-left">
            <div>
                <div class="modo-cz-title"><?= _e('Customizer') ?></div>
                <div class="modo-cz-subtitle"><?= htmlspecialchars($theme, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
        <div class="modo-cz-topbar-center">
            <div class="modo-cz-devices">
                <button type="button" class="modo-cz-device active" data-device="desktop"><?= _e('Desktop') ?></button>
                <button type="button" class="modo-cz-device" data-device="tablet"><?= _e('Tablet') ?></button>
                <button type="button" class="modo-cz-device" data-device="mobile"><?= _e('Mobile') ?></button>
            </div>
        </div>
        <div class="modo-cz-topbar-right">
            <span class="modo-cz-status" id="modo-cz-status"></span>
            <button type="button" class="btn btn-secondary" id="modo-cz-reset"><?= _e('Reset') ?></button>
            <button type="button" class="btn btn-primary" id="modo-cz-publish"><?= _e('Publish') ?></button>
        </div>
    </header>

    <div class="modo-cz-body">
        <aside class="modo-cz-sidebar">
            <div class="modo-cz-tabs">
                <button type="button" class="modo-cz-tab active" data-tab="customize"><?= _e('Customize') ?></button>
                <button type="button" class="modo-cz-tab" data-tab="build"><?= _e('Manage settings') ?></button>
            </div>

            <div class="modo-cz-panel" data-panel="customize">
                <?php require __DIR__ . "/views/customize-panel.php"; ?>
            </div>

            <div class="modo-cz-panel" data-panel="build" hidden>
                <p class="modo-cz-section-desc">
                    <?= _e('Create your own sections and fields. Theme and plugin settings are read-only; settings created here are editable and stored in the database.') ?>
                </p>
                <div id="modo-cz-builder"></div>
                <div class="modo-cz-builder-actions">
                    <button type="button" class="btn btn-secondary" id="modo-cz-add-section">+ <?= _e('Add section') ?></button>
                    <button type="button" class="btn btn-primary" id="modo-cz-save-schema"><?= _e('Save settings') ?></button>
                </div>
            </div>
        </aside>

        <section class="modo-cz-preview">
            <div class="modo-cz-frame-wrap" data-device="desktop">
                <iframe id="modo-cz-frame" src="<?= htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8') ?>" title="<?= _e('Live preview') ?>"></iframe>
            </div>
        </section>
    </div>
</div>

<script>
    window.MODO_CUSTOMIZER = {
        token: <?= json_encode($previewToken) ?>,
        theme: <?= json_encode($theme) ?>,
        csrf: <?= json_encode(Security::generateCsrfToken()) ?>,
        storedSchema: <?= json_encode($storedSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        cssMap: <?= json_encode($cssMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        controlTypes: <?= json_encode(Customizer::CONTROL_TYPES) ?>,
        labels: <?= json_encode([
            'saved' => __('Draft saved'),
            'published' => __('Changes published'),
            'reset' => __('Reset to defaults'),
            'error' => __('Something went wrong'),
            'uploadFailed' => __('File upload failed.'),
            'confirmReset' => __('Reset all customizer values to defaults?'),
            'section' => __('Section'),
            'field' => __('Field'),
            'addField' => __('Add field'),
            'id' => __('ID'),
            'type' => __('Type'),
            'label' => __('Label'),
            'default' => __('Default'),
            'description' => __('Description'),
            'cssVar' => __('CSS variable'),
            'cssUnit' => __('CSS unit'),
            'options' => __('Options'),
            'priority' => __('Priority'),
            'title' => __('Title'),
            'schemaSaved' => __('Settings saved'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    };
</script>
<script src="assets/js/customizer.js"></script>
<?php require_once __DIR__ . '/views/footer.php'; ?>

