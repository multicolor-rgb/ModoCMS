<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Database;
use Core\Security;
use Core\Auth;
use Core\I18n;
use Core\MediaLibrary;
use Core\PageSlug;
use Core\PageTypes;
use Core\Router;
use Core\Sitemap;

Auth::requireCapability('manage_pages');

$db = Database::getConnection();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$item = [
    'id' => 0,
    'title' => '', 'slug' => '', 'content' => '',
    'type' => $_GET['type'] ?? 'post', 'status' => 'published',
    'page_type_id' => 0,
    'parent_id' => 0,
    'lang' => $_GET['lang'] ?? I18n::getDefaultLocale(),
    'translation_group' => $_GET['group'] ?? bin2hex(random_bytes(8)),
    'featured_image' => '', 'meta_title' => '', 'meta_description' => '',
    'published_at' => ''
];

// Pre-select a type when the editor is opened from a page type quick link.
if ($id === 0 && !empty($_GET['page_type'])) {
    $requestedType = PageTypes::findBySlug((string) $_GET['page_type']);
    if ($requestedType) {
        $item['page_type_id'] = (int) $requestedType['id'];
        $item['type'] = (string) $requestedType['base_type'];
    }
}

$existingTags = '';
$assignedCategoryIds = [];

// Load existing record if editing
if ($id > 0) {
    $stmt = $db->prepare("SELECT * FROM pages WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $existing = $stmt->fetch();
    if ($existing) {
        if (!Auth::can('edit_others_pages') && (int)$existing['author_id'] !== (int)Auth::id()) {
            http_response_code(403);
            die('Access denied to this article.');
        }
        $item = $existing;

        // Fetch associated tags for posts
        $tagStmt = $db->prepare("
            SELECT t.name 
            FROM tags t 
            JOIN page_tags pt ON pt.tag_id = t.id 
            WHERE pt.page_id = :pid 
            ORDER BY t.name ASC
        ");
        $tagStmt->execute([':pid' => $id]);
        $existingTags = implode(', ', $tagStmt->fetchAll(\PDO::FETCH_COLUMN));

        // Fetch associated categories for posts
        $catStmt = $db->prepare("SELECT category_id FROM page_categories WHERE page_id = :pid");
        $catStmt->execute([':pid' => $id]);
        $assignedCategoryIds = array_map('intval', $catStmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}

// Load custom fields (page_meta) attached to this record.
$customFields = [];
if ($id > 0) {
    $metaStmt = $db->prepare("SELECT meta_key, meta_value, meta_type, meta_label FROM page_meta WHERE page_id = :pid ORDER BY id ASC");
    $metaStmt->execute([':pid' => $id]);
    $customFields = $metaStmt->fetchAll();
} elseif ((int) ($item['page_type_id'] ?? 0) > 0) {
    // New document: pre-fill the editor with the preset's default fields.
    $customFields = PageTypes::defaultFieldsFor((int) $item['page_type_id']);
}

// Build the list of other pages/posts that already have custom fields, so their
// field definitions and values can be copied into this document.
$cfSources = [];
try {
    $srcStmt = $db->prepare("
        SELECT p.id, p.title, p.type
        FROM pages p
        WHERE p.id <> :cur
          AND EXISTS (SELECT 1 FROM page_meta pm WHERE pm.page_id = p.id)
        ORDER BY p.title ASC
        LIMIT 200
    ");
    $srcStmt->execute([':cur' => $id]);
    $srcRows = $srcStmt->fetchAll();
    $srcMetaStmt = $db->prepare("SELECT meta_key, meta_value, meta_type, meta_label FROM page_meta WHERE page_id = :pid ORDER BY id ASC");
    foreach ($srcRows as $srcRow) {
        $srcMetaStmt->execute([':pid' => $srcRow['id']]);
        $fields = [];
        foreach ($srcMetaStmt->fetchAll() as $m) {
            $fields[] = [
                'key'   => (string) $m['meta_key'],
                'value' => (string) $m['meta_value'],
                'type'  => (string) $m['meta_type'],
                'label' => (string) ($m['meta_label'] ?? ''),
            ];
        }
        $cfSources[] = [
            'id'     => (int) $srcRow['id'],
            'title'  => (string) $srcRow['title'],
            'type'   => (string) $srcRow['type'],
            'fields' => $fields,
        ];
    }
} catch (\Throwable $e) {
    $cfSources = [];
}

// Page type presets are needed by the sidebar selector and the field editor JS.
$pageTypesForBase = PageTypes::forBaseType((string) ($item['type'] ?? 'page'));
$pageTypesAll = PageTypes::all(true);

$currentTypeLabel = $item['type'] === 'post' ? __('Blog Post') : __('Static Page');
if ((int) ($item['page_type_id'] ?? 0) > 0) {
    $currentPreset = PageTypes::find((int) $item['page_type_id']);
    if ($currentPreset) {
        $currentTypeLabel = (string) $currentPreset['name'];
    }
}

/**
 * Renders a single Custom Field editor row (used for existing fields and as the
 * clone source for the JS <template>). The canonical value always lives in a
 * hidden input so exactly one value is submitted per row, whatever the widget.
 */
if (!function_exists('renderCustomFieldRow')) {
function renderCustomFieldRow(array $cf = []): string
{
    $types = [
        'text'     => 'Text',
        'textarea' => 'Textarea',
        'richtext' => 'Rich Text',
        'tinymce'  => 'TinyMCE (WYSIWYG)',
        'gallery'  => 'Gallery',
        'image'    => 'Image',
        'checkbox' => 'Checkbox',
        'number'   => 'Number',
        'html'     => 'HTML',
        'php'      => 'PHP',
        'js'       => 'JavaScript',
        'css'      => 'CSS',
        'json'     => 'JSON',
        'code'     => 'Code',
    ];

    $key  = htmlspecialchars((string) ($cf['meta_key'] ?? ''), ENT_QUOTES, 'UTF-8');
    $val  = (string) ($cf['meta_value'] ?? '');
    $valE = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
    $type = (string) ($cf['meta_type'] ?? 'text');
    if (!isset($types[$type])) {
        $type = 'text';
    }
    $fieldLabel = trim((string) ($cf['meta_label'] ?? ''));
    if ($fieldLabel === '') {
        $fieldLabel = (string) ($cf['meta_key'] ?? '');
    }
    $fieldLabelE = htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8');

    $opts = '';
    foreach ($types as $tv => $tl) {
        $opts .= '<option value="' . $tv . '"' . ($tv === $type ? ' selected' : '') . '>'
               . htmlspecialchars(__($tl), ENT_QUOTES, 'UTF-8') . '</option>';
    }

    $checked = ((int) $val === 1) ? ' checked' : '';
    $imgShow = ($val !== '') ? 'block' : 'none';

    $lUpload   = htmlspecialchars(__('Upload'), ENT_QUOTES, 'UTF-8');
    $lImageUrl = htmlspecialchars(__('Image URL'), ENT_QUOTES, 'UTF-8');
    $lChooseLibrary = htmlspecialchars(__('Choose from library'), ENT_QUOTES, 'UTF-8');
    $lEnabled  = htmlspecialchars(__('Enabled'), ENT_QUOTES, 'UTF-8');
    $lRemove   = htmlspecialchars(__('Remove'), ENT_QUOTES, 'UTF-8');
    $lValue    = htmlspecialchars(__('Field Value'), ENT_QUOTES, 'UTF-8');
    $lAddImages    = htmlspecialchars(__('Add images'), ENT_QUOTES, 'UTF-8');
    $lUploadImages = htmlspecialchars(__('Upload images'), ENT_QUOTES, 'UTF-8');
    $lColumns      = htmlspecialchars(__('Columns'), ENT_QUOTES, 'UTF-8');
    $lGap          = htmlspecialchars(__('Gap (px)'), ENT_QUOTES, 'UTF-8');
    $lRatio        = htmlspecialchars(__('Aspect ratio'), ENT_QUOTES, 'UTF-8');
    $lLightbox     = htmlspecialchars(__('Lightbox'), ENT_QUOTES, 'UTF-8');
    $lCaptions     = htmlspecialchars(__('Show captions'), ENT_QUOTES, 'UTF-8');
    $lNoImages     = htmlspecialchars(__('No images selected yet.'), ENT_QUOTES, 'UTF-8');
    $lAuto         = htmlspecialchars(__('Auto'), ENT_QUOTES, 'UTF-8');

    $galleryColumnOpts = '';
    foreach (range(1, 6) as $column) {
        $galleryColumnOpts .= '<option value="' . $column . '">' . $column . '</option>';
    }
    $galleryRatioOpts = '<option value="1">1:1</option>'
        . '<option value="1.3333">4:3</option>'
        . '<option value="1.5">3:2</option>'
        . '<option value="1.7778">16:9</option>'
        . '<option value="auto">' . $lAuto . '</option>';

    return <<<HTML
<div class="custom-field-row" data-type="{$type}" style="border: 1px solid var(--border-subtle); border-radius: var(--radius-sm, 8px); padding: 12px; display: flex; flex-direction: column; gap: 8px;">
    <div class="cf-settings" style="display: flex; gap: 8px; flex-wrap: wrap;">
        <input class="form-control cf-key" type="text" name="custom_field_key[]" value="{$key}" placeholder="key" style="flex: 1 1 160px; min-width: 120px; font-size: 13px;">
        <select class="form-control cf-type" name="custom_field_type[]" style="flex: 0 0 140px; font-size: 13px;">{$opts}</select>
        <button type="button" class="btn btn-danger-ghost remove-custom-field" title="{$lRemove}" style="flex: 0 0 auto; font-size: 16px; padding: 4px 12px; line-height: 1;">&times;</button>
    </div>
    <div class="cf-value-part" style="display: flex; flex-direction: column; gap: 6px;">
        <label class="cf-field-label" style="font-size: 12px; font-weight: 600; color: var(--text-muted); font-family: monospace;">{$fieldLabelE}</label>
        <input type="hidden" class="cf-label" name="custom_field_label[]" value="{$fieldLabelE}">
        <input type="hidden" class="cf-value" name="custom_field_value[]" value="{$valE}">
        <input type="text" class="form-control cf-widget cf-w-text" value="{$valE}" placeholder="{$lValue}" style="font-size: 13px; display: none;">
        <textarea class="form-control cf-widget cf-w-textarea" rows="3" style="font-size: 13px; display: none;">{$valE}</textarea>
        <textarea class="cf-widget cf-w-richtext" style="display: none;">{$valE}</textarea>
        <textarea class="cf-widget cf-w-code" style="display: none;">{$valE}</textarea>
        <input type="number" class="form-control cf-widget cf-w-number" value="{$valE}" style="font-size: 13px; display: none;">
        <label class="cf-widget cf-w-checkbox" style="align-items: center; gap: 8px; font-size: 13px; cursor: pointer; display: none;">
            <input type="checkbox" class="cf-check-input" value="1"{$checked}> <span>{$lEnabled}</span>
        </label>
        <div class="cf-widget cf-w-image" style="display: none;">
            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <input type="text" class="form-control cf-image-url" value="{$valE}" placeholder="{$lImageUrl}" style="flex: 1 1 160px; font-size: 13px;">
                <button type="button" class="btn btn-secondary cf-image-library" style="font-size: 12px;">{$lChooseLibrary}</button>
                <button type="button" class="btn btn-secondary cf-image-upload" style="font-size: 12px;">{$lUpload}</button>
                <button type="button" class="btn btn-danger-ghost cf-image-clear" style="font-size: 14px;">&times;</button>
                <input type="file" class="cf-image-file" accept="image/*" style="display: none;">
            </div>
            <img class="cf-image-preview" src="{$valE}" alt="" style="max-height: 90px; margin-top: 8px; border-radius: 6px; display: {$imgShow};">
        </div>
        <div class="cf-widget cf-w-gallery" style="display: none;">
            <div class="cf-gallery-options" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 8px; margin-bottom: 10px;">
                <label style="display: flex; flex-direction: column; gap: 4px; font-size: 11px; color: var(--text-muted); font-weight: 600;">{$lColumns}
                    <select class="form-control cf-gallery-columns" style="font-size: 12px;">{$galleryColumnOpts}</select>
                </label>
                <label style="display: flex; flex-direction: column; gap: 4px; font-size: 11px; color: var(--text-muted); font-weight: 600;">{$lGap}
                    <input class="form-control cf-gallery-gap" type="number" min="0" max="60" step="1" value="14" style="font-size: 12px;">
                </label>
                <label style="display: flex; flex-direction: column; gap: 4px; font-size: 11px; color: var(--text-muted); font-weight: 600;">{$lRatio}
                    <select class="form-control cf-gallery-ratio" style="font-size: 12px;">{$galleryRatioOpts}</select>
                </label>
                <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; cursor: pointer; margin-top: 16px;">
                    <input type="checkbox" class="cf-gallery-lightbox"> {$lLightbox}
                </label>
                <label style="display: flex; align-items: center; gap: 6px; font-size: 12px; cursor: pointer; margin-top: 16px;">
                    <input type="checkbox" class="cf-gallery-captions"> {$lCaptions}
                </label>
            </div>
            <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 10px;">
                <button type="button" class="btn btn-secondary cf-gallery-add" style="font-size: 12px; padding: 4px 10px;">+ {$lAddImages}</button>
                <button type="button" class="btn btn-secondary cf-gallery-upload" style="font-size: 12px; padding: 4px 10px;">{$lUploadImages}</button>
                <input type="file" class="cf-gallery-file" accept="image/*" multiple style="display: none;">
            </div>
            <div class="cf-gallery-items" style="display: flex; flex-direction: column; gap: 8px;"></div>
            <p class="cf-gallery-empty" style="font-size: 12px; color: var(--text-muted); margin: 6px 0 0;">{$lNoImages}</p>
        </div>
    </div>
</div>
HTML;
}
}


// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token');
    }

    if ($id === 0) {
        Auth::requireCapability('create_pages');
    }

    $title = trim($_POST['title'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $content = $_POST['content'] ?? '';
    $type = in_array($_POST['type'] ?? '', ['post', 'page'], true) ? $_POST['type'] : 'post';
    $pageTypeId = (int) ($_POST['page_type_id'] ?? 0);
    if ($pageTypeId > 0) {
        $postedPageType = PageTypes::find($pageTypeId);
        if (!$postedPageType || (string) $postedPageType['base_type'] !== $type) {
            $pageTypeId = 0;
        }
    }
    $isNewDocument = ($id === 0);
    $status = in_array($_POST['status'] ?? '', ['published', 'draft'], true) ? (string)$_POST['status'] : 'draft';
    $previousStatus = (string)($existing['status'] ?? 'draft');
    if ($status === 'published' && $previousStatus !== 'published') {
        Auth::requireCapability('publish_pages');
    }

    $publishedAtInput = trim((string)($_POST['published_at'] ?? ''));
    $publishedAt = null;
    if ($publishedAtInput !== '') {
        $publishedTimestamp = strtotime($publishedAtInput);
        $publishedAt = $publishedTimestamp !== false ? date('Y-m-d H:i:s', $publishedTimestamp) : null;
    }

    $lang = trim($_POST['lang'] ?? I18n::getDefaultLocale());
    $parentId = ($type === 'page') ? (int)($_POST['parent_id'] ?? 0) : 0;
    $translation_group = trim($_POST['translation_group'] ?? bin2hex(random_bytes(8)));
    $featured_image = trim($_POST['featured_image'] ?? '');
    $meta_title = trim($_POST['meta_title'] ?? '');
    $meta_description = trim($_POST['meta_description'] ?? '');
    $tagsInput = trim($_POST['tags'] ?? '');

    if ($id > 0 && $parentId === $id) {
        $parentId = 0;
    }

    // Normalize the requested slug, or derive it from the title when empty.
    $slug = PageSlug::slugify($slug !== '' ? $slug : $title);

    // The database enforces a unique (slug, lang) pair. Instead of letting the
    // save fail, keep the requested slug and append -1, -2, ... when needed.
    $requestedSlug = $slug;
    $slug = PageSlug::unique($slug, $lang, $id);
    $slugAdjusted = ($slug !== $requestedSlug);

    // Capture pre-save public paths so slug / parent changes can create 301s.
    $oldPublicPath = '';
    $descendantOldPaths = [];
    if ($id > 0 && class_exists(\Core\Redirects::class)) {
        $lookupBefore = \Core\Redirects::pageLookup();
        $oldPublicPath = \Core\Redirects::publicPathFromLookup($id, $lookupBefore);
        foreach (\Core\Redirects::descendantIds($id, $lookupBefore) as $childId) {
            $descendantOldPaths[$childId] = \Core\Redirects::publicPathFromLookup($childId, $lookupBefore);
        }
    }

    // Capture the previous version of an existing document before it is
    // overwritten, so the edit can always be rolled back from the revisions panel.
    if ($id > 0 && class_exists(\Core\Revisions::class)) {
        \Core\Revisions::snapshot($id, 'save');
    }

    if ($id > 0) {
        $stmt = $db->prepare("
            UPDATE pages 
            SET title = :t, slug = :s, content = :c, type = :tp, page_type_id = :ptid, status = :st,
                parent_id = :p, lang = :lg, translation_group = :tg,
                featured_image = :img, meta_title = :mt, meta_description = :md, 
                published_at = :pub,
                updated_at = CURRENT_TIMESTAMP 
            WHERE id = :id
        ");
        $stmt->execute([
            ':t' => $title, ':s' => $slug, ':c' => $content, ':tp' => $type,
            ':ptid' => $pageTypeId > 0 ? $pageTypeId : null, ':st' => $status,
            ':p' => $parentId, ':lg' => $lang, ':tg' => $translation_group, 
            ':img' => $featured_image, ':mt' => $meta_title, ':md' => $meta_description, 
            ':pub' => $publishedAt, ':id' => $id
        ]);
    } else {
        $stmt = $db->prepare("
            INSERT INTO pages (
                title, slug, content, type, page_type_id, status, parent_id, lang,
                translation_group, featured_image, meta_title, meta_description, author_id, published_at
            ) VALUES (
                :t, :s, :c, :tp, :ptid, :st, :p, :lg, :tg, :img, :mt, :md, :aid, :pub
            )
        ");
        $stmt->execute([
            ':t' => $title, ':s' => $slug, ':c' => $content, ':tp' => $type,
            ':ptid' => $pageTypeId > 0 ? $pageTypeId : null, ':st' => $status,
            ':p' => $parentId, ':lg' => $lang, ':tg' => $translation_group, 
            ':img' => $featured_image, ':mt' => $meta_title, ':md' => $meta_description, 
            ':aid' => Auth::id(), ':pub' => $publishedAt
        ]);
        $id = (int)$db->lastInsertId();
    }

    if ($type === 'post') {
        $delStmt = $db->prepare("DELETE FROM page_tags WHERE page_id = :pid");
        $delStmt->execute([':pid' => $id]);

        if ($tagsInput !== '') {
            $tagList = array_unique(array_filter(array_map('trim', explode(',', $tagsInput))));
            foreach ($tagList as $rawTag) {
                $tagSlug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $rawTag), '-'));
                if ($tagSlug === '') continue;

                $tagCheck = $db->prepare("SELECT id FROM tags WHERE slug = :s LIMIT 1");
                $tagCheck->execute([':s' => $tagSlug]);
                $tagId = $tagCheck->fetchColumn();

                if (!$tagId) {
                    $tagInsert = $db->prepare("INSERT INTO tags (name, slug) VALUES (:n, :s)");
                    $tagInsert->execute([':n' => $rawTag, ':s' => $tagSlug]);
                    $tagId = (int)$db->lastInsertId();
                }

                $attachStmt = $db->prepare("INSERT OR IGNORE INTO page_tags (page_id, tag_id) VALUES (:pid, :tid)");
                $attachStmt->execute([':pid' => $id, ':tid' => (int)$tagId]);
            }
        }

        // Sync assigned categories (multi-select checkboxes in the sidebar).
        $selectedCategoryIds = [];
        $postedCategories = $_POST['categories'] ?? [];
        if (is_array($postedCategories)) {
            $validCategoryIds = array_map('intval', $db->query("SELECT id FROM categories")->fetchAll(\PDO::FETCH_COLUMN));
            foreach ($postedCategories as $rawCategoryId) {
                $candidateCategoryId = (int) $rawCategoryId;
                if ($candidateCategoryId > 0 && in_array($candidateCategoryId, $validCategoryIds, true)) {
                    $selectedCategoryIds[$candidateCategoryId] = $candidateCategoryId;
                }
            }
        }

        $clearCategories = $db->prepare("DELETE FROM page_categories WHERE page_id = :pid");
        $clearCategories->execute([':pid' => $id]);

        if (!empty($selectedCategoryIds)) {
            $attachCategory = $db->prepare("INSERT OR IGNORE INTO page_categories (page_id, category_id) VALUES (:pid, :cid)");
            foreach ($selectedCategoryIds as $categoryId) {
                $attachCategory->execute([':pid' => $id, ':cid' => $categoryId]);
            }
        }
    }

    // Persist custom fields (page_meta): upsert posted keys, drop removed ones.
    $postedKeys = $_POST['custom_field_key'] ?? [];
    if (is_array($postedKeys)) {
        $postedValues = $_POST['custom_field_value'] ?? [];
        $postedTypes = $_POST['custom_field_type'] ?? [];
        $postedLabels = $_POST['custom_field_label'] ?? [];
        $allowedTypes = ['text', 'textarea', 'richtext', 'tinymce', 'gallery', 'image', 'checkbox', 'number', 'html', 'php', 'js', 'css', 'json', 'code'];
        $keptKeys = [];
        $metaUpsert = $db->prepare("INSERT OR REPLACE INTO page_meta (page_id, meta_key, meta_value, meta_type, meta_label) VALUES (:pid, :k, :v, :t, :l)");

        foreach (array_values($postedKeys) as $i => $rawKey) {
            $metaKey = strtolower(trim((string) preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string) $rawKey), '_'));
            if ($metaKey === '' || in_array($metaKey, $keptKeys, true)) {
                continue;
            }
            $keptKeys[] = $metaKey;

            $metaType = (string) ($postedTypes[$i] ?? 'text');
            if (!in_array($metaType, $allowedTypes, true)) {
                $metaType = 'text';
            }

            $metaValue = (string) ($postedValues[$i] ?? '');
            if ($metaType === \Core\MetaGallery::TYPE) {
                $metaValue = \Core\MetaGallery::toJson($metaValue);
                if (\Core\MediaLibrary::galleryThumbnailsAuto()) {
                    try {
                        \Core\MetaGallery::warmThumbnails($metaValue);
                    } catch (\Throwable $e) {
                        // Gallery thumbnails are an optimisation; never block the save.
                    }
                }
            }

            $metaLabel = trim((string) ($postedLabels[$i] ?? ''));
            if ($metaLabel === '') {
                $metaLabel = $metaKey;
            }

            $metaUpsert->execute([
                ':pid' => $id,
                ':k'   => $metaKey,
                ':v'   => $metaValue,
                ':t'   => $metaType,
                ':l'   => $metaLabel,
            ]);
        }

        if (empty($keptKeys)) {
            $db->prepare("DELETE FROM page_meta WHERE page_id = :pid")->execute([':pid' => $id]);
        } else {
            $placeholders = implode(',', array_fill(0, count($keptKeys), '?'));
            $delMeta = $db->prepare("DELETE FROM page_meta WHERE page_id = ? AND meta_key NOT IN ($placeholders)");
            $delMeta->execute(array_merge([$id], $keptKeys));
        }
    }

    // Safety net: seed defaults when JavaScript did not initialise the field
    // editor (e.g. no-JS form submission) or when an explicit bulk apply was
    // requested. Existing values are never overwritten.
    if ($pageTypeId > 0 && (
        ($isNewDocument && (string) ($_POST['cf_initialized'] ?? '0') !== '1')
        || (!$isNewDocument && (string) ($_POST['apply_type_defaults'] ?? '0') === '1')
    )) {
        PageTypes::applyDefaults($id, $pageTypeId);
    }

    // Automatic permanent redirects when the slug or parent hierarchy changed.
    if (class_exists(\Core\Redirects::class) && \Core\Redirects::isEnabled()) {
        $lookupAfter = \Core\Redirects::pageLookup();
        $newPublicPath = \Core\Redirects::publicPathFromLookup($id, $lookupAfter);
        if ($oldPublicPath !== '' && $newPublicPath !== '' && $oldPublicPath !== $newPublicPath) {
            \Core\Redirects::add($oldPublicPath, $newPublicPath);
        }
        foreach ($descendantOldPaths as $childId => $oldChildPath) {
            $newChildPath = \Core\Redirects::publicPathFromLookup($childId, $lookupAfter);
            if ($oldChildPath !== '' && $newChildPath !== '' && $oldChildPath !== $newChildPath) {
                \Core\Redirects::add($oldChildPath, $newChildPath);
            }
        }
    }

    // Invalidate the full-page cache after any content change.
    if (class_exists(\Core\PageCache::class)) {
        \Core\PageCache::purge();
    }

    if (class_exists('Hooks')) {
        \Hooks::doAction('admin-save-page', $id);
    }

    // Automatyczna aktualizacja sitemapy Google
    if (class_exists('Core\Sitemap')) {
        try {
            Sitemap::generate();
        } catch (\Throwable $e) {
            // Unikaj blokowania zapisu dokumentu w razie problemów z plikiem
        }
    }

    \Core\Audit::log($isNewDocument ? 'page.create' : 'page.update', 'page:' . $id, ['status' => $status, 'type' => $type]);

    $redirect = 'page-edit.php?id=' . $id . '&saved=1';
    if ($slugAdjusted) {
        $redirect .= '&slug_adjusted=1';
    }

    header('Location: ' . $redirect);
    exit;
}

// Fetch all pages of matching language
$allLangPages = $db->prepare("SELECT id, parent_id, title, slug FROM pages WHERE type = 'page' AND lang = :lang ORDER BY title ASC");
$allLangPages->execute([':lang' => $item['lang']]);
$lookupPages = [];
foreach ($allLangPages->fetchAll() as $p) {
    $lookupPages[(int)$p['id']] = $p;
}

// Categories available for assignment (flat list, shown for blog posts only).
$allCategories = $db->query("SELECT id, name, slug FROM categories ORDER BY name ASC")->fetchAll();

function resolveParentPath(int $parentId, array $lookup): string {
    $slugs = [];
    $curr = $parentId;
    $visited = [];
    $limit = 20;

    while ($curr > 0 && isset($lookup[$curr]) && !in_array($curr, $visited, true) && $limit-- > 0) {
        $visited[] = $curr;
        if (!empty($lookup[$curr]['slug']) && $lookup[$curr]['slug'] !== 'home') {
            array_unshift($slugs, $lookup[$curr]['slug']);
        }
        $curr = (int)($lookup[$curr]['parent_id'] ?? 0);
    }
    return !empty($slugs) ? implode('/', $slugs) . '/' : '';
}

// Uploads lookup
$basePrefix = class_exists('Core\Router') ? Router::getBaseSubdirectory() : '';
$existingMedia = MediaLibrary::listImages();
$mediaFolders = MediaLibrary::folders();
$thumbMissing = MediaLibrary::thumbnailSupported() ? MediaLibrary::missingThumbnailCount() : 0;

$tinymcePreset = Router::getOption('tinymce_preset', 'standard');
$tinymceCustom = Router::getOption('tinymce_custom_toolbar', '');

$resolvedToolbar = match ($tinymcePreset) {
    'basic' => 'undo redo | blocks | bold italic underline | bullist numlist | link mediamanager | removeformat',
    'advanced' => 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image mediamanager table | subscript superscript | removeformat code fullscreen',
    'custom' => !empty($tinymceCustom) ? $tinymceCustom : 'undo redo | blocks | bold italic | link mediamanager | code',
    default => 'undo redo | blocks | bold italic underline | alignleft aligncenter alignright | bullist numlist | link image mediamanager table | removeformat code fullscreen',
};

$enableMenubar = ($tinymcePreset === 'advanced');

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') 
           . ($_SERVER['HTTP_HOST'] ?? 'localhost') 
           . ($basePrefix !== '' ? rtrim($basePrefix, '/') : '');

$postsPageSlug = Router::getPostsPageSlug();

// --- Revision history (backups) -------------------------------------------
$revisionsEnabled = class_exists(\Core\Revisions::class) && \Core\Revisions::isEnabled();
$revisionList  = [];
$revisionCount = 0;
$autosaveInterval = 60;
if ($id > 0 && $revisionsEnabled) {
    $revisionList     = \Core\Revisions::list($id, 30);
    $revisionCount    = \Core\Revisions::count($id);
    $autosaveInterval = \Core\Revisions::autosaveInterval();
}

require_once __DIR__ . '/views/header.php';
?>

<style>
/* Modern Form & Component Styles */
.editor-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 360px;
    gap: 28px;
    align-items: start;
}
@media (max-width: 1080px) {
    .editor-layout {
        grid-template-columns: 1fr;
    }
}

.title-input-large {
    font-size: 20px !important;
    font-weight: 700 !important;
    padding: 14px 18px !important;
    border-radius: var(--radius-md, 10px) !important;
    background: var(--bg-surface, #1e293b) !important;
    border: 1px solid var(--border-subtle) !important;
    color: var(--text-main) !important;
    transition: all 0.2s ease;
}
.title-input-large:focus {
    border-color: var(--primary, #3b82f6) !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15) !important;
}

/* Integrated Permalink Bar */
.permalink-bar {
    display: flex;
    align-items: center;
    background: var(--bg-surface, #1e293b);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-sm, 8px);
    padding: 6px 12px;
    gap: 6px;
    margin-top: 8px;
    transition: border-color 0.2s;
}
.permalink-bar:focus-within {
    border-color: var(--primary, #3b82f6);
}
.permalink-prefix {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 12px;
    color: var(--text-muted);
    user-select: none;
    white-space: nowrap;
}
.permalink-input {
    flex: 1;
    border: none !important;
    background: transparent !important;
    padding: 2px 4px !important;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace !important;
    font-size: 13px !important;
    font-weight: 600;
    color: var(--accent, #60a5fa) !important;
    box-shadow: none !important;
    outline: none !important;
}
.permalink-actions {
    display: flex;
    gap: 4px;
}

/* Featured Image Uploader Box */
.featured-uploader-box {
    position: relative;
    border: 2px dashed var(--border-subtle);
    border-radius: var(--radius-md, 10px);
    background: var(--bg-surface, #1e293b);
    overflow: hidden;
    transition: all 0.2s ease;
    text-align: center;
}
.featured-uploader-box:hover {
    border-color: var(--primary, #3b82f6);
    background: rgba(59, 130, 246, 0.03);
}
.featured-preview-img {
    width: 100%;
    height: 190px;
    object-fit: cover;
    display: block;
}
.featured-empty-placeholder {
    padding: 34px 16px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}

/* Translation Language Pills */
.lang-pill {
    padding: 4px 10px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.04em;
    border-radius: 9999px;
    text-decoration: none;
    background: var(--bg-surface, #1e293b);
    border: 1px solid var(--border-subtle);
    color: var(--text-muted);
    transition: all 0.15s ease;
}
.lang-pill:hover {
    color: var(--text-main);
    border-color: var(--primary, #3b82f6);
    transform: translateY(-1px);
}
</style>

<script src="assets/vendor/tinymce/tinymce.min.js"></script>
<script src="assets/js/grid-editor.js"></script>
<script>
    window.MODO_GRID_CSS = <?= json_encode(\Core\CssFramework::editorContentCss(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.MODO_GRID_STYLE = <?= json_encode(\Core\CssFramework::editorContentStyle(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.MODO_GRID_GUIDE = <?= json_encode(\Core\CssFramework::editorGuideCss(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.MODO_EDITOR_BASE_STYLE = <?= json_encode('*,*::before,*::after{box-sizing:border-box}html,body{overflow-x:hidden}body{padding:10px}', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.MODO_GRID = <?= json_encode(\Core\CssFramework::gridConfig(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.MODO_THEME_CSS = <?= json_encode(\Core\ThemeEditorStyles::contentCss(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.MODO_THEME_STYLE = <?= json_encode(\Core\ThemeEditorStyles::contentStyle(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.MODO_THEME_BODY_CLASS = <?= json_encode(\Core\ThemeEditorStyles::bodyClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>

<link rel="stylesheet" href="assets/vendor/codemirror/codemirror.min.css">
<link rel="stylesheet" href="assets/vendor/codemirror/theme/dracula.min.css">
<script src="assets/vendor/codemirror/codemirror.min.js"></script>
<script src="assets/vendor/codemirror/mode/xml/xml.min.js"></script>
<script src="assets/vendor/codemirror/mode/javascript/javascript.min.js"></script>
<script src="assets/vendor/codemirror/mode/css/css.min.js"></script>
<script src="assets/vendor/codemirror/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="assets/vendor/codemirror/mode/clike/clike.min.js"></script>
<script src="assets/vendor/codemirror/mode/php/php.min.js"></script>
<style>
.custom-field-row .CodeMirror {
    height: auto;
    min-height: 90px;
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-sm, 8px);
    font-size: 13px;
}
/* TinyMCE can collapse its edit area when the field was sized while hidden. */
#custom-fields-list .custom-field-row[data-type="tinymce"] .tox-tinymce {
    min-height: 340px;
}
#custom-fields-list .custom-field-row[data-type="tinymce"] .tox-edit-area {
    min-height: 300px;
}
#custom-fields-list .custom-field-row[data-type="tinymce"] .tox-edit-area iframe {
    min-height: 300px !important;
}
.cf-gallery-item {
    display: grid;
    grid-template-columns: 22px 72px minmax(0, 1fr) 28px;
    gap: 8px;
    align-items: center;
    padding: 8px;
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-sm, 8px);
    background: var(--bg-surface, #1e293b);
}
.cf-gallery-item.is-dragging {
    opacity: .55;
}
.cf-gallery-thumb {
    width: 72px;
    height: 54px;
    object-fit: cover;
    border-radius: 6px;
    background: rgba(0, 0, 0, .08);
    display: block;
}
.cf-gallery-fields {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
    min-width: 0;
}
.cf-gallery-fields .cf-gallery-link {
    grid-column: 1 / -1;
}
.cf-gallery-drag {
    cursor: grab;
    color: var(--text-muted);
    text-align: center;
    font-size: 16px;
    line-height: 1;
    user-select: none;
}
.cf-gallery-remove {
    border: none;
    background: transparent;
    color: #dc2626;
    font-size: 20px;
    line-height: 1;
    cursor: pointer;
    padding: 0 4px;
}
@media (max-width: 720px) {
    .cf-gallery-item {
        grid-template-columns: 22px 56px minmax(0, 1fr) 28px;
    }
    .cf-gallery-thumb {
        width: 56px;
        height: 42px;
    }
    .cf-gallery-fields {
        grid-template-columns: 1fr;
    }
    .cf-gallery-fields .cf-gallery-link {
        grid-column: auto;
    }
}
#custom-fields-card .cf-tab {
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    padding: 8px 14px;
    margin-bottom: -1px;
    font-size: 13px;
    color: var(--text-muted);
    cursor: pointer;
}
#custom-fields-card .cf-tab.active {
    color: var(--text-main);
    border-bottom-color: var(--primary);
    font-weight: 600;
}
#custom-fields-card[data-tab="settings"] .cf-value-part,
#custom-fields-card[data-tab="settings"] .cf-fields-hint {
    display: none !important;
}
#custom-fields-card[data-tab="fields"] .cf-settings,
#custom-fields-card[data-tab="fields"] #cf-settings-toolbar {
    display: none !important;
}
#custom-fields-card[data-tab="copy"] #custom-fields-list,
#custom-fields-card[data-tab="copy"] .cf-fields-hint,
#custom-fields-card[data-tab="copy"] #cf-settings-toolbar,
#custom-fields-card[data-tab="copy"] .cf-settings,
#custom-fields-card[data-tab="copy"] .cf-value-part {
    display: none !important;
}
#custom-fields-card:not([data-tab="copy"]) .cf-copy {
    display: none !important;
}
.media-pick-item.selected {
    border-color: var(--primary, #3b82f6) !important;
    box-shadow: 0 0 0 2px rgba(59, 130, 246, .35) !important;
}
</style>

<div class="page-header" style="margin-bottom: 24px;">
    <div>
        <h1 class="page-title" style="display: flex; align-items: center; gap: 10px;">
            <span><?= $id > 0 ? _e('Edit') . ': ' . Security::sanitize($item['title']) : _e('New Document') ?></span>
            <span class="badge" style="font-size: 12px; background: var(--bg-surface, #1e293b); border: 1px solid var(--border-subtle); color: var(--accent, #60a5fa); text-transform: uppercase;">
                <?= Security::sanitize($currentTypeLabel) ?>
            </span>
        </h1>
        <p style="color: var(--text-muted); font-size: 13px; margin: 4px 0 0;"><?= _e('Manage page content, parent hierarchy, and SEO meta tags') ?></p>
    </div>
    <div style="display: flex; gap: 10px; align-items: center;">
        <a href="pages.php" class="btn btn-secondary">&larr; <?= _e('Content') ?></a>
    </div>
</div>

<?php if (isset($_GET['saved'])): ?>
    <div class="card" style="border-left: 4px solid var(--success, #10b981); background: rgba(16, 185, 129, 0.08); padding: 14px 18px; margin-bottom: 24px; border-radius: var(--radius-sm); color: #34d399; font-weight: 500; display: flex; align-items: center; justify-content: space-between;">
        <span><?= _e('Document saved successfully.') ?></span>
        <?php if ($id > 0): ?>
            <a id="success-live-link" href="#" target="_blank" style="font-size: 12px; color: var(--accent, #60a5fa); text-decoration: underline;"><?= _e('View Page') ?> &nearr;</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['slug_adjusted'])): ?>
    <div class="card" style="border-left: 4px solid var(--warning, #f59e0b); background: rgba(245, 158, 11, 0.08); padding: 14px 18px; margin-bottom: 24px; border-radius: var(--radius-sm); color: #fbbf24; font-weight: 500;">
        <?= sprintf(__('The slug was already in use, so it was changed to: %s'), '<code style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; color: var(--accent, #60a5fa);">/' . Security::sanitize((string) $item['slug']) . '</code>') ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['restored'])): ?>
    <div class="card" style="border-left: 4px solid var(--primary, #3b82f6); background: rgba(59, 130, 246, 0.08); padding: 14px 18px; margin-bottom: 24px; border-radius: var(--radius-sm); color: #60a5fa; font-weight: 500;">
        <?= _e('A previous version has been restored. Review the content and save again if needed.') ?>
    </div>
<?php endif; ?>

<form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
    <input type="hidden" name="cf_initialized" id="cf-initialized" value="0">
    <input type="hidden" name="apply_type_defaults" id="apply-type-defaults" value="0">

    <div class="editor-layout">
        
        <div style="display: flex; flex-direction: column; gap: 24px;">
            <div class="card" style="padding: 24px;">
                <div class="form-group" style="margin-bottom: 16px;">
                    <input class="title-input-large" type="text" id="title" name="title" value="<?= Security::sanitize($item['title']) ?>" placeholder="<?= _e('Document Title...') ?>" required autofocus>
                </div>

                <div class="form-group" style="margin-bottom: 24px;">
                    <label class="form-label" style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 4px;"><?= _e('Permalink / URL Structure') ?></label>
                    <div class="permalink-bar">
                        <span class="permalink-prefix" id="parent-slug-prefix"><?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/</span>
                        <input class="permalink-input" type="text" id="slug" name="slug" value="<?= Security::sanitize($item['slug']) ?>" placeholder="slug-name" autocomplete="off">
                        <div class="permalink-actions">
                            <button type="button" class="btn btn-secondary" style="padding: 3px 8px; font-size: 11px;" onclick="copyLiveUrl(this)" title="<?= _e('Copy link') ?>">
                                <?= _e('Copy') ?>
                            </button>
                            <a id="live-open-btn" href="#" target="_blank" class="btn btn-secondary" style="padding: 3px 8px; font-size: 11px;" title="<?= _e('Open URL in new tab') ?>">
                                &nearr;
                            </a>
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <textarea id="editor" name="content"><?= htmlspecialchars($item['content'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
            </div>

            <?php if (class_exists('Hooks')) { \Hooks::doAction('admin-edit-form-content', $id); } ?>
            <div class="card" id="custom-fields-card" data-tab="fields" style="padding: 24px;">
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 12px;">
                    <h2 style="font-size: 15px; font-weight: 700; margin: 0; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                        <svg style="width: 18px; height: 18px; color: var(--primary);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                        <?= _e('Custom Fields') ?>
                    </h2>
                </div>

                <div class="cf-tabs" style="display: flex; gap: 4px; border-bottom: 1px solid var(--border-subtle); margin-bottom: 16px; flex-wrap: wrap;">
                    <button type="button" class="cf-tab" data-tab="fields"><?= _e('Fields') ?></button>
                    <button type="button" class="cf-tab" data-tab="settings"><?= _e('Field Settings') ?></button>
                    <?php if (!empty($cfSources)): ?>
                        <button type="button" class="cf-tab" data-tab="copy"><?= _e('Copy from other pages') ?></button>
                    <?php endif; ?>
                </div>

                <?php if (!empty($cfSources)): ?>
                <div class="cf-copy" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 16px; padding: 10px 12px; border: 1px dashed var(--border-subtle); border-radius: 8px;">
                    <label style="font-size: 12px; font-weight: 600; color: var(--text-muted);"><?= _e('Copy from:') ?></label>
                    <select id="cf-source" class="form-control" style="flex: 1 1 220px; min-width: 160px; font-size: 13px;">
                        <option value=""><?= _e('Select a page or post...') ?></option>
                        <?php foreach ($cfSources as $src): ?>
                            <option value="<?= (int) $src['id'] ?>">
                                <?= htmlspecialchars($src['title'], ENT_QUOTES, 'UTF-8') ?>
                                (<?= $src['type'] === 'post' ? _e('Post') : _e('Page') ?>, <?= count($src['fields']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label style="display: flex; align-items: center; gap: 4px; font-size: 12px; cursor: pointer;">
                        <input type="checkbox" id="cf-copy-settings" checked> <?= _e('Field Settings') ?>
                    </label>
                    <label style="display: flex; align-items: center; gap: 4px; font-size: 12px; cursor: pointer;">
                        <input type="checkbox" id="cf-copy-values" checked> <?= _e('Content') ?>
                    </label>
                    <button type="button" id="cf-copy-btn" class="btn btn-secondary" style="font-size: 12px; padding: 6px 12px;"><?= _e('Copy fields') ?></button>
                </div>
                <script type="application/json" id="cf-sources-data"><?= json_encode($cfSources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
                <?php endif; ?>

                <div id="cf-settings-toolbar" style="margin-bottom: 14px;">
                    <button type="button" id="add-custom-field" class="btn btn-secondary" style="font-size: 12px; padding: 6px 12px;">+ <?= _e('Add Field') ?></button>
                    <p style="font-size: 12px; color: var(--text-muted); margin: 10px 0 0; line-height: 1.5;"><?= _e('Define each field key and type. Fill in the values in the "Fields" tab.') ?></p>
                </div>

                <p class="cf-fields-hint" style="font-size: 12px; color: var(--text-muted); margin: 0 0 14px; line-height: 1.5;"><?= _e("Enter a value for each field. Read them in your theme using get_meta('key').") ?></p>

                <div id="custom-fields-list" style="display: flex; flex-direction: column; gap: 14px;">
                    <?php foreach ($customFields as $cf) { echo renderCustomFieldRow($cf); } ?>
                </div>

                <template id="custom-field-template">
                    <?php echo renderCustomFieldRow(); ?>
                </template>

                <template id="cf-gallery-item-template">
                    <div class="cf-gallery-item">
                        <span class="cf-gallery-drag" draggable="true" title="<?= _e('Drag to reorder') ?>">&#8942;&#8942;</span>
                        <img class="cf-gallery-thumb" src="" alt="">
                        <div class="cf-gallery-fields">
                            <input class="form-control cf-gallery-alt" type="text" placeholder="<?= _e('Alt text') ?>" style="font-size: 12px;">
                            <input class="form-control cf-gallery-caption" type="text" placeholder="<?= _e('Caption') ?>" style="font-size: 12px;">
                            <input class="form-control cf-gallery-link" type="text" placeholder="<?= _e('Link (optional)') ?>" style="font-size: 12px;">
                        </div>
                        <button type="button" class="cf-gallery-remove" title="<?= _e('Remove') ?>">&times;</button>
                    </div>
                </template>
            </div>



            <div class="card" style="padding: 24px;">
                <div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border-subtle); padding-bottom: 12px; margin-bottom: 18px;">
                    <h2 style="font-size: 15px; font-weight: 700; margin: 0; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                        <svg style="width: 18px; height: 18px; color: var(--primary);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <?= _e('Search Engine Optimization (SEO)') ?>
                    </h2>
                    <span style="font-size: 11px; color: var(--text-muted);"><?= _e('Google & Social Preview') ?></span>
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="form-label" for="meta_title" style="font-size: 12px; font-weight: 600;"><?= _e('Custom Meta Title') ?></label>
                    <input class="form-control" type="text" id="meta_title" name="meta_title" value="<?= Security::sanitize($item['meta_title'] ?? '') ?>" placeholder="<?= _e('Default will inherit the main title') ?>">
                </div>

                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label" for="meta_description" style="font-size: 12px; font-weight: 600;"><?= _e('Meta Description') ?></label>
                    <textarea class="form-control" id="meta_description" name="meta_description" rows="3" placeholder="<?= _e('Write a compelling summary for search result snippets (150-160 characters)...') ?>"><?= htmlspecialchars($item['meta_description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
            </div>

            <?php if ($id > 0): ?>
            <div class="card" id="revisions-card" style="padding: 0; overflow: hidden;">
                <button type="button" id="revisions-toggle" aria-expanded="false" aria-controls="revisions-body"
                        style="width: 100%; display: flex; align-items: center; justify-content: space-between; gap: 10px; background: transparent; border: 0; padding: 16px 20px; margin: 0; cursor: pointer; text-align: left;">
                    <span style="font-size: 14px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                        <svg style="width: 18px; height: 18px; color: var(--primary);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <?= _e('Revision History') ?>
                        <span id="revisions-count" class="badge" style="font-size: 11px; background: var(--bg-surface, #1e293b); border: 1px solid var(--border-subtle); color: var(--text-muted);"><?= (int) $revisionCount ?></span>
                    </span>
                    <svg id="revisions-chevron" style="width: 16px; height: 16px; color: var(--text-muted); transition: transform .2s;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <div id="revisions-body" style="display: none; padding: 0 20px 20px;">
                    <?php if (!$revisionsEnabled): ?>
                        <p style="font-size: 12px; color: var(--text-muted); margin: 0;"><?= _e('Version history is disabled in System Settings.') ?></p>
                    <?php else: ?>
                        <button type="button" id="revision-snapshot-btn" class="btn btn-secondary" style="width: 100%; font-size: 12px; margin-bottom: 12px;">
                            <?= _e('Save snapshot now') ?>
                        </button>
                        <p id="autosave-status" style="font-size: 11px; color: var(--text-muted); margin: 0 0 12px; display: flex; align-items: center; gap: 6px;">
                            <span id="autosave-dot" style="width: 7px; height: 7px; border-radius: 50%; background: var(--text-muted); display: inline-block;"></span>
                            <span id="autosave-status-text"><?= _e('Autosave active') ?></span>
                        </p>
                        <div id="revisions-list" style="display: flex; flex-direction: column; gap: 8px; max-height: 420px; overflow-y: auto;">
                            <p class="revisions-empty" style="font-size: 12px; color: var(--text-muted); margin: 0;"><?= _e('No revisions yet.') ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <script>
            (function () {
                var card = document.getElementById('revisions-card');
                if (!card) return;
                var toggle = document.getElementById('revisions-toggle');
                var body = document.getElementById('revisions-body');
                var chevron = document.getElementById('revisions-chevron');
                if (!toggle || !body) return;
                function setOpen(open) {
                    body.style.display = open ? 'block' : 'none';
                    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                    if (chevron) chevron.style.transform = open ? 'rotate(180deg)' : '';
                }
                toggle.addEventListener('click', function () { setOpen(body.style.display === 'none'); });
                if (window.location.hash === '#revisions-card') {
                    setOpen(true);
                    setTimeout(function () { card.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 60);
                }
            })();
            </script>
            <?php endif; ?>
        </div>

        <div style="display: flex; flex-direction: column; gap: 24px;">
            <div class="card" style="padding: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <h3 style="font-size: 14px; font-weight: 700; margin: 0; color: var(--text-main);"><?= _e('Document Settings') ?></h3>
                    <span class="badge <?= $item['status'] === 'published' ? 'badge-success' : 'badge-warning' ?>" style="font-size: 11px; text-transform: uppercase;">
                        <?= $item['status'] === 'published' ? _e('Published') : _e('Draft') ?>
                    </span>
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label class="form-label" for="status" style="font-size: 12px; font-weight: 600;"><?= _e('Status') ?></label>
                    <select class="form-control" name="status" id="status">
                        <?php if (Auth::can('publish_pages') || $item['status'] === 'published'): ?>
                            <option value="published" <?= $item['status'] === 'published' ? 'selected' : '' ?>><?= _e('Published') ?></option>
                        <?php endif; ?>
                        <option value="draft" <?= $item['status'] === 'draft' ? 'selected' : '' ?>><?= _e('Draft') ?></option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label class="form-label" for="published_at" style="font-size: 12px; font-weight: 600;"><?= _e('Publish Date') ?></label>
                    <input class="form-control" type="datetime-local" name="published_at" id="published_at"
                           value="<?= htmlspecialchars(!empty($item['published_at']) ? date('Y-m-d\TH:i', strtotime((string)$item['published_at'])) : '', ENT_QUOTES, 'UTF-8') ?>">
                    <small style="font-size: 11px; color: var(--text-muted); display: block; margin-top: 4px;">
                        <?= _e('Optional. A future date schedules the entry; leave empty to publish immediately.') ?>
                    </small>
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label class="form-label" for="type" style="font-size: 12px; font-weight: 600;"><?= _e('Document Type') ?></label>
                    <select class="form-control" name="type" id="type">
                        <option value="post" <?= $item['type'] === 'post' ? 'selected' : '' ?>><?= _e('Blog Post') ?></option>
                        <option value="page" <?= $item['type'] === 'page' ? 'selected' : '' ?>><?= _e('Static Page') ?></option>
                    </select>
                </div>

                <div class="form-group" id="page-type-group" style="<?= empty($pageTypesForBase) ? 'display: none;' : '' ?>; margin-bottom: 14px;">
                    <label class="form-label" for="page_type_id" style="font-size: 12px; font-weight: 600;"><?= _e('Page Type') ?></label>
                    <select class="form-control" name="page_type_id" id="page_type_id">
                        <option value="0">&mdash; <?= _e('None') ?> &mdash;</option>
                        <?php foreach ($pageTypesForBase as $preset): ?>
                            <option value="<?= (int) $preset['id'] ?>" <?= (int) $item['page_type_id'] === (int) $preset['id'] ? 'selected' : '' ?>>
                                <?= Security::sanitize((string) $preset['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="font-size: 11px; color: var(--text-muted); display: block; margin-top: 4px;">
                        <?= _e('A preset adds its default custom fields to this document. Existing values are not overwritten.') ?>
                    </small>
                    <button type="button" id="apply-page-type-defaults" class="btn btn-secondary" style="margin-top: 8px; padding: 5px 10px; font-size: 11px;">
                        <?= _e('Apply type defaults') ?>
                    </button>
                </div>

                <div class="form-group" id="parent-page-group" style="<?= $item['type'] === 'page' ? '' : 'display: none;' ?>; margin-bottom: 14px;">
                    <label class="form-label" for="parent_id" style="font-size: 12px; font-weight: 600;"><?= _e('Parent Page') ?></label>
                    <select class="form-control" name="parent_id" id="parent_id">
                        <option value="0" data-path="">&mdash; <?= _e('No Parent (Top Level)') ?> &mdash;</option>
                        <?php foreach ($lookupPages as $candId => $candidate): 
                            if ($id > 0 && (int)$candId === $id) continue;
                            $parentPath = resolveParentPath((int)$candidate['id'], $lookupPages);
                            $isSelected = (int)$item['parent_id'] === (int)$candId;
                        ?>
                            <option value="<?= $candId ?>" data-path="<?= htmlspecialchars($parentPath, ENT_QUOTES, 'UTF-8') ?>" <?= $isSelected ? 'selected' : '' ?>>
                                <?= Security::sanitize($candidate['title']) ?> (<?= $parentPath ? '/' . rtrim($parentPath, '/') : '/' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" id="tags-group" style="<?= $item['type'] === 'post' ? '' : 'display: none;' ?>; margin-bottom: 14px;">
                    <label class="form-label" for="tags" style="font-size: 12px; font-weight: 600;"><?= _e('Tags') ?></label>
                    <input class="form-control" type="text" id="tags" name="tags" value="<?= Security::sanitize($existingTags) ?>" placeholder="technology, web, php">
                    <small style="font-size: 11px; color: var(--text-muted); display: block; margin-top: 4px;"><?= _e('Separate tags with commas.') ?></small>
                </div>

                <div class="form-group" id="categories-group" style="<?= $item['type'] === 'post' ? '' : 'display: none;' ?>; margin-bottom: 14px;">
                    <label class="form-label" style="font-size: 12px; font-weight: 600;"><?= _e('Categories') ?></label>
                    <?php if (empty($allCategories)): ?>
                        <small style="font-size: 11px; color: var(--text-muted); display: block;"><?= _e('No categories defined yet.') ?></small>
                        <a href="categories.php" class="btn btn-secondary" style="margin-top: 6px; padding: 4px 10px; font-size: 11px;"><?= _e('Manage Categories') ?></a>
                    <?php else: ?>
                        <input class="form-control" type="text" id="category-filter" placeholder="<?= _e('Filter categories...') ?>" style="margin-bottom: 8px; font-size: 12px;">
                        <div id="category-checkbox-list" style="max-height: 180px; overflow-y: auto; border: 1px solid var(--border-subtle); border-radius: var(--radius-sm, 8px); padding: 8px 10px; display: flex; flex-direction: column; gap: 6px; background: var(--bg-surface, #1e293b);">
                            <?php foreach ($allCategories as $category): ?>
                                <label class="category-option" style="display: flex; align-items: center; gap: 8px; font-size: 12px; cursor: pointer; margin: 0;">
                                    <input type="checkbox" name="categories[]" value="<?= (int) $category['id'] ?>" <?= in_array((int) $category['id'], $assignedCategoryIds, true) ? 'checked' : '' ?>>
                                    <span><?= Security::sanitize((string) $category['name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <small style="font-size: 11px; color: var(--text-muted); display: block; margin-top: 4px;">
                            <?= _e('Select one or more categories.') ?>
                            <a href="categories.php" style="margin-left: 4px;"><?= _e('Manage Categories') ?></a>
                        </small>
                    <?php endif; ?>
                </div>

                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="form-label" for="lang" style="font-size: 12px; font-weight: 600;"><?= _e('Language') ?></label>
                    <select class="form-control" name="lang" id="lang">
                        <?php foreach (I18n::getAvailableLanguages() as $code => $name): ?>
                            <option value="<?= $code ?>" <?= $item['lang'] === $code ? 'selected' : '' ?>>
                                <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?> (<?= strtoupper($code) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <input type="hidden" name="translation_group" value="<?= htmlspecialchars($item['translation_group'], ENT_QUOTES, 'UTF-8') ?>">

                <?php if ($id > 0): ?>
                    <div style="border-top: 1px solid var(--border-subtle); padding-top: 14px; margin-top: 14px;">
                        <span style="font-size: 11px; text-transform: uppercase; font-weight: 600; color: var(--text-muted); display: block; margin-bottom: 8px;"><?= _e('Available Translations') ?></span>
                        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                            <?php foreach (I18n::getAvailableLanguages() as $c => $n): if ($c === $item['lang']) continue; ?>
                                <a href="page-edit.php?type=<?= $item['type'] ?>&lang=<?= $c ?>&group=<?= urlencode($item['translation_group']) ?>" class="lang-pill">
                                    + <?= strtoupper($c) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 12px; margin-top: 18px; font-weight: 600; font-size: 14px;">
                    <?= _e('Save Document') ?> &rarr;
                </button>
            </div>

            <div class="card" style="padding: 20px;">
                <h3 style="font-size: 14px; font-weight: 700; margin: 0 0 14px 0; color: var(--text-main);"><?= _e('Featured Image') ?></h3>
                
                <div class="featured-uploader-box" id="image-preview-wrapper" style="<?= empty($item['featured_image']) ? 'display:none;' : '' ?> margin-bottom: 12px;">
                    <img id="image-preview" class="featured-preview-img" src="<?= htmlspecialchars($item['featured_image'] ?? '', ENT_QUOTES, 'UTF-8') ?>" alt="Preview">
                </div>

                <div id="image-placeholder-box" class="featured-uploader-box" style="<?= !empty($item['featured_image']) ? 'display:none;' : '' ?> margin-bottom: 12px;">
                    <div class="featured-empty-placeholder" id="placeholder-trigger">
                        <svg style="width: 36px; height: 36px; color: var(--text-muted);" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        <span style="font-size: 12px; font-weight: 500; color: var(--text-muted);"><?= _e('No image selected') ?></span>
                    </div>
                </div>

                <input type="hidden" name="featured_image" id="featured_image" value="<?= htmlspecialchars($item['featured_image'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <input type="file" id="image-file-input" accept="image/*" style="display: none;">

                <div style="display: flex; gap: 8px;">
                    <button type="button" id="upload-image-btn" class="btn btn-secondary" style="flex: 1; font-size: 12px;">
                        <?= empty($item['featured_image']) ? _e('Upload') : _e('Replace') ?>
                    </button>
                    <button type="button" id="library-image-btn" class="btn btn-secondary" style="flex: 1; font-size: 12px;">
                        <?= _e('Library') ?>
                    </button>
                    <button type="button" id="remove-image-btn" class="btn btn-danger-ghost" style="<?= empty($item['featured_image']) ? 'display:none;' : '' ?> font-size: 12px;" title="<?= _e('Remove') ?>">
                        &times;
                    </button>
                </div>
            </div>

            <?php if (class_exists('Hooks')) { \Hooks::doAction('admin-edit-form', $id); } ?>

        </div>
    </div>
</form>

<div id="media-modal" style="display: none; position: fixed; inset: 0; background: rgba(11, 15, 25, 0.8); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(8px);">
    <div class="card" style="width: 90%; max-width: 860px; max-height: 85vh; padding: 0; display: flex; flex-direction: column; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6); overflow: hidden; background: var(--bg-card, #111827); border: 1px solid var(--border-subtle);">
        <div style="padding: 18px 24px; border-bottom: 1px solid var(--border-subtle); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 16px; font-weight: 700; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                <svg style="width: 20px; height: 20px; color: var(--primary);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                <?= _e('Select Media from Library') ?>
            </h3>
            <button type="button" id="close-modal-btn" style="background: transparent; border: none; font-size: 24px; line-height: 1; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        <div style="padding: 24px; overflow-y: auto; flex: 1;">
            <?php if ($thumbMissing > 0): ?>
                <div id="media-thumb-status" style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; padding: 10px 12px; border: 1px solid var(--border-subtle); border-radius: 8px; background: var(--bg-surface, #1e293b);">
                    <span style="font-size: 12px; color: var(--text-muted);">
                        <?= htmlspecialchars(sprintf(__('Missing thumbnails: %d'), $thumbMissing), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <button type="button" id="media-thumb-backfill-btn" class="btn btn-secondary" style="font-size: 12px; padding: 4px 10px;"
                            data-csrf="<?= htmlspecialchars(Security::generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <?= _e('Generate missing thumbnails') ?>
                    </button>
                </div>
            <?php endif; ?>
            <?php if (empty($existingMedia)): ?>
                <div style="text-align: center; padding: 48px; color: var(--text-muted);">
                    <p style="font-size: 14px; margin: 0;"><?= _e('No uploaded images found in the library.') ?></p>
                </div>
            <?php else: ?>
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px; flex-wrap: wrap;">
                    <label for="media-folder-filter" style="font-size: 12px; color: var(--text-muted);"><?= _e('Folder') ?></label>
                    <select id="media-folder-filter" class="form-control" style="max-width: 240px; padding: 6px 10px; font-size: 13px;">
                        <option value="__all__"><?= _e('All files') ?></option>
                        <option value=""><?= _e('Root') ?></option>
                        <?php foreach ($mediaFolders as $folder): ?>
                            <option value="<?= htmlspecialchars((string) $folder['name'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= Security::sanitize((string) $folder['name']) ?> (<?= (int) $folder['count'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label id="media-select-all-wrap" style="display: none; align-items: center; gap: 8px; margin-left: auto; font-size: 12px; color: var(--text-muted); cursor: pointer;">
                        <input type="checkbox" id="media-select-all" style="margin: 0; cursor: pointer;">
                        <?= _e('Select all') ?>
                    </label>
                </div>
                <div id="media-picker-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(135px, 1fr)); gap: 16px;">
                    <?php foreach ($existingMedia as $media): ?>
                        <div class="media-pick-item" data-url="<?= htmlspecialchars($media['url'], ENT_QUOTES, 'UTF-8') ?>" data-folder="<?= htmlspecialchars((string) ($media['folder'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" style="cursor: pointer; border: 2px solid transparent; border-radius: var(--radius-sm, 8px); overflow: hidden; background: var(--bg-surface, #1e293b); transition: all 0.2s ease;">
                            <img src="<?= htmlspecialchars(MediaLibrary::thumbnailUrl((string) $media['path'], 160), ENT_QUOTES, 'UTF-8') ?>" srcset="<?= htmlspecialchars(MediaLibrary::thumbnailUrl((string) $media['path'], 160), ENT_QUOTES, 'UTF-8') ?> 1x, <?= htmlspecialchars(MediaLibrary::thumbnailUrl((string) $media['path'], 240), ENT_QUOTES, 'UTF-8') ?> 2x" alt="<?= htmlspecialchars($media['name'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($media['name'], ENT_QUOTES, 'UTF-8') ?>" width="160" height="110" loading="lazy" decoding="async" style="width: 100%; height: 110px; object-fit: cover; display: block;">
                            <div style="font-size: 11px; padding: 6px 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-align: center; color: var(--text-muted);">
                                <?= Security::sanitize($media['name']) ?>
                                <span style="display: block; font-size: 10px; opacity: 0.8;">
                                    <?= ($media['folder'] ?? '') !== '' ? Security::sanitize((string) $media['folder']) : _e('Root') ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div style="display: flex; justify-content: center; margin-top: 16px;">
                    <button type="button" id="media-load-more" class="btn btn-secondary" style="font-size: 12px; display: none;">
                        <?= _e('Load more') ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <div style="padding: 14px 24px; border-top: 1px solid var(--border-subtle); display: flex; justify-content: flex-end; gap: 10px; background: var(--bg-surface, #1e293b);">
            <button type="button" id="insert-media-btn" class="btn btn-primary" style="font-size: 13px; display: none;"><?= _e('Insert selected') ?></button>
            <button type="button" id="cancel-modal-btn" class="btn btn-secondary" style="font-size: 13px;"><?= _e('Close') ?></button>
        </div>
    </div>
</div>

<script type="application/json" id="page-types-data"><?= json_encode($pageTypesAll, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<script>
const siteBaseUrl = <?= json_encode($baseUrl) ?>;
const postsBaseSlug = <?= json_encode($postsPageSlug) ?>;
const slugInput = document.getElementById('slug');
const titleInput = document.getElementById('title');
const parentSelect = document.getElementById('parent_id');
const parentPrefixSpan = document.getElementById('parent-slug-prefix');
const liveOpenBtn = document.getElementById('live-open-btn');
const successLiveLink = document.getElementById('success-live-link');
const typeSelect = document.getElementById('type');
const pageTypeSelect = document.getElementById('page_type_id');
const pageTypeGroup = document.getElementById('page-type-group');
const parentGroup = document.getElementById('parent-page-group');
const tagsGroup = document.getElementById('tags-group');
const categoriesGroup = document.getElementById('categories-group');

let pageTypesData = [];
try {
    const pageTypesEl = document.getElementById('page-types-data');
    pageTypesData = JSON.parse(pageTypesEl ? pageTypesEl.textContent : '[]') || [];
} catch (err) {
    pageTypesData = [];
}

/**
 * Rebuilds the page-type selector for the selected base type (page/post).
 */
function refreshPageTypeOptions(baseType, selectedId) {
    if (!pageTypeSelect) return '0';
    const current = (selectedId === undefined || selectedId === null) ? pageTypeSelect.value : String(selectedId);
    const presets = pageTypesData.filter((t) => String(t.base_type) === String(baseType));

    pageTypeSelect.innerHTML = '';
    const none = document.createElement('option');
    none.value = '0';
    none.textContent = <?= json_encode('— ' . __('None') . ' —', JSON_UNESCAPED_UNICODE) ?>;
    pageTypeSelect.appendChild(none);

    presets.forEach((preset) => {
        const option = document.createElement('option');
        option.value = String(preset.id);
        option.textContent = String(preset.name || preset.slug || '');
        pageTypeSelect.appendChild(option);
    });

    if (pageTypeGroup) pageTypeGroup.style.display = presets.length ? 'block' : 'none';

    const valid = presets.some((preset) => String(preset.id) === String(current));
    pageTypeSelect.value = valid ? String(current) : '0';

    return pageTypeSelect.value;
}

refreshPageTypeOptions(typeSelect ? typeSelect.value : 'post', <?= (int) ($_POST['page_type_id'] ?? $item['page_type_id'] ?? 0) ?>);

function updateHierarchicalSlugPreview() {
    let parentPath = '';
    if (typeSelect && typeSelect.value === 'post') {
        parentPath = postsBaseSlug + '/';
    } else if (typeSelect && typeSelect.value === 'page' && parentSelect && parentSelect.selectedIndex >= 0) {
        const selectedOpt = parentSelect.options[parentSelect.selectedIndex];
        parentPath = selectedOpt.getAttribute('data-path') || '';
    }

    if (parentPrefixSpan) {
        parentPrefixSpan.textContent = siteBaseUrl + '/' + parentPath;
    }

    let currentSlug = slugInput ? slugInput.value.trim() : '';
    if (currentSlug === '' && titleInput && titleInput.value.trim() !== '') {
        currentSlug = titleInput.value.trim().toLowerCase()
            .replace(/[^\w\s-]/g, '')
            .replace(/[\s_-]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    const fullUrl = siteBaseUrl + '/' + parentPath + (currentSlug || '');
    if (liveOpenBtn) liveOpenBtn.href = fullUrl;
    if (successLiveLink) successLiveLink.href = fullUrl;
}

function copyLiveUrl(btn) {
    let parentPath = '';
    if (typeSelect && typeSelect.value === 'post') {
        parentPath = postsBaseSlug + '/';
    } else if (typeSelect && typeSelect.value === 'page' && parentSelect && parentSelect.selectedIndex >= 0) {
        parentPath = parentSelect.options[parentSelect.selectedIndex].getAttribute('data-path') || '';
    }
    const fullUrl = siteBaseUrl + '/' + parentPath + (slugInput ? slugInput.value.trim() : '');
    navigator.clipboard.writeText(fullUrl).then(() => {
        const orig = btn.textContent;
        btn.textContent = '<?= _e('Copied!') ?>';
        setTimeout(() => { btn.textContent = orig; }, 1500);
    });
}

if (titleInput) {
    titleInput.addEventListener('input', () => {
        if (<?= $id === 0 ? 'true' : 'false' ?> && slugInput && slugInput.dataset.manual !== '1') {
            const auto = titleInput.value.toLowerCase()
                .replace(/[^\w\s-]/g, '')
                .replace(/[\s_-]+/g, '-')
                .replace(/^-+|-+$/g, '');
            slugInput.value = auto;
        }
        updateHierarchicalSlugPreview();
    });
}

if (slugInput) {
    slugInput.addEventListener('input', () => {
        slugInput.dataset.manual = '1';
        updateHierarchicalSlugPreview();
    });
}

if (parentSelect) {
    parentSelect.addEventListener('change', updateHierarchicalSlugPreview);
}

if (typeSelect) {
    typeSelect.addEventListener('change', () => {
        if (typeSelect.value === 'page') {
            if (parentGroup) parentGroup.style.display = 'block';
            if (tagsGroup) tagsGroup.style.display = 'none';
            if (categoriesGroup) categoriesGroup.style.display = 'none';
        } else {
            if (parentGroup) parentGroup.style.display = 'none';
            if (tagsGroup) tagsGroup.style.display = 'block';
            if (categoriesGroup) categoriesGroup.style.display = 'block';
        }
        const nextPageType = refreshPageTypeOptions(typeSelect.value);
        if (window.ModoPageTypes && typeof window.ModoPageTypes.onBaseTypeChange === 'function') {
            window.ModoPageTypes.onBaseTypeChange(typeSelect.value, nextPageType);
        }
        updateHierarchicalSlugPreview();
    });
}

if (pageTypeSelect) {
    pageTypeSelect.addEventListener('change', () => {
        if (window.ModoPageTypes && typeof window.ModoPageTypes.onPageTypeChange === 'function') {
            window.ModoPageTypes.onPageTypeChange(parseInt(pageTypeSelect.value, 10) || 0);
        }
    });
}

// Live filter for the category checkbox list.
const categoryFilter = document.getElementById('category-filter');
if (categoryFilter) {
    categoryFilter.addEventListener('input', () => {
        const term = categoryFilter.value.trim().toLowerCase();
        document.querySelectorAll('#category-checkbox-list .category-option').forEach((option) => {
            const label = (option.textContent || '').toLowerCase();
            option.style.display = (term === '' || label.indexOf(term) !== -1) ? 'flex' : 'none';
        });
    });
}

// Media library & TinyMCE State
let currentMediaTarget = 'featured'; 
let tinymceFilePickerCallback = null;

const isDarkActive = () => {
    return document.documentElement.getAttribute('data-theme') === 'dark' || 
           document.body.getAttribute('data-theme') === 'dark' ||
           document.body.classList.contains('dark') ||
           document.body.classList.contains('dark-theme') ||
           (localStorage.getItem('modo_cms_theme') || localStorage.getItem('clean_cms_theme')) === 'dark';
};

function initCleanTinyMCE() {
    if (typeof tinymce === 'undefined') return;
    const isDark = isDarkActive();
    const gridCss = (window.MODO_GRID_CSS || []).slice();
    const gridStyle = window.MODO_GRID_STYLE || '';
    const themeCss = (window.MODO_THEME_CSS || []).slice();
    const themeStyle = window.MODO_THEME_STYLE || '';
    // The content canvas stays light in every admin theme; only the UI skin
    // (toolbar, dialogs) follows dark mode.
    const contentCss = ['default'].concat(gridCss, themeCss);

    tinymce.init({
        selector: '#editor',
        height: 520,
        menubar: <?= $enableMenubar ? 'true' : 'false' ?>,
        plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen table wordcount',
        toolbar: <?= json_encode($resolvedToolbar) ?>,
        skin: isDark ? 'oxide-dark' : 'oxide',
        content_css: contentCss,
        content_style: (window.MODO_EDITOR_BASE_STYLE || '') + themeStyle + (window.MODO_GRID_GUIDE || '') + gridStyle,
        body_class: window.MODO_THEME_BODY_CLASS || '',
        images_upload_handler: (blobInfo) => new Promise((resolve, reject) => {
            const fd = new FormData();
            fd.append('file', blobInfo.blob(), blobInfo.filename());
            fd.append('csrf', window.MODO_CSRF || '');
            fetch('upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then((r) => r.json())
                .then((d) => (d && d.location) ? resolve(d.location) : reject((d && d.error) || 'Upload failed'))
                .catch(() => reject('Upload failed'));
        }),
        automatic_uploads: true,
        relative_urls: false,
        remove_script_host: false,
        file_picker_types: 'image',
        file_picker_callback: (callback, value, meta) => {
            if (meta.filetype === 'image') {
                currentMediaTarget = 'tinymce_dialog';
                tinymceFilePickerCallback = callback;
                openModal();
            }
        },
        setup: (editor) => {
            editor.ui.registry.addButton('mediamanager', {
                icon: 'gallery',
                tooltip: '<?= _e('Insert from Media Library') ?>',
                onAction: () => {
                    currentMediaTarget = 'tinymce_direct';
                    openModal();
                }
            });
        }
    });
}

function switchTinyMCETheme() {
    if (typeof tinymce === 'undefined') return;
    const editor = tinymce.get('editor');
    if (editor) {
        editor.save();
        editor.remove();
    }
    document.querySelectorAll('link[id^="u"], link[href*="tinymce/skins"]').forEach(el => el.remove());
    setTimeout(() => { initCleanTinyMCE(); }, 50);
}

initCleanTinyMCE();

let lastThemeState = isDarkActive();
const themeObserver = new MutationObserver(() => {
    const currentThemeState = isDarkActive();
    if (currentThemeState !== lastThemeState) {
        lastThemeState = currentThemeState;
        switchTinyMCETheme();
    }
});

themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'class'] });
themeObserver.observe(document.body, { attributes: true, attributeFilter: ['data-theme', 'class'] });

updateHierarchicalSlugPreview();

// Uploads modal & actions
const uploadBtn = document.getElementById('upload-image-btn');
const libraryBtn = document.getElementById('library-image-btn');
const placeholderTrigger = document.getElementById('placeholder-trigger');
const fileInput = document.getElementById('image-file-input');
const hiddenInput = document.getElementById('featured_image');
const previewWrapper = document.getElementById('image-preview-wrapper');
const placeholderBox = document.getElementById('image-placeholder-box');
const previewImg = document.getElementById('image-preview');
const removeBtn = document.getElementById('remove-image-btn');

const modal = document.getElementById('media-modal');
const closeModalBtn = document.getElementById('close-modal-btn');
const cancelModalBtn = document.getElementById('cancel-modal-btn');
const insertMediaBtn = document.getElementById('insert-media-btn');
const mediaItems = document.querySelectorAll('.media-pick-item');
const mediaLoadMore = document.getElementById('media-load-more');
const mediaSelectAll = document.getElementById('media-select-all');
const mediaSelectAllWrap = document.getElementById('media-select-all-wrap');

const MEDIA_PAGE_SIZE = 24;
let mediaPageSize = MEDIA_PAGE_SIZE;

let mediaPickerMultiple = false;
let mediaPickerCallback = null;
const mediaPickerSelected = new Set();

const setMediaItemSelected = (item, selected) => {
    if (!item) return;
    item.classList.toggle('selected', selected);
    item.style.borderColor = selected ? 'var(--primary, #3b82f6)' : 'transparent';
    item.style.boxShadow = selected ? '0 0 0 2px rgba(59, 130, 246, .35)' : 'none';
};

const mediaItemMatchesFolder = (item) => {
    if (!mediaFolderFilter) return true;
    const value = mediaFolderFilter.value;
    return value === '__all__' || (item.getAttribute('data-folder') || '') === value;
};

const updateMediaSelectAll = () => {
    if (!mediaSelectAll) return;

    const matching = Array.from(mediaItems).filter(mediaItemMatchesFolder);
    const selected = matching.filter((item) => item.classList.contains('selected')).length;

    if (mediaSelectAllWrap) {
        mediaSelectAllWrap.style.display = mediaPickerMultiple && matching.length > 0 ? 'inline-flex' : 'none';
    }

    mediaSelectAll.disabled = !mediaPickerMultiple || matching.length === 0;
    mediaSelectAll.checked = matching.length > 0 && selected === matching.length;
    mediaSelectAll.indeterminate = selected > 0 && selected < matching.length;
};

const resetMediaSelection = () => {
    mediaPickerSelected.clear();
    mediaItems.forEach((item) => setMediaItemSelected(item, false));
    updateMediaSelectAll();
};

const mediaFolderFilter = document.getElementById('media-folder-filter');
const applyMediaFolderFilter = () => {
    const value = mediaFolderFilter ? mediaFolderFilter.value : '__all__';
    let matching = 0;
    let shown = 0;

    mediaItems.forEach((item) => {
        const matches = value === '__all__' || (item.getAttribute('data-folder') || '') === value;
        if (matches) {
            matching++;
        }
        const visible = matches && shown < mediaPageSize;
        item.style.display = visible ? '' : 'none';
        if (visible) {
            shown++;
        }
    });

    if (mediaLoadMore) {
        const remaining = matching - shown;
        mediaLoadMore.style.display = remaining > 0 ? 'inline-flex' : 'none';
        mediaLoadMore.textContent = <?= json_encode(__('Load more')) ?> + ' (' + remaining + ')';
    }

    updateMediaSelectAll();
};

if (mediaLoadMore) {
    mediaLoadMore.addEventListener('click', () => {
        mediaPageSize += MEDIA_PAGE_SIZE;
        applyMediaFolderFilter();
    });
}

if (mediaSelectAll) {
    mediaSelectAll.addEventListener('change', () => {
        const matching = Array.from(mediaItems).filter(mediaItemMatchesFolder);
        matching.forEach((item) => {
            const url = item.getAttribute('data-url') || '';
            if (!url) return;
            if (mediaSelectAll.checked) {
                mediaPickerSelected.add(url);
                setMediaItemSelected(item, true);
            } else {
                mediaPickerSelected.delete(url);
                setMediaItemSelected(item, false);
            }
        });
        updateMediaSelectAll();
    });
}

if (mediaFolderFilter) {
    mediaFolderFilter.addEventListener('change', () => {
        mediaPageSize = MEDIA_PAGE_SIZE;
        resetMediaSelection();
        applyMediaFolderFilter();
    });
}

const openModal = () => {
    if (modal) modal.style.display = 'flex';
    mediaPageSize = MEDIA_PAGE_SIZE;
    if (mediaFolderFilter) {
        mediaFolderFilter.value = '__all__';
    }
    applyMediaFolderFilter();
    updateMediaSelectAll();
};
const hideModal = () => {
    if (modal) modal.style.display = 'none';
    tinymceFilePickerCallback = null;
    currentMediaTarget = 'featured';
    mediaPickerMultiple = false;
    mediaPickerCallback = null;
    resetMediaSelection();
    updateMediaSelectAll();
    if (insertMediaBtn) insertMediaBtn.style.display = 'none';
};

// Shared picker API for custom-field galleries (multi-select). Existing single
// select flows (featured image / TinyMCE) keep using currentMediaTarget.
window.ModoMediaPicker = {
    open(options) {
        options = options || {};
        mediaPickerMultiple = !!options.multiple;
        mediaPickerCallback = typeof options.onSelect === 'function' ? options.onSelect : null;
        resetMediaSelection();
        if (insertMediaBtn) insertMediaBtn.style.display = mediaPickerMultiple ? 'inline-flex' : 'none';
        currentMediaTarget = 'callback';
        openModal();
    }
};

if (insertMediaBtn) {
    insertMediaBtn.addEventListener('click', () => {
        const urls = Array.from(mediaPickerSelected);
        const callback = mediaPickerCallback;
        hideModal();
        if (callback && urls.length) callback(urls);
    });
}

const triggerFileUpload = () => fileInput && fileInput.click();
if (uploadBtn) uploadBtn.addEventListener('click', triggerFileUpload);
if (placeholderTrigger) placeholderTrigger.addEventListener('click', triggerFileUpload);

if (fileInput) {
    fileInput.addEventListener('change', () => {
        if (!fileInput.files.length) return;
        const formData = new FormData();
        formData.append('file', fileInput.files[0]);
        formData.append('csrf', window.MODO_CSRF || '');
        if (uploadBtn) {
            uploadBtn.innerText = '<?= _e('Uploading...') ?>';
            uploadBtn.disabled = true;
        }

        fetch('upload.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (uploadBtn) {
                uploadBtn.disabled = false;
                uploadBtn.innerText = '<?= _e('Replace') ?>';
            }
            if (data.location) {
                hiddenInput.value = data.location;
                previewImg.src = data.location;
                previewWrapper.style.display = 'block';
                placeholderBox.style.display = 'none';
                if (removeBtn) removeBtn.style.display = 'inline-block';
            } else {
                UI.alert({ title: '<?= _e('Upload Error') ?>', message: data.error || 'Upload failed.', danger: true });
            }
        }).catch(() => {
            if (uploadBtn) {
                uploadBtn.disabled = false;
                uploadBtn.innerText = '<?= _e('Replace') ?>';
            }
            UI.alert({ title: '<?= _e('Upload Error') ?>', message: 'Network error during upload.', danger: true });
        });
    });
}

if (removeBtn) {
    removeBtn.addEventListener('click', () => {
        hiddenInput.value = '';
        previewImg.src = '';
        previewWrapper.style.display = 'none';
        placeholderBox.style.display = 'block';
        removeBtn.style.display = 'none';
        if (uploadBtn) uploadBtn.innerText = '<?= _e('Upload') ?>';
    });
}

if (libraryBtn) libraryBtn.addEventListener('click', () => { currentMediaTarget = 'featured'; openModal(); });
if (closeModalBtn) closeModalBtn.addEventListener('click', hideModal);
if (cancelModalBtn) cancelModalBtn.addEventListener('click', hideModal);

if (modal) {
    modal.addEventListener('click', (e) => {
        if (e.target === modal) hideModal();
    });
}

mediaItems.forEach(item => {
    item.addEventListener('mouseenter', () => {
        item.style.borderColor = 'var(--primary, #3b82f6)';
        item.style.transform = 'translateY(-2px)';
        item.style.boxShadow = '0 6px 12px rgba(0,0,0,0.2)';
    });
    item.addEventListener('mouseleave', () => {
        const selected = item.classList.contains('selected');
        item.style.borderColor = selected ? 'var(--primary, #3b82f6)' : 'transparent';
        item.style.transform = 'translateY(0)';
        item.style.boxShadow = selected ? '0 0 0 2px rgba(59, 130, 246, .35)' : 'none';
    });
    item.addEventListener('click', () => {
        const selectedUrl = item.getAttribute('data-url');
        const altText = item.querySelector('img')?.getAttribute('alt') || '';

        if (mediaPickerMultiple) {
            if (mediaPickerSelected.has(selectedUrl)) {
                mediaPickerSelected.delete(selectedUrl);
                setMediaItemSelected(item, false);
            } else {
                mediaPickerSelected.add(selectedUrl);
                setMediaItemSelected(item, true);
            }
            updateMediaSelectAll();
            return;
        }

        if (currentMediaTarget === 'callback' && typeof mediaPickerCallback === 'function') {
            const callback = mediaPickerCallback;
            hideModal();
            callback([selectedUrl]);
            return;
        }

        if (currentMediaTarget === 'featured') {
            hiddenInput.value = selectedUrl;
            previewImg.src = selectedUrl;
            previewWrapper.style.display = 'block';
            placeholderBox.style.display = 'none';
            if (removeBtn) removeBtn.style.display = 'inline-block';
            if (uploadBtn) uploadBtn.innerText = '<?= _e('Replace') ?>';
        } else if (currentMediaTarget === 'tinymce_dialog') {
            if (typeof tinymceFilePickerCallback === 'function') {
                tinymceFilePickerCallback(selectedUrl, { alt: altText });
            }
        } else if (currentMediaTarget === 'tinymce_direct') {
            if (typeof tinymce !== 'undefined' && tinymce.activeEditor) {
                tinymce.activeEditor.insertContent(`<img src="${selectedUrl}" alt="${altText}" />`);
            }
        }

        hideModal();
    });
});

// Custom fields (page_meta) dynamic repeater with per-type widgets.
(function () {
    const cfList = document.getElementById('custom-fields-list');
    const cfTemplate = document.getElementById('custom-field-template');
    const cfAddBtn = document.getElementById('add-custom-field');
    if (!cfList || !cfTemplate || !cfAddBtn) return;

    const codeTypes = {
        html: 'htmlmixed',
        php: { name: 'application/x-httpd-php', startOpen: true },
        js: 'text/javascript',
        css: 'text/css',
        json: 'text/javascript',
        code: 'clike'
    };
    const cmMap = new Map();
    const tmMap = new Map();
    const tmPending = new Set();
    let editorsReady = false;

    const cfCard = document.getElementById('custom-fields-card');
    if (cfCard) {
        const activateTab = (name) => {
            cfCard.setAttribute('data-tab', name);
            cfCard.querySelectorAll('.cf-tab').forEach((b) => b.classList.toggle('active', b.getAttribute('data-tab') === name));
            if (name === 'fields') {
                cfList.querySelectorAll('.cf-w-code').forEach((ta) => { const cm = cmMap.get(ta); if (cm) cm.refresh(); });
                setTimeout(() => {
                    window.dispatchEvent(new Event('resize'));
                    if (!editorsReady) return;
                    // Editors created while the value panel was hidden need a
                    // visible container before TinyMCE can size their iframe.
                    cfList.querySelectorAll('.custom-field-row').forEach((row) => {
                        const type = currentType(row);
                        if (!isTinyMceType(type)) return;
                        const ta = q(row, '.cf-w-richtext');
                        if (ta && !tmMap.has(ta) && !tmPending.has(ta)) ensureEditor(row, type);
                    });
                    tmMap.forEach((editor) => ensureTinyMceHeight(editor));
                }, 80);
            }
        };
        cfCard.querySelectorAll('.cf-tab').forEach((btn) => {
            btn.addEventListener('click', () => activateTab(btn.getAttribute('data-tab')));
        });
        activateTab(cfCard.getAttribute('data-tab') || 'fields');
    }

    const q = (row, sel) => row.querySelector(sel);
    const hidden = (row) => q(row, '.cf-value');
    const currentType = (row) => { const s = q(row, '.cf-type'); return s ? s.value : 'text'; };

    const galleryItemTemplate = document.getElementById('cf-gallery-item-template');
    const galleryDefaults = {
        columns: '3',
        gap: '14',
        ratio: '1',
        lightbox: '1',
        captions: '1'
    };
    const isTinyMceType = (type) => type === 'richtext' || type === 'tinymce';

    function applyImageValue(row, url) {
        const value = url ? String(url) : '';
        const urlInput = q(row, '.cf-image-url');
        if (urlInput) urlInput.value = value;
        const preview = q(row, '.cf-image-preview');
        if (preview) {
            preview.src = value;
            preview.style.display = value ? 'block' : 'none';
        }
        const h = hidden(row);
        if (h) h.value = value;
    }

    function normalizeGalleryValue(raw) {
        let data = raw;
        if (typeof raw === 'string') {
            const trimmed = raw.trim();
            if (trimmed === '') {
                data = null;
            } else {
                try { data = JSON.parse(trimmed); } catch (err) { data = null; }
            }
        }
        if (!data || typeof data !== 'object') data = {};

        const itemsRaw = Array.isArray(data.items) ? data.items : (Array.isArray(data) ? data : []);
        const items = itemsRaw
            .filter((item) => item && typeof item === 'object' && String(item.image || '').trim() !== '')
            .map((item) => ({
                image: String(item.image || '').trim(),
                alt: String(item.alt || '').trim(),
                caption: String(item.caption || '').trim(),
                link: String(item.link || '').trim()
            }));

        const optionsRaw = (data.options && typeof data.options === 'object') ? data.options : {};
        const options = Object.assign({}, galleryDefaults);
        if (optionsRaw.columns !== undefined) {
            options.columns = String(Math.max(1, Math.min(6, parseInt(optionsRaw.columns, 10) || 3)));
        }
        if (optionsRaw.gap !== undefined) {
            options.gap = String(Math.max(0, Math.min(60, parseInt(optionsRaw.gap, 10) || 0)));
        }
        if (optionsRaw.ratio !== undefined) {
            const ratio = String(optionsRaw.ratio);
            options.ratio = ['1', '1.3333', '1.5', '1.7778', 'auto'].indexOf(ratio) !== -1 ? ratio : '1';
        }
        ['lightbox', 'captions'].forEach((flag) => {
            if (optionsRaw[flag] !== undefined) {
                const value = optionsRaw[flag];
                options[flag] = (value === false || value === null || value === '' || value === '0' || value === 0) ? '0' : '1';
            }
        });

        return { items, options };
    }

    function galleryState(row) {
        if (!row.__cfGallery) {
            const h = hidden(row);
            row.__cfGallery = normalizeGalleryValue(h ? h.value : '');
        }
        return row.__cfGallery;
    }

    function writeGallery(row) {
        const h = hidden(row);
        if (!h) return;
        const state = galleryState(row);
        h.value = JSON.stringify({ items: state.items, options: state.options });
    }

    function applyGalleryOptions(row, state) {
        const columns = q(row, '.cf-gallery-columns');
        if (columns) columns.value = state.options.columns;
        const gap = q(row, '.cf-gallery-gap');
        if (gap) gap.value = state.options.gap;
        const ratio = q(row, '.cf-gallery-ratio');
        if (ratio) ratio.value = state.options.ratio;
        const lightbox = q(row, '.cf-gallery-lightbox');
        if (lightbox) lightbox.checked = state.options.lightbox === '1';
        const captions = q(row, '.cf-gallery-captions');
        if (captions) captions.checked = state.options.captions === '1';
    }

    /**
     * Builds the admin thumbnail URL for a gallery item. Gallery items store
     * public image URLs, while thumb.php expects the path below uploads/.
     * Remote images are left untouched.
     */
    function cfGalleryThumbUrl(url, width = 160) {
        if (!url) return '';

        try {
            const parsed = new URL(String(url), window.location.origin);
            if (parsed.origin !== window.location.origin) return String(url);

            const stored = parsed.pathname.replace(/^\/+/, '');
            if (!stored) return String(url);

            return 'thumb.php?p=' + encodeURIComponent(stored) + '&w=' + encodeURIComponent(String(width));
        } catch (e) {
            return String(url);
        }
    }

    function renderGallery(row) {
        const state = galleryState(row);
        applyGalleryOptions(row, state);

        const itemsBox = q(row, '.cf-gallery-items');
        if (!itemsBox) return;
        itemsBox.innerHTML = '';

        if (galleryItemTemplate && galleryItemTemplate.content && galleryItemTemplate.content.firstElementChild) {
            state.items.forEach((item, index) => {
                const node = galleryItemTemplate.content.firstElementChild.cloneNode(true);
                node.setAttribute('data-index', String(index));

                const img = q(node, 'img');
                if (img) {
                    img.src = cfGalleryThumbUrl(item.image);
                    img.loading = 'lazy';
                    img.decoding = 'async';
                }

                const alt = q(node, '.cf-gallery-alt');
                if (alt) {
                    alt.value = item.alt;
                    alt.addEventListener('input', () => {
                        state.items[index].alt = alt.value;
                        writeGallery(row);
                    });
                }

                const caption = q(node, '.cf-gallery-caption');
                if (caption) {
                    caption.value = item.caption;
                    caption.addEventListener('input', () => {
                        state.items[index].caption = caption.value;
                        writeGallery(row);
                    });
                }

                const link = q(node, '.cf-gallery-link');
                if (link) {
                    link.value = item.link;
                    link.addEventListener('input', () => {
                        state.items[index].link = link.value;
                        writeGallery(row);
                    });
                }

                const remove = q(node, '.cf-gallery-remove');
                if (remove) {
                    remove.addEventListener('click', () => {
                        state.items.splice(index, 1);
                        writeGallery(row);
                        renderGallery(row);
                    });
                }

                itemsBox.appendChild(node);
            });
        }

        const empty = q(row, '.cf-gallery-empty');
        if (empty) empty.style.display = state.items.length ? 'none' : 'block';
    }

    function ensureGallery(row) {
        const wrap = q(row, '.cf-w-gallery');
        if (!wrap) return;

        if (!wrap.__cfGalleryBound) {
            wrap.__cfGalleryBound = true;

            const addBtn = q(row, '.cf-gallery-add');
            if (addBtn) {
                addBtn.addEventListener('click', () => {
                    if (!window.ModoMediaPicker || typeof window.ModoMediaPicker.open !== 'function') return;
                    window.ModoMediaPicker.open({
                        multiple: true,
                        onSelect: (urls) => {
                            const state = galleryState(row);
                            (urls || []).forEach((url) => {
                                if (url) state.items.push({ image: url, alt: '', caption: '', link: '' });
                            });
                            writeGallery(row);
                            renderGallery(row);
                        }
                    });
                });
            }

            const uploadBtn = q(row, '.cf-gallery-upload');
            const fileIn = q(row, '.cf-gallery-file');
            if (uploadBtn && fileIn) uploadBtn.addEventListener('click', () => fileIn.click());
            if (fileIn) {
                fileIn.addEventListener('change', () => {
                    const files = Array.from(fileIn.files || []);
                    if (!files.length) return;

                    Promise.all(files.map((file) => {
                        const fd = new FormData();
                        fd.append('file', file);
                        fd.append('csrf', window.MODO_CSRF || '');
                        return fetch('upload.php', { method: 'POST', body: fd })
                            .then((response) => response.json())
                            .then((data) => (data && data.location) ? data.location : '')
                            .catch(() => '');
                    })).then((urls) => {
                        const state = galleryState(row);
                        urls.filter(Boolean).forEach((url) => {
                            state.items.push({ image: url, alt: '', caption: '', link: '' });
                        });
                        writeGallery(row);
                        renderGallery(row);
                    });

                    fileIn.value = '';
                });
            }

            const itemsBox = q(row, '.cf-gallery-items');
            if (itemsBox) {
                let dragItem = null;

                itemsBox.addEventListener('dragstart', (event) => {
                    const handle = event.target.closest('.cf-gallery-drag');
                    if (!handle) {
                        event.preventDefault();
                        return;
                    }
                    dragItem = handle.closest('.cf-gallery-item');
                    if (dragItem) {
                        dragItem.classList.add('is-dragging');
                        event.dataTransfer.effectAllowed = 'move';
                    }
                });

                itemsBox.addEventListener('dragend', () => {
                    if (!dragItem) return;
                    dragItem.classList.remove('is-dragging');
                    const order = Array.from(itemsBox.querySelectorAll('.cf-gallery-item'))
                        .map((node) => parseInt(node.getAttribute('data-index'), 10))
                        .filter((index) => !isNaN(index));
                    const state = galleryState(row);
                    state.items = order.map((index) => state.items[index]).filter(Boolean);
                    dragItem = null;
                    writeGallery(row);
                    renderGallery(row);
                });

                itemsBox.addEventListener('dragover', (event) => {
                    event.preventDefault();
                    if (!dragItem) return;
                    const target = event.target.closest('.cf-gallery-item');
                    if (!target || target === dragItem) return;
                    const rect = target.getBoundingClientRect();
                    const after = (event.clientY - rect.top) > rect.height / 2;
                    itemsBox.insertBefore(dragItem, after ? target.nextSibling : target);
                });
            }

            [['.cf-gallery-columns', 'columns'], ['.cf-gallery-gap', 'gap'], ['.cf-gallery-ratio', 'ratio']].forEach(([selector, key]) => {
                const el = q(row, selector);
                if (!el) return;
                const sync = () => {
                    const state = galleryState(row);
                    state.options[key] = el.value;
                    writeGallery(row);
                };
                el.addEventListener('input', sync);
                el.addEventListener('change', sync);
            });

            [['.cf-gallery-lightbox', 'lightbox'], ['.cf-gallery-captions', 'captions']].forEach(([selector, key]) => {
                const el = q(row, selector);
                if (!el) return;
                el.addEventListener('change', () => {
                    const state = galleryState(row);
                    state.options[key] = el.checked ? '1' : '0';
                    writeGallery(row);
                });
            });
        }

        renderGallery(row);
    }

    function commit(row) {
        const type = currentType(row);
        const h = hidden(row);
        if (!h) return;
        if (codeTypes[type]) {
            const cm = cmMap.get(q(row, '.cf-w-code'));
            if (cm) h.value = cm.getValue();
        } else if (isTinyMceType(type)) {
            const ed = tmMap.get(q(row, '.cf-w-richtext'));
            if (ed) h.value = ed.getContent();
        } else if (type === 'gallery') {
            writeGallery(row);
        } else if (type === 'checkbox') {
            const cb = q(row, '.cf-check-input');
            if (cb) h.value = cb.checked ? '1' : '0';
        } else if (type === 'image') {
            const u = q(row, '.cf-image-url');
            if (u) h.value = u.value;
        } else if (type === 'textarea') {
            const t = q(row, '.cf-w-textarea');
            if (t) h.value = t.value;
        } else if (type === 'number') {
            const n = q(row, '.cf-w-number');
            if (n) h.value = n.value;
        } else {
            const t = q(row, '.cf-w-text');
            if (t) h.value = t.value;
        }
    }

    /**
     * TinyMCE can end up with a collapsed edit area when the field was sized
     * while hidden (or when a second init raced the first one). Resize it once
     * more after layout and enforce a usable height as a last resort.
     */
    function ensureTinyMceHeight(editor) {
        if (!editor || !editor.initialized) return;
        const container = editor.getContainer();
        if (!container) return;
        const area = container.querySelector('.tox-edit-area');
        const iframe = container.querySelector('.tox-edit-area iframe');
        if (!area || !iframe) return;

        try {
            editor.dispatch('ResizeWindow');
            editor.dispatch('ResizeEditor');
        } catch (err) {}

        requestAnimationFrame(() => {
            if (iframe.offsetHeight >= 40) return;
            iframe.style.minHeight = '300px';
            area.style.minHeight = '300px';
            container.style.minHeight = '340px';
        });
    }

    function ensureEditor(row, type) {
        if (isTinyMceType(type)) {
            const ta = q(row, '.cf-w-richtext');
            if (!ta || tmMap.has(ta) || tmPending.has(ta) || typeof tinymce === 'undefined') return;
            // Defer init while the "Settings" tab hides the value panel; the
            // tab switch triggers ensureEditor() again once the field is visible.
            if (!ta.offsetParent) return;
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            tmPending.add(ta);
            const initPromise = tinymce.init({
                target: ta,
                height: 300,
                menubar: false,
                plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen table wordcount',
                toolbar: 'undo redo | blocks | bold italic underline strikethrough | alignleft aligncenter alignright | bullist numlist outdent indent | link image table | code fullscreen',
                skin: isDark ? 'oxide-dark' : 'oxide',
                content_css: ['default'].concat(window.MODO_GRID_CSS || [], window.MODO_THEME_CSS || []),
                content_style: (window.MODO_EDITOR_BASE_STYLE || '') + (window.MODO_THEME_STYLE || '') + (window.MODO_GRID_GUIDE || '') + (window.MODO_GRID_STYLE || ''),
                body_class: window.MODO_THEME_BODY_CLASS || '',
                relative_urls: false,
                remove_script_host: false,
                images_upload_handler: (blobInfo) => new Promise((resolve, reject) => {
            const fd = new FormData();
            fd.append('file', blobInfo.blob(), blobInfo.filename());
            fd.append('csrf', window.MODO_CSRF || '');
            fetch('upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then((r) => r.json())
                .then((d) => (d && d.location) ? resolve(d.location) : reject((d && d.error) || 'Upload failed'))
                .catch(() => reject('Upload failed'));
        }),
                automatic_uploads: true,
                setup: (editor) => {
                    editor.on('init', () => {
                        tmPending.delete(ta);
                        tmMap.set(ta, editor);
                        ensureTinyMceHeight(editor);
                        setTimeout(() => ensureTinyMceHeight(editor), 250);
                    });
                    editor.on('change', () => { const h = hidden(row); if (h) h.value = editor.getContent(); });
                }
            });
            if (initPromise && typeof initPromise.catch === 'function') {
                initPromise.catch(() => tmPending.delete(ta));
            }
        } else if (codeTypes[type]) {
            const ta = q(row, '.cf-w-code');
            if (!ta || cmMap.has(ta) || typeof CodeMirror === 'undefined') return;
            const cm = CodeMirror.fromTextArea(ta, {
                lineNumbers: true, lineWrapping: true, theme: 'dracula',
                mode: codeTypes[type], indentUnit: 4, tabSize: 4, viewportMargin: Infinity
            });
            cm.on('change', () => { const h = hidden(row); if (h) h.value = cm.getValue(); });
            cmMap.set(ta, cm);
        }
    }

    function showWidget(row, type) {
        row.setAttribute('data-type', type);
        const richTa = q(row, '.cf-w-richtext');
        const richEditor = richTa ? tmMap.get(richTa) : null;
        if (richEditor) richEditor.getContainer().style.display = 'none';
        row.querySelectorAll('.cf-widget').forEach((w) => { w.style.display = 'none'; });
        let sel = '.cf-w-text';
        if (type === 'textarea') sel = '.cf-w-textarea';
        else if (isTinyMceType(type)) sel = '.cf-w-richtext';
        else if (type === 'image') sel = '.cf-w-image';
        else if (type === 'gallery') sel = '.cf-w-gallery';
        else if (type === 'checkbox') sel = '.cf-w-checkbox';
        else if (type === 'number') sel = '.cf-w-number';
        else if (codeTypes[type]) sel = '.cf-w-code';
        const w = q(row, sel);
        if (w) w.style.display = (type === 'checkbox') ? 'flex' : 'block';
        ensureEditor(row, type);
        if (isTinyMceType(type) && richEditor) {
            richEditor.getContainer().style.display = 'block';
            setTimeout(() => ensureTinyMceHeight(richEditor), 30);
        }
        if (type === 'gallery') {
            ensureGallery(row);
        }
        if (codeTypes[type]) {
            const cm = cmMap.get(q(row, '.cf-w-code'));
            if (cm) setTimeout(() => cm.refresh(), 40);
        }
    }


    function wireRow(row) {
        const h = hidden(row);
        const val = h ? h.value : '';
        const set = (sel, v) => { const el = q(row, sel); if (el) el.value = v; };
        set('.cf-w-text', val);
        set('.cf-w-textarea', val);
        set('.cf-w-richtext', val);
        set('.cf-w-code', val);
        set('.cf-w-number', val);
        set('.cf-image-url', val);
        const cb = q(row, '.cf-check-input'); if (cb) cb.checked = (val === '1' || val === 'true');
        const ip = q(row, '.cf-image-preview'); if (ip) { ip.src = val; ip.style.display = val ? 'block' : 'none'; }

        showWidget(row, currentType(row));

        ['.cf-w-text', '.cf-w-textarea', '.cf-w-number', '.cf-image-url'].forEach((s) => {
            const el = q(row, s);
            if (el) el.addEventListener('input', () => { if (h) h.value = el.value; });
        });
        const richFallback = q(row, '.cf-w-richtext');
        if (richFallback) richFallback.addEventListener('input', () => { if (h) h.value = richFallback.value; });
        const cbx = q(row, '.cf-check-input');
        if (cbx) cbx.addEventListener('change', () => { if (h) h.value = cbx.checked ? '1' : '0'; });

        const keyInput = q(row, '.cf-key');
        const labelEl = q(row, '.cf-field-label');
        const labelHidden = q(row, '.cf-label');
        if (keyInput && labelEl) {
            keyInput.addEventListener('input', () => {
                labelEl.textContent = keyInput.value;
                if (labelHidden && !labelHidden.value.trim()) {
                    labelHidden.value = keyInput.value;
                }
            });
        }

        const libBtn = q(row, '.cf-image-library');
        if (libBtn) {
            libBtn.addEventListener('click', () => {
                if (!window.ModoMediaPicker || typeof window.ModoMediaPicker.open !== 'function') return;
                window.ModoMediaPicker.open({
                    multiple: false,
                    onSelect: (urls) => {
                        const url = Array.isArray(urls) ? urls[0] : urls;
                        if (url) applyImageValue(row, url);
                    }
                });
            });
        }

        const fileIn = q(row, '.cf-image-file');
        const upBtn = q(row, '.cf-image-upload');
        if (upBtn && fileIn) upBtn.addEventListener('click', () => fileIn.click());
        if (fileIn) fileIn.addEventListener('change', () => {
            if (!fileIn.files.length) return;
            const fd = new FormData();
            fd.append('file', fileIn.files[0]);
            fd.append('csrf', window.MODO_CSRF || '');
            fetch('upload.php', { method: 'POST', body: fd })
                .then((r) => r.json())
                .then((data) => {
                    if (data.location) {
                        applyImageValue(row, data.location);
                    }
                }).catch(() => {});
            fileIn.value = '';
        });
        const clr = q(row, '.cf-image-clear');
        if (clr) clr.addEventListener('click', () => {
            applyImageValue(row, '');
        });

        const sel = q(row, '.cf-type');
        if (sel) sel.addEventListener('change', () => onTypeChange(row));
    }

    function setRowValue(row, value) {
        const h = hidden(row);
        const v = (value == null) ? '' : String(value);
        if (h) h.value = v;
        const set = (sel, val) => { const el = q(row, sel); if (el) el.value = val; };
        set('.cf-w-text', v);
        set('.cf-w-textarea', v);
        set('.cf-w-number', v);
        set('.cf-image-url', v);
        const cb = q(row, '.cf-check-input'); if (cb) cb.checked = (v === '1' || v === 'true');
        const ip = q(row, '.cf-image-preview'); if (ip) { ip.src = v; ip.style.display = v ? 'block' : 'none'; }
        const rt = q(row, '.cf-w-richtext'); if (rt) rt.value = v;
        const rted = tmMap.get(rt); if (rted) rted.setContent(v);
        const cd = q(row, '.cf-w-code'); if (cd) cd.value = v;
        const cm = cmMap.get(cd); if (cm) cm.setValue(v);

        if (currentType(row) === 'gallery') {
            row.__cfGallery = normalizeGalleryValue(v);
            writeGallery(row);
            ensureGallery(row);
        }
    }

    function onTypeChange(row) {
        const h = hidden(row);
        setRowValue(row, h ? h.value : '');
        showWidget(row, currentType(row));
    }

    function addRow() {
        const node = cfTemplate.content.firstElementChild;
        if (!node) return null;
        const clone = node.cloneNode(true);
        cfList.appendChild(clone);
        wireRow(clone);
        return clone;
    }

    function findRowByKey(key) {
        const k = String(key || '').toLowerCase().trim();
        if (k === '') return null;
        let found = null;
        cfList.querySelectorAll('.custom-field-row').forEach((row) => {
            if (found) return;
            const ki = q(row, '.cf-key');
            if (ki && ki.value.toLowerCase().trim() === k) found = row;
        });
        return found;
    }

    // Copy field definitions and/or values from another page/post.
    const cfSourcesEl = document.getElementById('cf-sources-data');
    let cfSources = [];
    try { cfSources = JSON.parse(cfSourcesEl ? cfSourcesEl.textContent : '[]') || []; } catch (err) { cfSources = []; }

    const cfCopyBtn = document.getElementById('cf-copy-btn');
    const cfSourceSel = document.getElementById('cf-source');
    const cfCopySettings = document.getElementById('cf-copy-settings');
    const cfCopyValues = document.getElementById('cf-copy-values');
    const CF_I18N = <?= json_encode([
        'confirmTitle' => __('Copy custom fields?'),
        'confirmMsg'   => __('Copy custom fields from "%s"? Fields with the same key will be updated, new ones will be added.'),
        'okText'       => __('Copy fields'),
        'cancelText'   => __('Cancel'),
        'noSource'     => __('Select a source page or post first.'),
        'copied'       => __('Copied fields: %s'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    if (cfCopyBtn && cfSourceSel) {
        cfCopyBtn.addEventListener('click', () => {
            const srcId = cfSourceSel.value;
            if (!srcId) {
                if (window.UI) { UI.alert({ title: CF_I18N.confirmTitle, message: CF_I18N.noSource }); }
                return;
            }
            const src = cfSources.find((s) => String(s.id) === String(srcId));
            if (!src || !Array.isArray(src.fields)) return;

            const doSettings = !cfCopySettings || cfCopySettings.checked;
            const doValues = !cfCopyValues || cfCopyValues.checked;

            const runCopy = () => {
                let count = 0;
                src.fields.forEach((f) => {
                    let row = findRowByKey(f.key);
                    if (!row) {
                        if (!doSettings) return;
                        row = addRow();
                        if (!row) return;
                        const ki = q(row, '.cf-key'); if (ki) ki.value = f.key;
                        const lbl = q(row, '.cf-field-label'); if (lbl) lbl.textContent = f.label || f.key;
                        const lh = q(row, '.cf-label'); if (lh) lh.value = f.label || f.key;
                    }
                    const sel = q(row, '.cf-type');
                    if (doSettings && sel) sel.value = f.type;
                    if (doValues) setRowValue(row, f.value);
                    showWidget(row, currentType(row));
                    count++;
                });
                if (window.UI) {
                    UI.alert({ title: CF_I18N.confirmTitle, message: CF_I18N.copied.replace('%s', String(count)) });
                }
            };

            if (window.UI && UI.confirm) {
                UI.confirm({
                    title: CF_I18N.confirmTitle,
                    message: CF_I18N.confirmMsg.replace('%s', src.title),
                    okText: CF_I18N.okText,
                    cancelText: CF_I18N.cancelText
                }).then((ok) => { if (ok) runCopy(); });
            } else {
                runCopy();
            }
        });
    }

    cfList.querySelectorAll('.custom-field-row').forEach(wireRow);
    editorsReady = true;

    cfAddBtn.addEventListener('click', () => addRow());

    cfList.addEventListener('click', (e) => {
        const btn = e.target.closest('.remove-custom-field');
        if (!btn) return;
        const row = btn.closest('.custom-field-row');
        if (!row) return;
        const cd = q(row, '.cf-w-code');
        if (cd && cmMap.has(cd)) { cmMap.get(cd).toTextArea(); cmMap.delete(cd); }
        const rt = q(row, '.cf-w-richtext');
        if (rt && tmMap.has(rt)) { try { tmMap.get(rt).remove(); } catch (err) {} tmMap.delete(rt); }
        row.remove();
    });

    const ownerForm = cfList.closest('form');
    if (ownerForm) ownerForm.addEventListener('submit', () => {
        cfList.querySelectorAll('.custom-field-row').forEach(commit);
    });

    // ------------------------------------------------------------------
    // Page type presets: add default fields without overwriting content.
    // ------------------------------------------------------------------
    const pageTypeSelect = document.getElementById('page_type_id');
    const applyTypeBtn = document.getElementById('apply-page-type-defaults');
    const cfInitializedInput = document.getElementById('cf-initialized');
    const IS_NEW_PAGE = <?= $id === 0 ? 'true' : 'false' ?>;
    let pageTypePresets = [];
    try {
        const presetsEl = document.getElementById('page-types-data');
        pageTypePresets = JSON.parse(presetsEl ? presetsEl.textContent : '[]') || [];
    } catch (err) {
        pageTypePresets = [];
    }

    function markInitialized() {
        if (cfInitializedInput) cfInitializedInput.value = '1';
    }

    function hasAnyFields() {
        return cfList.querySelectorAll('.custom-field-row').length > 0;
    }

    function findPreset(typeId) {
        return pageTypePresets.find((preset) => String(preset.id) === String(typeId)) || null;
    }

    function setRowLabel(row, label) {
        const text = String(label || '').trim();
        if (text === '') return;
        const hiddenLabel = q(row, '.cf-label');
        if (hiddenLabel) hiddenLabel.value = text;
        const labelEl = q(row, '.cf-field-label');
        if (labelEl) labelEl.textContent = text;
    }

    function missingDefaultKeys(typeId) {
        const preset = findPreset(typeId);
        if (!preset || !Array.isArray(preset.fields)) return [];
        return preset.fields.filter((field) => !findRowByKey(field.field_key));
    }

    function applyTypeDefaults(typeId, options) {
        const opts = options || {};
        const preset = findPreset(typeId);
        if (!preset || !Array.isArray(preset.fields)) return 0;

        let added = 0;
        preset.fields.forEach((field) => {
            let row = findRowByKey(field.field_key);
            if (row) {
                if (opts.refreshTypes) {
                    const select = q(row, '.cf-type');
                    if (select) select.value = field.field_type;
                }
                setRowLabel(row, field.label || field.field_key);
                if (opts.overwriteValues) {
                    setRowValue(row, field.default_value);
                }
                showWidget(row, currentType(row));
                return;
            }

            row = addRow();
            if (!row) return;
            const keyInput = q(row, '.cf-key');
            if (keyInput) keyInput.value = field.field_key;
            setRowLabel(row, field.label || field.field_key);
            const typeInput = q(row, '.cf-type');
            if (typeInput) typeInput.value = field.field_type;
            setRowValue(row, field.default_value);
            showWidget(row, field.field_type);
            added++;
        });

        markInitialized();
        return added;
    }

    function onPageTypeChange(typeId) {
        if (!typeId) return;
        const missing = missingDefaultKeys(typeId);
        if (missing.length === 0) return;

        if (!hasAnyFields()) {
            applyTypeDefaults(typeId);
            return;
        }

        const title = <?= json_encode(__('Apply page type defaults'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const message = <?= json_encode(__('This page type has %s field(s) that are not on this document yet. Add them with their default values? Existing fields and values will not be changed.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>.replace('%s', String(missing.length));

        const run = () => {
            const added = applyTypeDefaults(typeId);
            if (window.UI) {
                UI.alert({
                    title: title,
                    message: added > 0
                        ? <?= json_encode(__('Added fields: %s'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>.replace('%s', String(added))
                        : <?= json_encode(__('All default fields are already present.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
                });
            }
        };

        if (window.UI && UI.confirm) {
            UI.confirm({
                title: title,
                message: message,
                okText: <?= json_encode(__('Add fields'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                cancelText: <?= json_encode(__('Cancel'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
            }).then((ok) => { if (ok) run(); });
        } else if (window.confirm(message)) {
            run();
        }
    }

    function onBaseTypeChange(baseType, typeId) {
        if (IS_NEW_PAGE && typeId > 0 && !hasAnyFields()) {
            applyTypeDefaults(typeId);
        }
    }

    window.ModoPageTypes = {
        applyTypeDefaults: applyTypeDefaults,
        onPageTypeChange: onPageTypeChange,
        onBaseTypeChange: onBaseTypeChange,
        hasAnyFields: hasAnyFields
    };

    if (applyTypeBtn) {
        applyTypeBtn.addEventListener('click', () => {
            const typeId = pageTypeSelect ? (parseInt(pageTypeSelect.value, 10) || 0) : 0;
            const title = <?= json_encode(__('Apply page type defaults'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            if (!typeId) {
                if (window.UI) {
                    UI.alert({ title: title, message: <?= json_encode(__('Select a page type first.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?> });
                }
                return;
            }
            const applyDefaultsInput = document.getElementById('apply-type-defaults');
            if (applyDefaultsInput) applyDefaultsInput.value = '1';
            const added = applyTypeDefaults(typeId);
            if (window.UI) {
                UI.alert({
                    title: title,
                    message: added > 0
                        ? <?= json_encode(__('Added fields: %s'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>.replace('%s', String(added))
                        : <?= json_encode(__('All default fields are already present.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
                });
            }
        });
    }

    // JavaScript handled the initial preset rendering, so the server does not
    // need to seed defaults again on submit.
    if (IS_NEW_PAGE) markInitialized();
})();

</script>
<script type="application/json" id="revisions-config">
<?= json_encode([
    'pageId'   => (int) $id,
    'csrf'     => Security::generateCsrfToken(),
    'endpoint' => 'revisions.php',
    'interval' => (int) $autosaveInterval,
    'enabled'  => (bool) ($id > 0 && $revisionsEnabled),
    'items'    => array_map(static function ($r) {
        return [
            'id'          => (int) $r['id'],
            'title'       => (string) $r['title'],
            'source'      => (string) $r['source'],
            'note'        => (string) ($r['note'] ?? ''),
            'content_len' => (int) $r['content_len'],
            'created_at'  => (string) $r['created_at'],
        ];
    }, $revisionList),
    'i18n'     => [
        'sources'      => [
            'save'     => __('Save'),
            'autosave' => __('Auto'),
            'manual'   => __('Manual'),
            'restore'  => __('Restore'),
        ],
        'restore'        => __('Restore'),
        'preview'        => __('Preview'),
        'close'          => __('Close'),
        'restoreTitle'   => __('Restore this version?'),
        'restoreMsg'     => __('The current content will be snapshotted first, so this action can be undone.'),
        'deleteTitle'    => __('Delete this revision?'),
        'deleteMsg'      => __('This version will be permanently removed.'),
        'noRevisions'    => __('No revisions yet.'),
        'snapshotSaved'  => __('Snapshot saved.'),
        'deleted'        => __('Revision deleted.'),
        'autosavedAt'    => __('Autosaved at %s'),
        'unsaved'        => __('You have unsaved changes.'),
        'error'          => __('Error'),
        'networkError'   => __('Network error. Please try again.'),
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
</script>
<script>
(function () {
    'use strict';
    var cfgEl = document.getElementById('revisions-config');
    if (!cfgEl) return;
    var cfg;
    try { cfg = JSON.parse(cfgEl.textContent); } catch (e) { return; }
    if (!cfg.enabled || !cfg.pageId) return;

    var i18n = cfg.i18n || {};
    var listEl = document.getElementById('revisions-list');
    var countEl = document.getElementById('revisions-count');
    var dot = document.getElementById('autosave-dot');
    var statusText = document.getElementById('autosave-status-text');
    var snapBtn = document.getElementById('revision-snapshot-btn');
    var form = document.querySelector('form');
    var dirty = false;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
        });
    }
    function sourceLabel(src) {
        return (i18n.sources && i18n.sources[src]) ? i18n.sources[src] : src;
    }
    function sourceColor(src) {
        switch (src) {
            case 'autosave': return '#f59e0b';
            case 'manual':   return '#8b5cf6';
            case 'restore':  return '#3b82f6';
            default:         return '#10b981';
        }
    }
    function renderItems(items) {
        if (!listEl) return;
        if (!items || !items.length) {
            listEl.innerHTML = '<p class="revisions-empty" style="font-size:12px;color:var(--text-muted);margin:0;">'
                + esc(i18n.noRevisions || 'No revisions yet.') + '</p>';
            return;
        }
        var html = '';
        items.forEach(function (it) {
            var col = sourceColor(it.source);
            html += '<div class="revision-row" data-id="' + it.id + '" style="border:1px solid var(--border-subtle);border-radius:var(--radius-sm);padding:8px 10px;display:flex;flex-direction:column;gap:6px;">'
                + '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">'
                +   '<span style="font-size:11px;color:var(--text-main);font-weight:600;">' + esc(it.created_at) + '</span>'
                +   '<span class="badge" style="font-size:10px;background:' + col + '22;border:1px solid ' + col + '55;color:' + col + ';">' + esc(sourceLabel(it.source)) + '</span>'
                + '</div>'
                + '<div style="display:flex;gap:6px;flex-wrap:wrap;">'
                +   '<button type="button" class="btn btn-secondary rev-restore" data-id="' + it.id + '" style="padding:3px 8px;font-size:11px;">' + esc(i18n.restore || 'Restore') + '</button>'
                +   '<button type="button" class="btn btn-secondary rev-preview" data-id="' + it.id + '" style="padding:3px 8px;font-size:11px;">' + esc(i18n.preview || 'Preview') + '</button>'
                +   '<button type="button" class="btn btn-danger-ghost rev-delete" data-id="' + it.id + '" style="padding:3px 8px;font-size:11px;" title="' + esc(i18n.deleteTitle || 'Delete') + '">&times;</button>'
                + '</div>'
                + '</div>';
        });
        listEl.innerHTML = html;
    }
    function updateCount(data) {
        if (countEl && typeof data.count === 'number') countEl.textContent = data.count;
    }
    function post(action, extra) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('csrf_token', cfg.csrf);
        fd.append('page_id', cfg.pageId);
        if (extra) { Object.keys(extra).forEach(function (k) { fd.append(k, extra[k]); }); }
        return fetch(cfg.endpoint, { method: 'POST', body: fd, headers: { 'X-CSRF-Token': cfg.csrf }, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { status: 'error', message: 'Bad response' }; }); });
    }
    function uiAlert(message) {
        if (window.UI) { UI.alert({ title: i18n.error || 'Error', message: message, danger: true }); }
        else { window.alert(message); }
    }

    renderItems(cfg.items);


    // --- Row actions --------------------------------------------------------
    if (listEl) {
        listEl.addEventListener('click', function (e) {
            var btn = e.target.closest('button');
            if (!btn) return;
            var id = parseInt(btn.getAttribute('data-id'), 10);
            if (btn.classList.contains('rev-restore')) doRestore(id);
            else if (btn.classList.contains('rev-preview')) doPreview(id);
            else if (btn.classList.contains('rev-delete')) doDelete(id);
        });
    }

    function doSnapshot() {
        if (snapBtn) snapBtn.disabled = true;
        post('snapshot').then(function (data) {
            if (snapBtn) snapBtn.disabled = false;
            if (data.status === 'success') {
                renderItems(data.items);
                updateCount(data);
                if (window.UI) UI.toast(i18n.snapshotSaved || 'Snapshot saved.');
            } else { uiAlert(data.message || 'Failed.'); }
        }).catch(function () { if (snapBtn) snapBtn.disabled = false; uiAlert(i18n.networkError || 'Network error.'); });
    }
    if (snapBtn) snapBtn.addEventListener('click', doSnapshot);

    function doRestore(id) {
        var go = function () {
            post('restore', { revision_id: id }).then(function (data) {
                if (data.status === 'success') {
                    window.location.href = 'page-edit.php?id=' + cfg.pageId + '&restored=1';
                } else { uiAlert(data.message || 'Failed.'); }
            }).catch(function () { uiAlert(i18n.networkError || 'Network error.'); });
        };
        if (window.UI && UI.confirm) {
            UI.confirm({ title: i18n.restoreTitle || 'Restore', message: i18n.restoreMsg || '', okText: i18n.restore || 'Restore', danger: true })
                .then(function (ok) { if (ok) go(); });
        } else if (window.confirm(i18n.restoreMsg || 'Restore this version?')) { go(); }
    }

    function doDelete(id) {
        var go = function () {
            post('delete', { revision_id: id }).then(function (data) {
                if (data.status === 'success') {
                    renderItems(data.items);
                    updateCount(data);
                    if (window.UI) UI.toast(i18n.deleted || 'Revision deleted.');
                } else { uiAlert(data.message || 'Failed.'); }
            }).catch(function () { uiAlert(i18n.networkError || 'Network error.'); });
        };
        if (window.UI && UI.confirm) {
            UI.confirm({ title: i18n.deleteTitle || 'Delete', message: i18n.deleteMsg || '', okText: i18n.deleteTitle || 'Delete', danger: true })
                .then(function (ok) { if (ok) go(); });
        } else if (window.confirm(i18n.deleteMsg || 'Delete this revision?')) { go(); }
    }


    function doPreview(id) {
        post('get', { revision_id: id }).then(function (data) {
            if (data.status !== 'success' || !data.revision) { uiAlert(data.message || 'Not found.'); return; }
            showPreview(data.revision);
        }).catch(function () { uiAlert(i18n.networkError || 'Network error.'); });
    }

    function showPreview(rev) {
        var overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(11,15,25,.8);z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(8px);';
        overlay.innerHTML = '<div style="width:100%;max-width:860px;height:80vh;background:var(--bg-card,#111827);border:1px solid var(--border-subtle);border-radius:12px;display:flex;flex-direction:column;overflow:hidden;">'
            + '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:14px 18px;border-bottom:1px solid var(--border-subtle);">'
            +   '<div style="min-width:0;"><div style="font-size:14px;font-weight:700;color:var(--text-main);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(rev.title) + '</div>'
            +   '<div style="font-size:11px;color:var(--text-muted);">' + esc(rev.created_at) + ' &bull; ' + esc(sourceLabel(rev.source)) + '</div></div>'
            +   '<button type="button" class="btn btn-secondary" data-close style="padding:5px 12px;font-size:12px;">' + esc(i18n.close || 'Close') + '</button>'
            + '</div>'
            + '<iframe data-preview style="flex:1;width:100%;border:0;background:#fff;"></iframe>'
            + '</div>';
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay || e.target.hasAttribute('data-close')) { document.body.removeChild(overlay); }
        });
        document.body.appendChild(overlay);
        var frame = overlay.querySelector('[data-preview]');
        var doc = frame.contentDocument || frame.contentWindow.document;
        doc.open();
        doc.write('<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:system-ui,Arial,sans-serif;padding:20px;color:#111;line-height:1.6;}img{max-width:100%;height:auto;}</style></head><body>' + (rev.content || '') + '</body></html>');
        doc.close();
    }

    // --- Autosave -----------------------------------------------------------
    if (form) {
        form.addEventListener('input', function () { dirty = true; });
        form.addEventListener('change', function () { dirty = true; });
        form.addEventListener('submit', function () { dirty = false; });
    }
    window.addEventListener('beforeunload', function (e) {
        if (dirty) { e.preventDefault(); e.returnValue = i18n.unsaved || 'You have unsaved changes.'; return e.returnValue; }
    });

    function autosave() {
        if (!form || !dirty) return;
        if (window.tinymce && typeof window.tinymce.triggerSave === 'function') { try { window.tinymce.triggerSave(); } catch (e) {} }
        sendAutosave();
    }

    function sendAutosave() {
        var fd = new FormData(form);
        fd.set('action', 'autosave');
        fd.set('csrf_token', cfg.csrf);
        fd.set('page_id', cfg.pageId);
        fetch(cfg.endpoint, { method: 'POST', body: fd, headers: { 'X-CSRF-Token': cfg.csrf }, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { status: 'error' }; }); })
            .then(function (data) {
                if (data.status === 'success') {
                    dirty = false;
                    renderItems(data.items);
                    updateCount(data);
                    if (dot) { dot.style.background = '#10b981'; }
                    if (statusText) {
                        statusText.textContent = (i18n.autosavedAt || 'Autosaved at %s').replace('%s', data.autosaved_at || new Date().toLocaleTimeString());
                    }
                }
            })
            .catch(function () { /* silent — autosave is best-effort */ });
    }
    if (cfg.interval && cfg.interval > 0) { setInterval(autosave, cfg.interval * 1000); }
})();
</script>

<script>
    // Generate missing preview thumbnails in small batches. The page is not
    // reloaded so unsaved document changes are preserved.
    (function () {
        const btn = document.getElementById('media-thumb-backfill-btn');
        if (!btn) return;

        const status = document.getElementById('media-thumb-status');
        const csrf = btn.getAttribute('data-csrf') || '';

        btn.addEventListener('click', async () => {
            btn.disabled = true;
            btn.textContent = <?= json_encode(__('Generating thumbnails...')) ?>;

            let offset = 0;
            let done = false;
            let failed = 0;
            while (!done) {
                const body = new URLSearchParams();
                body.set('csrf', csrf);
                body.set('offset', String(offset));
                body.set('limit', '5');

                try {
                    const response = await fetch('thumbs-backfill.php', { method: 'POST', body });
                    const data = await response.json();
                    if (!data.ok) throw new Error(data.error || 'failed');

                    offset = Number(data.next) || 0;
                    done = !!data.done;
                    failed += Number(data.failed) || 0;
                    btn.textContent = offset + ' / ' + data.total + (failed > 0 ? ' · errors: ' + failed : '');
                } catch (error) {
                    btn.disabled = false;
                    btn.textContent = <?= json_encode(__('Thumbnail generation failed.')) ?>;
                    return;
                }
            }

            btn.textContent = <?= json_encode(__('Thumbnails generated.')) ?> + (failed > 0 ? ' · errors: ' + failed : '');
            if (status) {
                window.setTimeout(() => { status.style.display = 'none'; }, 1500);
            }
        });

        // Build the preview cache in the background so the media modal is fast
        // on this and every later visit. Runs only when some thumbs are missing.
        try {
            const lockKey = 'modoThumbBackfillAt';
            const lastRun = parseInt(window.localStorage.getItem(lockKey) || '0', 10);
            if (Date.now() - lastRun > 10 * 60 * 1000) {
                window.localStorage.setItem(lockKey, String(Date.now()));
                window.setTimeout(() => {
                    if (!btn.disabled) btn.click();
                }, 2000);
            }
        } catch (error) {
            // Private mode / disabled storage: the button still works manually.
        }
    })();
</script>

<?php require_once __DIR__ . '/views/footer.php'; ?>