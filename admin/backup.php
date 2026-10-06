<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Security;
use Core\Backup;

// Backups require super-admin privileges
Auth::requireCapability('manage_settings');

$message = '';
$messageType = 'success';

// Handle 1-Click Backup Generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }

    $action = $_POST['action'];

    try {
        if ($action === 'create_full') {
            $filename = Backup::createFullArchive();
            header('Location: backup.php?created=' . urlencode($filename));
            exit;
        } elseif ($action === 'create_db') {
            $filename = Backup::createDatabaseSnapshot();
            header('Location: backup.php?created=' . urlencode($filename));
            exit;
        }
    } catch (\Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
}

// Handle File Download
if (isset($_GET['download']) && isset($_GET['csrf'])) {
    if (Security::verifyCsrfToken($_GET['csrf'])) {
        Backup::downloadBackup($_GET['download']);
        exit;
    }
}

// Handle File Deletion
if (isset($_GET['delete']) && isset($_GET['csrf'])) {
    if (Security::verifyCsrfToken($_GET['csrf'])) {
        if (Backup::deleteBackup($_GET['delete'])) {
            header('Location: backup.php?deleted=1');
            exit;
        } else {
            $message = 'Could not delete backup file.';
            $messageType = 'danger';
        }
    }
}

// Calculate directory sizes for overview
$dbFile = __DIR__ . '/../data/cms.sqlite';
$dbSize = file_exists($dbFile) ? filesize($dbFile) : 0;

$uploadsDir = __DIR__ . '/../uploads';
$uploadsSize = 0;
if (is_dir($uploadsDir)) {
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($uploadsDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
        $uploadsSize += $file->getSize();
    }
}

$backups = Backup::listBackups();
$zipSupported = class_exists('\ZipArchive');

require_once __DIR__ . '/views/header.php';
?>

<div class="page-header" style="margin-bottom: 24px;">
    <div>
        <h1 class="page-title"><?= _e('Backups & Recovery') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px;"><?= _e('1-Click full exports of your SQLite database and uploaded media assets') ?></p>
    </div>
</div>

<?php if (isset($_GET['created'])): ?>
    <div class="card" style="border-left: 4px solid var(--success, #10b981); background: rgba(16, 185, 129, 0.08); padding: 12px 16px; margin-bottom: 20px; color: #34d399; font-weight: 500;">
        <?= _e('Backup archive created successfully:') ?> <strong><?= htmlspecialchars($_GET['created'], ENT_QUOTES, 'UTF-8') ?></strong>
    </div>
<?php elseif (isset($_GET['deleted'])): ?>
    <div class="card" style="border-left: 4px solid var(--success, #10b981); background: rgba(16, 185, 129, 0.08); padding: 12px 16px; margin-bottom: 20px; color: #34d399; font-weight: 500;">
        <?= _e('Backup file deleted successfully.') ?>
    </div>
<?php elseif ($message): ?>
    <div class="card" style="border-left: 4px solid var(--danger, #ef4444); background: rgba(239, 68, 68, 0.08); padding: 12px 16px; margin-bottom: 20px; color: #f87171;">
        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<!-- System Storage Metrics Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 18px; margin-bottom: 28px;">
    <div class="card" style="padding: 20px; display: flex; align-items: center; gap: 16px;">
        <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(59, 130, 246, 0.12); color: var(--accent, #60a5fa); display: flex; align-items: center; justify-content: center; font-size: 20px;">
            🗄️
        </div>
        <div>
            <div style="font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-weight: 600; letter-spacing: 0.05em;"><?= _e('Database Engine') ?></div>
            <div style="font-size: 18px; font-weight: 700; color: var(--text-main); margin-top: 2px;">
                SQLite <?= Backup::formatBytes($dbSize) ?>
            </div>
        </div>
    </div>

    <div class="card" style="padding: 20px; display: flex; align-items: center; gap: 16px;">
        <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(16, 185, 129, 0.12); color: #34d399; display: flex; align-items: center; justify-content: center; font-size: 20px;">
            🖼️
        </div>
        <div>
            <div style="font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-weight: 600; letter-spacing: 0.05em;"><?= _e('Media Directory') ?></div>
            <div style="font-size: 18px; font-weight: 700; color: var(--text-main); margin-top: 2px;">
                /uploads (<?= Backup::formatBytes($uploadsSize) ?>)
            </div>
        </div>
    </div>

    <div class="card" style="padding: 20px; display: flex; align-items: center; gap: 16px;">
        <div style="width: 44px; height: 44px; border-radius: 10px; background: rgba(245, 158, 11, 0.12); color: #fbbf24; display: flex; align-items: center; justify-content: center; font-size: 20px;">
            📦
        </div>
        <div>
            <div style="font-size: 12px; color: var(--text-muted); text-transform: uppercase; font-weight: 600; letter-spacing: 0.05em;"><?= _e('Available Backups') ?></div>
            <div style="font-size: 18px; font-weight: 700; color: var(--text-main); margin-top: 2px;">
                <?= count($backups) ?> <?= _e('archives') ?>
            </div>
        </div>
    </div>
</div>

<!-- 1-Click Action Hub -->
<div class="card" style="padding: 24px; margin-bottom: 28px; border: 1px solid var(--border-subtle); background: var(--bg-surface, #1e293b);">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div>
            <h3 style="margin: 0 0 6px 0; font-size: 16px; color: var(--text-main); font-weight: 700;"><?= _e('Create a New Backup') ?></h3>
            <p style="margin: 0; font-size: 13px; color: var(--text-muted);">
                <?= _e('Full backups bundle both your database and all uploaded media files into a portable ZIP package.') ?>
            </p>
        </div>
        <div style="display: flex; gap: 12px; flex-wrap: wrap;">
            <!-- Database Snapshot Button -->
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_db">
                <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
                <button type="submit" class="btn btn-secondary" style="padding: 10px 18px; font-size: 13px; display: inline-flex; align-items: center; gap: 8px;">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width: 16px; height: 16px;"><path stroke-linecap="round" stroke-linejoin="round" d="M4 7v10c0 2 1.5 3 3.5 3h9c2 0 3.5-1 3.5-3V7M4 7c0-2 1.5-3 3.5-3h9c2 0 3.5 1 3.5 3M4 7h16m-8 4v6m-3-3h6"/></svg>
                    <?= _e('Database Only (.sqlite)') ?>
                </button>
            </form>

            <!-- Full ZIP Backup Button -->
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_full">
                <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
                <button type="submit" class="btn btn-primary" <?= !$zipSupported ? 'disabled title="ZipArchive PHP extension is missing"' : '' ?> style="padding: 10px 20px; font-size: 13px; display: inline-flex; align-items: center; gap: 8px;">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width: 16px; height: 16px;"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    <strong><?= _e('Full Backup (ZIP)') ?></strong>
                </button>
            </form>
        </div>
    </div>
</div>

<!-- Backups Archives List -->
<div class="card" style="padding: 0; overflow: hidden; border: 1px solid var(--border-subtle);">
    <div style="padding: 16px 20px; border-bottom: 1px solid var(--border-subtle); background: var(--bg-surface, #1e293b); font-weight: 700; font-size: 14px; color: var(--text-main);">
        <?= _e('Existing Backup Files') ?>
    </div>

    <table class="pro-table">
        <thead>
            <tr>
                <th style="width: 50px; text-align: center;"><?= _e('Type') ?></th>
                <th><?= _e('Filename') ?></th>
                <th style="width: 130px;"><?= _e('File Size') ?></th>
                <th style="width: 160px;"><?= _e('Created') ?></th>
                <th style="text-align: right; width: 140px;"><?= _e('Actions') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($backups)): ?>
                <tr>
                    <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 48px 20px;">
                        <p style="margin: 0; font-size: 14px;"><?= _e('No backup archives found. Click above to generate your first backup.') ?></p>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($backups as $b): 
                    $downloadUrl = "backup.php?download=" . urlencode($b['filename']) . "&csrf=" . Security::generateCsrfToken();
                    $deleteUrl = "backup.php?delete=" . urlencode($b['filename']) . "&csrf=" . Security::generateCsrfToken();
                ?>
                    <tr>
                        <td style="text-align: center; font-size: 18px;">
                            <?= $b['type'] === 'full' ? '📦' : '🗄️' ?>
                        </td>
                        <td>
                            <strong style="color: var(--text-main); font-family: ui-monospace, monospace; font-size: 13px;">
                                <?= htmlspecialchars($b['filename'], ENT_QUOTES, 'UTF-8') ?>
                            </strong>
                            <div style="font-size: 11px; color: var(--text-muted); margin-top: 2px;">
                                <?= $b['type'] === 'full' ? _e('Full package: database + uploads') : _e('SQLite database snapshot') ?>
                            </div>
                        </td>
                        <td style="color: var(--text-muted); font-size: 13px; font-family: monospace;">
                            <?= Backup::formatBytes($b['size']) ?>
                        </td>
                        <td style="color: var(--text-muted); font-size: 12px;">
                            <?= date('d.m.Y H:i:s', $b['date']) ?>
                        </td>
                        <td style="text-align: right; vertical-align: middle;">
                            <div style="display: inline-flex; gap: 6px;">
                              <a href="<?= $downloadUrl ?>" 
   class="btn btn-secondary" 
   style="display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 5px 12px; font-size: 12px; line-height: 1; white-space: nowrap;" 
   title="<?= _e('Download') ?>">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width: 13px; height: 13px; flex-shrink: 0;">
        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
    </svg>
    <span><?= _e('Download') ?></span>
</a>
                                <a href="<?= $deleteUrl ?>" 
                                   class="btn btn-danger-ghost" 
                                   style="padding: 4px 8px; font-size: 12px;" 
                                   data-confirm
                                   data-confirm-title="<?= _e('Delete Backup') ?>"
                                   data-confirm-message="<?= _e('Delete this backup archive permanently?') ?>"
                                   data-confirm-ok="<?= _e('Delete') ?>"
                                   data-confirm-danger
                                   title="<?= _e('Delete') ?>">
                                    &times;
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/views/footer.php'; ?>