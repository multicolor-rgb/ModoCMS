<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Database;
use Core\MediaLibrary;
use Core\Security;

Auth::requireCapability('manage_pages');

$db = Database::getConnection();
$csrfToken = Security::generateCsrfToken();

/**
 * Redirects back to the requested media view with a flash message code.
 */
function mediaRedirect(?string $folder, string $key, bool $isError = false): void
{
    $params = [];
    if ($folder === '') {
        $params['folder'] = 'root';
    } elseif ($folder !== null) {
        $params['folder'] = $folder;
    }
    $params[$isError ? 'err' : 'msg'] = $key;

    header('Location: media.php?' . http_build_query($params));
    exit;
}

/**
 * Parses "all", "root" or a real folder name from a request value.
 */
function mediaReadFolder(?string $value): ?string
{
    if ($value === null || $value === 'all') {
        return null;
    }
    if ($value === 'root' || $value === '') {
        return '';
    }

    $resolved = MediaLibrary::resolveFolder($value);
    return $resolved !== null ? basename($resolved) : null;
}

function mediaErrorKey(string $error, bool $file = false): string
{
    return match ($error) {
        'invalid'   => 'invalid_folder',
        'exists'    => 'folder_exists',
        'not_empty' => 'folder_not_empty',
        'missing'   => $file ? 'file_missing' : 'folder_missing',
        'failed'    => $file ? 'delete_failed' : 'operation_failed',
        default     => 'operation_failed',
    };
}

function mediaPostString(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? $value : $default;
}

// ---------------------------------------------------------------------------
// POST actions: folders, moves and deletion.
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['media_action']) && is_string($_POST['media_action'])) {
    $returnFolder = mediaReadFolder(mediaPostString('return_folder', 'all'));

    if (!Security::verifyCsrfToken(mediaPostString('csrf'))) {
        mediaRedirect($returnFolder, 'csrf', true);
    }

    $action = $_POST['media_action'];

    switch ($action) {
        case 'create_folder':
            $result = MediaLibrary::createFolder(mediaPostString('folder_name'));
            if (!empty($result['ok'])) {
                mediaRedirect((string) ($result['name'] ?? $returnFolder), 'folder_created');
            }
            mediaRedirect($returnFolder, mediaErrorKey((string) ($result['error'] ?? 'failed')), true);
            break;

        case 'rename_folder':
            $result = MediaLibrary::renameFolder(
                mediaPostString('old_folder'),
                mediaPostString('folder_name')
            );
            if (!empty($result['ok'])) {
                mediaRedirect((string) ($result['name'] ?? $returnFolder), 'folder_renamed');
            }
            mediaRedirect($returnFolder, mediaErrorKey((string) ($result['error'] ?? 'failed')), true);
            break;

        case 'delete_folder':
            $folderName = mediaPostString('folder_name');
            $result = MediaLibrary::deleteFolder($folderName);
            if (!empty($result['ok'])) {
                // Never redirect back into a folder that no longer exists.
                $destination = $returnFolder === $folderName ? null : $returnFolder;
                mediaRedirect($destination, 'folder_deleted');
            }
            mediaRedirect($returnFolder, mediaErrorKey((string) ($result['error'] ?? 'failed')), true);
            break;

        case 'delete_file':
            $result = MediaLibrary::deleteFile(mediaPostString('file_path'));
            if (!empty($result['ok'])) {
                mediaRedirect($returnFolder, 'file_deleted');
            }
            mediaRedirect($returnFolder, mediaErrorKey((string) ($result['error'] ?? 'failed'), true), true);
            break;

        case 'move_files':
            $paths = $_POST['paths'] ?? [];
            if (!is_array($paths)) {
                $paths = [];
            }

            $targetValue = mediaPostString('target_folder', 'root');
            $targetFolder = null;
            if ($targetValue !== '' && $targetValue !== 'root') {
                $resolved = MediaLibrary::resolveFolder($targetValue);
                if ($resolved === null) {
                    mediaRedirect($returnFolder, 'invalid_folder', true);
                }
                $targetFolder = basename($resolved);
            }

            $moved = 0;
            $failed = 0;
            foreach ($paths as $path) {
                if (!is_string($path) || $path === '') {
                    continue;
                }
                $result = MediaLibrary::moveFile($path, $targetFolder);
                if (!empty($result['ok'])) {
                    $moved++;
                } else {
                    $failed++;
                }
            }

            if ($moved === 0) {
                mediaRedirect($returnFolder, 'move_failed', true);
            }
            if ($failed > 0) {
                mediaRedirect($returnFolder, 'move_partial', true);
            }
            mediaRedirect($returnFolder, 'moved');
            break;
    }

    mediaRedirect($returnFolder, 'operation_failed', true);
}

// ---------------------------------------------------------------------------
// Resolve the current view.
// ---------------------------------------------------------------------------
$currentFolder = null;
$folderParam = $_GET['folder'] ?? null;
if (is_array($folderParam)) {
    mediaRedirect(null, 'folder_missing', true);
}
if ($folderParam !== null) {
    if ($folderParam === 'all') {
        $currentFolder = null;
    } elseif ($folderParam === 'root' || $folderParam === '') {
        $currentFolder = '';
    } else {
        $resolved = MediaLibrary::resolveFolder((string) $folderParam);
        if ($resolved === null) {
            mediaRedirect(null, 'folder_missing', true);
        }
        $currentFolder = basename($resolved);
    }
}

// Make files copied over FTP visible in the library as well.
try {
    MediaLibrary::syncMissingMedia(Auth::id());
} catch (\Throwable $e) {
    // The media page must still render even if a sync attempt fails.
}

$allMedia = $db->query("
    SELECT m.*, u.username AS uploader
    FROM media m
    LEFT JOIN users u ON m.user_id = u.id
    ORDER BY m.created_at DESC
")->fetchAll();

$folders = MediaLibrary::folders();
$currentFolderCount = 0;
if ($currentFolder !== null && $currentFolder !== '') {
    foreach ($folders as $folderInfo) {
        if ((string) $folderInfo['name'] === $currentFolder) {
            $currentFolderCount = (int) $folderInfo['count'];
            break;
        }
    }
}

$mediaItems = $allMedia;
if ($currentFolder !== null) {
    $mediaItems = array_values(array_filter($allMedia, static function (array $item) use ($currentFolder): bool {
        return MediaLibrary::folderOfStoredPath((string) $item['filepath']) === $currentFolder;
    }));
}
$viewCount = count($mediaItems);
$thumbMissing = MediaLibrary::thumbnailSupported() ? MediaLibrary::missingThumbnailCount() : 0;

$perPage = 48;
$totalPages = max(1, (int) ceil($viewCount / $perPage));
$mediaPage = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$mediaPage = min($mediaPage, $totalPages);
$pageItems = array_slice($mediaItems, ($mediaPage - 1) * $perPage, $perPage);
$pageUrl = static function (int $page) use ($currentFolder): string {
    $params = [];
    if ($currentFolder !== null) {
        $params['folder'] = $currentFolder === '' ? 'root' : $currentFolder;
    }
    $params['page'] = $page;
    return 'media.php?' . http_build_query($params);
};

$showFolderCards = $currentFolder === null || $currentFolder === '';
$returnFolderValue = $currentFolder === null ? 'all' : ($currentFolder === '' ? 'root' : $currentFolder);
$uploadTargetLabel = ($currentFolder === null || $currentFolder === '') ? __('Root') : $currentFolder;

$flashMessages = [
    'folder_created' => __('Folder created successfully.'),
    'folder_renamed' => __('Folder renamed successfully.'),
    'folder_deleted' => __('Folder deleted successfully.'),
    'moved'          => __('Files moved successfully.'),
    'file_deleted'   => __('Media asset removed successfully.'),
];

$flashErrors = [
    'csrf'             => __('Invalid security token. Please try again.'),
    'invalid_folder'   => __('Invalid folder name or reserved name.'),
    'folder_exists'    => __('A folder with that name already exists.'),
    'folder_not_empty' => __('Folder is not empty. Move its files first.'),
    'folder_missing'   => __('Folder not found.'),
    'file_missing'     => __('File not found.'),
    'move_partial'     => __('Some files could not be moved.'),
    'move_failed'      => __('Could not move the selected files.'),
    'delete_failed'    => __('Could not delete the file.'),
    'operation_failed' => __('The operation could not be completed.'),
];

$jsonAttr = static function ($value): string {
    return htmlspecialchars((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
};

require_once __DIR__ . '/views/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= _e('Media Library') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px;"><?= _e('Manage site assets, upload images, and perform in-browser edits') ?></p>
    </div>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <button type="button" class="btn btn-secondary" onclick="openFolderModal('create')">
            <?= _e('+ New Folder') ?>
        </button>
        <button type="button" class="btn btn-primary" onclick="document.getElementById('file-upload-input').click()">
            <?= _e('+ Upload File') ?>
        </button>
    </div>
</div>

<?php
$flashMessageKey = isset($_GET['msg']) && is_string($_GET['msg']) ? $_GET['msg'] : '';
$flashErrorKey = isset($_GET['err']) && is_string($_GET['err']) ? $_GET['err'] : '';
?>
<?php if ($flashMessageKey !== '' && isset($flashMessages[$flashMessageKey])): ?>
    <div class="card" style="border-left: 4px solid var(--success); padding: 12px; margin-bottom: 20px;">
        <?= htmlspecialchars($flashMessages[$flashMessageKey], ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<?php if ($flashErrorKey !== '' && isset($flashErrors[$flashErrorKey])): ?>
    <div class="card" style="border-left: 4px solid var(--danger, #ef4444); padding: 12px; margin-bottom: 20px;">
        <?= htmlspecialchars($flashErrors[$flashErrorKey], ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<?php if (!MediaLibrary::thumbnailSupported()): ?>
    <div class="card" style="border-left: 4px solid var(--danger, #ef4444); padding: 12px; margin-bottom: 20px; font-size: 13px;">
        <?= _e('Thumbnail generation is not available on this server. Previews use the original files.') ?>
    </div>
<?php elseif ($thumbMissing > 0): ?>
    <div class="card" id="thumb-backfill-card" style="padding: 14px 16px; margin-bottom: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
            <div>
                <strong style="display: block; font-size: 13px; color: var(--text-main);"><?= _e('Thumbnails') ?></strong>
                <span style="font-size: 12px; color: var(--text-muted);">
                    <?= htmlspecialchars(sprintf(__('Missing thumbnails: %d'), $thumbMissing), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <button type="button" id="thumb-backfill-btn" class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;"
                    data-csrf="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
                    data-total="<?= (int) $viewCount ?>">
                <?= _e('Generate missing thumbnails') ?>
            </button>
        </div>
        <div id="thumb-backfill-progress" style="display: none; margin-top: 12px;">
            <div style="width: 100%; height: 6px; background: var(--border-subtle, #334155); border-radius: 9999px; overflow: hidden;">
                <div id="thumb-backfill-bar" style="width: 0%; height: 100%; background: var(--primary, #3b82f6); transition: width 0.2s;"></div>
            </div>
            <span id="thumb-backfill-status" style="font-size: 11px; color: var(--text-muted);"></span>
        </div>
    </div>
<?php endif; ?>

<div class="card" style="padding: 12px 16px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;">
    <div style="font-size: 13px; color: var(--text-muted); display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
        <?php if ($currentFolder === null): ?>
            <strong style="color: var(--text-main);"><?= _e('All files') ?></strong>
        <?php else: ?>
            <a href="media.php" style="color: var(--primary, #3b82f6); text-decoration: none;"><?= _e('All files') ?></a>
        <?php endif; ?>
        <span>/</span>
        <?php if ($currentFolder === ''): ?>
            <strong style="color: var(--text-main);"><?= _e('Root') ?></strong>
        <?php else: ?>
            <a href="media.php?folder=root" style="color: var(--primary, #3b82f6); text-decoration: none;"><?= _e('Root') ?></a>
            <?php if ($currentFolder !== null): ?>
                <span>/</span>
                <strong style="color: var(--text-main);"><?= Security::sanitize($currentFolder) ?></strong>
            <?php endif; ?>
        <?php endif; ?>
        <span style="margin-left: 6px;">(<?= $viewCount ?>)</span>
    </div>

    <?php if ($currentFolder !== null && $currentFolder !== ''): ?>
        <div style="display: flex; gap: 6px;">
            <button type="button" class="btn btn-secondary" style="padding: 5px 10px; font-size: 12px;"
                    onclick="openFolderModal('rename', <?= $jsonAttr($currentFolder) ?>)">
                <?= _e('Rename') ?>
            </button>
            <button type="button" class="btn btn-danger-ghost" style="padding: 5px 10px; font-size: 12px;"
                    onclick="requestDeleteFolder(<?= $jsonAttr($currentFolder) ?>, <?= $currentFolderCount ?>)">
                <?= _e('Delete Folder') ?>
            </button>
        </div>
    <?php endif; ?>
</div>

<?php if ($showFolderCards): ?>
    <div class="card" style="padding: 16px; margin-bottom: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 14px; flex-wrap: wrap;">
            <h2 style="font-size: 14px; font-weight: 700; margin: 0; color: var(--text-main);"><?= _e('Folders') ?></h2>
            <button type="button" class="btn btn-secondary" style="padding: 5px 10px; font-size: 12px;" onclick="openFolderModal('create')">
                <?= _e('+ New Folder') ?>
            </button>
        </div>

        <?php if (empty($folders)): ?>
            <p style="font-size: 13px; color: var(--text-muted); margin: 0;">
                <?= _e('No folders yet. Create one to keep your media organised.') ?>
            </p>
        <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px;">
                <?php foreach ($folders as $folder): ?>
                    <div class="card" style="padding: 0; overflow: hidden; background: var(--bg-card, #111827); display: flex; flex-direction: column;">
                        <a href="media.php?folder=<?= rawurlencode((string) $folder['name']) ?>"
                           style="padding: 14px; display: flex; align-items: flex-start; gap: 10px; text-decoration: none; color: inherit; flex-grow: 1;">
                            <svg style="width: 26px; height: 26px; color: var(--primary, #3b82f6); flex-shrink: 0;" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 7a2 2 0 012-2h3.586a1 1 0 01.707.293L11 7h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>
                            </svg>
                            <span style="min-width: 0;">
                                <strong style="display: block; font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--text-main);">
                                    <?= Security::sanitize((string) $folder['name']) ?>
                                </strong>
                                <span style="font-size: 11px; color: var(--text-muted);">
                                    <?= (int) $folder['count'] ?> <?= _e('files') ?>
                                </span>
                            </span>
                        </a>
                        <div style="display: flex; gap: 6px; padding: 0 12px 12px;">
                            <button type="button" class="btn btn-secondary" style="padding: 4px 8px; font-size: 11px; flex-grow: 1;"
                                    onclick="openFolderModal('rename', <?= $jsonAttr((string) $folder['name']) ?>)">
                                <?= _e('Rename') ?>
                            </button>
                            <button type="button" class="btn btn-danger-ghost" style="padding: 4px 8px; font-size: 13px; font-weight: bold; line-height: 1;"
                                    title="<?= _e('Delete Folder') ?>"
                                    onclick="requestDeleteFolder(<?= $jsonAttr((string) $folder['name']) ?>, <?= (int) $folder['count'] ?>)">
                                &times;
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div id="drop-zone" class="card" style="border: 2px dashed var(--border-strong); text-align: center; padding: 32px 20px; cursor: pointer; transition: all 0.2s ease; background: var(--bg-surface, #1e293b);">
    <input type="file" id="file-upload-input" multiple accept="image/*" style="display: none;">
    <svg style="width: 42px; height: 42px; color: var(--primary, #3b82f6); margin: 0 auto 10px; display: block;" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
    </svg>
    <p style="font-weight: 600; font-size: 15px; margin-bottom: 4px; color: var(--text-main);"><?= _e('Drag and drop images here, or click to browse') ?></p>
    <span style="font-size: 12px; color: var(--text-muted);"><?= _e('Supports JPG, PNG, WEBP, GIF up to 8MB') ?></span>
    <div style="font-size: 12px; color: var(--text-muted); margin-top: 8px;">
        <?= _e('Upload target:') ?> <strong style="color: var(--text-main);"><?= Security::sanitize((string) $uploadTargetLabel) ?></strong>
    </div>
    <div id="upload-progress-bar" style="display: none; width: 100%; max-width: 320px; height: 6px; background: var(--border-subtle, #334155); border-radius: 9999px; margin: 16px auto 0; overflow: hidden;">
        <div id="upload-progress" style="width: 0%; height: 100%; background: var(--primary, #3b82f6); transition: width 0.2s;"></div>
    </div>
</div>

<?php if (!empty($mediaItems)): ?>
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 10px; margin: 24px 0 0;">
        <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-muted); cursor: pointer;">
            <input type="checkbox" id="media-select-all">
            <?= _e('Select all') ?>
        </label>
        <div id="bulk-actions" style="display: none; align-items: center; gap: 8px; flex-wrap: wrap;">
            <span id="bulk-count" style="font-size: 12px; color: var(--text-muted);"></span>
            <button type="button" class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;" onclick="openMoveModalFromSelection()">
                <?= _e('Move selected') ?>
            </button>
        </div>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 20px; margin-top: 24px;">
    <?php if (empty($mediaItems)): ?>
        <div class="card" style="grid-column: 1 / -1; text-align: center; padding: 48px; color: var(--text-muted);">
            <?= _e('No media assets found in library.') ?>
        </div>
    <?php else: ?>
        <?php foreach ($pageItems as $item):
            $filePath = (string) $item['filepath'];
            $fullUrl = MediaLibrary::urlForStoredPath($filePath);
            $thumbUrl = MediaLibrary::thumbnailUrl($filePath, 240);
            $itemFolder = MediaLibrary::folderOfStoredPath($filePath);
            $itemFolderLabel = $itemFolder === '' ? __('Root') : $itemFolder;
            $fileSizeKb = round((int) $item['file_size'] / 1024, 1);
        ?>
            <div class="card" style="padding: 0; overflow: hidden; display: flex; flex-direction: column; box-shadow: var(--shadow-sm); background: var(--bg-card, #111827);">
                <div style="width: 100%; height: 140px; background: var(--bg-surface, #1e293b); display: flex; align-items: center; justify-content: center; position: relative;">
                    <img src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= Security::sanitize((string) $item['filename']) ?>" loading="lazy" decoding="async" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                    <label style="position: absolute; top: 8px; left: 8px; display: flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 6px; background: rgba(15, 23, 42, 0.65); cursor: pointer;">
                        <input type="checkbox" class="media-select" value="<?= htmlspecialchars($filePath, ENT_QUOTES, 'UTF-8') ?>" style="margin: 0; cursor: pointer;">
                    </label>
                </div>
                <div style="padding: 12px; flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between;">
                    <div>
                        <span style="display: block; font-weight: 600; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text-main);" title="<?= Security::sanitize((string) $item['filename']) ?>">
                            <?= Security::sanitize((string) $item['filename']) ?>
                        </span>
                        <span style="font-size: 11px; color: var(--text-muted);"><?= $fileSizeKb ?> KB</span>
                        <?php if ($currentFolder === null): ?>
                            <span style="display: block; font-size: 11px; color: var(--text-muted); margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <?= _e('Folder') ?>: <?= Security::sanitize((string) $itemFolderLabel) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div style="display: flex; gap: 6px; margin-top: 12px;">
                        <button type="button" class="btn btn-secondary" style="padding: 4px 8px; font-size: 11px; flex-grow: 1;"
                                onclick="openImageEditor(<?= $jsonAttr($fullUrl) ?>, <?= $jsonAttr((string) $item['filename']) ?>, <?= $jsonAttr($itemFolder) ?>)">
                            <?= _e('Edit') ?>
                        </button>
                        <button type="button" class="btn btn-secondary" style="padding: 4px 8px; font-size: 11px; flex-grow: 1;"
                                onclick="copyMediaUrl(<?= $jsonAttr($fullUrl) ?>, this)">
                            <?= _e('Copy URL') ?>
                        </button>
                        <button type="button" class="btn btn-secondary" style="padding: 4px 8px; font-size: 11px; flex-grow: 1;"
                                onclick="openMoveModal([<?= $jsonAttr($filePath) ?>])">
                            <?= _e('Move') ?>
                        </button>
                        <button type="button" class="btn btn-danger-ghost" style="padding: 4px 8px; font-size: 13px; font-weight: bold; line-height: 1;"
                                onclick="confirmDeleteMedia(<?= $jsonAttr($filePath) ?>, <?= $jsonAttr((string) $item['filename']) ?>)">
                            &times;
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if ($totalPages > 1): ?>
    <div style="display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 24px; flex-wrap: wrap;">
        <?php if ($mediaPage > 1): ?>
            <a class="btn btn-secondary" style="padding: 5px 10px; font-size: 12px;" href="<?= htmlspecialchars($pageUrl($mediaPage - 1), ENT_QUOTES, 'UTF-8') ?>">&larr; <?= _e('Previous') ?></a>
        <?php endif; ?>
        <span style="font-size: 12px; color: var(--text-muted);">
            <?= _e('Page') ?> <?= (int) $mediaPage ?> / <?= (int) $totalPages ?>
        </span>
        <?php if ($mediaPage < $totalPages): ?>
            <a class="btn btn-secondary" style="padding: 5px 10px; font-size: 12px;" href="<?= htmlspecialchars($pageUrl($mediaPage + 1), ENT_QUOTES, 'UTF-8') ?>"><?= _e('Next') ?> &rarr;</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Create / rename folder -->
<div id="folder-modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.75); z-index: 2000; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(4px);">
    <div class="card" style="width: 100%; max-width: 430px; padding: 24px; background: var(--bg-card, #111827); border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid var(--border-subtle);">
        <h3 id="folder-modal-title" style="font-size: 17px; font-weight: 700; margin: 0 0 16px; color: var(--text-main);"><?= _e('Create Folder') ?></h3>
        <form method="post" id="folder-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="media_action" id="folder-action" value="create_folder">
            <input type="hidden" name="old_folder" id="folder-old" value="">
            <input type="hidden" name="return_folder" value="<?= htmlspecialchars($returnFolderValue, ENT_QUOTES, 'UTF-8') ?>">

            <label for="folder-name-input" class="form-label" style="display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 6px;">
                <?= _e('Folder name') ?>
            </label>
            <input type="text" class="form-control" id="folder-name-input" name="folder_name" maxlength="64" required autocomplete="off">
            <p style="font-size: 11px; color: var(--text-muted); margin: 8px 0 18px;">
                <?= _e('Use letters, numbers, hyphens and underscores.') ?>
            </p>

            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn btn-secondary" onclick="closeFolderModal()"><?= _e('Cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= _e('Save') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Move files -->
<div id="move-modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.75); z-index: 2000; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(4px);">
    <div class="card" style="width: 100%; max-width: 430px; padding: 24px; background: var(--bg-card, #111827); border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid var(--border-subtle);">
        <h3 style="font-size: 17px; font-weight: 700; margin: 0 0 16px; color: var(--text-main);"><?= _e('Move files') ?></h3>
        <form method="post" id="move-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="media_action" value="move_files">
            <input type="hidden" name="return_folder" value="<?= htmlspecialchars($returnFolderValue, ENT_QUOTES, 'UTF-8') ?>">
            <div id="move-paths"></div>

            <label for="move-target-folder" class="form-label" style="display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 6px;">
                <?= _e('Move to folder') ?>
            </label>
            <select class="form-control" id="move-target-folder" name="target_folder">
                <option value="root"><?= _e('Root (no folder)') ?></option>
                <?php foreach ($folders as $folder): ?>
                    <option value="<?= htmlspecialchars((string) $folder['name'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= Security::sanitize((string) $folder['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 18px;">
                <button type="button" class="btn btn-secondary" onclick="closeMoveModal()"><?= _e('Cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= _e('Move') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Delete file -->
<div id="delete-confirm-modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.75); z-index: 2000; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(4px);">
    <div class="card" style="width: 100%; max-width: 420px; padding: 24px; background: var(--bg-card, #111827); border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid var(--border-subtle); text-align: center;">
        <div style="width: 48px; height: 48px; border-radius: 50%; background: rgba(239, 68, 68, 0.15); color: var(--danger, #ef4444); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
            <svg style="width: 24px; height: 24px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
        </div>
        <h3 style="font-size: 17px; font-weight: 700; margin: 0 0 8px; color: var(--text-main);"><?= _e('Delete Media Asset') ?></h3>
        <p style="font-size: 13px; color: var(--text-muted); margin: 0 0 20px; line-height: 1.5;">
            <?= _e('Are you sure you want to permanently delete') ?> <strong id="delete-filename-placeholder" style="color: var(--text-main);"></strong>? <?= _e('This action cannot be undone.') ?>
        </p>
        <form method="post" id="delete-file-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="media_action" value="delete_file">
            <input type="hidden" name="return_folder" value="<?= htmlspecialchars($returnFolderValue, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="file_path" id="delete-file-path" value="">
            <div style="display: flex; gap: 10px; justify-content: center;">
                <button type="button" class="btn btn-secondary" style="flex: 1; padding: 9px;" onclick="closeDeleteModal()"><?= _e('Cancel') ?></button>
                <button type="submit" class="btn btn-primary" style="background: var(--danger, #ef4444); border-color: var(--danger, #ef4444); flex: 1; padding: 9px;">
                    <?= _e('Delete') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<form method="post" id="delete-folder-form" style="display: none;">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="media_action" value="delete_folder">
    <input type="hidden" name="return_folder" value="<?= htmlspecialchars($returnFolderValue, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="folder_name" id="delete-folder-name" value="">
</form>

<div id="image-editor-modal" style="display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.75); z-index: 1000; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(4px);">
    <div style="background: var(--bg-card, #111827); border-radius: var(--radius-md, 8px); max-width: 900px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: var(--shadow-sm); border: 1px solid var(--border-subtle);">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border-subtle); display: flex; justify-content: space-between; align-items: center;">
            <h3 style="font-size: 16px; font-weight: 700; margin: 0; color: var(--text-main);"><?= _e('Canvas Image Editor') ?></h3>
            <button type="button" onclick="closeImageEditor()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <div style="padding: 14px 20px; background: var(--bg-surface, #1e293b); border-bottom: 1px solid var(--border-subtle); display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
            <button type="button" class="btn btn-secondary" onclick="rotateImage(90)"><?= _e('Rotate 90°') ?></button>
            <button type="button" class="btn btn-secondary" onclick="flipImage('h')"><?= _e('Flip Horizontal') ?></button>
            <button type="button" class="btn btn-secondary" onclick="flipImage('v')"><?= _e('Flip Vertical') ?></button>
            <select id="aspect-ratio-selector" class="form-control" style="width: auto; padding: 6px 12px; font-size: 13px;" onchange="applyAspectCrop(this.value)">
                <option value="free"><?= _e('Original Ratio') ?></option>
                <option value="16:9">16:9 (Landscape)</option>
                <option value="4:3">4:3 (Standard)</option>
                <option value="1:1">1:1 (Square)</option>
            </select>
        </div>

        <div style="padding: 20px; overflow: auto; flex-grow: 1; display: flex; justify-content: center; align-items: center; background: #0b0f19;">
            <canvas id="editor-canvas" style="max-width: 100%; max-height: 55vh; box-shadow: 0 4px 12px rgba(0,0,0,0.4);"></canvas>
        </div>

        <div style="padding: 16px 20px; border-top: 1px solid var(--border-subtle); display: flex; justify-content: space-between; align-items: center; background: var(--bg-card, #111827);">
            <span id="canvas-resolution" style="font-size: 12px; color: var(--text-muted);"></span>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeImageEditor()"><?= _e('Cancel') ?></button>
                <button type="button" id="save-canvas-btn" class="btn btn-primary" onclick="saveEditedImage()"><?= _e('Save as New Image') ?></button>
            </div>
        </div>
    </div>
</div>

<script>
const MEDIA_CURRENT_FOLDER = <?= json_encode($currentFolder ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const MEDIA_I18N = {
    selected: <?= json_encode(__('selected')) ?>,
    deleteFolderTitle: <?= json_encode(__('Delete Folder')) ?>,
    deleteFolderMessage: <?= json_encode(__('Are you sure you want to delete this folder?')) ?>,
    folderNotEmpty: <?= json_encode(__('Folder is not empty. Move its files first.')) ?>,
    selectAtLeastOne: <?= json_encode(__('Select at least one file.')) ?>
};

// ---------------------------------------------------------------------------
// Folder modals
// ---------------------------------------------------------------------------
const folderModal = document.getElementById('folder-modal');
const folderForm = document.getElementById('folder-form');
const folderAction = document.getElementById('folder-action');
const folderOld = document.getElementById('folder-old');
const folderNameInput = document.getElementById('folder-name-input');
const folderModalTitle = document.getElementById('folder-modal-title');
const FOLDER_TITLE_CREATE = <?= json_encode(__('Create Folder')) ?>;
const FOLDER_TITLE_RENAME = <?= json_encode(__('Rename Folder')) ?>;

function openFolderModal(mode, name) {
    if (!folderModal || !folderForm) return;
    const isRename = mode === 'rename';
    folderAction.value = isRename ? 'rename_folder' : 'create_folder';
    folderOld.value = isRename ? (name || '') : '';
    folderNameInput.value = isRename ? (name || '') : '';
    folderModalTitle.textContent = isRename ? FOLDER_TITLE_RENAME : FOLDER_TITLE_CREATE;
    folderModal.style.display = 'flex';
    setTimeout(() => folderNameInput.focus(), 30);
}

function closeFolderModal() {
    if (folderModal) folderModal.style.display = 'none';
}

if (folderModal) {
    folderModal.addEventListener('click', (e) => {
        if (e.target === folderModal) closeFolderModal();
    });
}

const deleteFolderForm = document.getElementById('delete-folder-form');
const deleteFolderInput = document.getElementById('delete-folder-name');

function requestDeleteFolder(name, count) {
    if (count > 0) {
        UI.alert({ title: MEDIA_I18N.deleteFolderTitle, message: MEDIA_I18N.folderNotEmpty, danger: true });
        return;
    }
    UI.confirm({
        title: MEDIA_I18N.deleteFolderTitle,
        message: MEDIA_I18N.deleteFolderMessage,
        okText: <?= json_encode(__('Delete')) ?>,
        cancelText: <?= json_encode(__('Cancel')) ?>,
        danger: true
    }).then((ok) => {
        if (!ok || !deleteFolderForm) return;
        deleteFolderInput.value = name;
        deleteFolderForm.submit();
    });
}

// ---------------------------------------------------------------------------
// Selecting and moving files
// ---------------------------------------------------------------------------
const selectAllCheckbox = document.getElementById('media-select-all');
const bulkActions = document.getElementById('bulk-actions');
const bulkCount = document.getElementById('bulk-count');
const moveModal = document.getElementById('move-modal');
const movePaths = document.getElementById('move-paths');

function updateBulkUI() {
    const checkboxes = Array.from(document.querySelectorAll('.media-select'));
    const checked = checkboxes.filter((cb) => cb.checked);

    if (bulkActions) bulkActions.style.display = checked.length ? 'flex' : 'none';
    if (bulkCount) bulkCount.textContent = checked.length + ' ' + MEDIA_I18N.selected;
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = checkboxes.length > 0 && checked.length === checkboxes.length;
        selectAllCheckbox.indeterminate = checked.length > 0 && checked.length < checkboxes.length;
    }
}

document.querySelectorAll('.media-select').forEach((cb) => cb.addEventListener('change', updateBulkUI));

if (selectAllCheckbox) {
    selectAllCheckbox.addEventListener('change', () => {
        document.querySelectorAll('.media-select').forEach((cb) => {
            cb.checked = selectAllCheckbox.checked;
        });
        updateBulkUI();
    });
}

function openMoveModal(paths) {
    if (!moveModal || !movePaths) return;
    paths = (paths || []).filter((path) => typeof path === 'string' && path !== '');
    if (!paths.length) {
        UI.alert({ title: <?= json_encode(__('Move files')) ?>, message: MEDIA_I18N.selectAtLeastOne, danger: true });
        return;
    }

    movePaths.innerHTML = '';
    paths.forEach((path) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'paths[]';
        input.value = path;
        movePaths.appendChild(input);
    });
    moveModal.style.display = 'flex';
}

function openMoveModalFromSelection() {
    openMoveModal(Array.from(document.querySelectorAll('.media-select:checked')).map((cb) => cb.value));
}

function closeMoveModal() {
    if (moveModal) moveModal.style.display = 'none';
}

if (moveModal) {
    moveModal.addEventListener('click', (e) => {
        if (e.target === moveModal) closeMoveModal();
    });
}

// ---------------------------------------------------------------------------
// Deleting files
// ---------------------------------------------------------------------------
const deleteModal = document.getElementById('delete-confirm-modal');
const deletePathInput = document.getElementById('delete-file-path');
const deleteNamePlaceholder = document.getElementById('delete-filename-placeholder');

function confirmDeleteMedia(path, filename) {
    if (!deleteModal) return;
    deletePathInput.value = path;
    deleteNamePlaceholder.textContent = filename;
    deleteModal.style.display = 'flex';
}

function closeDeleteModal() {
    if (deleteModal) deleteModal.style.display = 'none';
}

if (deleteModal) {
    deleteModal.addEventListener('click', (e) => {
        if (e.target === deleteModal) closeDeleteModal();
    });
}

// ---------------------------------------------------------------------------
// Copy URL
// ---------------------------------------------------------------------------
function copyMediaUrl(path, buttonElement = null) {
    const cleanPath = path.startsWith('/') ? path : '/' + path;
    const absoluteUrl = window.location.origin + cleanPath;

    navigator.clipboard.writeText(absoluteUrl).then(() => {
        if (buttonElement) {
            const originalText = buttonElement.innerText;
            buttonElement.innerText = <?= json_encode(__('Copied!')) ?>;
            buttonElement.style.color = 'var(--primary, #3b82f6)';
            setTimeout(() => {
                buttonElement.innerText = originalText;
                buttonElement.style.color = '';
            }, 1800);
        } else {
            UI.toast(<?= json_encode(__('Link copied to clipboard')) ?>, 'success');
        }
    }).catch(() => {
        const textarea = document.createElement('textarea');
        textarea.value = absoluteUrl;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        UI.toast(<?= json_encode(__('Link copied to clipboard')) ?>, 'success');
    });
}

// ---------------------------------------------------------------------------
// Uploads
// ---------------------------------------------------------------------------
const dropZone = document.getElementById('drop-zone');
const fileInput = document.getElementById('file-upload-input');
const progressBar = document.getElementById('upload-progress-bar');
const progress = document.getElementById('upload-progress');

if (dropZone) {
    dropZone.addEventListener('click', () => fileInput.click());
}

['dragenter', 'dragover'].forEach((e) => {
    dropZone.addEventListener(e, (evt) => {
        evt.preventDefault();
        dropZone.style.borderColor = 'var(--primary, #3b82f6)';
        dropZone.style.backgroundColor = 'var(--primary-subtle, rgba(59, 130, 246, 0.1))';
    });
});

['dragleave', 'drop'].forEach((e) => {
    dropZone.addEventListener(e, (evt) => {
        evt.preventDefault();
        dropZone.style.borderColor = 'var(--border-strong)';
        dropZone.style.backgroundColor = 'var(--bg-surface, #1e293b)';
    });
});

dropZone.addEventListener('drop', (evt) => {
    if (evt.dataTransfer.files.length) {
        uploadFiles(evt.dataTransfer.files);
    }
});

fileInput.addEventListener('change', () => {
    if (fileInput.files.length) {
        uploadFiles(fileInput.files);
    }
});

function uploadFiles(files) {
    progressBar.style.display = 'block';
    let uploadedCount = 0;
    let failedCount = 0;

    Array.from(files).forEach((file) => {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('folder', MEDIA_CURRENT_FOLDER);

        fetch('upload.php', { method: 'POST', body: formData })
            .then((res) => res.json())
            .then((data) => {
                if (!data || !data.location) failedCount++;
            })
            .catch(() => { failedCount++; })
            .finally(() => {
                uploadedCount++;
                progress.style.width = Math.round((uploadedCount / files.length) * 100) + '%';
                if (uploadedCount === files.length) {
                    if (failedCount > 0) {
                        UI.alert({ title: <?= json_encode(__('Upload Error')) ?>, message: <?= json_encode(__('Some files could not be uploaded.')) ?>, danger: true });
                    }
                    setTimeout(() => window.location.reload(), 300);
                }
            });
    });
}

// ---------------------------------------------------------------------------
// Canvas image editor
// ---------------------------------------------------------------------------
const editorModal = document.getElementById('image-editor-modal');
const canvas = document.getElementById('editor-canvas');
const ctx = canvas.getContext('2d');
const resIndicator = document.getElementById('canvas-resolution');
let originalImg = new Image();
let currentAngle = 0;
let scaleH = 1;
let scaleV = 1;
let activeFilename = 'edited.jpg';
let activeFolder = '';

function openImageEditor(imageUrl, filename, folder) {
    activeFilename = filename;
    activeFolder = folder || '';
    originalImg.crossOrigin = 'anonymous';
    originalImg.src = imageUrl;
    originalImg.onload = () => {
        currentAngle = 0;
        scaleH = 1;
        scaleV = 1;
        document.getElementById('aspect-ratio-selector').value = 'free';
        renderCanvas();
        editorModal.style.display = 'flex';
    };
}

function closeImageEditor() {
    editorModal.style.display = 'none';
}

function renderCanvas() {
    const isRotated = currentAngle % 180 !== 0;
    const w = isRotated ? originalImg.naturalHeight : originalImg.naturalWidth;
    const h = isRotated ? originalImg.naturalWidth : originalImg.naturalHeight;

    canvas.width = w;
    canvas.height = h;

    ctx.save();
    ctx.translate(canvas.width / 2, canvas.height / 2);
    ctx.rotate((currentAngle * Math.PI) / 180);
    ctx.scale(scaleH, scaleV);
    ctx.drawImage(originalImg, -originalImg.naturalWidth / 2, -originalImg.naturalHeight / 2);
    ctx.restore();

    resIndicator.textContent = `${canvas.width} x ${canvas.height} px`;
}

function rotateImage(deg) {
    currentAngle = (currentAngle + deg) % 360;
    renderCanvas();
}

function flipImage(axis) {
    if (axis === 'h') scaleH *= -1;
    if (axis === 'v') scaleV *= -1;
    renderCanvas();
}

function applyAspectCrop(ratio) {
    if (ratio === 'free') {
        renderCanvas();
        return;
    }
    renderCanvas();
    const [rw, rh] = ratio.split(':').map(Number);
    let targetW = canvas.width;
    let targetH = Math.round(targetW * (rh / rw));

    if (targetH > canvas.height) {
        targetH = canvas.height;
        targetW = Math.round(targetH * (rw / rh));
    }

    const startX = Math.round((canvas.width - targetW) / 2);
    const startY = Math.round((canvas.height - targetH) / 2);

    const croppedData = ctx.getImageData(startX, startY, targetW, targetH);
    canvas.width = targetW;
    canvas.height = targetH;
    ctx.putImageData(croppedData, 0, 0);
    resIndicator.textContent = `${canvas.width} x ${canvas.height} px`;
}

function saveEditedImage() {
    const saveBtn = document.getElementById('save-canvas-btn');
    saveBtn.disabled = true;
    saveBtn.textContent = <?= json_encode(__('Saving...')) ?>;

    canvas.toBlob((blob) => {
        const formData = new FormData();
        formData.append('file', blob, 'edited_' + activeFilename.replace(/\.[^/.]+$/, '.jpg'));
        formData.append('folder', activeFolder);

        fetch('upload.php', { method: 'POST', body: formData })
            .then((r) => r.json())
            .then((data) => {
                if (data.location) {
                    window.location.reload();
                } else {
                    UI.alert({ title: <?= json_encode(__('Save Error')) ?>, message: data.error || <?= json_encode(__('Failed to save image.')) ?>, danger: true });
                    saveBtn.disabled = false;
                    saveBtn.textContent = <?= json_encode(__('Save as New Image')) ?>;
                }
            })
            .catch(() => {
                UI.alert({ title: <?= json_encode(__('Save Error')) ?>, message: <?= json_encode(__('Upload failed due to network error.')) ?>, danger: true });
                saveBtn.disabled = false;
                saveBtn.textContent = <?= json_encode(__('Save as New Image')) ?>;
            });
    }, 'image/jpeg', 0.92);
}

// Generate missing thumbnails in small batches so the request never times out.
(function () {
    const btn = document.getElementById('thumb-backfill-btn');
    if (!btn) return;

    const wrap = document.getElementById('thumb-backfill-progress');
    const bar = document.getElementById('thumb-backfill-bar');
    const status = document.getElementById('thumb-backfill-status');
    const csrf = btn.getAttribute('data-csrf') || '';

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        if (wrap) wrap.style.display = 'block';

        let offset = 0;
        let done = false;
        while (!done) {
            const body = new URLSearchParams();
            body.set('csrf', csrf);
            body.set('offset', String(offset));
            body.set('limit', '5');

            try {
                const response = await fetch('thumbs-backfill.php', { method: 'POST', body });
                const data = await response.json();
                if (!data.ok) throw new Error(data.error || 'failed');

                offset = Number(data.next) || 0;
                done = !!data.done;
                const percent = data.total > 0 ? Math.min(100, Math.round((offset / data.total) * 100)) : 100;
                if (bar) bar.style.width = percent + '%';
                if (status) status.textContent = offset + ' / ' + data.total;
            } catch (error) {
                if (status) status.textContent = <?= json_encode(__('Thumbnail generation failed.')) ?>;
                btn.disabled = false;
                return;
            }
        }

        if (status) status.textContent = <?= json_encode(__('Thumbnails generated.')) ?>;
        window.location.reload();
    });

    // Fill the cache in the background on first visit so the picker is fast
    // even before the administrator clicks anything.
    try {
        const lockKey = 'modoThumbBackfillAt';
        const lastRun = parseInt(window.localStorage.getItem(lockKey) || '0', 10);
        if (Date.now() - lastRun > 10 * 60 * 1000) {
            window.localStorage.setItem(lockKey, String(Date.now()));
            window.setTimeout(() => {
                if (!btn.disabled && document.visibilityState !== 'hidden') btn.click();
            }, 1500);
        }
    } catch (error) {
        // Private mode / disabled storage: the button still works manually.
    }
})();
</script>

<?php require_once __DIR__ . '/views/footer.php'; ?>
