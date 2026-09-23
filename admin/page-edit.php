<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';
use Core\Database;
use Core\Security;
use Core\Auth;
use Core\I18n;
use Core\Router;

Auth::requireCapability('manage_pages');

$db = Database::getConnection();$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
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
    $stmt =$db->prepare("SELECT * FROM pages WHERE id = :id LIMIT 1");
    $stmt->execute([':id' =>$id]);
    $existing =$stmt->fetch();
    if ($existing) {
        if (!Auth::can('edit_others_pages') && (int)$existing['author_id'] !== (int)Auth::id()) {
            die('Access denied to this article.');
        }
        $item =$existing;

        // Fetch associated tags for posts
        $tagStmt =$db->prepare("
            SELECT t.name 
            FROM tags t 
            JOIN page_tags pt ON pt.tag_id = t.id 
            WHERE pt.page_id = :pid 
            ORDER BY t.name ASC
        ");
        $tagStmt->execute([':pid' =>$id]);
        $existingTags = implode(', ',$tagStmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token');
    }

    $title = trim($_POST['title'] ?? '');$slug = trim($_POST['slug'] ?? '');$content = $_POST['content'] ?? '';$type = in_array($_POST['type'] ?? '', ['post', 'page'], true) ?$_POST['type'] : 'post';
    $status =$_POST['status'] ?? 'published';
    $lang = trim($_POST['lang'] ?? I18n::getDefaultLocale());
    $parentId = ($type === 'page') ? (int)($_POST['parent_id'] ?? 0) : 0;
    $translation_group = trim($_POST['translation_group'] ?? bin2hex(random_bytes(8)));
    $featured_image = trim($_POST['featured_image'] ?? '');
    $meta_title = trim($_POST['meta_title'] ?? '');
    $meta_description = trim($_POST['meta_description'] ?? '');
    $tagsInput = trim($_POST['tags'] ?? '');

    // Prevent selecting itself as a parent
    if ($id > 0 &&$parentId === $id) {$parentId = 0;
    }

    // Auto-generate URL slug if left blank
    if ($slug === '') {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-',$title), '-'));
    }

    if ($id > 0) {
        // Update existing page or post
        $stmt =$db->prepare("
            UPDATE pages 
            SET title = :t, slug = :s, content = :c, type = :tp, status = :st, 
                parent_id = :p, lang = :lg, translation_group = :tg, 
                featured_image = :img, meta_title = :mt, meta_description = :md, 
                updated_at = CURRENT_TIMESTAMP 
            WHERE id = :id
        ");
        $stmt->execute([
            ':t' => $title, ':s' => $slug, ':c' =>$content, ':tp' => $type, ':st' =>$status,
            ':p' => $parentId, ':lg' => $lang, ':tg' =>$translation_group, 
            ':img' => $featured_image, ':mt' => $meta_title, ':md' =>$meta_description, 
            ':id' => $id
        ]);
    } else {
        // Insert new record
        $stmt =$db->prepare("
            INSERT INTO pages (
                title, slug, content, type, status, parent_id, lang, 
                translation_group, featured_image, meta_title, meta_description, author_id
            ) VALUES (
                :t, :s, :c, :tp, :st, :p, :lg, :tg, :img, :mt, :md, :aid
            )
        ");
        $stmt->execute([
            ':t' => $title, ':s' => $slug, ':c' =>$content, ':tp' => $type, ':st' =>$status,
            ':p' => $parentId, ':lg' => $lang, ':tg' =>$translation_group, 
            ':img' => $featured_image, ':mt' => $meta_title, ':md' =>$meta_description, 
            ':aid' => Auth::id()
        ]);
        $id = (int)$db->lastInsertId();
    }

    // Synchronize tags if the document is a blog post
    if ($type === 'post') {
        $delStmt =$db->prepare("DELETE FROM page_tags WHERE page_id = :pid");
        $delStmt->execute([':pid' =>$id]);

        if ($tagsInput !== '') {
            $tagList = array_unique(array_filter(array_map('trim', explode(',',$tagsInput))));
            foreach ($tagList as$rawTag) {
                $tagSlug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-',$rawTag), '-'));
                if ($tagSlug === '') continue;

                $tagCheck =$db->prepare("SELECT id FROM tags WHERE slug = :s LIMIT 1");
                $tagCheck->execute([':s' =>$tagSlug]);
                $tagId =$tagCheck->fetchColumn();

                if (!$tagId) {
                    $tagInsert =$db->prepare("INSERT INTO tags (name, slug) VALUES (:n, :s)");
                    $tagInsert->execute([':n' => $rawTag, ':s' =>$tagSlug]);
                    $tagId = (int)$db->lastInsertId();
                }

                $attachStmt =$db->prepare("INSERT OR IGNORE INTO page_tags (page_id, tag_id) VALUES (:pid, :tid)");
                $attachStmt->execute([':pid' => $id, ':tid' => (int)$tagId]);
            }
        }
    }

    header('Location: page-edit.php?id=' . $id . '&saved=1');
    exit;
}

// Fetch potential parent pages for hierarchy dropdown (excluding self)
$parentPagesStmt =$db->prepare("
    SELECT id, title, slug 
    FROM pages 
    WHERE type = 'page' AND id != :current_id AND lang = :lang 
    ORDER BY title ASC
");
$parentPagesStmt->execute([
    ':current_id' => $id,
    ':lang' => $item['lang']
]);
$parentPages =$parentPagesStmt->fetchAll();

// Fetch all uploaded media images for the modal picker
$uploadsDir = dirname(__DIR__) . '/uploads/';$basePrefix = class_exists('Core\Router') ? Router::getBaseSubdirectory() : '';
$existingMedia = [];

if (is_dir($uploadsDir)) {
    $files = scandir($uploadsDir, SCANDIR_SORT_DESCENDING) ?: [];
    foreach ($files as$file) {
        if ($file === '.' ||$file === '..') continue;
        $filePath = $uploadsDir .$file;
        if (!is_file($filePath)) continue;

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'], true)) {
            $webPath = ($basePrefix !== '' ? rtrim($basePrefix, '/') : '') . '/uploads/' .$file;
            $existingMedia[] = [
                'name' => $file,
                'url'  => $webPath
            ];
        }
    }
}

// Resolve TinyMCE toolbar preset from global settings
$tinymcePreset = Router::getOption('tinymce_preset', 'standard');$tinymceCustom = Router::getOption('tinymce_custom_toolbar', '');

$resolvedToolbar = match ($tinymcePreset) {
    'basic' => 'undo redo | blocks | bold italic underline | bullist numlist | link mediamanager | removeformat',
    'advanced' => 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image mediamanager table | subscript superscript | removeformat code fullscreen',
    'custom' => !empty($tinymceCustom) ?$tinymceCustom : 'undo redo | blocks | bold italic | link mediamanager | code',
    default => 'undo redo | blocks | bold italic underline | alignleft aligncenter alignright | bullist numlist | link image mediamanager table | removeformat code fullscreen',
};

$enableMenubar = ($tinymcePreset === 'advanced');

require_once __DIR__ . '/views/header.php';
?>
<script src="assets/vendor/tinymce/tinymce.min.js"></script>
<div class="page-header">
    <div>
        <h1 class="page-title">
            <?= $id > 0 ? _e('Edit') . ': ' . Security::sanitize($item['title']) : _e('New Document') ?>
        </h1>
        <p style="color: var(--text-muted); font-size: 13px;"><?= _e('Manage page content, parent hierarchy, and SEO meta tags') ?></p>
    </div>
    <a href="pages.php" class="btn btn-secondary">&larr; <?= _e('Content') ?></a>
</div>

<?php if (isset($_GET['saved'])): ?>
    <div class="card" style="border-left: 4px solid var(--success); padding: 12px; margin-bottom: 20px;">
        <?= _e('Document saved successfully.') ?>
    </div>
<?php endif; ?>

<form method="POST" action="">
    <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">

    <div style="display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 24px; align-items: start;">
        <div>
            <div class="card">
                <div class="form-group">
                    <label class="form-label" for="title"><?= _e('Title') ?></label>
                    <input class="form-control" type="text" id="title" name="title" value="<?= Security::sanitize($item['title']) ?>" placeholder="<?= _e('Document Title') ?>" required style="font-size: 16px; font-weight: 600;">
                </div>

                <div class="form-group">
                    <label class="form-label" for="slug"><?= _e('Slug / Permalink') ?></label>
                    <input class="form-control" type="text" id="slug" name="slug" value="<?= Security::sanitize($item['slug']) ?>" placeholder="e.g. test">
                    <?php if ($id > 0 &&$item['type'] === 'page'): ?>
                        <small style="font-size: 11px; color: var(--text-muted); display: block; margin-top: 4px;">
                            <?= _e('Live URL') ?>: <a href="<?= page_url($item, false) ?>" target="_blank" style="color: var(--primary);"><?= page_url($item, false) ?></a>
                        </small>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label" for="editor"><?= _e('Content') ?></label>
                    <textarea id="editor" name="content"><?= htmlspecialchars($item['content'], ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
            </div>

            <div class="card">
                <h2 style="font-size: 15px; font-weight: 700; margin-bottom: 16px;"><?= _e('SEO & Social Meta Tags') ?></h2>
                <div class="form-group">
                    <label class="form-label" for="meta_title"><?= _e('Meta Title') ?></label>
                    <input class="form-control" type="text" id="meta_title" name="meta_title" value="<?= Security::sanitize($item['meta_title'] ?? '') ?>" placeholder="<?= _e('Default uses page title') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="meta_description"><?= _e('Meta Description') ?></label>
                    <textarea class="form-control" id="meta_description" name="meta_description" rows="3" placeholder="<?= _e('Brief summary for search engines and social shares') ?>"><?= htmlspecialchars($item['meta_description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                </div>
            </div>
        </div>

        <div>
            <div class="card">
                <h3 style="font-size: 14px; font-weight: 700; margin-bottom: 14px;"><?= _e('Document Settings') ?></h3>
                
                <div class="form-group">
                    <label class="form-label" for="type"><?= _e('Type') ?></label>
                    <select class="form-control" name="type" id="type">
                        <option value="post" <?= $item['type'] === 'post' ? 'selected' : '' ?>><?= _e('Blog Post') ?></option>
                        <option value="page" <?= $item['type'] === 'page' ? 'selected' : '' ?>><?= _e('Static Page') ?></option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="status"><?= _e('Status') ?></label>
                    <select class="form-control" name="status" id="status">
                        <option value="published" <?= $item['status'] === 'published' ? 'selected' : '' ?>><?= _e('Published') ?></option>
                        <option value="draft" <?= $item['status'] === 'draft' ? 'selected' : '' ?>><?= _e('Draft') ?></option>
                    </select>
                </div>

                <div class="form-group" id="parent-page-group" style="<?= $item['type'] === 'page' ? '' : 'display: none;' ?>">
                    <label class="form-label" for="parent_id"><?= _e('Parent Page') ?></label>
                    <select class="form-control" name="parent_id" id="parent_id">
                        <option value="0">&mdash; <?= _e('No Parent (Top Level)') ?> &mdash;</option>
                        <?php foreach ($parentPages as$parent): ?>
                            <option value="<?= $parent['id'] ?>" <?= (int)$item['parent_id'] === (int)$parent['id'] ? 'selected' : '' ?>>
                                <?= Security::sanitize($parent['title']) ?> (/<?= Security::sanitize($parent['slug']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" id="tags-group" style="<?= $item['type'] === 'post' ? '' : 'display: none;' ?>">
                    <label class="form-label" for="tags"><?= _e('Tags') ?></label>
                    <input class="form-control" type="text" id="tags" name="tags" value="<?= Security::sanitize($existingTags) ?>" placeholder="php, sqlite, tutorials">
                    <small style="font-size: 11px; color: var(--text-muted); display: block; margin-top: 4px;"><?= _e('Separate multiple tags with commas.') ?></small>
                </div>

                <div class="form-group">
                    <label class="form-label" for="lang"><?= _e('Content Language') ?></label>
                    <select class="form-control" name="lang" id="lang">
                        <?php foreach (I18n::getAvailableLanguages() as $code =>$name): ?>
                            <option value="<?= $code ?>" <?= $item['lang'] ===$code ? 'selected' : '' ?>>
                                <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?> (<?= strtoupper($code) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <input type="hidden" name="translation_group" value="<?= htmlspecialchars($item['translation_group'], ENT_QUOTES, 'UTF-8') ?>">

                <?php if ($id > 0): ?>
                    <div style="border-top: 1px solid var(--border-subtle); padding-top: 12px; margin-top: 12px;">
                        <span style="font-size: 12px; color: var(--text-muted); display: block; margin-bottom: 6px;"><?= _e('Add translation for this document:') ?></span>
                        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                            <?php foreach (I18n::getAvailableLanguages() as $c =>$n): if ($c ===$item['lang']) continue; ?>
                                <a href="page-edit.php?type=<?= $item['type'] ?>&lang=<?= $c ?>&group=<?= urlencode($item['translation_group']) ?>" class="btn btn-secondary" style="padding: 3px 8px; font-size: 11px;">
                                    + <?= strtoupper($c) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 10px; margin-top: 16px;"><?= _e('Save Document') ?></button>
            </div>

            <div class="card">
                <h3 style="font-size: 14px; font-weight: 700; margin-bottom: 14px;"><?= _e('Featured Image') ?></h3>
                <div id="image-preview-wrapper" style="margin-bottom: 12px; <?= empty($item['featured_image']) ? 'display:none;' : '' ?>">
                    <img id="image-preview" src="<?= htmlspecialchars($item['featured_image'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="width: 100%; height: 160px; object-fit: cover; border-radius: var(--radius-sm); border: 1px solid var(--border-subtle);">
                    <button type="button" id="remove-image-btn" class="btn btn-danger-ghost" style="width: 100%; margin-top: 6px; font-size: 12px;"><?= _e('Remove Image') ?></button>
                </div>
                <input type="hidden" name="featured_image" id="featured_image" value="<?= htmlspecialchars($item['featured_image'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <input type="file" id="image-file-input" accept="image/*" style="display: none;">
                
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <button type="button" id="upload-image-btn" class="btn btn-secondary" style="width: 100%;">
                        <?= empty($item['featured_image']) ? _e('Upload New') : _e('Upload Replacement') ?>
                    </button>
                    <button type="button" id="library-image-btn" class="btn btn-secondary" style="width: 100%;">
                        <?= _e('Choose from Library') ?>
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<div id="media-modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.65); z-index: 9999; align-items: center; justify-content: center; backdrop-filter: blur(2px);">
    <div style="background: var(--bg-card, #ffffff); width: 90%; max-width: 820px; max-height: 85vh; border-radius: var(--radius-md, 8px); display: flex; flex-direction: column; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.2); overflow: hidden;">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border-subtle); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 16px; font-weight: 700;"><?= _e('Select Media from Library') ?></h3>
            <button type="button" id="close-modal-btn" style="background: transparent; border: none; font-size: 20px; line-height: 1; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>
        <div style="padding: 20px; overflow-y: auto; flex: 1;">
            <?php if (empty($existingMedia)): ?>
                <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                    <?= _e('No uploaded images found in the library.') ?>
                </div>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 14px;">
                    <?php foreach ($existingMedia as$media): ?>
                        <div class="media-pick-item" data-url="<?= htmlspecialchars($media['url'], ENT_QUOTES, 'UTF-8') ?>" style="cursor: pointer; border: 2px solid transparent; border-radius: var(--radius-sm, 6px); overflow: hidden; background: #0f172a08; transition: border-color 0.15s, transform 0.15s;">
                            <img src="<?= htmlspecialchars($media['url'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($media['name'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($media['name'], ENT_QUOTES, 'UTF-8') ?>" style="width: 100%; height: 105px; object-fit: cover; display: block;">
                            <div style="font-size: 10px; padding: 4px 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-align: center; color: var(--text-muted);">
                                <?= Security::sanitize($media['name']) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div style="padding: 12px 20px; border-top: 1px solid var(--border-subtle); display: flex; justify-content: flex-end;">
            <button type="button" id="cancel-modal-btn" class="btn btn-secondary" style="font-size: 12px;"><?= _e('Close') ?></button>
        </div>
    </div>
</div>

<script>
// Target state: track whether modal was opened for featured image or TinyMCE
let currentMediaTarget = 'featured'; // 'featured' | 'tinymce_dialog' | 'tinymce_direct'
let tinymceFilePickerCallback = null;

// Initialize TinyMCE WYSIWYG Editor using dynamically resolved configuration
tinymce.init({
    selector: '#editor',
    height: 480,
    menubar: <?= $enableMenubar ? 'true' : 'false' ?>,
    plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen table wordcount',
    toolbar: <?= json_encode($resolvedToolbar) ?>,
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

// Toggle parent-page dropdown and tags input based on Type selection
const typeSelect = document.getElementById('type');
const parentGroup = document.getElementById('parent-page-group');
const tagsGroup = document.getElementById('tags-group');

typeSelect.addEventListener('change', () => {
    if (typeSelect.value === 'page') {
        parentGroup.style.display = 'block';
        tagsGroup.style.display = 'none';
    } else {
        parentGroup.style.display = 'none';
        tagsGroup.style.display = 'block';
    }
});

// Featured Image Elements
const uploadBtn = document.getElementById('upload-image-btn');
const libraryBtn = document.getElementById('library-image-btn');
const fileInput = document.getElementById('image-file-input');
const hiddenInput = document.getElementById('featured_image');
const previewWrapper = document.getElementById('image-preview-wrapper');
const previewImg = document.getElementById('image-preview');
const removeBtn = document.getElementById('remove-image-btn');

// Modal Elements
const modal = document.getElementById('media-modal');
const closeModalBtn = document.getElementById('close-modal-btn');
const cancelModalBtn = document.getElementById('cancel-modal-btn');
const mediaItems = document.querySelectorAll('.media-pick-item');

const openModal = () => {
    modal.style.display = 'flex';
};

const hideModal = () => {
    modal.style.display = 'none';
    tinymceFilePickerCallback = null;
    currentMediaTarget = 'featured';
};

uploadBtn.addEventListener('click', () => fileInput.click());

fileInput.addEventListener('change', () => {
    if (!fileInput.files.length) return;
    const formData = new FormData();
    formData.append('file', fileInput.files[0]);
    uploadBtn.innerText = '<?= _e('Uploading...') ?>';
    uploadBtn.disabled = true;

    fetch('upload.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        uploadBtn.disabled = false;
        uploadBtn.innerText = '<?= _e('Upload Replacement') ?>';
        if (data.location) {
            hiddenInput.value = data.location;
            previewImg.src = data.location;
            previewWrapper.style.display = 'block';
        } else {
            alert(data.error || 'Upload failed.');
        }
    }).catch(() => {
        uploadBtn.disabled = false;
        uploadBtn.innerText = '<?= _e('Upload Replacement') ?>';
        alert('Network error during upload.');
    });
});

removeBtn.addEventListener('click', () => {
    hiddenInput.value = '';
    previewImg.src = '';
    previewWrapper.style.display = 'none';
    uploadBtn.innerText = '<?= _e('Upload New') ?>';
});

// Open modal for Featured Image
libraryBtn.addEventListener('click', () => {
    currentMediaTarget = 'featured';
    openModal();
});

closeModalBtn.addEventListener('click', hideModal);
cancelModalBtn.addEventListener('click', hideModal);

modal.addEventListener('click', (e) => {
    if (e.target === modal) hideModal();
});

// Handle media asset selection
mediaItems.forEach(item => {
    item.addEventListener('mouseenter', () => {
        item.style.borderColor = 'var(--primary, #2563eb)';
        item.style.transform = 'translateY(-2px)';
    });
    item.addEventListener('mouseleave', () => {
        item.style.borderColor = 'transparent';
        item.style.transform = 'translateY(0)';
    });
    item.addEventListener('click', () => {
        const selectedUrl = item.getAttribute('data-url');
        const altText = item.querySelector('img')?.getAttribute('alt') || '';

        if (currentMediaTarget === 'featured') {
            hiddenInput.value = selectedUrl;
            previewImg.src = selectedUrl;
            previewWrapper.style.display = 'block';
            uploadBtn.innerText = '<?= _e('Upload Replacement') ?>';
        } else if (currentMediaTarget === 'tinymce_dialog') {
            if (typeof tinymceFilePickerCallback === 'function') {
                tinymceFilePickerCallback(selectedUrl, { alt: altText });
            }
        } else if (currentMediaTarget === 'tinymce_direct') {
            tinymce.activeEditor.insertContent(`<img src="${selectedUrl}" alt="${altText}" />`);
        }

        hideModal();
    });
});
</script>
<?php require_once __DIR__ . '/views/footer.php'; ?>