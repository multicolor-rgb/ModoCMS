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

$allMenus = $db->query("SELECT * FROM menus ORDER BY id ASC")->fetchAll();
$selectedMenuId = isset($_GET['menu']) ? (int)$_GET['menu'] : ($allMenus[0]['id'] ?? 0);

// Create new menu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_menu') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) die('Invalid CSRF');
    $name = trim($_POST['menu_name'] ?? '');
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $_POST['menu_slug'] ?: $name), '-'));
    if ($name && $slug) {
        try {
            $stmt = $db->prepare("INSERT INTO menus (name, slug) VALUES (:n, :s)");
            $stmt->execute([':n' => $name, ':s' => $slug]);
            header("Location: menus.php?menu=" . $db->lastInsertId() . '&saved=1');
            exit;
        } catch (\PDOException $e) { 
            $message = 'Menu slug is already in use.'; 
            $messageType = 'danger';
        }
    }
}

// Delete menu
if (isset($_GET['delete_menu']) && isset($_GET['csrf'])) {
    if (Security::verifyCsrfToken($_GET['csrf'])) {
        $delId = (int)$_GET['delete_menu'];
        $stmt = $db->prepare("DELETE FROM menus WHERE id = :id");
        $stmt->execute([':id' => $delId]);
        header("Location: menus.php");
        exit;
    }
}

// Add single item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_item') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) die('Invalid CSRF');
    $stmt = $db->prepare("INSERT INTO menu_items (menu_id, parent_id, title, url, sort_order) VALUES (:m, :p, :t, :u, :s)");
    $stmt->execute([
        ':m' => $selectedMenuId,
        ':p' => (int)($_POST['parent_id'] ?? 0),
        ':t' => trim($_POST['item_title'] ?? ''),
        ':u' => trim($_POST['item_url'] ?? ''),
        ':s' => (int)($_POST['sort_order'] ?? 0)
    ]);
    header("Location: menus.php?menu={$selectedMenuId}&saved=1");
    exit;
}

// Bulk update existing menu items (requires explicit Save button)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_menu_structure') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) die('Invalid CSRF');

    $itemsData = $_POST['items'] ?? [];
    if (is_array($itemsData)) {
        $updateStmt = $db->prepare("UPDATE menu_items SET title = :t, url = :u, parent_id = :p, sort_order = :s WHERE id = :id AND menu_id = :m");
        foreach ($itemsData as $itemId => $fields) {
            $updateStmt->execute([
                ':t'  => trim($fields['title'] ?? ''),
                ':u'  => trim($fields['url'] ?? ''),
                ':p'  => (int)($fields['parent_id'] ?? 0),
                ':s'  => (int)($fields['sort_order'] ?? 0),
                ':id' => (int)$itemId,
                ':m'  => $selectedMenuId
            ]);
        }
    }
    header("Location: menus.php?menu={$selectedMenuId}&saved=1");
    exit;
}

// Delete single item
if (isset($_GET['delete_item']) && isset($_GET['csrf'])) {
    if (Security::verifyCsrfToken($_GET['csrf'])) {
        $itemId = (int)$_GET['delete_item'];
        $stmt = $db->prepare("DELETE FROM menu_items WHERE id = :id OR parent_id = :id");
        $stmt->execute([':id' => $itemId]);
        header("Location: menus.php?menu={$selectedMenuId}");
        exit;
    }
}

// Fetch pages and compute nested hierarchy paths
$allPages = $db->query("SELECT id, parent_id, title, slug FROM pages WHERE status = 'published' ORDER BY parent_id ASC, title ASC")->fetchAll();

$pageLookup = [];
foreach ($allPages as $p) {
    $pageLookup[$p['id']] = $p;
}

/**
 * Builds full hierarchical URL path for a page record.
 */
function buildPageHierarchyUrl(array $page, array $lookup): string {
    $slugs = [$page['slug']];
    $currParent = (int)$page['parent_id'];
    while ($currParent > 0 && isset($lookup[$currParent])) {
        if ($lookup[$currParent]['slug'] !== 'home') {
            array_unshift($slugs, $lookup[$currParent]['slug']);
        }
        $currParent = (int)$lookup[$currParent]['parent_id'];
    }
    return '/' . implode('/', $slugs);
}

$menuItems = [];
$currentMenu = null;
if ($selectedMenuId > 0) {
    $stmt = $db->prepare("SELECT * FROM menus WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $selectedMenuId]);
    $currentMenu = $stmt->fetch();

    $stmt = $db->prepare("SELECT * FROM menu_items WHERE menu_id = :m ORDER BY sort_order ASC, id ASC");
    $stmt->execute([':m' => $selectedMenuId]);
    $menuItems = $stmt->fetchAll();
}

/**
 * Recursive tree builder for administrative display.
 */
function buildMenuTree(array $items, int $parentId = 0, int $depth = 0): array {
    $branch = [];
    foreach ($items as $item) {
        if ((int)$item['parent_id'] === $parentId) {
            $item['depth'] = $depth;
            $children = buildMenuTree($items, (int)$item['id'], $depth + 1);
            if ($children) $item['children'] = $children;
            $branch[] = $item;
        }
    }
    return $branch;
}
$tree = buildMenuTree($menuItems);

require_once __DIR__ . '/views/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= _e('Navigation & Menus') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px;"><?= _e('Manage hierarchical, multi-level site links') ?></p>
    </div>
</div>

<?php if (isset($_GET['saved'])): ?>
    <div class="card" style="border-left: 4px solid var(--success); padding: 12px; margin-bottom: 20px;">
        <?= _e('Menu updated successfully.') ?>
    </div>
<?php elseif ($message): ?>
    <div class="card" style="border-left: 4px solid var(--<?= $messageType ?>); padding: 12px; margin-bottom: 20px;">
        <?= Security::sanitize($message) ?>
    </div>
<?php endif; ?>

<div class="card" style="padding: 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
    <form method="GET" action="" style="display: flex; align-items: center; gap: 10px;">
        <label style="font-weight: 600; color: var(--text-main);"><?= _e('Select menu to edit:') ?></label>
        <select name="menu" class="form-control" style="width: auto;" onchange="this.form.submit()">
            <?php foreach ($allMenus as $m): ?>
                <option value="<?= $m['id'] ?>" <?= $m['id'] == $selectedMenuId ? 'selected' : '' ?>>
                    <?= Security::sanitize($m['name']) ?> (<?= Security::sanitize($m['slug']) ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </form>
    <div style="display: flex; gap: 10px;">
        <?php if ($currentMenu && count($allMenus) > 1): ?>
            <a href="menus.php?delete_menu=<?= $currentMenu['id'] ?>&csrf=<?= Security::generateCsrfToken() ?>" 
               class="btn btn-danger-ghost" 
               onclick="return confirm('Delete this menu?');"><?= _e('Delete Current Menu') ?></a>
        <?php endif; ?>
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('new-menu-box').style.display='block'"><?= _e('+ New Menu') ?></button>
    </div>
</div>

<div id="new-menu-box" class="card" style="display: none; border-left: 4px solid var(--primary); margin-bottom: 24px;">
    <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 12px; color: var(--text-main);"><?= _e('Create New Menu') ?></h3>
    <form method="POST" action="" style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 14px; align-items: end;">
        <input type="hidden" name="action" value="create_menu">
        <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
        <div>
            <label class="form-label"><?= _e('Menu Name') ?></label>
            <input type="text" name="menu_name" class="form-control" placeholder="e.g. Footer Menu" required>
        </div>
        <div>
            <label class="form-label"><?= _e('Menu Slug') ?></label>
            <input type="text" name="menu_slug" class="form-control" placeholder="e.g. footer-menu">
        </div>
        <button type="submit" class="btn btn-primary"><?= _e('Add Menu') ?></button>
    </form>
</div>

<?php if ($currentMenu): ?>
<div style="display: grid; grid-template-columns: 340px minmax(0, 1fr); gap: 24px; align-items: start;">
    <!-- Left Column: Add New Link -->
    <div>
        <div class="card" style="margin-bottom: 16px;">
            <h3 style="font-size: 14px; font-weight: 700; margin-bottom: 12px; color: var(--text-main);"><?= _e('Quick Select Existing Page') ?></h3>
            <select id="quick-page-select" class="form-control" onchange="const o=this.options[this.selectedIndex]; if(o.value){document.getElementById('item_title').value=o.getAttribute('data-t'); document.getElementById('item_url').value=o.value;}">
                <option value="">-- <?= _e('Select Page') ?> --</option>
                <option value="/" data-t="<?= _e('Home') ?>"><?= _e('Home') ?> (/)</option>
                <option value="/blog" data-t="<?= _e('Blog') ?>"><?= _e('Blog') ?> (/blog)</option>
                <?php foreach ($allPages as $pg): 
                    $fullPath = buildPageHierarchyUrl($pg, $pageLookup);
                    $prefix = (int)$pg['parent_id'] > 0 ? '&mdash; ' : '';
                ?>
                    <option value="<?= htmlspecialchars($fullPath, ENT_QUOTES, 'UTF-8') ?>" data-t="<?= Security::sanitize($pg['title']) ?>">
                        <?= $prefix ?><?= Security::sanitize($pg['title']) ?> (<?= htmlspecialchars($fullPath, ENT_QUOTES, 'UTF-8') ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="card">
            <h3 style="font-size: 14px; font-weight: 700; margin-bottom: 14px; color: var(--text-main);"><?= _e('Link Properties') ?></h3>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_item">
                <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
                <div class="form-group">
                    <label class="form-label"><?= _e('Label') ?></label>
                    <input type="text" name="item_title" id="item_title" class="form-control" required placeholder="e.g. Services">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= _e('Target URL') ?></label>
                    <input type="text" name="item_url" id="item_url" class="form-control" required placeholder="e.g. /services/web-development">
                </div>
                <div class="form-group">
                    <label class="form-label"><?= _e('Parent Menu Item') ?></label>
                    <select name="parent_id" class="form-control">
                        <option value="0"><?= _e('None (Root Level)') ?></option>
                        <?php foreach ($menuItems as $it): ?>
                            <option value="<?= $it['id'] ?>"><?= Security::sanitize($it['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label"><?= _e('Sort Order') ?></label>
                    <input type="number" name="sort_order" class="form-control" value="0">
                </div>
                <button type="submit" class="btn btn-secondary" style="width: 100%;"><?= _e('+ Add Link to Table') ?></button>
            </form>
        </div>
    </div>

    <!-- Right Column: Editable Table with Save Requirement -->
    <div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="save_menu_structure">
            <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">

            <div class="table-container card" style="padding: 0; overflow: hidden;">
                <div style="padding: 14px 20px; background: var(--bg-surface, #1e293b); border-bottom: 1px solid var(--border-subtle); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <span style="font-weight: 700; font-size: 15px; color: var(--text-main);"><?= _e('Structure:') ?> <?= Security::sanitize($currentMenu['name']) ?></span>
                        <span style="font-size: 12px; color: var(--text-muted); margin-left: 8px;">Tag: <code style="background: var(--bg-body, #0b0f19); border: 1px solid var(--border-subtle); color: var(--primary, #3b82f6); padding: 2px 6px; border-radius: 4px;">menu('<?= Security::sanitize($currentMenu['slug']) ?>');</code></span>
                    </div>
                    <?php if (!empty($tree)): ?>
                        <button type="submit" class="btn btn-primary" style="padding: 6px 14px; font-size: 13px;">
                            <?= _e('Save Menu Structure') ?>
                        </button>
                    <?php endif; ?>
                </div>

                <table class="pro-table">
                    <thead>
                        <tr>
                            <th style="width: 28%;"><?= _e('Label') ?></th>
                            <th style="width: 32%;"><?= _e('Target URL') ?></th>
                            <th style="width: 20%;"><?= _e('Parent') ?></th>
                            <th style="width: 10%;"><?= _e('Order') ?></th>
                            <th style="text-align: right; width: 10%;"><?= _e('Action') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tree)): ?>
                            <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 30px;"><?= _e('This menu is currently empty.') ?></td></tr>
                        <?php else: ?>
                            <?php
                            function renderAdminRows(array $nodes, int $menuId, array $allItems) {
                                foreach ($nodes as $node) {
                                    $indent = str_repeat('&mdash; ', $node['depth']);
                                    $nid = (int)$node['id'];
                                    ?>
                                    <tr>
                                        <td>
                                            <div style="display: flex; align-items: center; gap: 6px;">
                                                <span style="color: var(--primary); font-weight: bold;"><?= $indent ?></span>
                                                <input type="text" name="items[<?= $nid ?>][title]" class="form-control" value="<?= Security::sanitize($node['title']) ?>" style="padding: 6px 10px; font-size: 13px;" required>
                                            </div>
                                        </td>
                                        <td>
                                            <input type="text" name="items[<?= $nid ?>][url]" class="form-control" value="<?= Security::sanitize($node['url']) ?>" style="padding: 6px 10px; font-size: 13px; font-family: monospace;" required>
                                        </td>
                                        <td>
                                            <select name="items[<?= $nid ?>][parent_id]" class="form-control" style="padding: 6px 8px; font-size: 12px;">
                                                <option value="0"><?= _e('Root') ?></option>
                                                <?php foreach ($allItems as $candidate): 
                                                    if ((int)$candidate['id'] === $nid) continue; // Prevent self-parenting
                                                ?>
                                                    <option value="<?= $candidate['id'] ?>" <?= (int)$node['parent_id'] === (int)$candidate['id'] ? 'selected' : '' ?>>
                                                        <?= Security::sanitize($candidate['title']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" name="items[<?= $nid ?>][sort_order]" class="form-control" value="<?= (int)$node['sort_order'] ?>" style="padding: 6px 8px; font-size: 13px; text-align: center;">
                                        </td>
                                        <td style="text-align: right;">
                                            <a href="menus.php?menu=<?= $menuId ?>&delete_item=<?= $nid ?>&csrf=<?= Security::generateCsrfToken() ?>" 
                                               class="btn btn-danger-ghost" style="padding: 4px 8px; font-size: 12px;" onclick="return confirm('Delete this link?');"><?= _e('Delete') ?></a>
                                        </td>
                                    </tr>
                                    <?php
                                    if (!empty($node['children'])) {
                                        renderAdminRows($node['children'], $menuId, $allItems);
                                    }
                                }
                            }
                            renderAdminRows($tree, $selectedMenuId, $menuItems);
                            ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if (!empty($tree)): ?>
                    <div style="padding: 14px 20px; background: var(--bg-surface, #1e293b); border-top: 1px solid var(--border-subtle); text-align: right;">
                        <button type="submit" class="btn btn-primary"><?= _e('Save Menu Structure') ?></button>
                    </div>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/views/footer.php'; ?>