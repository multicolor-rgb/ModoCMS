<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Database;
use Core\Security;

Auth::requireCapability('manage_settings');

$db = Database::getConnection();

$message = '';
$messageType = 'success';

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$form = ['id' => 0, 'name' => '', 'label' => '', 'content' => '', 'enabled' => 1];

// Handle create / update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token');
    }

    $snippetId = (int) ($_POST['id'] ?? 0);
    $name = strtolower(trim((string) preg_replace('/[^A-Za-z0-9_\-]+/', '-', (string) ($_POST['name'] ?? '')), '-'));
    $label = trim((string) ($_POST['label'] ?? ''));
    $content = (string) ($_POST['content'] ?? '');
    $enabled = !empty($_POST['enabled']) ? 1 : 0;

    $form = ['id' => $snippetId, 'name' => $name, 'label' => $label, 'content' => $content, 'enabled' => $enabled];

    if ($name === '') {
        $message = 'Name is required.';
        $messageType = 'danger';
    } else {
        try {
            if ($snippetId > 0) {
                $stmt = $db->prepare("UPDATE snippets SET name = :n, label = :l, content = :c, enabled = :e, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->execute([':n' => $name, ':l' => $label, ':c' => $content, ':e' => $enabled, ':id' => $snippetId]);
            } else {
                $stmt = $db->prepare("INSERT INTO snippets (name, label, content, enabled) VALUES (:n, :l, :c, :e)");
                $stmt->execute([':n' => $name, ':l' => $label, ':c' => $content, ':e' => $enabled]);
                $snippetId = (int) $db->lastInsertId();
            }
            header('Location: snippets.php?edit=' . $snippetId . '&saved=1');
            exit;
        } catch (\PDOException $e) {
            $message = 'A snippet with that name already exists.';
            $messageType = 'danger';
        }
    }
}

// Handle delete
if (isset($_GET['delete'], $_GET['csrf']) && Security::verifyCsrfToken((string) $_GET['csrf'])) {
    $stmt = $db->prepare("DELETE FROM snippets WHERE id = :id");
    $stmt->execute([':id' => (int) $_GET['delete']]);
    header('Location: snippets.php?deleted=1');
    exit;
}

// Handle enable/disable toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token');
    }
    $stmt = $db->prepare("UPDATE snippets SET enabled = CASE WHEN enabled = 1 THEN 0 ELSE 1 END, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
    $stmt->execute([':id' => (int) ($_POST['id'] ?? 0)]);
    header('Location: snippets.php?saved=1');
    exit;
}

// Load the snippet being edited
if ($editId > 0) {
    $stmt = $db->prepare("SELECT * FROM snippets WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $editId]);
    $found = $stmt->fetch();
    if ($found) {
        $form = $found;
    }
}

$allSnippets = $db->query("SELECT * FROM snippets ORDER BY name ASC")->fetchAll();

require_once __DIR__ . '/views/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= _e('Reusable Snippets') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px;"><?= _e('Global HTML/text blocks. Insert them anywhere with the [snippet:name] shortcode.') ?></p>
    </div>
</div>

<?php if (isset($_GET['saved']) && $message === ''): ?>
    <div class="card" style="border-left: 4px solid var(--success); padding: 12px; margin-bottom: 20px;">
        <?= _e('Snippet saved successfully.') ?>
    </div>
<?php elseif (isset($_GET['deleted'])): ?>
    <div class="card" style="border-left: 4px solid var(--success); padding: 12px; margin-bottom: 20px;">
        <?= _e('Snippet deleted successfully.') ?>
    </div>
<?php endif; ?>

<?php if ($message !== ''): ?>
    <div class="card" style="border-left: 4px solid var(--<?= $messageType === 'danger' ? 'danger' : 'success' ?>); padding: 12px; margin-bottom: 20px;">
        <?= Security::sanitize($message) ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
    <div class="card">
        <h2 style="font-size: 15px; font-weight: 600; margin-bottom: 16px;">
            <?= $form['id'] > 0 ? _e('Edit Snippet') : _e('New Snippet') ?>
        </h2>

        <form method="POST" action="snippets.php">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
            <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">

            <div class="form-group">
                <label class="form-label" for="name"><?= _e('Snippet Name') ?></label>
                <input class="form-control" type="text" id="name" name="name" value="<?= Security::sanitize((string) $form['name']) ?>" placeholder="footer-cta" pattern="[A-Za-z0-9_\-]+" required>
                <small style="color: var(--text-muted);"><?= _e('Letters, numbers, hyphens and underscores only.') ?></small>
            </div>

            <div class="form-group">
                <label class="form-label" for="label"><?= _e('Label') ?></label>
                <input class="form-control" type="text" id="label" name="label" value="<?= Security::sanitize((string) $form['label']) ?>" placeholder="<?= _e('Footer call to action') ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="content"><?= _e('Content') ?></label>
                <textarea class="form-control" id="snippet-editor" name="content" rows="12" placeholder="<p>...</p>"><?= htmlspecialchars((string) $form['content'], ENT_QUOTES, 'UTF-8') ?></textarea>
                <small style="color: var(--text-muted);"><?= _e('Accepts HTML or plain text. Nested [snippet:...] tags are supported.') ?></small>
            </div>

            <div class="form-group">
                <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer;">
                    <input type="checkbox" name="enabled" value="1" <?= !empty($form['enabled']) ? 'checked' : '' ?>>
                    <?= _e('Enabled') ?>
                </label>
            </div>

            <div style="display: flex; gap: 8px; align-items: center;">
                <button type="submit" class="btn btn-primary"><?= _e('Save Snippet') ?></button>
                <?php if ($form['id'] > 0): ?>
                    <a href="snippets.php" class="btn btn-secondary"><?= _e('New Snippet') ?></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <div class="card">
        <h2 style="font-size: 15px; font-weight: 600; margin-bottom: 16px;"><?= _e('Snippets') ?></h2>

        <?php if (empty($allSnippets)): ?>
            <p style="color: var(--text-muted); font-size: 13px;"><?= _e('No snippets yet. Create your first reusable block.') ?></p>
        <?php else: ?>
            <div class="table-container" style="overflow-x: auto;">
            <table class="pro-table">
                <thead>
                    <tr>
                        <th><?= _e('Snippet Name') ?></th>
                        <th><?= _e('Shortcode') ?></th>
                        <th><?= _e('Status') ?></th>
                        <th style="width: 170px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allSnippets as $s): ?>
                        <tr>
                            <td>
                                <a href="snippets.php?edit=<?= (int) $s['id'] ?>" style="font-weight: 600; color: var(--text-main); text-decoration: none;">
                                    <?= Security::sanitize((string) $s['name']) ?>
                                </a>
                                <?php if (!empty($s['label'])): ?>
                                    <div style="font-size: 11px; color: var(--text-muted);"><?= Security::sanitize((string) $s['label']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 6px;">
                                    <code style="font-size: 12px;">[snippet:<?= Security::sanitize((string) $s['name']) ?>]</code>
                                    <button type="button" class="btn btn-secondary copy-shortcode" data-shortcode="[snippet:<?= Security::sanitize((string) $s['name']) ?>]" style="padding: 2px 8px; font-size: 11px;"><?= _e('Copy') ?></button>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($s['enabled'])): ?>
                                    <span class="badge" style="background:#dcfce7; color:#166534; font-size:11px;"><?= _e('Enabled') ?></span>
                                <?php else: ?>
                                    <span class="badge" style="background:#fee2e2; color:#991b1b; font-size:11px;"><?= _e('Disabled') ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                    <form method="POST" action="snippets.php" style="margin:0;">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
                                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                        <button type="submit" class="btn btn-secondary" style="padding: 2px 8px; font-size: 11px;">
                                            <?= !empty($s['enabled']) ? _e('Disable') : _e('Enable') ?>
                                        </button>
                                    </form>
                                    <a href="snippets.php?edit=<?= (int) $s['id'] ?>" class="btn btn-secondary" style="padding: 2px 8px; font-size: 11px;"><?= _e('Edit') ?></a>
                                    <a href="snippets.php?delete=<?= (int) $s['id'] ?>&csrf=<?= urlencode(Security::generateCsrfToken()) ?>" class="btn btn-danger-ghost" style="padding: 2px 8px; font-size: 11px;" onclick="return confirm('<?= _e('Delete this snippet? Pages using it will render an empty block.') ?>');"><?= _e('Delete') ?></a>
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
document.querySelectorAll('.copy-shortcode').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const text = btn.getAttribute('data-shortcode') || '';
        const done = function () {
            const original = btn.textContent;
            btn.textContent = '<?= _e('Copied!') ?>';
            setTimeout(function () { btn.textContent = original; }, 1500);
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(done);
        } else {
            const tmp = document.createElement('textarea');
            tmp.value = text;
            document.body.appendChild(tmp);
            tmp.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(tmp);
            done();
        }
    });
});
</script>

<script src="assets/vendor/tinymce/tinymce.min.js"></script>
<script src="assets/js/grid-editor.js"></script>
<script>
    window.MODO_GRID_CSS = <?= json_encode(\Core\CssFramework::editorContentCss(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.MODO_GRID_STYLE = <?= json_encode(\Core\CssFramework::editorContentStyle(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    window.MODO_GRID = <?= json_encode(\Core\CssFramework::gridConfig(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script>
(function () {
    if (typeof tinymce === 'undefined') return;
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    tinymce.init({
        selector: '#snippet-editor',
        height: 420,
        menubar: false,
        plugins: 'advlist autolink lists link image charmap preview anchor searchreplace visualblocks code fullscreen table wordcount',
        toolbar: 'undo redo | blocks | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image table | code fullscreen',
        skin: isDark ? 'oxide-dark' : 'oxide',
        content_css: [(isDark ? 'dark' : 'default')].concat(window.MODO_GRID_CSS || []),
        content_style: window.MODO_GRID_STYLE || '',
        relative_urls: false,
        remove_script_host: false,
        images_upload_url: 'upload.php',
        automatic_uploads: true,
        file_picker_types: 'image'
    });
})();
</script>

<?php require_once __DIR__ . '/views/footer.php'; ?>


