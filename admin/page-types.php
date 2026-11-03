<?php
declare(strict_types=1);

define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Database;
use Core\PageTypes;
use Core\Security;

Auth::requireCapability('manage_pages');

$db = Database::getConnection();

$message = '';
$messageType = 'success';

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$form = [
    'id'          => 0,
    'name'        => '',
    'slug'        => '',
    'description' => '',
    'base_type'   => 'page',
    'sort_order'  => 0,
    'fields'      => [],
];

$fieldTypeLabels = [
    'text'     => __('Text'),
    'textarea' => __('Textarea'),
    'richtext' => __('Rich Text'),
    'tinymce'  => __('TinyMCE (WYSIWYG)'),
    'gallery'  => __('Gallery'),
    'image'    => __('Image'),
    'checkbox' => __('Checkbox'),
    'number'   => __('Number'),
    'html'     => __('HTML'),
    'php'      => __('PHP'),
    'js'       => __('JavaScript'),
    'css'      => __('CSS'),
    'json'     => __('JSON'),
    'code'     => __('Code'),
];

/**
 * Renders one editable field definition row.
 */
if (!function_exists('renderTypeFieldRow')) {
    function renderTypeFieldRow(array $field = [], array $fieldTypes = []): string
    {
        $key = htmlspecialchars((string) ($field['field_key'] ?? $field['key'] ?? ''), ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars((string) ($field['label'] ?? ''), ENT_QUOTES, 'UTF-8');
        $default = htmlspecialchars((string) ($field['default_value'] ?? $field['value'] ?? ''), ENT_QUOTES, 'UTF-8');
        $type = (string) ($field['field_type'] ?? $field['type'] ?? 'text');
        if (!isset($fieldTypes[$type])) {
            $type = 'text';
        }
        $required = !empty($field['required']) ? '1' : '0';
        $requiredNo = $required === '0' ? 'selected' : '';
        $requiredYes = $required === '1' ? 'selected' : '';

        $options = '';
        foreach ($fieldTypes as $typeValue => $typeLabel) {
            $options .= '<option value="' . htmlspecialchars($typeValue, ENT_QUOTES, 'UTF-8') . '"'
                . ($typeValue === $type ? ' selected' : '') . '>'
                . htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') . '</option>';
        }

        $removeLabel = htmlspecialchars(__('Remove'), ENT_QUOTES, 'UTF-8');
        $upLabel = htmlspecialchars(__('Move up'), ENT_QUOTES, 'UTF-8');
        $downLabel = htmlspecialchars(__('Move down'), ENT_QUOTES, 'UTF-8');
        $keyLabel = htmlspecialchars(__('Field key'), ENT_QUOTES, 'UTF-8');
        $labelLabel = htmlspecialchars(__('Label'), ENT_QUOTES, 'UTF-8');
        $typeLabel = htmlspecialchars(__('Type'), ENT_QUOTES, 'UTF-8');
        $defaultLabel = htmlspecialchars(__('Default value'), ENT_QUOTES, 'UTF-8');
        $requiredLabel = htmlspecialchars(__('Required'), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<div class="pt-field-row" style="border: 1px solid var(--border-subtle); border-radius: var(--radius-sm, 8px); padding: 12px; display: flex; flex-direction: column; gap: 8px; background: var(--bg-surface, #1e293b);">
    <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
        <input class="form-control pt-field-key" type="text" name="fields_key[]" value="{$key}" placeholder="{$keyLabel}" style="flex: 1 1 140px; min-width: 110px; font-size: 13px;">
        <input class="form-control pt-field-label" type="text" name="fields_label[]" value="{$label}" placeholder="{$labelLabel}" style="flex: 1 1 160px; min-width: 120px; font-size: 13px;">
        <select class="form-control pt-field-type" name="fields_type[]" style="flex: 0 0 150px; font-size: 13px;">{$options}</select>
    </div>
    <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
        <input class="form-control pt-field-default" type="text" name="fields_default[]" value="{$default}" placeholder="{$defaultLabel}" style="flex: 1 1 220px; min-width: 160px; font-size: 13px;">
        <select class="form-control pt-field-required" name="fields_required[]" style="flex: 0 0 120px; font-size: 13px;" title="{$requiredLabel}">
            <option value="0" {$requiredNo}>{$requiredLabel}: &times;</option>
            <option value="1" {$requiredYes}>{$requiredLabel}: &#10003;</option>
        </select>
        <div style="display: flex; gap: 4px; margin-left: auto;">
            <button type="button" class="btn btn-secondary pt-field-up" title="{$upLabel}" style="padding: 4px 9px; font-size: 13px; line-height: 1;">&uarr;</button>
            <button type="button" class="btn btn-secondary pt-field-down" title="{$downLabel}" style="padding: 4px 9px; font-size: 13px; line-height: 1;">&darr;</button>
            <button type="button" class="btn btn-danger-ghost pt-field-remove" title="{$removeLabel}" style="padding: 4px 9px; font-size: 15px; line-height: 1;">&times;</button>
        </div>
    </div>
</div>
HTML;
    }
}

// Handle create / update.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token');
    }

    $postedFields = [];
    $keys = $_POST['fields_key'] ?? [];
    if (is_array($keys)) {
        $labels = $_POST['fields_label'] ?? [];
        $types = $_POST['fields_type'] ?? [];
        $defaults = $_POST['fields_default'] ?? [];
        $required = $_POST['fields_required'] ?? [];
        foreach (array_values($keys) as $i => $key) {
            $postedFields[] = [
                'field_key'     => (string) $key,
                'label'         => (string) ($labels[$i] ?? ''),
                'field_type'    => (string) ($types[$i] ?? 'text'),
                'default_value' => (string) ($defaults[$i] ?? ''),
                'required'      => (int) ($required[$i] ?? 0),
            ];
        }
    }

    $form = [
        'id'          => (int) ($_POST['id'] ?? 0),
        'name'        => trim((string) ($_POST['name'] ?? '')),
        'slug'        => trim((string) ($_POST['slug'] ?? '')),
        'description' => trim((string) ($_POST['description'] ?? '')),
        'base_type'   => PageTypes::normalizeBaseType((string) ($_POST['base_type'] ?? 'page')),
        'sort_order'  => (int) ($_POST['sort_order'] ?? 0),
        'fields'      => $postedFields,
    ];

    try {
        $savedId = PageTypes::save($form, $postedFields);
        header('Location: page-types.php?edit=' . $savedId . '&saved=1');
        exit;
    } catch (\InvalidArgumentException $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    } catch (\PDOException $e) {
        $message = __('A page type with that slug already exists.');
        $messageType = 'danger';
    }
}

// Handle delete.
if (isset($_GET['delete'], $_GET['csrf']) && Security::verifyCsrfToken((string) $_GET['csrf'])) {
    PageTypes::delete((int) $_GET['delete']);
    header('Location: page-types.php?deleted=1');
    exit;
}

// Handle duplicate.
if (isset($_GET['duplicate'], $_GET['csrf']) && Security::verifyCsrfToken((string) $_GET['csrf'])) {
    $newId = PageTypes::duplicate((int) $_GET['duplicate']);
    if ($newId !== null) {
        header('Location: page-types.php?edit=' . $newId . '&duplicated=1');
        exit;
    }
    header('Location: page-types.php');
    exit;
}

// Load the type being edited (not on POST, so validation errors keep values).
if ($editId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $found = PageTypes::find($editId);
    if ($found) {
        $form = [
            'id'          => (int) $found['id'],
            'name'        => (string) $found['name'],
            'slug'        => (string) $found['slug'],
            'description' => (string) ($found['description'] ?? ''),
            'base_type'   => (string) $found['base_type'],
            'sort_order'  => (int) $found['sort_order'],
            'fields'      => $found['fields'],
        ];
    }
}

$allTypes = $db->query("
    SELECT t.*,
        (SELECT COUNT(*) FROM page_type_fields f WHERE f.type_id = t.id) AS field_count,
        (SELECT COUNT(*) FROM pages p WHERE p.page_type_id = t.id) AS usage_count
    FROM page_types t
    ORDER BY t.sort_order ASC, t.name ASC
")->fetchAll();

require_once __DIR__ . '/views/header.php';
?>

<div class="page-header" style="margin-bottom: 24px;">
    <div>
        <h1 class="page-title"><?= _e('Page Types') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px; max-width: 760px;">
            <?= _e('Define reusable content presets with default custom fields. Page types can be based on static pages or blog posts, and are available while creating or editing content.') ?>
        </p>
    </div>
    <?php if ($form['id'] > 0): ?>
        <a href="page-types.php" class="btn btn-secondary">+ <?= _e('New Page Type') ?></a>
    <?php endif; ?>
</div>

<?php if (isset($_GET['saved']) && $message === ''): ?>
    <div class="card" style="border-left: 4px solid var(--success, #10b981); background: rgba(16, 185, 129, 0.08); padding: 12px 16px; margin-bottom: 20px; color: #34d399; font-weight: 500;">
        <?= _e('Page type saved successfully.') ?>
    </div>
<?php elseif (isset($_GET['deleted'])): ?>
    <div class="card" style="border-left: 4px solid var(--success, #10b981); background: rgba(16, 185, 129, 0.08); padding: 12px 16px; margin-bottom: 20px; color: #34d399; font-weight: 500;">
        <?= _e('Page type deleted. Documents that used it were kept and detached from the type.') ?>
    </div>
<?php elseif (isset($_GET['duplicated'])): ?>
    <div class="card" style="border-left: 4px solid var(--success, #10b981); background: rgba(16, 185, 129, 0.08); padding: 12px 16px; margin-bottom: 20px; color: #34d399; font-weight: 500;">
        <?= _e('Page type duplicated successfully.') ?>
    </div>
<?php endif; ?>

<?php if ($message !== ''): ?>
    <div class="card" style="border-left: 4px solid var(--danger, #ef4444); padding: 12px 16px; margin-bottom: 20px;">
        <?= Security::sanitize($message) ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr); gap: 24px; align-items: start;">

    <div class="card" style="padding: 24px;">
        <h2 style="font-size: 15px; font-weight: 700; margin: 0 0 18px; color: var(--text-main);">
            <?= $form['id'] > 0 ? _e('Edit Page Type') : _e('New Page Type') ?>
        </h2>

        <form method="POST" action="page-types.php" id="page-type-form">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
            <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="name"><?= _e('Name') ?></label>
                    <input class="form-control" type="text" id="name" name="name" value="<?= Security::sanitize((string) $form['name']) ?>" placeholder="<?= _e('Product page') ?>" required>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="slug"><?= _e('Slug') ?></label>
                    <input class="form-control" type="text" id="slug" name="slug" value="<?= Security::sanitize((string) $form['slug']) ?>" placeholder="product-page">
                    <small style="color: var(--text-muted);"><?= _e('Leave empty to generate it from the name. Used by themes and the API.') ?></small>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 14px;">
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="base_type"><?= _e('Base type') ?></label>
                    <select class="form-control" id="base_type" name="base_type">
                        <option value="page" <?= $form['base_type'] === 'page' ? 'selected' : '' ?>><?= _e('Static Page') ?></option>
                        <option value="post" <?= $form['base_type'] === 'post' ? 'selected' : '' ?>><?= _e('Blog Post') ?></option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="sort_order"><?= _e('Sort order') ?></label>
                    <input class="form-control" type="number" id="sort_order" name="sort_order" value="<?= (int) $form['sort_order'] ?>" step="1">
                </div>
            </div>

            <div class="form-group" style="margin-top: 14px;">
                <label class="form-label" for="description"><?= _e('Description') ?></label>
                <textarea class="form-control" id="description" name="description" rows="2" placeholder="<?= _e('Optional. Shown in the type list and helps editors pick the right preset.') ?>"><?= htmlspecialchars((string) $form['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; border-top: 1px solid var(--border-subtle); padding-top: 18px; margin-top: 18px;">
                <div>
                    <h3 style="font-size: 14px; font-weight: 700; margin: 0; color: var(--text-main);"><?= _e('Default custom fields') ?></h3>
                    <p style="font-size: 12px; color: var(--text-muted); margin: 4px 0 0; line-height: 1.5;">
                        <?= _e('These fields are pre-filled when a new document uses this type. Existing values are never overwritten automatically.') ?>
                    </p>
                </div>
                <button type="button" id="pt-add-field" class="btn btn-secondary" style="font-size: 12px; padding: 6px 12px;">+ <?= _e('Add Field') ?></button>
            </div>

            <div id="pt-fields-list" style="display: flex; flex-direction: column; gap: 10px; margin-top: 14px;">
                <?php foreach ($form['fields'] as $field) { echo renderTypeFieldRow($field, $fieldTypeLabels); } ?>
            </div>

            <p id="pt-fields-empty" style="font-size: 12px; color: var(--text-muted); margin: 12px 0 0; <?= empty($form['fields']) ? '' : 'display: none;' ?>">
                <?= _e('No default fields yet. Add fields to build a preset.') ?>
            </p>

            <template id="pt-field-template">
                <?= renderTypeFieldRow([], $fieldTypeLabels) ?>
            </template>

            <p style="font-size: 11px; color: var(--text-muted); margin: 14px 0 0; line-height: 1.5;">
                <?= _e('The "Required" flag is informational only and does not block saving. For gallery fields the default value may be a JSON object with "items" and "options".') ?>
            </p>

            <div style="display: flex; gap: 8px; align-items: center; margin-top: 20px;">
                <button type="submit" class="btn btn-primary"><?= _e('Save Page Type') ?></button>
                <?php if ($form['id'] > 0): ?>
                    <a href="page-types.php" class="btn btn-secondary"><?= _e('Cancel') ?></a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card" style="padding: 0; overflow: hidden;">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border-subtle); display: flex; align-items: center; justify-content: space-between;">
            <h2 style="font-size: 15px; font-weight: 600; margin: 0;"><?= _e('All Page Types') ?></h2>
            <span class="badge" style="font-size: 11px; background: var(--bg-surface, #1e293b); border: 1px solid var(--border-subtle); color: var(--text-muted);"><?= count($allTypes) ?></span>
        </div>

        <?php if (empty($allTypes)): ?>
            <div style="padding: 28px 20px; text-align: center; color: var(--text-muted); font-size: 13px;">
                <?= _e('No page types yet. Create your first preset on the left.') ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="pro-table">
                    <thead>
                        <tr>
                            <th><?= _e('Name') ?></th>
                            <th style="width: 80px; text-align: center;"><?= _e('Fields') ?></th>
                            <th style="width: 80px; text-align: center;"><?= _e('Content') ?></th>
                            <th style="width: 190px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allTypes as $type): ?>
                            <tr>
                                <td>
                                    <a href="page-types.php?edit=<?= (int) $type['id'] ?>" style="font-weight: 600; color: var(--text-main); text-decoration: none;">
                                        <?= Security::sanitize((string) $type['name']) ?>
                                    </a>
                                    <div style="font-size: 11px; color: var(--text-muted);">
                                        /<?= Security::sanitize((string) $type['slug']) ?>
                                        &middot; <?= $type['base_type'] === 'post' ? _e('Blog Post') : _e('Static Page') ?>
                                    </div>
                                    <?php if (!empty($type['description'])): ?>
                                        <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;"><?= Security::sanitize((string) $type['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;"><?= (int) ($type['field_count'] ?? 0) ?></td>
                                <td style="text-align: center;"><?= (int) ($type['usage_count'] ?? 0) ?></td>
                                <td>
                                    <div style="display: flex; gap: 6px; justify-content: flex-end; flex-wrap: wrap;">
                                        <a href="page-types.php?edit=<?= (int) $type['id'] ?>" class="btn btn-secondary" style="padding: 2px 8px; font-size: 11px;"><?= _e('Edit') ?></a>
                                        <a href="page-types.php?duplicate=<?= (int) $type['id'] ?>&csrf=<?= urlencode(Security::generateCsrfToken()) ?>" class="btn btn-secondary" style="padding: 2px 8px; font-size: 11px;"><?= _e('Duplicate') ?></a>
                                        <a href="page-types.php?delete=<?= (int) $type['id'] ?>&csrf=<?= urlencode(Security::generateCsrfToken()) ?>" class="btn btn-danger-ghost" style="padding: 2px 8px; font-size: 11px;" onclick="return confirm('<?= _e('Delete this page type? Documents using it will be kept and detached from the type.') ?>');"><?= _e('Delete') ?></a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
(function () {
    const list = document.getElementById('pt-fields-list');
    const template = document.getElementById('pt-field-template');
    const addBtn = document.getElementById('pt-add-field');
    const emptyHint = document.getElementById('pt-fields-empty');
    const nameInput = document.getElementById('name');
    const slugInput = document.getElementById('slug');
    if (!list || !template || !addBtn) return;

    function refreshEmpty() {
        if (!emptyHint) return;
        emptyHint.style.display = list.querySelector('.pt-field-row') ? 'none' : 'block';
    }

    function wireRow(row) {
        const remove = row.querySelector('.pt-field-remove');
        if (remove) remove.addEventListener('click', () => { row.remove(); refreshEmpty(); });

        const up = row.querySelector('.pt-field-up');
        if (up) up.addEventListener('click', () => {
            const prev = row.previousElementSibling;
            if (prev) list.insertBefore(row, prev);
        });

        const down = row.querySelector('.pt-field-down');
        if (down) down.addEventListener('click', () => {
            const next = row.nextElementSibling;
            if (next) list.insertBefore(next, row);
        });
    }

    list.querySelectorAll('.pt-field-row').forEach(wireRow);

    addBtn.addEventListener('click', () => {
        const node = template.content.firstElementChild;
        if (!node) return;
        const clone = node.cloneNode(true);
        list.appendChild(clone);
        wireRow(clone);
        refreshEmpty();
        const key = clone.querySelector('.pt-field-key');
        if (key) key.focus();
    });

    if (slugInput && nameInput) {
        let manualSlug = slugInput.value.trim() !== '';
        slugInput.addEventListener('input', () => { manualSlug = slugInput.value.trim() !== ''; });
        nameInput.addEventListener('input', () => {
            if (manualSlug) return;
            slugInput.value = nameInput.value.toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '');
        });
    }
})();
</script>

<?php require_once __DIR__ . '/views/footer.php'; ?>
