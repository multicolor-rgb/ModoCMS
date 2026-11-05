<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';
use Core\Database;
use Core\Security;
use Core\Auth;
use Core\MediaLibrary;
use Core\PageTypes;
use Core\Sitemap;

Auth::requireCapability('manage_pages');

$db = Database::getConnection();
$currentType = $_GET['type'] ?? 'all';
$currentLang = $_GET['lang'] ?? 'all';
$currentPageType = isset($_GET['page_type']) ? (int) $_GET['page_type'] : 0;
$showTrash = (($_GET['trash'] ?? '') === '1');
$pageTypes = PageTypes::all();

// Fetch all pages to calculate full hierarchical URL paths
$allPagesStmt = $db->query("SELECT id, parent_id, slug, lang, type FROM pages");
$lookupPages = [];
while ($p = $allPagesStmt->fetch()) {
    $lookupPages[(int)$p['id']] = $p;
}

function resolveFullSlug(array $item, array $lookup): string {
    if ($item['type'] === 'post') {
        return '/' . \Core\Router::getPostsPageSlug() . '/' . $item['slug'];
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
 * Builds a pre-order forest from the already filtered page rows. A row whose
 * parent is hidden by the current filters becomes a root, so no matching
 * document disappears from the listing.
 *
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array<string,mixed>>
 */
function buildPageTreeRows(array $items): array {
    $byId = [];
    foreach ($items as $item) {
        $byId[(int) $item['id']] = $item;
    }

    $children = [];
    $roots = [];
    foreach ($items as $item) {
        $id = (int) $item['id'];
        $parentId = (int) ($item['parent_id'] ?? 0);
        if ($parentId > 0 && $parentId !== $id && isset($byId[$parentId])) {
            $children[$parentId][] = $id;
        } else {
            $roots[] = $id;
        }
    }

    $ordered = [];
    $visited = [];
    $walk = static function (int $id, int $depth) use (&$walk, &$byId, &$children, &$visited, &$ordered): void {
        if (isset($visited[$id])) {
            return;
        }
        $visited[$id] = true;
        $node = $byId[$id];
        $node['tree_depth'] = $depth;
        $node['has_children'] = !empty($children[$id]);
        $ordered[] = $node;
        foreach ($children[$id] ?? [] as $childId) {
            $walk($childId, $depth + 1);
        }
    };

    foreach ($roots as $id) {
        $walk($id, 0);
    }

    // Corrupted legacy data can contain cycles. Promote the remaining rows to
    // roots and mark them as visited through the normal walk.
    foreach ($byId as $id => $item) {
        if (isset($visited[$id])) {
            continue;
        }
        $byId[$id]['parent_id'] = 0;
        $walk($id, 0);
    }

    return $ordered;
}

/**
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array<string,mixed>>
 */
function flattenPageRows(array $items): array {
    $rows = [];
    foreach ($items as $item) {
        $item['tree_depth'] = 0;
        $item['has_children'] = false;
        $rows[] = $item;
    }
    return $rows;
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
/**
 * Deletes a single document and performs all related cleanup: removes matching
 * navigation menu items (cascade), detaches tags, clears homepage/posts settings.
 * Returns true when a row was actually removed.
 */
function deleteDocumentCascade(\PDO $db, int $delId, array $lookup, bool $enforceOwnership = true): bool {
    $checkStmt = $db->prepare("SELECT * FROM pages WHERE id = :id LIMIT 1");
    $checkStmt->execute([':id' => $delId]);
    $pageToDelete = $checkStmt->fetch();

    if (!$pageToDelete) {
        return false;
    }

    if ($enforceOwnership && !Auth::can('edit_others_pages') && (int)$pageToDelete['author_id'] !== (int)Auth::id()) {
        return false;
    }

    // 1. Zbuduj możliwe warianty URL strony, które mogły trafić do nawigacji
    $candidateUrls = [];
    $rawSlug = $pageToDelete['slug'];
    $candidateUrls[] = '/' . $rawSlug;
    $candidateUrls[] = $rawSlug;

    $hierarchicalPath = resolveFullSlug($pageToDelete, $lookup);
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

    return true;
}

/**
 * Builds a map of named placeholders (:id0, :id1, ...) for an IN(...) clause.
 */
function buildIdPlaceholders(array $ids): array {
    $placeholders = [];
    $params = [];
    foreach (array_values($ids) as $i => $id) {
        $key = ':id' . $i;
        $placeholders[] = $key;
        $params[$key] = (int)$id;
    }
    return ['sqlPlaceholders' => implode(',', $placeholders), 'params' => $params];
}



// Handle document actions (POST only: destructive actions must not be GET)
$pageAction = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string)($_POST['action'] ?? '') : '';
if (in_array($pageAction, ['delete', 'restore', 'force_delete'], true)
    && Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    $delId = (int)($_POST['id'] ?? 0);
    $returnType = (string)($_POST['type'] ?? $currentType);
    $returnLang = (string)($_POST['lang'] ?? $currentLang);

    if ($pageAction === 'delete') {
        Auth::requireCapability('delete_pages');
        $owned = $db->prepare("SELECT author_id FROM pages WHERE id = :id AND deleted_at IS NULL LIMIT 1");
        $owned->execute([':id' => $delId]);
        $authorId = $owned->fetchColumn();
        if ($authorId !== false && (Auth::can('edit_others_pages') || (int)$authorId === Auth::id())) {
            $db->prepare("UPDATE pages SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute([':id' => $delId]);
            \Core\Audit::log('page.trash', 'page:' . $delId);
            if (class_exists('Core\PageCache')) {
                \Core\PageCache::purge();
            }
            if (class_exists('Core\Sitemap')) {
                try { Sitemap::generate(); } catch (\Throwable $e) {}
            }
        }
        header('Location: pages.php?type=' . urlencode($returnType) . '&lang=' . urlencode($returnLang) . ($currentPageType > 0 ? '&page_type=' . $currentPageType : '') . '&trashed=1');
        exit;
    }

    if ($pageAction === 'restore') {
        Auth::requireCapability('delete_pages');
        $db->prepare("UPDATE pages SET deleted_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':id' => $delId]);
        \Core\Audit::log('page.restore', 'page:' . $delId);
        if (class_exists('Core\PageCache')) {
            \Core\PageCache::purge();
        }
        if (class_exists('Core\Sitemap')) {
            try { Sitemap::generate(); } catch (\Throwable $e) {}
        }
        header('Location: pages.php?trash=1&type=' . urlencode($returnType) . '&lang=' . urlencode($returnLang) . '&restored=1');
        exit;
    }

    if ($pageAction === 'force_delete') {
        Auth::requireCapability('delete_pages');
        \Core\Audit::log('page.delete', 'page:' . $delId);
        if (deleteDocumentCascade($db, $delId, $lookupPages)) {
            if (class_exists('Core\Sitemap')) {
                try { Sitemap::generate(); } catch (\Throwable $e) {}
            }
        }
        header('Location: pages.php?trash=1&type=' . urlencode($returnType) . '&lang=' . urlencode($returnLang) . '&deleted=1');
        exit;
    }
}
// Handle Bulk Actions (posts & pages): delete, change status / author / language
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_action') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF');
    }

    $bulkType = (string)($_POST['bulk_action_type'] ?? '');
    $selectedIds = array_values(array_unique(array_filter(
        array_map('intval', (array)($_POST['ids'] ?? [])),
        static fn($id) => $id > 0
    )));

    $affected = 0;
    $bulkResult = 'none';

    if (!empty($selectedIds) && in_array($bulkType, ['delete', 'status', 'author', 'lang'], true)) {
        // Restrict the operation to rows the current user is allowed to modify.
        $idMap = buildIdPlaceholders($selectedIds);
        $inClause = $idMap['sqlPlaceholders'];
        $baseParams = $idMap['params'];
        $ownershipClause = '';
        if (!Auth::can('edit_others_pages')) {
            $ownershipClause = ' AND author_id = :own';
            $baseParams[':own'] = Auth::id();
        }

        if ($bulkType === 'delete') {
            Auth::requireCapability('delete_pages');
            $params = $baseParams;
            $stmt = $db->prepare("UPDATE pages SET deleted_at = CURRENT_TIMESTAMP WHERE id IN ($inClause) AND deleted_at IS NULL$ownershipClause");
            $stmt->execute($params);
            $affected = $stmt->rowCount();
            if ($affected > 0 && class_exists('Core\PageCache')) {
                \Core\PageCache::purge();
            }
            if ($affected > 0 && class_exists('Core\Sitemap')) {
                try { Sitemap::generate(); } catch (\Throwable $e) {}
            }
            $bulkResult = 'trashed';
        } elseif ($bulkType === 'status') {
            Auth::requireCapability('publish_pages');
            $newStatus = ($_POST['bulk_status'] ?? '') === 'published' ? 'published' : 'draft';
            $params = $baseParams + [':val' => $newStatus];
            $stmt = $db->prepare("UPDATE pages SET status = :val, updated_at = CURRENT_TIMESTAMP WHERE id IN ($inClause)$ownershipClause");
            $stmt->execute($params);
            $affected = $stmt->rowCount();
            $bulkResult = 'updated';
        } elseif ($bulkType === 'author') {
            Auth::requireCapability('edit_others_pages');
            $newAuthor = (int)($_POST['bulk_author'] ?? 0);
            if ($newAuthor > 0) {
                $authorCheck = $db->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
                $authorCheck->execute([':id' => $newAuthor]);
                if ($authorCheck->fetchColumn()) {
                    $params = $baseParams + [':author' => $newAuthor];
                    $stmt = $db->prepare("UPDATE pages SET author_id = :author, updated_at = CURRENT_TIMESTAMP WHERE id IN ($inClause)$ownershipClause");
                    $stmt->execute($params);
                    $affected = $stmt->rowCount();
                    $bulkResult = 'updated';
                }
            }
        } elseif ($bulkType === 'lang') {
            Auth::requireCapability('manage_pages');
            $newLang = trim((string)($_POST['bulk_lang'] ?? ''));
            if ($newLang !== '' && array_key_exists($newLang, \Core\I18n::getAvailableLanguages())) {
                $params = $baseParams + [':lang' => $newLang];
                $stmt = $db->prepare("UPDATE pages SET lang = :lang, updated_at = CURRENT_TIMESTAMP WHERE id IN ($inClause)$ownershipClause");
                $stmt->execute($params);
                $affected = $stmt->rowCount();
                $bulkResult = 'updated';
            }
        }
    }

    header('Location: pages.php?type=' . urlencode($currentType) . '&lang=' . urlencode($currentLang)
        . ($currentPageType > 0 ? '&page_type=' . $currentPageType : '')
        . ($showTrash ? '&trash=1' : '') . '&bulk=' . urlencode($bulkResult) . '&count=' . (int)$affected);
    exit;
}



// Build query with filters
$sql = "SELECT p.*, u.username as author_name,
               pt.name AS page_type_name, pt.slug AS page_type_slug, pt.base_type AS page_type_base
        FROM pages p
        LEFT JOIN users u ON p.author_id = u.id
        LEFT JOIN page_types pt ON pt.id = p.page_type_id";
$conditions = [];
$params = [];

if ($showTrash) {
    $conditions[] = "p.deleted_at IS NOT NULL";
} else {
    $conditions[] = "p.deleted_at IS NULL";
}

if ($currentType === 'post' || $currentType === 'page') {
    $conditions[] = "p.type = :tp";
    $params[':tp'] = $currentType;
}

if ($currentPageType > 0) {
    $conditions[] = "p.page_type_id = :ptid";
    $params[':ptid'] = $currentPageType;
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

// Pages use parent_id for hierarchy; posts are always roots. Trash stays flat
// so mixed deleted/restored states do not create confusing branches.
$treeItems = $showTrash ? flattenPageRows($items) : buildPageTreeRows($items);
$hasPageChildren = false;
foreach ($treeItems as $treeItem) {
    if (!empty($treeItem['has_children'])) {
        $hasPageChildren = true;
        break;
    }
}

// Counts for filter pills
$countAll = (int)$db->query("SELECT COUNT(*) FROM pages WHERE deleted_at IS NULL")->fetchColumn();
$countPosts = (int)$db->query("SELECT COUNT(*) FROM pages WHERE type = 'post' AND deleted_at IS NULL")->fetchColumn();
$countPages = (int)$db->query("SELECT COUNT(*) FROM pages WHERE type = 'page' AND deleted_at IS NULL")->fetchColumn();
$countTrash = (int)$db->query("SELECT COUNT(*) FROM pages WHERE deleted_at IS NOT NULL")->fetchColumn();
$pageTypeCounts = [];
foreach ($db->query("SELECT page_type_id, COUNT(*) AS total FROM pages WHERE page_type_id IS NOT NULL AND deleted_at IS NULL GROUP BY page_type_id")->fetchAll() as $countRow) {
    $pageTypeCounts[(int) $countRow['page_type_id']] = (int) $countRow['total'];
}
// Authors list for the bulk "Change author" action (privileged users only)
$allUsers = [];
if (Auth::can('edit_others_pages')) {
    $allUsers = $db->query("SELECT id, username FROM users ORDER BY username")->fetchAll();
}



$trashQuery = $showTrash ? '&trash=1' : '';

require_once __DIR__ . '/views/header.php';
?>

<div class="page-header" style="margin-bottom: 24px;">
    <div>
        <h1 class="page-title"><?= _e('Content') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px;"><?= _e('Manage static pages, blog articles, and localized content') ?></p>
    </div>
    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <?php if (!empty($pageTypes)): ?>
            <select class="form-control" style="width: auto; padding: 8px 12px; font-size: 12px;" onchange="if (this.value) { const opt = this.options[this.selectedIndex]; location.href = 'page-edit.php?type=' + encodeURIComponent(opt.dataset.base || 'page') + '&page_type=' + encodeURIComponent(this.value); }">
                <option value="">+ <?= _e('New from type...') ?></option>
                <?php foreach ($pageTypes as $preset): ?>
                    <option value="<?= htmlspecialchars((string) $preset['slug'], ENT_QUOTES, 'UTF-8') ?>" data-base="<?= htmlspecialchars((string) $preset['base_type'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= Security::sanitize((string) $preset['name']) ?> (<?= $preset['base_type'] === 'post' ? _e('Post') : _e('Page') ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <a href="page-edit.php?type=post" class="btn btn-primary">+ <?= _e('New Post') ?></a>
        <a href="page-edit.php?type=page" class="btn btn-secondary">+ <?= _e('New Page') ?></a>
    </div>
</div>

<?php if (isset($_GET['trashed'])): ?>
    <div class="card" style="border-left: 4px solid var(--warning, #f59e0b); padding: 12px 16px; margin-bottom: 20px;">
        <?= _e('Document moved to trash. You can restore it from the Trash view.') ?>
    </div>
<?php endif; ?>
<?php if (isset($_GET['restored'])): ?>
    <div class="card" style="border-left: 4px solid var(--success, #10b981); padding: 12px 16px; margin-bottom: 20px;">
        <?= _e('Document restored successfully.') ?>
    </div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
    <div class="card" style="border-left: 4px solid var(--success, #10b981); background: rgba(16, 185, 129, 0.08); padding: 12px 16px; margin-bottom: 20px; color: #34d399; font-weight: 500;">
        <?= _e('Document deleted permanently and removed from menus.') ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['bulk']) && $_GET['bulk'] !== 'none'): ?>
    <div class="card" style="border-left: 4px solid var(--success, #10b981); background: rgba(16, 185, 129, 0.08); padding: 12px 16px; margin-bottom: 20px; color: #34d399; font-weight: 500;">
        <?= _e('Bulk action applied to') ?> <strong><?= (int)($_GET['count'] ?? 0) ?></strong> <?= _e('item(s).') ?>
    </div>
<?php endif; ?>

<!-- Filters Toolbar -->
<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 20px;">
    <div style="display: flex; gap: 8px; align-items: center;">
        <a href="pages.php?type=all&lang=<?= urlencode($currentLang) ?><?= $trashQuery ?>"
           class="btn <?= $currentType === 'all' ? 'btn-primary' : 'btn-secondary' ?>" 
           style="padding: 6px 14px; font-size: 12px;">
            <?= _e('All') ?> <span style="opacity: 0.75; font-size: 11px;">(<?= $countAll ?>)</span>
        </a>
        <a href="pages.php?type=post&lang=<?= urlencode($currentLang) ?><?= $trashQuery ?>"
           class="btn <?= $currentType === 'post' ? 'btn-primary' : 'btn-secondary' ?>" 
           style="padding: 6px 14px; font-size: 12px;">
            <?= _e('Posts') ?> <span style="opacity: 0.75; font-size: 11px;">(<?= $countPosts ?>)</span>
        </a>
        <a href="pages.php?type=page&lang=<?= urlencode($currentLang) ?><?= $trashQuery ?>"
           class="btn <?= $currentType === 'page' ? 'btn-primary' : 'btn-secondary' ?>" 
           style="padding: 6px 14px; font-size: 12px;">
            <?= _e('Pages') ?> <span style="opacity: 0.75; font-size: 11px;">(<?= $countPages ?>)</span>
        </a>
        <a href="pages.php?trash=1&type=<?= urlencode($currentType) ?>&lang=<?= urlencode($currentLang) ?>"
           class="btn <?= $showTrash ? 'btn-primary' : 'btn-secondary' ?>"
           style="padding: 6px 14px; font-size: 12px;">
            <?= _e('Trash') ?> <span style="opacity: 0.75; font-size: 11px;">(<?= $countTrash ?>)</span>
        </a>
    </div>

    <!-- Quick Language Filter -->
    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
        <?php if (!empty($pageTypes)): ?>
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="font-size: 12px; color: var(--text-muted);"><?= _e('Page type:') ?></span>
                <select class="form-control" style="width: auto; padding: 4px 10px; font-size: 12px;" onchange="const value = this.value; const opt = this.options[this.selectedIndex]; const base = value === '0' ? <?= json_encode((string) $currentType) ?> : (opt.dataset.base || 'all'); location.href = 'pages.php?type=' + encodeURIComponent(base) + '&lang=<?= urlencode($currentLang) ?><?= $trashQuery ?>&page_type=' + encodeURIComponent(value);">
                    <option value="0"><?= _e('All page types') ?></option>
                    <?php foreach ($pageTypes as $preset): ?>
                        <option value="<?= (int) $preset['id'] ?>" data-base="<?= htmlspecialchars((string) $preset['base_type'], ENT_QUOTES, 'UTF-8') ?>" <?= $currentPageType === (int) $preset['id'] ? 'selected' : '' ?>>
                            <?= Security::sanitize((string) $preset['name']) ?> (<?= (int) ($pageTypeCounts[(int) $preset['id']] ?? 0) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div style="display: flex; align-items: center; gap: 8px;">
            <span style="font-size: 12px; color: var(--text-muted);"><?= _e('Language:') ?></span>
            <select class="form-control" style="width: auto; padding: 4px 10px; font-size: 12px;" onchange="location.href='pages.php?type=<?= urlencode($currentType) ?>&lang=' + this.value<?= $currentPageType > 0 ? " + '&page_type=" . $currentPageType . "'" : '' ?><?= $showTrash ? " + '&trash=1'" : '' ?>">
                <option value="all" <?= $currentLang === 'all' ? 'selected' : '' ?>><?= _e('All Languages') ?></option>
                <?php foreach (\Core\I18n::getAvailableLanguages() as $code => $name): ?>
                    <option value="<?= $code ?>" <?= $currentLang === $code ? 'selected' : '' ?>><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?> (<?= strtoupper($code) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
</div>

<form method="post" id="bulk-form" style="margin: 0;">
<input type="hidden" name="action" value="bulk_action">
<input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
<input type="hidden" name="bulk_action_type" id="bulk-action-type" value="">

<!-- Bulk Actions Bar -->
<?php if (!$showTrash): ?>
<div id="bulk-bar" class="card" style="display: none; align-items: center; gap: 10px; flex-wrap: wrap; padding: 10px 14px; margin-bottom: 16px; border: 1px solid var(--border-subtle);">
    <span id="bulk-count" style="font-size: 13px; font-weight: 600; color: var(--text-main);"></span>
    <select class="form-control" id="bulk-action-picker" style="width: auto; padding: 6px 10px; font-size: 13px;">
        <option value=""><?= _e('Bulk actions') ?></option>
        <?php if (Auth::can('delete_pages')): ?>
            <option value="delete"><?= _e('Delete') ?></option>
        <?php endif; ?>
        <?php if (Auth::can('publish_pages')): ?>
            <option value="status"><?= _e('Change status') ?></option>
        <?php endif; ?>
        <?php if (Auth::can('edit_others_pages')): ?>
            <option value="author"><?= _e('Change author') ?></option>
        <?php endif; ?>
        <option value="lang"><?= _e('Change language') ?></option>
    </select>

    <select class="form-control" id="bulk-status-field" name="bulk_status" style="display: none; width: auto; padding: 6px 10px; font-size: 13px;">
        <option value="published"><?= _e('Published') ?></option>
        <option value="draft"><?= _e('Draft') ?></option>
    </select>

    <?php if (Auth::can('edit_others_pages')): ?>
    <select class="form-control" id="bulk-author-field" name="bulk_author" style="display: none; width: auto; padding: 6px 10px; font-size: 13px;">
        <?php foreach ($allUsers as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <select class="form-control" id="bulk-lang-field" name="bulk_lang" style="display: none; width: auto; padding: 6px 10px; font-size: 13px;">
        <?php foreach (\Core\I18n::getAvailableLanguages() as $code => $name): ?>
            <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?> (<?= strtoupper($code) ?>)</option>
        <?php endforeach; ?>
    </select>

    <button type="button" class="btn btn-primary" id="bulk-apply-btn" style="padding: 6px 16px; font-size: 13px;"><?= _e('Apply') ?></button>
    <button type="button" class="btn btn-secondary" id="bulk-clear-btn" style="padding: 6px 16px; font-size: 13px;"><?= _e('Cancel') ?></button>
</div>
<?php endif; ?>

<?php if (!$showTrash && $hasPageChildren): ?>
<div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 10px;">
    <span style="font-size: 12px; color: var(--text-muted);"><?= _e('Pages are shown as a tree. Use the arrows to expand or collapse subpages.') ?></span>
    <div style="display: flex; gap: 6px;">
        <button type="button" class="btn btn-secondary" id="pages-expand-all" style="padding: 4px 10px; font-size: 12px;"><?= _e('Expand all') ?></button>
        <button type="button" class="btn btn-secondary" id="pages-collapse-all" style="padding: 4px 10px; font-size: 12px;"><?= _e('Collapse all') ?></button>
    </div>
</div>
<?php endif; ?>

<div class="table-container card table-responsive" style="padding: 0; border: 1px solid var(--border-subtle);">
    <table class="pro-table table-wide">
        <thead>
            <tr>
                <?php if (!$showTrash): ?><th style="width: 40px; text-align: center;"><input type="checkbox" id="bulk-select-all" aria-label="<?= _e('Select all') ?>"></th><?php else: ?><th style="width: 40px;"></th><?php endif; ?>
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
        <tbody id="pages-tree-body">
            <?php if (empty($treeItems)): ?>
                <tr>
                    <td colspan="9" style="text-align: center; color: var(--text-muted); padding: 48px 20px;">
                        <p style="margin: 0; font-size: 14px;"><?= _e('No matching documents found.') ?></p>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($treeItems as $item):
                    $fullPath = resolveFullSlug($item, $lookupPages);
                    $treeDepth = (int) ($item['tree_depth'] ?? 0);
                ?>
                    <tr class="page-tree-row"
                        data-page-id="<?= (int) $item['id'] ?>"
                        data-parent-id="<?= (int) ($item['parent_id'] ?? 0) ?>"
                        data-depth="<?= $treeDepth ?>"
                        data-has-children="<?= !empty($item['has_children']) ? '1' : '0' ?>">
                        <td style="text-align: center; vertical-align: middle;">
                            <?php if (!$showTrash): ?>
                                <input type="checkbox" class="bulk-checkbox" name="ids[]" value="<?= (int)$item['id'] ?>" aria-label="<?= _e('Select') ?>">
                            <?php endif; ?>
                        </td>
                        <td style="text-align: center; vertical-align: middle;">
                            <?php if (!empty($item['featured_image'])): ?>
                                <img src="<?= htmlspecialchars(MediaLibrary::thumbnailUrl((string) $item['featured_image'], 80), ENT_QUOTES, 'UTF-8') ?>"
                                     alt="Thumb" 
                                     loading="lazy" decoding="async"
                                     style="width: 38px; height: 38px; object-fit: cover; border-radius: var(--radius-sm, 6px); border: 1px solid var(--border-subtle); display: block; margin: 0 auto;">
                            <?php else: ?>
                                <div style="width: 38px; height: 38px; background: var(--bg-surface, #1e293b); border: 1px solid var(--border-subtle); border-radius: var(--radius-sm, 6px); display: flex; align-items: center; justify-content: center; color: var(--text-muted); font-size: 11px; margin: 0 auto;">
                                    &mdash;
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display: flex; align-items: flex-start; gap: 6px; padding-left: <?= $treeDepth * 20 ?>px;">
                                <?php if (!$showTrash && !empty($item['has_children'])): ?>
                                    <button type="button" class="page-tree-toggle" data-target-id="<?= (int) $item['id'] ?>" aria-expanded="true" aria-label="<?= _e('Toggle subpages') ?>" title="<?= _e('Toggle subpages') ?>">
                                        <svg class="page-tree-chevron" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 5l6 5-6 5"/></svg>
                                    </button>
                                <?php else: ?>
                                    <span class="page-tree-toggle-spacer" aria-hidden="true"></span>
                                <?php endif; ?>
                                <div style="min-width: 0;">
                                    <strong style="font-size: 14px;">
                                        <a href="page-edit.php?id=<?= $item['id'] ?>" style="color: var(--text-main); text-decoration: none;">
                                            <?= Security::sanitize($item['title']) ?>
                                        </a>
                                    </strong>
                                    <div style="color: var(--text-muted); font-size: 12px; font-family: ui-monospace, monospace; margin-top: 2px;">
                                        <?= htmlspecialchars($fullPath, ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge" style="background: var(--bg-surface, #1e293b); color: var(--text-muted); border: 1px solid var(--border-subtle); font-size: 11px;" title="<?= $item['type'] === 'post' ? _e('Blog Post') : _e('Static Page') ?>">
                                <?= !empty($item['page_type_name']) ? Security::sanitize((string) $item['page_type_name']) : ($item['type'] === 'post' ? _e('Post') : _e('Page')) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($showTrash): ?>
                                <span class="badge badge-warning" style="font-size: 11px;"><?= _e('Deleted') ?></span>
                            <?php else: ?>
                                <span class="badge <?= $item['status'] === 'published' ? 'badge-success' : 'badge-warning' ?>" style="font-size: 11px;">
                                    <?= $item['status'] === 'published' ? _e('Published') : _e('Draft') ?>
                                </span>
                            <?php endif; ?>
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
                            <?= date('d.m.Y H:i', strtotime((string)($showTrash && !empty($item['deleted_at']) ? $item['deleted_at'] : $item['updated_at']))) ?>
                        </td>
                        <td style="text-align: right; vertical-align: middle;">
                            <div style="display: inline-flex; gap: 6px;">
                                <?php if ($showTrash): ?>
                                    <button type="button" class="btn btn-secondary" style="padding: 4px 9px; font-size: 12px;"
                                            onclick="submitPageAction('restore', <?= (int)$item['id'] ?>)">
                                        <?= _e('Restore') ?>
                                    </button>
                                    <?php if (Auth::can('delete_pages')): ?>
                                        <button type="button" class="btn btn-danger-ghost" style="padding: 4px 8px; font-size: 12px;"
                                                onclick="if (confirm(<?= json_encode(__('Delete this document permanently? This cannot be undone.')) ?>)) submitPageAction('force_delete', <?= (int)$item['id'] ?>)">
                                            <?= _e('Delete permanently') ?>
                                        </button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <a href="page-edit.php?id=<?= $item['id'] ?>" class="btn btn-secondary" style="padding: 4px 9px; font-size: 12px;">
                                        <?= _e('Edit') ?>
                                    </a>
                                    <a href="page-edit.php?id=<?= $item['id'] ?>#revisions-card" class="btn btn-secondary" style="padding: 4px 8px; font-size: 12px; line-height: 1;" title="<?= _e('Revision History') ?>" aria-label="<?= _e('Revision History') ?>">
                                        <svg style="width: 14px; height: 14px; vertical-align: middle;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </a>
                                    <?php if (Auth::can('delete_pages')): ?>
                                        <button type="button"
                                                class="btn btn-danger-ghost"
                                                style="padding: 4px 8px; font-size: 12px; line-height: 1;"
                                                onclick="confirmDeletePage(<?= (int)$item['id'] ?>, '<?= htmlspecialchars(addslashes($item['title']), ENT_QUOTES, 'UTF-8') ?>')">
                                            &times;
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</form>

<!-- Modal potwierdzenia usunięcia -->
<div id="delete-page-modal" style="display: none; position: fixed; inset: 0; background: rgba(11, 15, 25, 0.8); z-index: 2000; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(6px);">
    <div class="card" style="width: 100%; max-width: 420px; padding: 24px; background: var(--bg-card, #111827); border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6); border: 1px solid var(--border-subtle); text-align: center;">
        <div style="width: 46px; height: 46px; border-radius: 50%; background: rgba(239, 68, 68, 0.15); color: var(--danger, #ef4444); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
            <svg style="width: 24px; height: 24px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
        </div>
        <h3 style="font-size: 16px; font-weight: 700; margin: 0 0 8px; color: var(--text-main);"><?= _e('Move to Trash') ?></h3>
        <p style="font-size: 13px; color: var(--text-muted); margin: 0 0 20px; line-height: 1.5;">
            <?= _e('Move') ?> <strong id="delete-title-placeholder" style="color: var(--text-main);"></strong> <?= _e('to the trash? You can restore it later.') ?>
        </p>
        <div style="display: flex; gap: 10px; justify-content: center;">
            <button type="button" class="btn btn-secondary" style="flex: 1; padding: 9px;" onclick="closeDeleteModal()"><?= _e('Cancel') ?></button>
            <button type="button" id="confirm-delete-btn" class="btn btn-primary" style="background: var(--danger, #ef4444); border-color: var(--danger, #ef4444); flex: 1; padding: 9px; text-align: center;">
                <?= _e('Move to Trash') ?>
            </button>
        </div>
    </div>
</div>

<script>
const delModal = document.getElementById('delete-page-modal');
const delBtn = document.getElementById('confirm-delete-btn');
const delPlaceholder = document.getElementById('delete-title-placeholder');

function submitPageAction(action, id) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'pages.php';
    const fields = {
        action: action,
        id: String(id),
        csrf_token: <?= json_encode(Security::generateCsrfToken()) ?>,
        type: <?= json_encode($currentType) ?>,
        lang: <?= json_encode($currentLang) ?>,
        trash: <?= json_encode($showTrash ? '1' : '') ?>
    };
    Object.keys(fields).forEach((name) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = fields[name];
        form.appendChild(input);
    });
    document.body.appendChild(form);
    form.submit();
}

function confirmDeletePage(id, title) {
    delPlaceholder.textContent = title;
    delBtn.dataset.pageId = String(id);
    delModal.style.display = 'flex';
}

function closeDeleteModal() {
    delModal.style.display = 'none';
}

delBtn.addEventListener('click', () => {
    const id = delBtn.dataset.pageId || '';
    if (!id) return;
    submitPageAction('delete', id);
});

delModal.addEventListener('click', (e) => {
    if (e.target === delModal) closeDeleteModal();
});
</script>

<script>
(function () {
    const form = document.getElementById('bulk-form');
    const bar = document.getElementById('bulk-bar');
    if (!form || !bar) return;

    const selectAll = document.getElementById('bulk-select-all');
    const checkboxes = Array.from(document.querySelectorAll('.bulk-checkbox'));
    const countEl = document.getElementById('bulk-count');
    const picker = document.getElementById('bulk-action-picker');
    const typeField = document.getElementById('bulk-action-type');
    const statusField = document.getElementById('bulk-status-field');
    const authorField = document.getElementById('bulk-author-field');
    const langField = document.getElementById('bulk-lang-field');
    const applyBtn = document.getElementById('bulk-apply-btn');
    const clearBtn = document.getElementById('bulk-clear-btn');

    const LBL = {
        barTitle: <?= json_encode(__('Bulk actions')) ?>,
        selected: <?= json_encode(__('item(s) selected')) ?>,
        selectItem: <?= json_encode(__('Please select at least one item.')) ?>,
        chooseAction: <?= json_encode(__('Please choose a bulk action.')) ?>,
        confirmTitle: <?= json_encode(__('Delete selected items')) ?>,
        confirmMsg: <?= json_encode(__('This action cannot be undone.')) ?>,
        deleteLabel: <?= json_encode(__('Delete')) ?>
    };

    const selectedCount = () => checkboxes.filter(c => c.checked).length;

    const updateBar = () => {
        const n = selectedCount();
        bar.style.display = n > 0 ? 'flex' : 'none';
        countEl.textContent = n + ' ' + LBL.selected;
        if (selectAll) {
            selectAll.checked = n > 0 && n === checkboxes.length;
            selectAll.indeterminate = n > 0 && n < checkboxes.length;
        }
    };

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            checkboxes.forEach(c => { c.checked = selectAll.checked; });
            updateBar();
        });
    }
    checkboxes.forEach(c => c.addEventListener('change', updateBar));

    const toggleFields = (val) => {
        typeField.value = val;
        if (statusField) statusField.style.display = val === 'status' ? 'inline-block' : 'none';
        if (authorField) authorField.style.display = val === 'author' ? 'inline-block' : 'none';
        if (langField) langField.style.display = val === 'lang' ? 'inline-block' : 'none';
    };
    picker.addEventListener('change', () => toggleFields(picker.value));

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            checkboxes.forEach(c => { c.checked = false; });
            picker.value = '';
            toggleFields('');
            updateBar();
        });
    }

    applyBtn.addEventListener('click', () => {
        const n = selectedCount();
        const val = picker.value;
        if (n === 0) {
            UI.alert({ title: LBL.barTitle, message: LBL.selectItem });
            return;
        }
        if (!val) {
            UI.alert({ title: LBL.barTitle, message: LBL.chooseAction });
            return;
        }
        if (val === 'delete') {
            UI.confirm({
                title: LBL.confirmTitle,
                message: n + ' ' + LBL.selected + '. ' + LBL.confirmMsg,
                okText: LBL.deleteLabel,
                danger: true
            }).then(ok => { if (ok) form.submit(); });
        } else {
            form.submit();
        }
    });

    updateBar();
})();
</script>


<?php if (!$showTrash): ?>
<script>
(function () {
    const body = document.getElementById('pages-tree-body');
    if (!body) return;

    const rows = Array.from(body.querySelectorAll('tr.page-tree-row'));
    if (!rows.length) return;

    const rowById = new Map(rows.map((row) => [row.dataset.pageId, row]));
    const storageKey = 'modo_pages_collapsed';
    let collapsed = new Set();

    try {
        const raw = JSON.parse(localStorage.getItem(storageKey) || '[]');
        if (Array.isArray(raw)) collapsed = new Set(raw.map(String));
    } catch (e) {
        collapsed = new Set();
    }

    const save = () => {
        try { localStorage.setItem(storageKey, JSON.stringify(Array.from(collapsed))); } catch (e) {}
    };

    const hiddenByAncestor = (row) => {
        let parentId = row.dataset.parentId;
        const seen = new Set();
        while (parentId && parentId !== '0' && rowById.has(parentId)) {
            if (collapsed.has(parentId)) return true;
            if (seen.has(parentId)) break;
            seen.add(parentId);
            parentId = rowById.get(parentId).dataset.parentId;
        }
        return false;
    };

    const apply = () => {
        rows.forEach((row) => {
            row.style.display = hiddenByAncestor(row) ? 'none' : '';
            const toggle = row.querySelector('.page-tree-toggle');
            if (!toggle) return;
            const isCollapsed = collapsed.has(row.dataset.pageId);
            toggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
            toggle.classList.toggle('is-collapsed', isCollapsed);
        });
    };

    body.addEventListener('click', (event) => {
        const button = event.target.closest('.page-tree-toggle');
        if (!button) return;
        const row = button.closest('tr.page-tree-row');
        if (!row) return;
        const id = row.dataset.pageId;
        if (collapsed.has(id)) collapsed.delete(id); else collapsed.add(id);
        save();
        apply();
    });

    const expandAll = document.getElementById('pages-expand-all');
    if (expandAll) {
        expandAll.addEventListener('click', () => { collapsed.clear(); save(); apply(); });
    }

    const collapseAll = document.getElementById('pages-collapse-all');
    if (collapseAll) {
        collapseAll.addEventListener('click', () => {
            rows.forEach((row) => {
                if (row.dataset.hasChildren === '1') collapsed.add(row.dataset.pageId);
            });
            save();
            apply();
        });
    }

    apply();
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/views/footer.php'; ?>
