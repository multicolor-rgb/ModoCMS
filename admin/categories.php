<?php
declare(strict_types=1);
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Database;
use Core\Router;
use Core\Security;

Auth::requireCapability('manage_pages');

$db = Database::getConnection();

$message = '';
$messageType = 'success';

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$form = ['id' => 0, 'name' => '', 'slug' => '', 'description' => ''];

/**
 * Normalizes a category name into a URL friendly slug.
 */
function categorySlugify(string $value): string
{
    return strtolower(trim((string) preg_replace('/[^A-Za-z0-9-]+/', '-', $value), '-'));
}

// Handle create / update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token');
    }

    $categoryId = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $slug = categorySlugify(trim((string) ($_POST['slug'] ?? '')));
    if ($slug === '') {
        $slug = categorySlugify($name);
    }
    $description = trim((string) ($_POST['description'] ?? ''));

    $form = ['id' => $categoryId, 'name' => $name, 'slug' => $slug, 'description' => $description];

    if ($name === '') {
        $message = 'Category name is required.';
        $messageType = 'danger';
    } elseif ($slug === '') {
        $message = 'Category slug is required.';
        $messageType = 'danger';
    } else {
        try {
            if ($categoryId > 0) {
                $stmt = $db->prepare("UPDATE categories SET name = :n, slug = :s, description = :d WHERE id = :id");
                $stmt->execute([':n' => $name, ':s' => $slug, ':d' => $description, ':id' => $categoryId]);
            } else {
                $stmt = $db->prepare("INSERT INTO categories (name, slug, description) VALUES (:n, :s, :d)");
                $stmt->execute([':n' => $name, ':s' => $slug, ':d' => $description]);
                $categoryId = (int) $db->lastInsertId();
            }
            header('Location: categories.php?edit=' . $categoryId . '&saved=1');
            exit;
        } catch (\PDOException $e) {
            $message = 'A category with that name or slug already exists.';
            $messageType = 'danger';
        }
    }
}

// Handle delete
if (isset($_GET['delete'], $_GET['csrf']) && Security::verifyCsrfToken((string) $_GET['csrf'])) {
    $stmt = $db->prepare("DELETE FROM categories WHERE id = :id");
    $stmt->execute([':id' => (int) $_GET['delete']]);
    header('Location: categories.php?deleted=1');
    exit;
}

// Load the category being edited (never on a POST — the form then keeps the posted values)
if ($editId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $db->prepare("SELECT * FROM categories WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $editId]);
    $found = $stmt->fetch();
    if ($found) {
        $form = [
            'id' => (int) $found['id'],
            'name' => (string) $found['name'],
            'slug' => (string) $found['slug'],
            'description' => (string) ($found['description'] ?? ''),
        ];
    }
}

$allCategories = $db->query("
    SELECT c.*, (
        SELECT COUNT(*)
        FROM page_categories pc
        JOIN pages p ON p.id = pc.page_id
        WHERE pc.category_id = c.id AND p.type = 'post'
    ) AS post_count
    FROM categories c
    ORDER BY c.name ASC
")->fetchAll();

$postsSlug = Router::getPostsPageSlug();

require_once __DIR__ . '/views/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= _e('Categories') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px;"><?= _e('Group your blog posts into categories. Assign them while editing a post, then render them in your theme with post_categories().') ?></p>
    </div>
</div>

<?php if (isset($_GET['saved']) && $message === ''): ?>
    <div class="card" style="border-left: 4px solid var(--success); padding: 12px; margin-bottom: 20px;">
        <?= _e('Category saved successfully.') ?>
    </div>
<?php elseif (isset($_GET['deleted'])): ?>
    <div class="card" style="border-left: 4px solid var(--success); padding: 12px; margin-bottom: 20px;">
        <?= _e('Category deleted successfully.') ?>
    </div>
<?php endif; ?>

<?php if ($message !== ''): ?>
    <div class="card" style="border-left: 4px solid var(--<?= $messageType === 'danger' ? 'danger' : 'success' ?>); padding: 12px; margin-bottom: 20px;">
        <?= Security::sanitize($message) ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start;">
    <div class="card">
        <h2 style="font-size: 15px; font-weight: 600; margin-bottom: 16px;">
            <?= $form['id'] > 0 ? _e('Edit Category') : _e('New Category') ?>
        </h2>

        <form method="POST" action="categories.php">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
            <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">

            <div class="form-group">
                <label class="form-label" for="name"><?= _e('Category Name') ?></label>
                <input class="form-control" type="text" id="name" name="name" value="<?= Security::sanitize((string) $form['name']) ?>" placeholder="<?= _e('Technology') ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="slug"><?= _e('Category Slug') ?></label>
                <input class="form-control" type="text" id="slug" name="slug" value="<?= Security::sanitize((string) $form['slug']) ?>" placeholder="technology">
                <small style="color: var(--text-muted);"><?= _e('Leave empty to generate it from the name. Used in the category archive URL.') ?></small>
            </div>

            <div class="form-group">
                <label class="form-label" for="description"><?= _e('Description') ?></label>
                <textarea class="form-control" id="description" name="description" rows="4" placeholder="<?= _e('Optional. Shown above the category archive listing.') ?>"><?= htmlspecialchars((string) $form['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <div style="display: flex; gap: 8px; align-items: center;">
                <button type="submit" class="btn btn-primary"><?= _e('Save Category') ?></button>
                <?php if ($form['id'] > 0): ?>
                    <a href="categories.php" class="btn btn-secondary"><?= _e('New Category') ?></a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card" style="padding: 0; overflow: hidden;">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border-subtle); display: flex; align-items: center; justify-content: space-between;">
            <h2 style="font-size: 15px; font-weight: 600; margin: 0;"><?= _e('All Categories') ?></h2>
            <span class="badge" style="font-size: 11px; background: var(--bg-surface, #1e293b); border: 1px solid var(--border-subtle); color: var(--text-muted);"><?= count($allCategories) ?></span>
        </div>

        <?php if (empty($allCategories)): ?>
            <div style="padding: 28px 20px; text-align: center; color: var(--text-muted); font-size: 13px;">
                <?= _e('No categories yet. Create your first category on the left.') ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="pro-table">
                    <thead>
                        <tr>
                            <th><?= _e('Category Name') ?></th>
                            <th style="width: 70px; text-align: center;"><?= _e('Posts') ?></th>
                            <th style="width: 190px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allCategories as $category): ?>
                            <tr>
                                <td>
                                    <a href="categories.php?edit=<?= (int) $category['id'] ?>" style="font-weight: 600; color: var(--text-main); text-decoration: none;">
                                        <?= Security::sanitize((string) $category['name']) ?>
                                    </a>
                                    <div style="font-size: 11px; color: var(--text-muted);">/<?= Security::sanitize((string) $category['slug']) ?></div>
                                    <?php if (!empty($category['description'])): ?>
                                        <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;"><?= Security::sanitize((string) $category['description']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;"><?= (int) ($category['post_count'] ?? 0) ?></td>
                                <td>
                                    <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                        <a href="<?= htmlspecialchars(site_url($postsSlug . '/category/' . $category['slug'], false), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-secondary" style="padding: 2px 8px; font-size: 11px;"><?= _e('View') ?></a>
                                        <a href="categories.php?edit=<?= (int) $category['id'] ?>" class="btn btn-secondary" style="padding: 2px 8px; font-size: 11px;"><?= _e('Edit') ?></a>
                                        <a href="categories.php?delete=<?= (int) $category['id'] ?>&csrf=<?= urlencode(Security::generateCsrfToken()) ?>" class="btn btn-danger-ghost" style="padding: 2px 8px; font-size: 11px;" onclick="return confirm('<?= _e('Delete this category? It will be removed from all posts.') ?>');"><?= _e('Delete') ?></a>
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

<?php require_once __DIR__ . '/views/footer.php'; ?>

