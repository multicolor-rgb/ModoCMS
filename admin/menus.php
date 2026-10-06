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
    
    $maxSortStmt = $db->prepare("SELECT MAX(sort_order) FROM menu_items WHERE menu_id = :m");
    $maxSortStmt->execute([':m' => $selectedMenuId]);
    $nextSort = ((int)$maxSortStmt->fetchColumn()) + 1;

    $stmt = $db->prepare("INSERT INTO menu_items (menu_id, parent_id, title, url, sort_order) VALUES (:m, :p, :t, :u, :s)");
    $stmt->execute([
        ':m' => $selectedMenuId,
        ':p' => 0,
        ':t' => trim($_POST['item_title'] ?? ''),
        ':u' => trim($_POST['item_url'] ?? ''),
        ':s' => $nextSort
    ]);
    header("Location: menus.php?menu={$selectedMenuId}&saved=1");
    exit;
}

// Save entire menu structure
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

// Fetch published pages for quick links
$allPages = $db->query("SELECT id, parent_id, title, slug, type FROM pages WHERE status = 'published' ORDER BY parent_id ASC, title ASC")->fetchAll();
$pageLookup = [];
foreach ($allPages as $p) {
    $pageLookup[$p['id']] = $p;
}

function buildPageHierarchyUrl(array $page, array $lookup): string {
    // Blog posts live under the configured posts (blog) page slug
    if (($page['type'] ?? '') === 'post') {
        return '/' . \Core\Router::getPostsPageSlug() . '/' . $page['slug'];
    }

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

<style>
/* Drag and drop nestable menu styling */
.menu-tree-container {
    list-style: none;
    padding: 0;
    margin: 0;
    min-height: 50px;
}
.menu-node {
    margin-bottom: 10px;
    border-radius: var(--radius-sm, 8px);
    transition: margin-left 0.2s cubic-bezier(0.4, 0, 0.2, 1), transform 0.15s;
    user-select: none;
}
.menu-node.depth-0 { margin-left: 0; }
.menu-node.depth-1 { margin-left: 36px; }
.menu-node.depth-2 { margin-left: 72px; }
.menu-node.depth-3 { margin-left: 108px; }

.menu-item-card {
    background: var(--bg-surface, #1e293b);
    border: 1px solid var(--border-subtle);
    border-radius: var(--radius-sm, 8px);
    padding: 10px 14px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
}
.menu-item-card:hover {
    border-color: var(--primary, #3b82f6);
}
.menu-item-card.dragging {
    opacity: 0.45;
    transform: scale(0.98);
}
.menu-node.drop-target-above .menu-item-card {
    border-top: 3px solid var(--primary, #3b82f6);
}
.menu-node.drop-target-below .menu-item-card {
    border-bottom: 3px solid var(--primary, #3b82f6);
}
.menu-node.drop-target-child .menu-item-card {
    background: rgba(59, 130, 246, 0.12);
    border-color: var(--primary, #3b82f6);
}

.drag-handle {
    cursor: grab;
    color: var(--text-muted);
    font-size: 16px;
    padding: 4px 6px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.drag-handle:hover {
    color: var(--text-main);
    background: rgba(255, 255, 255, 0.05);
}

.depth-indicator-badge {
    font-size: 11px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 4px;
    background: var(--bg-card, #111827);
    color: var(--text-muted);
    border: 1px solid var(--border-subtle);
    font-family: monospace;
}

/* Stylizowana grupa przycisków akcji */
.item-actions-group {
    display: inline-flex;
    align-items: center;
    background: var(--bg-card, #111827);
    border: 1px solid var(--border-subtle);
    border-radius: 6px;
    padding: 2px;
    gap: 2px;
}
.action-icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    border: none;
    background: transparent;
    color: var(--text-muted);
    border-radius: 4px;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.15s ease;
}
.action-icon-btn:hover {
    background: rgba(255, 255, 255, 0.07);
    color: var(--text-main);
}
.action-icon-btn.primary-hover:hover {
    color: var(--primary, #3b82f6);
    background: rgba(59, 130, 246, 0.12);
}
.action-icon-btn.danger-hover:hover {
    color: var(--danger, #ef4444);
    background: rgba(239, 68, 68, 0.12);
}
.action-icon-btn svg {
    width: 14px;
    height: 14px;
}
</style>

<div class="page-header" style="margin-bottom: 24px;">
    <div>
        <h1 class="page-title"><?= _e('Navigation & Menus') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px;"><?= _e('Drag and drop to rearrange link order and hierarchical sub-menus') ?></p>
    </div>
</div>

<?php if (isset($_GET['saved'])): ?>
    <div class="card" style="border-left: 4px solid var(--success, #10b981); background: rgba(16, 185, 129, 0.08); padding: 12px 16px; margin-bottom: 20px; color: #34d399; font-weight: 500;">
        <?= _e('Menu structure updated successfully.') ?>
    </div>
<?php elseif ($message): ?>
    <div class="card" style="border-left: 4px solid var(--<?= $messageType ?>); padding: 12px 16px; margin-bottom: 20px;">
        <?= Security::sanitize($message) ?>
    </div>
<?php endif; ?>

<!-- Menu Selector & Creator Toolbar -->
<div class="card" style="padding: 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 24px;">
    <form method="GET" action="" style="display: flex; align-items: center; gap: 10px;">
        <label style="font-weight: 600; font-size: 14px; color: var(--text-main);"><?= _e('Select menu to edit:') ?></label>
        <select name="menu" class="form-control" style="width: auto; min-width: 200px;" onchange="this.form.submit()">
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
               data-confirm
               data-confirm-title="<?= _e('Delete Menu') ?>"
               data-confirm-message="<?= _e('Delete this entire menu? All links inside it will be removed.') ?>"
               data-confirm-ok="<?= _e('Delete') ?>"
               data-confirm-danger><?= _e('Delete Menu') ?></a>
        <?php endif; ?>
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('new-menu-box').style.display='block'"><?= _e('+ New Menu') ?></button>
    </div>
</div>

<div id="new-menu-box" class="card" style="display: none; border-left: 4px solid var(--primary); margin-bottom: 24px; padding: 20px;">
    <h3 style="font-size: 15px; font-weight: 700; margin-bottom: 12px; color: var(--text-main);"><?= _e('Create New Menu') ?></h3>
    <form method="POST" action="" style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 14px; align-items: end;">
        <input type="hidden" name="action" value="create_menu">
        <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
        <div>
            <label class="form-label"><?= _e('Menu Name') ?></label>
            <input type="text" name="menu_name" class="form-control" placeholder="e.g. Header Navigation" required>
        </div>
        <div>
            <label class="form-label"><?= _e('Menu Slug') ?></label>
            <input type="text" name="menu_slug" class="form-control" placeholder="e.g. main-nav">
        </div>
        <button type="submit" class="btn btn-primary"><?= _e('Add Menu') ?></button>
    </form>
</div>

<?php if ($currentMenu): ?>
<div style="display: grid; grid-template-columns: 320px minmax(0, 1fr); gap: 24px; align-items: start;">
    
    <!-- Left Column: Add Links -->
    <div style="display: flex; flex-direction: column; gap: 20px;">
        <div class="card" style="padding: 18px;">
            <h3 style="font-size: 14px; font-weight: 700; margin-bottom: 12px; color: var(--text-main);"><?= _e('Pages & Direct Routes') ?></h3>
            <select id="quick-page-select" class="form-control" onchange="const o=this.options[this.selectedIndex]; if(o.value){document.getElementById('item_title').value=o.getAttribute('data-t'); document.getElementById('item_url').value=o.value;}">
                <option value="">-- <?= _e('Select Page') ?> --</option>
                <option value="/" data-t="<?= _e('Home') ?>"><?= _e('Home') ?> (/)</option>
                <?php $blogBaseSlug = \Core\Router::getPostsPageSlug(); ?>
                <option value="/<?= htmlspecialchars($blogBaseSlug, ENT_QUOTES, 'UTF-8') ?>" data-t="<?= _e('Blog') ?>"><?= _e('Blog') ?> (/<?= htmlspecialchars($blogBaseSlug, ENT_QUOTES, 'UTF-8') ?>)</option>
                <?php foreach ($allPages as $pg): 
                    $fullPath = buildPageHierarchyUrl($pg, $pageLookup);
                    $prefix = (int)$pg['parent_id'] > 0 ? '&mdash; ' : '';
                ?>
                    <option value="<?= htmlspecialchars($fullPath, ENT_QUOTES, 'UTF-8') ?>" data-t="<?= Security::sanitize($pg['title']) ?>">
                        <?= $prefix ?><?= Security::sanitize($pg['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="card" style="padding: 18px;">
            <h3 style="font-size: 14px; font-weight: 700; margin-bottom: 14px; color: var(--text-main);"><?= _e('Add Custom Link') ?></h3>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_item">
                <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
                
                <div class="form-group" style="margin-bottom: 12px;">
                    <label class="form-label" style="font-size: 12px;"><?= _e('Link Text') ?></label>
                    <input type="text" name="item_title" id="item_title" class="form-control" required placeholder="e.g. Services">
                </div>
                
                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="form-label" style="font-size: 12px;"><?= _e('Target URL') ?></label>
                    <input type="text" name="item_url" id="item_url" class="form-control" required placeholder="e.g. /services or https://..." style="font-family: monospace; font-size: 13px;">
                </div>
                
                <button type="submit" class="btn btn-secondary" style="width: 100%; font-size: 13px;">+ <?= _e('Add to Menu') ?></button>
            </form>
        </div>

        <div class="card" style="padding: 14px 16px; background: var(--bg-surface, #1e293b); border: 1px solid var(--border-subtle); font-size: 12px; color: var(--text-muted); line-height: 1.5;">
            <strong style="color: var(--text-main); display: block; margin-bottom: 4px;">💡 <?= _e('Drag & Drop Tips:') ?></strong>
            <?= _e('Drag an item up or down to reorder. Use the indent buttons or drag slightly right to nest items as sub-menus.') ?>
        </div>
    </div>

    <!-- Right Column: Interactive Drag & Drop Hierarchy List -->
    <div>
        <form method="POST" action="" id="menu-structure-form">
            <input type="hidden" name="action" value="save_menu_structure">
            <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">

            <div class="card" style="padding: 0; overflow: hidden; border: 1px solid var(--border-subtle);">
                <div style="padding: 16px 20px; background: var(--bg-surface, #1e293b); border-bottom: 1px solid var(--border-subtle); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <span style="font-weight: 700; font-size: 15px; color: var(--text-main);"><?= Security::sanitize($currentMenu['name']) ?></span>
                        <span style="font-size: 12px; color: var(--text-muted); margin-left: 8px;">Theme Tag: <code style="background: var(--bg-card, #0b0f19); border: 1px solid var(--border-subtle); color: var(--accent, #60a5fa); padding: 2px 6px; border-radius: 4px;">menu('<?= Security::sanitize($currentMenu['slug']) ?>');</code></span>
                    </div>
                    <?php if (!empty($tree)): ?>
                        <button type="submit" class="btn btn-primary" style="padding: 7px 16px; font-size: 13px;">
                            <?= _e('Save Menu Structure') ?>
                        </button>
                    <?php endif; ?>
                </div>

                <div style="padding: 20px;">
                    <?php if (empty($tree)): ?>
                        <div style="text-align: center; color: var(--text-muted); padding: 48px 20px;">
                            <p style="font-size: 14px; margin: 0;"><?= _e('This menu is currently empty. Add your first link from the left sidebar.') ?></p>
                        </div>
                    <?php else: ?>
                        <ul class="menu-tree-container" id="menu-tree-list">
                            <?php
                            function renderSortableTree(array $nodes, int $menuId) {
                                foreach ($nodes as $node) {
                                    $nid = (int)$node['id'];
                                    $depth = (int)$node['depth'];
                                    ?>
                                    <li class="menu-node depth-<?= $depth ?>" 
                                        id="node-<?= $nid ?>" 
                                        data-id="<?= $nid ?>" 
                                        data-depth="<?= $depth ?>" 
                                        draggable="true">
                                        
                                        <input type="hidden" class="input-parent-id" name="items[<?= $nid ?>][parent_id]" value="<?= (int)$node['parent_id'] ?>">
                                        <input type="hidden" class="input-sort-order" name="items[<?= $nid ?>][sort_order]" value="<?= (int)$node['sort_order'] ?>">

                                        <div class="menu-item-card">
                                            <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                                                <div style="display: flex; align-items: center; gap: 8px; flex: 1; min-width: 0;">
                                                    <span class="drag-handle" title="<?= _e('Drag to reorder') ?>">⋮⋮</span>
                                                    <span class="depth-indicator-badge" id="badge-<?= $nid ?>"><?= $depth === 0 ? 'Root' : 'Level ' . $depth ?></span>
                                                    
                                                    <strong class="item-title-display" style="font-size: 14px; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer;" onclick="toggleDetails(<?= $nid ?>)">
                                                        <?= Security::sanitize($node['title']) ?>
                                                    </strong>
                                                    <span style="font-size: 12px; color: var(--text-muted); font-family: monospace; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                        <?= Security::sanitize($node['url']) ?>
                                                    </span>
                                                </div>

                                                <!-- Nowoczesna grupa przycisków akcji -->
                                                <div class="item-actions-group">
                                                    <button type="button" class="action-icon-btn primary-hover" onclick="shiftDepth(<?= $nid ?>, -1)" title="<?= _e('Outdent link') ?>">
                                                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/></svg>
                                                    </button>
                                                    <button type="button" class="action-icon-btn primary-hover" onclick="shiftDepth(<?= $nid ?>, 1)" title="<?= _e('Indent link') ?>">
                                                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 5l7 7-7 7M5 5l7 7-7 7"/></svg>
                                                    </button>
                                                    <button type="button" class="action-icon-btn primary-hover" onclick="toggleDetails(<?= $nid ?>)" title="<?= _e('Edit Details') ?>">
                                                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                                    </button>
                                                    <a href="menus.php?menu=<?= $menuId ?>&delete_item=<?= $nid ?>&csrf=<?= Security::generateCsrfToken() ?>"
                                                       class="action-icon-btn danger-hover"
                                                       data-confirm
                                                       data-confirm-title="<?= _e('Delete Link') ?>"
                                                       data-confirm-message="<?= _e('Delete this link and its sub-items?') ?>"
                                                       data-confirm-ok="<?= _e('Delete') ?>"
                                                       data-confirm-danger
                                                       title="<?= _e('Delete Link') ?>">
                                                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                                    </a>
                                                </div>
                                            </div>

                                            <!-- Collapsible Details Box -->
                                            <div id="details-<?= $nid ?>" style="display: none; border-top: 1px solid var(--border-subtle); margin-top: 12px; padding-top: 12px;">
                                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                                    <div>
                                                        <label class="form-label" style="font-size: 11px;"><?= _e('Navigation Label') ?></label>
                                                        <input type="text" name="items[<?= $nid ?>][title]" class="form-control" value="<?= Security::sanitize($node['title']) ?>" style="font-size: 13px;" oninput="document.querySelector('#node-<?= $nid ?> .item-title-display').textContent = this.value" required>
                                                    </div>
                                                    <div>
                                                        <label class="form-label" style="font-size: 11px;"><?= _e('Destination URL') ?></label>
                                                        <input type="text" name="items[<?= $nid ?>][url]" class="form-control" value="<?= Security::sanitize($node['url']) ?>" style="font-family: monospace; font-size: 13px;" required>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </li>
                                    <?php
                                    if (!empty($node['children'])) {
                                        renderSortableTree($node['children'], $menuId);
                                    }
                                }
                            }
                            renderSortableTree($tree, $selectedMenuId);
                            ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <?php if (!empty($tree)): ?>
                    <div style="padding: 14px 20px; background: var(--bg-surface, #1e293b); border-top: 1px solid var(--border-subtle); text-align: right;">
                        <button type="submit" class="btn btn-primary" style="padding: 8px 20px;"><?= _e('Save Menu Structure') ?></button>
                    </div>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function toggleDetails(id) {
    const box = document.getElementById('details-' + id);
    if (!box) return;
    box.style.display = box.style.display === 'none' ? 'block' : 'none';
}

const list = document.getElementById('menu-tree-list');
let draggedNode = null;

if (list) {
    list.addEventListener('dragstart', (e) => {
        const target = e.target.closest('.menu-node');
        if (!target) return;
        draggedNode = target;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', target.dataset.id);
        setTimeout(() => target.querySelector('.menu-item-card').classList.add('dragging'), 0);
    });

    list.addEventListener('dragend', (e) => {
        if (!draggedNode) return;
        draggedNode.querySelector('.menu-item-card').classList.remove('dragging');
        document.querySelectorAll('.menu-node').forEach(n => {
            n.classList.remove('drop-target-above', 'drop-target-below', 'drop-target-child');
        });
        draggedNode = null;
        recalculateHierarchy();
    });

    list.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';

        const hoverNode = e.target.closest('.menu-node');
        if (!hoverNode || hoverNode === draggedNode) return;

        const rect = hoverNode.getBoundingClientRect();
        const offsetY = e.clientY - rect.top;
        const height = rect.height;

        document.querySelectorAll('.menu-node').forEach(n => {
            n.classList.remove('drop-target-above', 'drop-target-below', 'drop-target-child');
        });

        if (offsetY < height * 0.3) {
            hoverNode.classList.add('drop-target-above');
        } else if (offsetY > height * 0.7) {
            hoverNode.classList.add('drop-target-below');
        } else {
            hoverNode.classList.add('drop-target-child');
        }
    });

    list.addEventListener('drop', (e) => {
        e.preventDefault();
        const hoverNode = e.target.closest('.menu-node');
        if (!hoverNode || !draggedNode || hoverNode === draggedNode) return;

        if (hoverNode.classList.contains('drop-target-above')) {
            list.insertBefore(draggedNode, hoverNode);
            setNodeDepth(draggedNode, parseInt(hoverNode.dataset.depth, 10));
        } else if (hoverNode.classList.contains('drop-target-below')) {
            list.insertBefore(draggedNode, hoverNode.nextSibling);
            setNodeDepth(draggedNode, parseInt(hoverNode.dataset.depth, 10));
        } else if (hoverNode.classList.contains('drop-target-child')) {
            list.insertBefore(draggedNode, hoverNode.nextSibling);
            const newDepth = Math.min(3, parseInt(hoverNode.dataset.depth, 10) + 1);
            setNodeDepth(draggedNode, newDepth);
        }

        recalculateHierarchy();
    });
}

function setNodeDepth(node, depth) {
    depth = Math.max(0, Math.min(3, depth));
    node.dataset.depth = depth;
    node.className = node.className.replace(/\bdepth-\d\b/g, '').trim() + ' depth-' + depth;
    const badge = document.getElementById('badge-' + node.dataset.id);
    if (badge) {
        badge.textContent = depth === 0 ? 'Root' : 'Level ' + depth;
    }
}

function shiftDepth(id, delta) {
    const node = document.getElementById('node-' + id);
    if (!node) return;
    const current = parseInt(node.dataset.depth, 10) || 0;
    setNodeDepth(node, current + delta);
    recalculateHierarchy();
}

function recalculateHierarchy() {
    if (!list) return;
    const nodes = Array.from(list.children);
    const parentStack = [{ id: 0, depth: -1 }];

    nodes.forEach((node, index) => {
        const id = parseInt(node.dataset.id, 10);
        let depth = parseInt(node.dataset.depth, 10) || 0;

        const prev = nodes[index - 1];
        const prevDepth = prev ? (parseInt(prev.dataset.depth, 10) || 0) : -1;
        if (depth > prevDepth + 1) {
            depth = prevDepth + 1;
            setNodeDepth(node, depth);
        }

        while (parentStack.length > 1 && parentStack[parentStack.length - 1].depth >= depth) {
            parentStack.pop();
        }

        const parentId = parentStack[parentStack.length - 1].id;

        const parentInput = node.querySelector('.input-parent-id');
        const sortInput = node.querySelector('.input-sort-order');
        if (parentInput) parentInput.value = parentId;
        if (sortInput) sortInput.value = index + 1;

        parentStack.push({ id: id, depth: depth });
    });
}
</script>

<?php require_once __DIR__ . '/views/footer.php'; ?>