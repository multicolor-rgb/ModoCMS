<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';
use Core\Database;
use Core\Security;
use Core\Auth;
use Core\Sitemap;

Auth::requireCapability('manage_pages');

$db = Database::getConnection();
$currentType = $_GET['type'] ?? 'all';
$currentLang = $_GET['lang'] ?? 'all';

// Fetch all pages to calculate full hierarchical URL paths
$allPagesStmt = $db->query("SELECT id, parent_id, slug, lang, type FROM pages");
$lookupPages = [];
while ($p = $allPagesStmt->fetch()) {
    $lookupPages[(int)$p['id']] = $p;
}

function resolveFullSlug(array $item, array $lookup): string {
    if ($item['type'] === 'post') {
        return '/' . $item['slug'];
    }
    $slugs = [];
    $curr = (int)$item['id'];
    $visited = [];
    $limit = 20;

    while ($curr > 0 && isset($lookup[$curr]) && !in_array($curr, $visited, true) && $limit-- > 0) {
        $visited[] = $curr;
        if (!empty($lookup[$curr]['slug']) && $lookup[$curr]['slug'] !== 'home') {
            array_unshift($slugs, $lookup[$curr]['slug']);
        }
        $curr = (int)($lookup[$curr]['parent_id'] ?? 0);
    }
    return '/' . implode('/', $slugs);
}

/**
 * Recursively deletes a menu item and all its nested sub-items (children).
 */
function deleteMenuItemCascade(\PDO $db, int $menuItemId): void {
    $childrenStmt = $db->prepare("SELECT id FROM menu_items WHERE parent_id = :pid");
    $childrenStmt->execute([':pid' => $menuItemId]);
    $childrenIds = $childrenStmt->fetchAll(\PDO::FETCH_COLUMN);

    foreach ($childrenIds as $childId) {
        deleteMenuItemCascade($db, (int)$childId);
    }

    $delStmt = $db->prepare("DELETE FROM menu_items WHERE id = :id");
    $delStmt->execute([':id' => $menuItemId]);
}

// Handle Document Deletion
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    if (Security::verifyCsrfToken($_GET['csrf'] ?? '')) {
        Auth::requireCapability('delete_pages');
        $delId = (int)$_GET['id'];

        $checkStmt = $db->prepare("SELECT * FROM pages WHERE id = :id LIMIT 1");
        $checkStmt->execute([':id' => $delId]);
        $pageToDelete = $checkStmt->fetch();

        if (!$pageToDelete) {
            header('Location: pages.php');
            exit;
        }

        if (!Auth::can('edit_others_pages') && (int)$pageToDelete['author_id'] !== (int)Auth::id()) {
            http_response_code(403);
            die('Access denied.');
        }

        // 1. Zbuduj możliwe warianty URL strony, które mogły trafić do nawigacji
        $candidateUrls = [];
        $rawSlug = $pageToDelete['slug'];
        $candidateUrls[] = '/' . $rawSlug;
        $candidateUrls[] = $rawSlug;

        $hierarchicalPath = resolveFullSlug($pageToDelete, $lookupPages);
        $candidateUrls[] = $hierarchicalPath;
        $candidateUrls[] = ltrim($hierarchicalPath, '/');

        if (!empty($pageToDelete['lang'])) {
            $candidateUrls[] = '/' . $pageToDelete['lang'] . $hierarchicalPath;
            $candidateUrls[] = '/' . $pageToDelete['lang'] . '/' . $rawSlug;
        }

        if ($rawSlug === 'home') {
            $candidateUrls[] = '/';
            $candidateUrls[] = '';
        }

        $candidateUrls = array_values(array_unique(array_filter($candidateUrls)));

        // 2. Znajdź i usuń pasujące elementy z menu_items (kaskadowo z dziećmi)
        if (!empty($candidateUrls)) {
            $placeholders = implode(',', array_fill(0, count($candidateUrls), '?'));
            $findMenuStmt = $db->prepare("SELECT id FROM menu_items WHERE url IN ($placeholders)");
            $findMenuStmt->execute($candidateUrls);
            $menuItemIds = $findMenuStmt->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($menuItemIds as $mId) {
                deleteMenuItemCascade($db, (int)$mId);
            }
        }

        // 3. Usuń powiązane tagi oraz sam dokument
        $db->prepare("DELETE FROM page_tags WHERE page_id = :id")->execute([':id' => $delId]);
        $db->prepare("DELETE FROM pages WHERE id = :id")->execute([':id' => $delId]);

        // 4. Jeśli usunięta strona była ustawiona jako Homepage lub Blog w settings - wyczyść konfigurację
        $homeId = (int)\Core\Router::getOption('homepage_page_id', 0);
        $postsId = (int)\Core\Router::getOption('posts_page_id', 0);

        if ($homeId === $delId) {
            $db->prepare("UPDATE settings SET value = '0' WHERE key = 'homepage_page_id'")->execute();
        }
        if ($postsId === $delId) {
            $db->prepare("UPDATE settings SET value = '0' WHERE key = 'posts_page_id'")->execute();
        }

        // 5. Zregeneruj sitemapę
        if (class_exists('Core\Sitemap')) {
            try {
                Sitemap::generate();
            } catch (\Throwable $e) {}
        }

        header('Location: pages.php?type=' . urlencode($currentType) . '&lang=' . urlencode($currentLang) . '&deleted=1');
        exit;
    }
}

// Build query with filters
$sql = "SELECT p.*, u.username as author_name FROM pages p LEFT JOIN users u ON p.author_id = u.id";
$conditions = [];
$params = [];

if ($currentType === 'post' || $currentType === 'page') {
    $conditions[] = "p.type = :tp";
    $params[':tp'] = $currentType;
}

if ($currentLang !== 'all') {
    $conditions[] = "p.lang = :lg";
    $params[':lg'] = $currentLang;
}

if (!Auth::can('edit_others_pages')) {
    $conditions[] = "p.author_id = :aid";
    $params[':aid'] = Auth::id();
}

if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY p.updated_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

// Counts for filter pills
$countAll = (int)$db->query("SELECT COUNT(*) FROM pages")->fetchColumn();
$countPosts = (int)$db->query("SELECT COUNT(*) FROM pages WHERE type = 'post'")->fetchColumn();
$countPages = (int)$db->query("SELECT COUNT(*) FROM pages WHERE type = 'page'")->fetchColumn();

require_once __DIR__ . '/views/header.php';
?>

<div class="page-header" style="margin-bottom: 24px;">
    <div>
        <h1 class="page-title"><?= _e('Content') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px;"><?= _e('Manage static pages, blog articles, and localized content') ?></p>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="page-edit.php?type=post" class="btn btn-primary">+ <?= _e('New Post') ?></a>
        <a href="page-edit.php?type=page" class="btn btn-secondary">+ <?= _e('New Page') ?></a>
    </div>
</div>

<?php if (isset($_GET['deleted'])): ?>
    <div class="card" style="border-left: 4px solid var(--success, #10b981); background: rgba(16, 185, 129, 0.08); padding: 12px 16px; margin-bottom: 20px; color: #34d399; font-weight: 500;">
        <?= _e('Document deleted successfully and removed from menus.') ?>
    </div>
<?php endif; ?>

<!-- Filters Toolbar -->
<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
    <div style="display: flex; gap: 8px; align-items: center;">
        <a href="pages.php?type=all&lang=<?= urlencode($currentLang) ?>" 
           class="btn <?= $currentType === 'all' ? 'btn-primary' : 'btn-secondary' ?>" 
           style="padding: 6px 14px; font-size: 12px;">
            <?= _e('All') ?> <span style="opacity: 0.75; font-size: 11px;">(<?= $countAll ?>)</span>
        </a>
        <a href="pages.php?type=post&lang=<?= urlencode($currentLang) ?>" 
           class="btn <?= $currentType === 'post' ? 'btn-primary' : 'btn-secondary' ?>" 
           style="padding: 6px 14px; font-size: 12px;">
            <?= _e('Posts') ?> <span style="opacity: 0.75; font-size: 11px;">(<?= $countPosts ?>)</span>
        </a>
        <a href="pages.php?type=page&lang=<?= urlencode($currentLang) ?>" 
           class="btn <?= $currentType === 'page' ? 'btn-primary' : 'btn-secondary' ?>" 
           style="padding: 6px 14px; font-size: 12px;">
            <?= _e('Pages') ?> <span style="opacity: 0.75; font-size: 11px;">(<?= $countPages ?>)</span>
        </a>
    </div>

    <!-- Quick Language Filter -->
    <div style="display: flex; align-items: center; gap: 8px;">
        <span style="font-size: 12px; color: var(--text-muted);"><?= _e('Language:') ?></span>
        <select class="form-control" style="width: auto; padding: 4px 10px; font-size: 12px;" onchange="location.href='pages.php?type=<?= urlencode($currentType) ?>&lang=' + this.value">
            <option value="all" <?= $currentLang === 'all' ? 'selected' : '' ?>><?= _e('All Languages') ?></option>
            <?php foreach (\Core\I18n::getAvailableLanguages() as $code => $name): ?>
                <option value="<?= $code ?>" <?= $currentLang === $code ? 'selected' : '' ?>><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?> (<?= strtoupper($code) ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div class="table-container card" style="padding: 0; overflow: hidden; border: 1px solid var(--border-subtle);">
    <table class="pro-table">
        <thead>
            <tr>
                <th style="width: 54px; text-align: center;"><?= _e('Image') ?></th>
                <th><?= _e('Title & Route') ?></th>
                <th style="width: 90px;"><?= _e('Type') ?></th>
                <th style="width: 100px;"><?= _e('Status') ?></th>
                <th style="width: 70px; text-align: center;"><?= _e('Lang') ?></th>
                <th style="width: 120px;"><?= _e('Author') ?></th>
                <th style="width: 130px;"><?= _e('Updated') ?></th>
                <th style="text-align: right; width: 110px;"><?= _e('Actions') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 48px 20px;">
                        <p style="margin: 0; font-size: 14px;"><?= _e('No matching documents found.') ?></p>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($items as $item): 
                    $fullPath = resolveFullSlug($item, $lookupPages);
                    $deleteUrl = "pages.php?action=delete&id={$item['id']}&csrf=" . Security::generateCsrfToken() . "&type=" . urlencode($currentType) . "&lang=" . urlencode($currentLang);
                ?>
                    <tr>
                        <td style="text-align: center; vertical-align: middle;">
                            <?php if (!empty($item['featured_image'])): ?>
                                <img src="<?= htmlspecialchars($item['featured_image'], ENT_QUOTES, 'UTF-8') ?>" 
                                     alt="Thumb" 
                                     style="width: 38px; height: 38px; object-fit: cover; border-radius: var(--radius-sm, 6px); border: 1px solid var(--border-subtle); display: block; margin: 0 auto;">
                            <?php else: ?>
                                <div style="width: 38px; height: 38px; background: var(--bg-surface, #1e293b); border: 1px solid var(--border-subtle); border-radius: var(--radius-sm, 6px); display: flex; align-items: center; justify-content: center; color: var(--text-muted); font-size: 11px; margin: 0 auto;">
                                    &mdash;
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong style="font-size: 14px;">
                                <a href="page-edit.php?id=<?= $item['id'] ?>" style="color: var(--text-main); text-decoration: none;">
                                    <?= Security::sanitize($item['title']) ?>
                                </a>
                            </strong>
                            <div style="color: var(--text-muted); font-size: 12px; font-family: ui-monospace, monospace; margin-top: 2px;">
                                <?= htmlspecialchars($fullPath, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge" style="background: var(--bg-surface, #1e293b); color: var(--text-muted); border: 1px solid var(--border-subtle); font-size: 11px;">
                                <?= $item['type'] === 'post' ? _e('Post') : _e('Page') ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= $item['status'] === 'published' ? 'badge-success' : 'badge-warning' ?>" style="font-size: 11px;">
                                <?= $item['status'] === 'published' ? _e('Published') : _e('Draft') ?>
                            </span>
                        </td>
                        <td style="text-align: center;">
                            <span class="badge" style="background: rgba(59, 130, 246, 0.12); color: var(--accent, #60a5fa); border: 1px solid rgba(59, 130, 246, 0.25); font-size: 11px; font-weight: 700;">
                                <?= strtoupper(Security::sanitize($item['lang'])) ?>
                            </span>
                        </td>
                        <td style="color: var(--text-muted); font-size: 13px;">
                            <?= Security::sanitize($item['author_name'] ?? 'System') ?>
                        </td>
                        <td style="color: var(--text-muted); font-size: 12px;">
                            <?= date('d.m.Y H:i', strtotime($item['updated_at'])) ?>
                        </td>
                        <td style="text-align: right; vertical-align: middle;">
                            <div style="display: inline-flex; gap: 6px;">
                                <a href="page-edit.php?id=<?= $item['id'] ?>" class="btn btn-secondary" style="padding: 4px 9px; font-size: 12px;">
                                    <?= _e('Edit') ?>
                                </a>
                                <?php if (Auth::can('delete_pages')): ?>
                                    <button type="button" 
                                            class="btn btn-danger-ghost" 
                                            style="padding: 4px 8px; font-size: 12px; line-height: 1;" 
                                            onclick="confirmDeletePage('<?= htmlspecialchars($deleteUrl, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(addslashes($item['title']), ENT_QUOTES, 'UTF-8') ?>')">
                                        &times;
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Modal potwierdzenia usunięcia -->
<div id="delete-page-modal" style="display: none; position: fixed; inset: 0; background: rgba(11, 15, 25, 0.8); z-index: 2000; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(6px);">
    <div class="card" style="width: 100%; max-width: 420px; padding: 24px; background: var(--bg-card, #111827); border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6); border: 1px solid var(--border-subtle); text-align: center;">
        <div style="width: 46px; height: 46px; border-radius: 50%; background: rgba(239, 68, 68, 0.15); color: var(--danger, #ef4444); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
            <svg style="width: 24px; height: 24px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
        </div>
        <h3 style="font-size: 16px; font-weight: 700; margin: 0 0 8px; color: var(--text-main);"><?= _e('Delete Document') ?></h3>
        <p style="font-size: 13px; color: var(--text-muted); margin: 0 0 20px; line-height: 1.5;">
            <?= _e('Are you sure you want to permanently delete') ?> <strong id="delete-title-placeholder" style="color: var(--text-main);"></strong>? <?= _e('It will also be removed from any navigation menus. This action cannot be undone.') ?>
        </p>
        <div style="display: flex; gap: 10px; justify-content: center;">
            <button type="button" class="btn btn-secondary" style="flex: 1; padding: 9px;" onclick="closeDeleteModal()"><?= _e('Cancel') ?></button>
            <a id="confirm-delete-btn" href="#" class="btn btn-primary" style="background: var(--danger, #ef4444); border-color: var(--danger, #ef4444); flex: 1; padding: 9px; text-decoration: none; text-align: center;">
                <?= _e('Delete') ?>
            </a>
        </div>
    </div>
</div>

<script>
const delModal = document.getElementById('delete-page-modal');
const delBtn = document.getElementById('confirm-delete-btn');
const delPlaceholder = document.getElementById('delete-title-placeholder');

function confirmDeletePage(url, title) {
    delPlaceholder.textContent = title;
    delBtn.href = url;
    delModal.style.display = 'flex';
}

function closeDeleteModal() {
    delModal.style.display = 'none';
}

delModal.addEventListener('click', (e) => {
    if (e.target === delModal) closeDeleteModal();
});
</script>

<?php require_once __DIR__ . '/views/footer.php'; ?>