<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Database;
use Core\Security;

Auth::requireCapability('manage_settings');

$db = Database::getConnection();

// --- 1. HANDLE TOGGLE ACTION & REDIRECT BEFORE ANY HTML OUTPUT ---

if (isset($_GET['toggle']) && isset($_GET['csrf'])) {
    if (!Security::verifyCsrfToken($_GET['csrf'])) {
        die('Invalid CSRF token');
    }

    $identifier = basename(trim($_GET['toggle']));

    // Ensure database table exists
    $db->exec("CREATE TABLE IF NOT EXISTS plugins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        folder TEXT UNIQUE NOT NULL,
        is_active INTEGER DEFAULT 0
    )");

    $stmt = $db->prepare("SELECT is_active FROM plugins WHERE folder = :f LIMIT 1");
    $stmt->execute([':f' => $identifier]);
    $status = $stmt->fetchColumn();

    if ($status !== false) {
        $newStatus = (int)$status === 1 ? 0 : 1;
        $upd = $db->prepare("UPDATE plugins SET is_active = :ns WHERE folder = :f");
        $upd->execute([':ns' => $newStatus, ':f' => $identifier]);
    } else {
        $ins = $db->prepare("INSERT INTO plugins (folder, is_active) VALUES (:f, 1)");
        $ins->execute([':f' => $identifier]);
    }

    header('Location: plugins.php');
    exit;
}

// Fetch active plugin identifiers
$activePlugins = [];
try {
    $activePlugins = $db->query("SELECT folder FROM plugins WHERE is_active = 1")->fetchAll(\PDO::FETCH_COLUMN) ?: [];
} catch (\PDOException $e) {
    // If table does not exist yet
}

// Discover plugins: standalone .php files in /plugins/ plus folders with a matching entry PHP file
$pluginsDir = dirname(__DIR__) . '/plugins/';
$discovered = [];

if (is_dir($pluginsDir)) {
    // Standalone root php plugins (e.g. autolightbox.php)
    foreach (glob($pluginsDir . '*.php') as $phpFile) {
        $id = basename($phpFile);
        $discovered[$id] = [
            'identifier' => $id,
            'type'       => 'file',
            'path'       => $phpFile
        ];
    }

    // Directory-based plugins (e.g. plugins/my-plugin/my-plugin.php or index.php)
    foreach (glob($pluginsDir . '*', GLOB_ONLYDIR) as $dir) {
        $folderName = basename($dir);
        $entryCandidate1 = $dir . '/' . $folderName . '.php';
        $entryCandidate2 = $dir . '/index.php';

        if (file_exists($entryCandidate1)) {
            $discovered[$folderName] = [
                'identifier' => $folderName,
                'type'       => 'dir',
                'path'       => $entryCandidate1
            ];
        } elseif (file_exists($entryCandidate2)) {
            $discovered[$folderName] = [
                'identifier' => $folderName,
                'type'       => 'dir',
                'path'       => $entryCandidate2
            ];
        }
    }
}

$pluginId = $_GET['id'] ?? '';

// --- 2. RENDER HTML HEADER AFTER REQUEST PROCESSING ---
require_once __DIR__ . '/views/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= _e('Plugin Management') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px;"><?= _e('Extend Clean CMS with modular hooks, filters, and legacy GetSimple plugins') ?></p>
    </div>
</div>

<?php if (!empty($pluginId) && isset(\Core\GSRegistry::$registeredPlugins[$pluginId])): ?>
    <!-- Plugin Settings Custom Subpage View -->
    <div class="card">
        <div style="margin-bottom: 16px;">
            <a href="plugins.php" class="btn btn-secondary" style="font-size: 12px;">&larr; <?= _e('Back to plugins') ?></a>
        </div>
        <?php \Core\Hooks::doAction('admin_plugin_view_' . $pluginId); ?>
    </div>
<?php else: ?>
    <!-- Plugins Overview Table -->
    <div class="table-container">
        <table class="pro-table">
            <thead>
                <tr>
                    <th style="width: 25%;"><?= _e('Plugin') ?></th>
                    <th style="width: 10%;"><?= _e('Version') ?></th>
                    <th style="width: 15%;"><?= _e('Author') ?></th>
                    <th style="width: 35%;"><?= _e('Description') ?></th>
                    <th style="text-align: right; width: 15%;"><?= _e('Status / Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($discovered)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 30px; color: var(--text-muted);">
                            <?= _e('No plugins found in plugins directory.') ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($discovered as $identifier => $item): 
                        $isActive = in_array($identifier, $activePlugins, true);

                        // Lookup GetSimple metadata from registry
                        $reg = \Core\GSRegistry::$registeredPlugins[$identifier] ?? null;

                        if (!$reg) {
                            $baseNameNoExt = pathinfo($identifier, PATHINFO_FILENAME);
                            $reg = \Core\GSRegistry::$registeredPlugins[$baseNameNoExt] ?? null;
                        }

                        $title = $reg['name'] ?? $identifier;
                        $version = $reg['version'] ?? '1.0';
                        $author = $reg['author'] ?? '&mdash;';
                        $authorUrl = $reg['url'] ?? '';
                        $desc = $reg['desc'] ?? '';
                    ?>
                        <tr>
                            <td>
                                <strong><?= Security::sanitize($title) ?></strong>
                                <div style="font-size: 11px; color: var(--text-muted); font-family: monospace;">
                                    <?= Security::sanitize($identifier) ?>
                                </div>
                                <?php if ($isActive && !empty($reg['load_func'])): ?>
                                    <a href="plugins.php?id=<?= urlencode($reg['id']) ?>" style="font-size: 12px; color: var(--primary); font-weight: 600; display: inline-block; margin-top: 4px;">
                                        <?= _e('Settings') ?> &rarr;
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td><?= Security::sanitize($version) ?></td>
                            <td>
                                <?php if (!empty($authorUrl) && $authorUrl !== '#'): ?>
                                    <a href="<?= Security::sanitize($authorUrl) ?>" target="_blank"><?= Security::sanitize($author) ?></a>
                                <?php else: ?>
                                    <?= Security::sanitize($author) ?>
                                <?php endif; ?>
                            </td>
                            <td style="color: var(--text-muted); font-size: 13px;">
                                <?= Security::sanitize($desc) ?>
                            </td>
                            <td style="text-align: right;">
                                <a href="plugins.php?toggle=<?= urlencode($identifier) ?>&csrf=<?= Security::generateCsrfToken() ?>" 
                                   class="btn <?= $isActive ? 'btn-danger-ghost' : 'btn-primary' ?>" 
                                   style="padding: 4px 10px; font-size: 12px;">
                                    <?= $isActive ? _e('Deactivate') : _e('Activate') ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/views/footer.php'; ?>