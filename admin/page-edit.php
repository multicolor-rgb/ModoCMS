<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Database;
use Core\Security;
use Core\Auth;
use Core\I18n;
use Core\Router;
use Core\Sitemap;

Auth::requireCapability('manage_pages');

$db = Database::getConnection();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$item = [
    'id' => 0,
    'title' => '', 'slug' => '', 'content' => '',
    'type' => $_GET['type'] ?? 'post', 'status' => 'published',
    'parent_id' => 0,
    'lang' => $_GET['lang'] ?? I18n::getDefaultLocale(),
    'translation_group' => $_GET['group'] ?? bin2hex(random_bytes(8)),
    'featured_image' => '', 'meta_title' => '', 'meta_description' => ''
];

$existingTags = '';

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
    }
}

// Load custom fields (page_meta) attached to this record.
$customFields = [];
if ($id > 0) {
    $metaStmt = $db->prepare("SELECT meta_key, meta_value, meta_type FROM page_meta WHERE page_id = :pid ORDER BY id ASC");
    $metaStmt->execute([':pid' => $id]);
    $customFields = $metaStmt->fetchAll();
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
    $srcMetaStmt = $db->prepare("SELECT meta_key, meta_value, meta_type FROM page_meta WHERE page_id = :pid ORDER BY id ASC");
    foreach ($srcRows as $srcRow) {
        $srcMetaStmt->execute([':pid' => $srcRow['id']]);
        $fields = [];
        foreach ($srcMetaStmt->fetchAll() as $m) {
            $fields[] = [
                'key'   => (string) $m['meta_key'],
                'value' => (string) $m['meta_value'],
                'type'  => (string) $m['meta_type'],
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

    $opts = '';
    foreach ($types as $tv => $tl) {
        $opts .= '<option value="' . $tv . '"' . ($tv === $type ? ' selected' : '') . '>'
               . htmlspecialchars(__($tl), ENT_QUOTES, 'UTF-8') . '</option>';
    }

    $checked = ((int) $val === 1) ? ' checked' : '';
    $imgShow = ($val !== '') ? 'block' : 'none';

    $lUpload   = htmlspecialchars(__('Upload'), ENT_QUOTES, 'UTF-8');
    $lImageUrl = htmlspecialchars(__('Image URL'), ENT_QUOTES, 'UTF-8');
    $lEnabled  = htmlspecialchars(__('Enabled'), ENT_QUOTES, 'UTF-8');
    $lRemove   = htmlspecialchars(__('Remove'), ENT_QUOTES, 'UTF-8');
    $lValue    = htmlspecialchars(__('Field Value'), ENT_QUOTES, 'UTF-8');

    return <<<HTML
<div class="custom-field-row" data-type="{$type}" style="border: 1px solid var(--border-subtle); border-radius: var(--radius-sm, 8px); padding: 12px; display: flex; flex-direction: column; gap: 8px;">
    <div class="cf-settings" style="display: flex; gap: 8px; flex-wrap: wrap;">
        <input class="form-control cf-key" type="text" name="custom_field_key[]" value="{$key}" placeholder="key" style="flex: 1 1 160px; min-width: 120px; font-size: 13px;">
        <select class="form-control cf-type" name="custom_field_type[]" style="flex: 0 0 140px; font-size: 13px;">{$opts}</select>
        <button type="button" class="btn btn-danger-ghost remove-custom-field" title="{$lRemove}" style="flex: 0 0 auto; font-size: 16px; padding: 4px 12px; line-height: 1;">&times;</button>
    </div>
    <div class="cf-value-part" style="display: flex; flex-direction: column; gap: 6px;">
        <label class="cf-field-label" style="font-size: 12px; font-weight: 600; color: var(--text-muted); font-family: monospace;">{$key}</label>
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
                <button type="button" class="btn btn-secondary cf-image-upload" style="font-size: 12px;">{$lUpload}</button>
                <button type="button" class="btn btn-danger-ghost cf-image-clear" style="font-size: 14px;">&times;</button>
                <input type="file" class="cf-image-file" accept="image/*" style="display: none;">
            </div>
            <img class="cf-image-preview" src="{$valE}" alt="" style="max-height: 90px; margin-top: 8px; border-radius: 6px; display: {$imgShow};">
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

    $title = trim($_POST['title'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $content = $_POST['content'] ?? '';
    $type = in_array($_POST['type'] ?? '', ['post', 'page'], true) ? $_POST['type'] : 'post';
    $status = $_POST['status'] ?? 'published';
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

    if ($slug === '') {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title), '-'));
    } else {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $slug), '-'));
    }

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

    if ($id > 0) {
        $stmt = $db->prepare("
            UPDATE pages 
            SET title = :t, slug = :s, content = :c, type = :tp, status = :st, 
                parent_id = :p, lang = :lg, translation_group = :tg, 
                featured_image = :img, meta_title = :mt, meta_description = :md, 
                updated_at = CURRENT_TIMESTAMP 
            WHERE id = :id
        ");
        $stmt->execute([
            ':t' => $title, ':s' => $slug, ':c' => $content, ':tp' => $type, ':st' => $status,
            ':p' => $parentId, ':lg' => $lang, ':tg' => $translation_group, 
            ':img' => $featured_image, ':mt' => $meta_title, ':md' => $meta_description, 
            ':id' => $id
        ]);
    } else {
        $stmt = $db->prepare("
            INSERT INTO pages (
                title, slug, content, type, status, parent_id, lang, 
                translation_group, featured_image, meta_title, meta_description, author_id
            ) VALUES (
                :t, :s, :c, :tp, :st, :p, :lg, :tg, :img, :mt, :md, :aid
            )
        ");
        $stmt->execute([
            ':t' => $title, ':s' => $slug, ':c' => $content, ':tp' => $type, ':st' => $status,
            ':p' => $parentId, ':lg' => $lang, ':tg' => $translation_group, 
            ':img' => $featured_image, ':mt' => $meta_title, ':md' => $meta_description, 
            ':aid' => Auth::id()
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
    }

    // Persist custom fields (page_meta): upsert posted keys, drop removed ones.
    $postedKeys = $_POST['custom_field_key'] ?? [];
    if (is_array($postedKeys)) {
        $postedValues = $_POST['custom_field_value'] ?? [];
        $postedTypes = $_POST['custom_field_type'] ?? [];
        $allowedTypes = ['text', 'textarea', 'richtext', 'image', 'checkbox', 'number', 'html', 'php', 'js', 'css', 'json', 'code'];
        $keptKeys = [];
        $metaUpsert = $db->prepare("INSERT OR REPLACE INTO page_meta (page_id, meta_key, meta_value, meta_type) VALUES (:pid, :k, :v, :t)");

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

            $metaUpsert->execute([
                ':pid' => $id,
                ':k'   => $metaKey,
                ':v'   => (string) ($postedValues[$i] ?? ''),
                ':t'   => $metaType,
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

    header('Location: page-edit.php?id=' . $id . '&saved=1');
    exit;
}

// Fetch all pages of matching language
$allLangPages = $db->prepare("SELECT id, parent_id, title, slug FROM pages WHERE type = 'page' AND lang = :lang ORDER BY title ASC");
$allLangPages->execute([':lang' => $item['lang']]);
$lookupPages = [];
foreach ($allLangPages->fetchAll() as $p) {
    $lookupPages[(int)$p['id']] = $p;
}

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
$uploadsDir = dirname(__DIR__) . '/uploads/';
$basePrefix = class_exists('Core\Router') ? Router::getBaseSubdirectory() : '';
$existingMedia = [];

if (is_dir($uploadsDir)) {
    $files = scandir($uploadsDir, SCANDIR_SORT_DESCENDING) ?: [];
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $filePath = $uploadsDir . $file;
        if (!is_file($filePath)) continue;

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'], true)) {
            $webPath = ($basePrefix !== '' ? rtrim($basePrefix, '/') : '') . '/uploads/' . $file;
            $existingMedia[] = [
                'name' => $file,
                'url'  => $webPath
            ];
        }
    }
}

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
</style>

<div class="page-header" style="margin-bottom: 24px;">
    <div>
        <h1 class="page-title" style="display: flex; align-items: center; gap: 10px;">
            <span><?= $id > 0 ? _e('Edit') . ': ' . Security::sanitize($item['title']) : _e('New Document') ?></span>
            <span class="badge" style="font-size: 12px; background: var(--bg-surface, #1e293b); border: 1px solid var(--border-subtle); color: var(--accent, #60a5fa); text-transform: uppercase;">
                <?= $item['type'] === 'post' ? _e('Blog Post') : _e('Static Page') ?>
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

<form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">

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
                        <option value="published" <?= $item['status'] === 'published' ? 'selected' : '' ?>><?= _e('Published') ?></option>
                        <option value="draft" <?= $item['status'] === 'draft' ? 'selected' : '' ?>><?= _e('Draft') ?></option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label class="form-label" for="type" style="font-size: 12px; font-weight: 600;"><?= _e('Document Type') ?></label>
                    <select class="form-control" name="type" id="type">
                        <option value="post" <?= $item['type'] === 'post' ? 'selected' : '' ?>><?= _e('Blog Post') ?></option>
                        <option value="page" <?= $item['type'] === 'page' ? 'selected' : '' ?>><?= _e('Static Page') ?></option>
                    </select>
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
            <?php if (empty($existingMedia)): ?>
                <div style="text-align: center; padding: 48px; color: var(--text-muted);">
                    <p style="font-size: 14px; margin: 0;"><?= _e('No uploaded images found in the library.') ?></p>
                </div>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(135px, 1fr)); gap: 16px;">
                    <?php foreach ($existingMedia as $media): ?>
                        <div class="media-pick-item" data-url="<?= htmlspecialchars($media['url'], ENT_QUOTES, 'UTF-8') ?>" style="cursor: pointer; border: 2px solid transparent; border-radius: var(--radius-sm, 8px); overflow: hidden; background: var(--bg-surface, #1e293b); transition: all 0.2s ease;">
                            <img src="<?= htmlspecialchars($media['url'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($media['name'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($media['name'], ENT_QUOTES, 'UTF-8') ?>" style="width: 100%; height: 110px; object-fit: cover; display: block;">
                            <div style="font-size: 11px; padding: 6px 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-align: center; color: var(--text-muted);">
                                <?= Security::sanitize($media['name']) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div style="padding: 14px 24px; border-top: 1px solid var(--border-subtle); display: flex; justify-content: flex-end; background: var(--bg-surface, #1e293b);">
            <button type="button" id="cancel-modal-btn" class="btn btn-secondary" style="font-size: 13px;"><?= _e('Close') ?></button>
        </div>
    </div>
</div>

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
const parentGroup = document.getElementById('parent-page-group');
const tagsGroup = document.getElementById('tags-group');

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
        } else {
            if (parentGroup) parentGroup.style.display = 'none';
            if (tagsGroup) tagsGroup.style.display = 'block';
        }
        updateHierarchicalSlugPreview();
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
           localStorage.getItem('theme') === 'dark';
};

function initCleanTinyMCE() {
    if (typeof tinymce === 'undefined') return;
    const isDark = isDarkActive();
    
    tinymce.init({
        selector: '#editor',
        height: 520,
        menubar: <?= $enableMenubar ? 'true' : 'false' ?>,
        plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen table wordcount',
        toolbar: <?= json_encode($resolvedToolbar) ?>,
        skin: isDark ? 'oxide-dark' : 'oxide',
        content_css: isDark ? 'dark' : 'default',
        images_upload_url: 'upload.php',
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
const mediaItems = document.querySelectorAll('.media-pick-item');

const openModal = () => { if (modal) modal.style.display = 'flex'; };
const hideModal = () => {
    if (modal) modal.style.display = 'none';
    tinymceFilePickerCallback = null;
    currentMediaTarget = 'featured';
};

const triggerFileUpload = () => fileInput && fileInput.click();
if (uploadBtn) uploadBtn.addEventListener('click', triggerFileUpload);
if (placeholderTrigger) placeholderTrigger.addEventListener('click', triggerFileUpload);

if (fileInput) {
    fileInput.addEventListener('change', () => {
        if (!fileInput.files.length) return;
        const formData = new FormData();
        formData.append('file', fileInput.files[0]);
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
        item.style.borderColor = 'transparent';
        item.style.transform = 'translateY(0)';
        item.style.boxShadow = 'none';
    });
    item.addEventListener('click', () => {
        const selectedUrl = item.getAttribute('data-url');
        const altText = item.querySelector('img')?.getAttribute('alt') || '';

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

    const cfCard = document.getElementById('custom-fields-card');
    if (cfCard) {
        const activateTab = (name) => {
            cfCard.setAttribute('data-tab', name);
            cfCard.querySelectorAll('.cf-tab').forEach((b) => b.classList.toggle('active', b.getAttribute('data-tab') === name));
            if (name === 'fields') {
                cfList.querySelectorAll('.cf-w-code').forEach((ta) => { const cm = cmMap.get(ta); if (cm) cm.refresh(); });
                setTimeout(() => window.dispatchEvent(new Event('resize')), 20);
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

    function commit(row) {
        const type = currentType(row);
        const h = hidden(row);
        if (!h) return;
        if (codeTypes[type]) {
            const cm = cmMap.get(q(row, '.cf-w-code'));
            if (cm) h.value = cm.getValue();
        } else if (type === 'richtext') {
            const ed = tmMap.get(q(row, '.cf-w-richtext'));
            if (ed) h.value = ed.getContent();
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

    function ensureEditor(row, type) {
        if (type === 'richtext') {
            const ta = q(row, '.cf-w-richtext');
            if (!ta || tmMap.has(ta) || typeof tinymce === 'undefined') return;
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            tinymce.init({
                target: ta,
                height: 300,
                menubar: false,
                plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen table wordcount',
                toolbar: 'undo redo | blocks | bold italic underline strikethrough | alignleft aligncenter alignright | bullist numlist outdent indent | link image table | code fullscreen',
                skin: isDark ? 'oxide-dark' : 'oxide',
                content_css: isDark ? 'dark' : 'default',
                relative_urls: false,
                remove_script_host: false,
                images_upload_url: 'upload.php',
                automatic_uploads: true,
                setup: (editor) => {
                    editor.on('init', () => tmMap.set(ta, editor));
                    editor.on('change', () => { const h = hidden(row); if (h) h.value = editor.getContent(); });
                }
            });
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
        row.querySelectorAll('.cf-widget').forEach((w) => { w.style.display = 'none'; });
        let sel = '.cf-w-text';
        if (type === 'textarea') sel = '.cf-w-textarea';
        else if (type === 'richtext') sel = '.cf-w-richtext';
        else if (type === 'image') sel = '.cf-w-image';
        else if (type === 'checkbox') sel = '.cf-w-checkbox';
        else if (type === 'number') sel = '.cf-w-number';
        else if (codeTypes[type]) sel = '.cf-w-code';
        const w = q(row, sel);
        if (w) w.style.display = (type === 'checkbox') ? 'flex' : 'block';
        ensureEditor(row, type);
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
        const cbx = q(row, '.cf-check-input');
        if (cbx) cbx.addEventListener('change', () => { if (h) h.value = cbx.checked ? '1' : '0'; });

        const keyInput = q(row, '.cf-key');
        const labelEl = q(row, '.cf-field-label');
        if (keyInput && labelEl) {
            keyInput.addEventListener('input', () => { labelEl.textContent = keyInput.value; });
        }

        const fileIn = q(row, '.cf-image-file');
        const upBtn = q(row, '.cf-image-upload');
        if (upBtn && fileIn) upBtn.addEventListener('click', () => fileIn.click());
        if (fileIn) fileIn.addEventListener('change', () => {
            if (!fileIn.files.length) return;
            const fd = new FormData();
            fd.append('file', fileIn.files[0]);
            fetch('upload.php', { method: 'POST', body: fd })
                .then((r) => r.json())
                .then((data) => {
                    if (data.location) {
                        const u = q(row, '.cf-image-url'); if (u) u.value = data.location;
                        const p = q(row, '.cf-image-preview'); if (p) { p.src = data.location; p.style.display = 'block'; }
                        if (h) h.value = data.location;
                    }
                }).catch(() => {});
            fileIn.value = '';
        });
        const clr = q(row, '.cf-image-clear');
        if (clr) clr.addEventListener('click', () => {
            const u = q(row, '.cf-image-url'); if (u) u.value = '';
            const p = q(row, '.cf-image-preview'); if (p) { p.src = ''; p.style.display = 'none'; }
            if (h) h.value = '';
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
                        const lbl = q(row, '.cf-field-label'); if (lbl) lbl.textContent = f.key;
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
})();

</script>
<?php require_once __DIR__ . '/views/footer.php'; ?>