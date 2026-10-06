<?php
declare(strict_types=1);

define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Security;
use Core\Customizer;

Auth::requireCapability('manage_settings');

// Backend scope: schema management only ("Manage settings"). Editing the
// actual values (with live preview) happens on the frontend via the
// Core\CustomizerPanel sidebar for logged-in administrators.
$theme = Customizer::activeTheme();
$storedSchema = Customizer::getStoredSchema($theme);

require_once __DIR__ . '/views/header.php';

?>
<link rel="stylesheet" href="assets/css/customizer.css">
<div class="modo-customizer" id="modo-customizer">
    <header class="modo-cz-topbar">
        <div class="modo-cz-topbar-left">
            <div>
                <div class="modo-cz-title"><?= _e('Manage settings') ?></div>
                <div class="modo-cz-subtitle"><?= htmlspecialchars($theme, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
        <div class="modo-cz-topbar-right">
            <span class="modo-cz-status" id="modo-cz-status"></span>
            <button type="button" class="btn btn-primary" id="modo-cz-save-schema"><?= _e('Save settings') ?></button>
        </div>
    </header>

    <div class="modo-cz-body">
        <aside class="modo-cz-sidebar">
            <div class="modo-cz-panel" data-panel="build">
                <p class="modo-cz-section-desc">
                    <?= _e('Create your own sections and fields. Theme and plugin settings are read-only; settings created here are editable and stored in the database.') ?>
                </p>
                <div id="modo-cz-builder"></div>
                <div class="modo-cz-builder-actions">
                    <button type="button" class="btn btn-secondary" id="modo-cz-add-section">+ <?= _e('Add section') ?></button>
                </div>
            </div>
        </aside>
    </div>
</div>

<script>
    window.MODO_CUSTOMIZER = {
        theme: <?= json_encode($theme) ?>,
        csrf: <?= json_encode(Security::generateCsrfToken()) ?>,
        storedSchema: <?= json_encode($storedSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        controlTypes: <?= json_encode(Customizer::CONTROL_TYPES) ?>,
        labels: <?= json_encode([
            'error' => __('Something went wrong'),
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

