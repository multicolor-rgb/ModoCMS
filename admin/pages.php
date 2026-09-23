<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/views/header.php';
use Core\Database;
use Core\Security;
use Core\Auth;

Auth::requireCapability('manage_pages');

$db = Database::getConnection();
$currentType = $_GET['type'] ?? 'all';

if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    if (Security::verifyCsrfToken($_GET['csrf'] ?? '')) {
        Auth::requireCapability('delete_pages');
        $stmt = $db->prepare("DELETE FROM pages WHERE id = :id");
        $stmt->execute([':id' => (int)$_GET['id']]);
        header('Location: pages.php?type=' . urlencode($currentType));
        exit;
    }
}

$sql = "SELECT p.*, u.username as author_name FROM pages p LEFT JOIN users u ON p.author_id = u.id";
$conditions = [];
$params = [];

if ($currentType === 'post' || $currentType === 'page') {
    $conditions[] = "p.type = :tp";
    $params[':tp'] = $currentType;
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
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= _e('Content') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px;"><?= _e('All Pages & Posts') ?></p>
    </div>
    <div style="display: flex; gap: 8px;">
        <a href="page-edit.php?type=post" class="btn btn-primary">+ <?= _e('New Post') ?></a>
        <a href="page-edit.php?type=page" class="btn btn-secondary">+ <?= _e('New Page') ?></a>
    </div>
</div>

<div style="margin-bottom: 16px; display: flex; gap: 8px;">
    <a href="pages.php" class="btn <?= $currentType === 'all' ? 'btn-primary' : 'btn-secondary' ?>" style="padding: 6px 12px; font-size: 12px;"><?= _e('All') ?></a>
    <a href="pages.php?type=post" class="btn <?= $currentType === 'post' ? 'btn-primary' : 'btn-secondary' ?>" style="padding: 6px 12px; font-size: 12px;"><?= _e('Posts Only') ?></a>
    <a href="pages.php?type=page" class="btn <?= $currentType === 'page' ? 'btn-primary' : 'btn-secondary' ?>" style="padding: 6px 12px; font-size: 12px;"><?= _e('Pages Only') ?></a>
</div>

<div class="table-container">
    <table class="pro-table">
        <thead>
            <tr>
                <th style="width: 50px;"><?= _e('Featured Image') ?></th>
                <th><?= _e('Title') ?></th>
                <th><?= _e('Type') ?></th>
                <th><?= _e('Status') ?></th>
                <th><?= _e('Content Language') ?></th>
                <th><?= _e('Author') ?></th>
                <th><?= _e('Updated') ?></th>
                <th style="text-align: right;"><?= _e('Actions') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 30px;"><?= _e('No items found.') ?></td>
                </tr>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <?php if (!empty($item['featured_image'])): ?>
                                <img src="<?= htmlspecialchars($item['featured_image'], ENT_QUOTES, 'UTF-8') ?>" style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px;">
                            <?php else: ?>
                                <div style="width: 40px; height: 40px; background: #e2e8f0; border-radius: 4px; display:flex; align-items:center; justify-content:center; color:#94a3b8; font-size:10px;">-</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><a href="page-edit.php?id=<?= $item['id'] ?>" style="color: var(--text-main); text-decoration: none;"><?= Security::sanitize($item['title']) ?></a></strong>
                            <div style="color: var(--text-muted); font-size: 12px; font-family: monospace;">/<?= Security::sanitize($item['slug']) ?></div>
                        </td>
                        <td>
                            <span class="badge" style="background: #f1f5f9; color: #475569;">
                                <?= $item['type'] === 'post' ? _e('Post') : _e('Page') ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= $item['status'] === 'published' ? 'badge-success' : 'badge-warning' ?>">
                                <?= $item['status'] === 'published' ? _e('Published') : _e('Draft') ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge" style="background: #e0e7ff; color: #3730a3; font-weight: 700;">
                                <?= strtoupper(Security::sanitize($item['lang'])) ?>
                            </span>
                        </td>
                        <td style="color: var(--text-muted);"><?= Security::sanitize($item['author_name'] ?? 'System') ?></td>
                        <td style="color: var(--text-muted); font-size: 12px;"><?= date('d.m.Y H:i', strtotime($item['updated_at'])) ?></td>
                        <td style="text-align: right;">
                            <a href="page-edit.php?id=<?= $item['id'] ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px;"><?= _e('Edit') ?></a>
                            <?php if (Auth::can('delete_pages')): ?>
                                <a href="pages.php?action=delete&id=<?= $item['id'] ?>&csrf=<?= Security::generateCsrfToken() ?>&type=<?= urlencode($currentType) ?>" 
                                   class="btn btn-danger-ghost" 
                                   style="padding: 4px 10px; font-size: 12px;"
                                   onclick="return confirm('<?= _e('Are you sure you want to delete this item?') ?>');">
                                    <?= _e('Delete') ?>
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/views/footer.php'; ?>
