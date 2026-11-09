<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Database;
use Core\MediaLibrary;
use Core\Security;

Auth::requireCapability('manage_media');

$db = Database::getConnection();
$csrfToken = Security::generateCsrfToken();

/**
 * Redirects back to the requested media view with a flash message code.
 */
function mediaRedirect(?string $folder, string $key, bool $isError = false, ?int $page = null): void
{
    $params = [];
    if ($folder === '') {
        $params['folder'] = 'root';
    } elseif ($folder !== null) {
        $params['folder'] = $folder;
    }
    if ($page !== null && $page > 1) {
        $params['page'] = $page;
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
    $returnPage = max(1, (int) mediaPostString('return_page', '1'));

    if (!Security::verifyCsrfToken(mediaPostString('csrf'))) {
        mediaRedirect($returnFolder, 'csrf', true, $returnPage);
    }

    $action = $_POST['media_action'];

    switch ($action) {
        case 'create_folder':
            $result = MediaLibrary::createFolder(mediaPostString('folder_name'));
            if (!empty($result['ok'])) {
                mediaRedirect((string) ($result['name'] ?? $returnFolder), 'folder_created', false, $returnPage);
            }
            mediaRedirect($returnFolder, mediaErrorKey((string) ($result['error'] ?? 'failed')), true, $returnPage);
            break;

        case 'rename_folder':
            $result = MediaLibrary::renameFolder(
                mediaPostString('old_folder'),
                mediaPostString('folder_name')
            );
            if (!empty($result['ok'])) {
                mediaRedirect((string) ($result['name'] ?? $returnFolder), 'folder_renamed', false, $returnPage);
            }
            mediaRedirect($returnFolder, mediaErrorKey((string) ($result['error'] ?? 'failed')), true, $returnPage);
            break;

        case 'delete_folder':
            $folderName = mediaPostString('folder_name');
            $result = MediaLibrary::deleteFolder($folderName);
            if (!empty($result['ok'])) {
                // Never redirect back into a folder that no longer exists.
                $destination = $returnFolder === $folderName ? null : $returnFolder;
                mediaRedirect($destination, 'folder_deleted', false, null);
            }
            mediaRedirect($returnFolder, mediaErrorKey((string) ($result['error'] ?? 'failed')), true, $returnPage);
            break;

        case 'delete_file':
            $result = MediaLibrary::deleteFile(mediaPostString('file_path'));
            if (!empty($result['ok'])) {
                mediaRedirect($returnFolder, 'file_deleted', false, $returnPage);
            }
            mediaRedirect($returnFolder, mediaErrorKey((string) ($result['error'] ?? 'failed'), true), true, $returnPage);
            break;

        case 'delete_files':
            $paths = $_POST['paths'] ?? [];
            if (!is_array($paths)) {
                $paths = [];
            }
            $paths = array_values(array_filter($paths, static function ($path): bool {
                return is_string($path) && $path !== '';
            }));

            $result = MediaLibrary::deleteFiles($paths);
            if ((int) ($result['deleted'] ?? 0) === 0) {
                mediaRedirect($returnFolder, 'delete_failed', true, $returnPage);
            }
            if ((int) ($result['failed'] ?? 0) > 0) {
                mediaRedirect($returnFolder, 'delete_partial', true, $returnPage);
            }
            mediaRedirect($returnFolder, 'files_deleted', false, $returnPage);
            break;

        case 'delete_all':
            $viewValue = mediaPostString('view_folder', 'all');
            // "All files" spans the entire library; only the root uploads
            // directory or a concrete folder can be cleared with this action.
            if ($viewValue === 'all') {
                mediaRedirect($returnFolder, 'invalid_folder', true, $returnPage);
            }

            $viewFolder = mediaReadFolder($viewValue);
            if ($viewFolder === null) {
                mediaRedirect($returnFolder, 'invalid_folder', true, $returnPage);
            }

            $paths = [];
            foreach (MediaLibrary::listImages($viewFolder, $viewFolder !== '') as $item) {
                $path = $item['path'] ?? null;
                if (is_string($path) && $path !== '') {
                    $paths[$path] = true;
                }
            }

            // Also collect database rows for this folder so stale records whose
            // files are already gone disappear together with the folder files.
            try {
                foreach ($db->query("SELECT filepath FROM media") as $row) {
                    $filepath = (string) ($row['filepath'] ?? '');
                    if ($filepath !== '' && MediaLibrary::folderOfStoredPath($filepath) === $viewFolder) {
                        $paths[$filepath] = true;
                    }
                }
            } catch (\Throwable $e) {
                // Filesystem deletion can still proceed without the DB scan.
            }

            $result = MediaLibrary::deleteFiles(array_keys($paths));
            if ((int) ($result['deleted'] ?? 0) === 0) {
                mediaRedirect($returnFolder, 'delete_failed', true, $returnPage);
            }
            if ((int) ($result['failed'] ?? 0) > 0) {
                mediaRedirect($returnFolder, 'delete_partial', true, $returnPage);
            }
            mediaRedirect($returnFolder, 'all_files_deleted', false, $returnPage);
            break;

        case 'bulk_metadata':
            $paths = $_POST['paths'] ?? [];
            if (!is_array($paths)) {
                $paths = [];
            }
            $paths = array_values(array_filter($paths, static function ($path): bool {
                return is_string($path) && $path !== '';
            }));

            $applyAlt = isset($_POST['apply_alt']);
            $applyCaption = isset($_POST['apply_caption']);
            if ($paths === [] || (!$applyAlt && !$applyCaption)) {
                mediaRedirect($returnFolder, 'metadata_failed', true, $returnPage);
            }

            MediaLibrary::syncMissingMedia(Auth::id());
            MediaLibrary::updateMetadata(
                $paths,
                $applyAlt ? mediaPostString('bulk_alt') : null,
                $applyCaption ? mediaPostString('bulk_caption') : null
            );
            mediaRedirect($returnFolder, 'metadata_updated', false, $returnPage);
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
                    mediaRedirect($returnFolder, 'invalid_folder', true, $returnPage);
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
                mediaRedirect($returnFolder, 'move_failed', true, $returnPage);
            }
            if ($failed > 0) {
                mediaRedirect($returnFolder, 'move_partial', true, $returnPage);
            }
            mediaRedirect($returnFolder, 'moved', false, $returnPage);
            break;
    }

    mediaRedirect($returnFolder, 'operation_failed', true, $returnPage);
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
$pageMetadata = MediaLibrary::metadataForPaths(array_map(
    static fn (array $item): string => (string) ($item['filepath'] ?? ''),
    $pageItems
));
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
    'files_deleted'  => __('Selected files deleted successfully.'),
    'all_files_deleted' => __('All files in this folder were deleted.'),
    'metadata_updated' => __('ALT text and/or caption updated for the selected files.'),
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
    'delete_failed'    => __('Could not delete the selected files.'),
    'delete_partial'   => __('Some files could not be deleted.'),
    'metadata_failed'  => __('Could not update the metadata. Select at least one file and a field to change.'),
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

    <?php if ($currentFolder !== null): ?>
        <div style="display: flex; gap: 6px; flex-wrap: wrap;">
            <?php if ($currentFolder !== ''): ?>
                <button type="button" class="btn btn-secondary" style="padding: 5px 10px; font-size: 12px;"
                        onclick="openFolderModal('rename', <?= $jsonAttr($currentFolder) ?>)">
                    <?= _e('Rename') ?>
                </button>
                <button type="button" class="btn btn-danger-ghost" style="padding: 5px 10px; font-size: 12px;"
                        onclick="requestDeleteFolder(<?= $jsonAttr($currentFolder) ?>, <?= $currentFolderCount ?>)">
                    <?= _e('Delete Folder') ?>
                </button>
            <?php endif; ?>
            <?php if ($viewCount > 0): ?>
                <button type="button" class="btn btn-danger-ghost" style="padding: 5px 10px; font-size: 12px;"
                        onclick="deleteAllFiles(<?= $jsonAttr($returnFolderValue) ?>)">
                    <?= _e('Delete all files') ?>
                </button>
            <?php endif; ?>
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
            <button type="button" class="btn btn-secondary" style="padding: 5px 10px; font-size: 12px;" onclick="openBulkMetadataModal()">
                <?= _e('Edit selected') ?>
            </button>
            <button type="button" class="btn btn-secondary" style="padding: 5px 10px; font-size: 12px;" onclick="openBulkTransformModal()">
                <?= _e('Transform selected') ?>
            </button>
            <button type="button" class="btn btn-secondary" style="padding: 5px 10px; font-size: 12px;" onclick="regenerateSelectedThumbnails()">
                <?= _e('Regenerate thumbnails') ?>
            </button>
            <button type="button" class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;" onclick="openMoveModalFromSelection()">
                <?= _e('Move selected') ?>
            </button>
            <button type="button" class="btn btn-danger-ghost" style="padding: 5px 10px; font-size: 12px;" onclick="deleteSelectedFiles()">
                <?= _e('Delete selected') ?>
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
        <?php foreach ($pageItems as $itemIndex => $item):
            $filePath = (string) $item['filepath'];
            $fullUrl = MediaLibrary::urlForStoredPath($filePath);
            $thumbUrl = MediaLibrary::thumbnailUrl($filePath, 240);
            $itemFolder = MediaLibrary::folderOfStoredPath($filePath);
            $itemFolderLabel = $itemFolder === '' ? __('Root') : $itemFolder;
            $fileSizeKb = round((int) $item['file_size'] / 1024, 1);
            $altText = (string) ($pageMetadata[$filePath]['alt_text'] ?? '');
            $captionText = (string) ($pageMetadata[$filePath]['caption'] ?? '');
        ?>
            <div class="card" style="padding: 0; overflow: hidden; display: flex; flex-direction: column; box-shadow: var(--shadow-sm); background: var(--bg-card, #111827);">
                <div style="width: 100%; height: 140px; background: var(--bg-surface, #1e293b); display: flex; align-items: center; justify-content: center; position: relative;">
                    <button type="button" class="media-thumb-button" onclick="openMediaLightbox(<?= (int) $itemIndex ?>)">
                        <img src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= Security::sanitize($altText !== '' ? $altText : (string) $item['filename']) ?>" loading="lazy" decoding="async" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                    </button>
                    <label class="media-thumb-check">
                        <input type="checkbox" class="media-select" value="<?= htmlspecialchars($filePath, ENT_QUOTES, 'UTF-8') ?>" style="margin: 0; cursor: pointer;">
                    </label>
                </div>
                <div style="padding: 12px; flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between;">
                    <div>
                        <span style="display: block; font-weight: 600; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text-main);" title="<?= Security::sanitize((string) $item['filename']) ?>">
                            <?= Security::sanitize((string) $item['filename']) ?>
                        </span>
                        <span style="font-size: 11px; color: var(--text-muted);"><?= $fileSizeKb ?> KB</span>
                        <?php if ($altText !== ''): ?>
                            <span style="display: block; font-size: 11px; color: var(--text-muted); margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= Security::sanitize($altText) ?>">
                                ALT: <?= Security::sanitize($altText) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($captionText !== ''): ?>
                            <span style="display: block; font-size: 11px; color: var(--text-muted); margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= Security::sanitize($captionText) ?>">
                                <?= Security::sanitize($captionText) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($currentFolder === null): ?>
                            <span style="display: block; font-size: 11px; color: var(--text-muted); margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <?= _e('Folder') ?>: <?= Security::sanitize((string) $itemFolderLabel) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div style="display: flex; gap: 6px; margin-top: 12px;">
                        <button type="button" class="btn btn-secondary" style="padding: 4px 8px; font-size: 11px; flex-grow: 1;"
                                onclick="openImageEditor(<?= $jsonAttr($fullUrl) ?>, <?= $jsonAttr((string) $item['filename']) ?>, <?= $jsonAttr($itemFolder) ?>, <?= $jsonAttr($filePath) ?>, <?= $jsonAttr((string) $item['mime_type']) ?>)">
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
            <input type="hidden" name="return_page" value="<?= (int) $mediaPage ?>">

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
            <input type="hidden" name="return_page" value="<?= (int) $mediaPage ?>">
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

<!-- Bulk metadata -->
<div id="bulk-metadata-modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.75); z-index: 2000; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(4px);">
    <div class="card" style="width: 100%; max-width: 520px; padding: 24px; background: var(--bg-card, #111827); border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid var(--border-subtle);">
        <h3 style="font-size: 17px; font-weight: 700; margin: 0 0 16px; color: var(--text-main);"><?= _e('Edit selected media') ?></h3>
        <form method="post" id="bulk-metadata-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="media_action" value="bulk_metadata">
            <input type="hidden" name="return_folder" value="<?= htmlspecialchars($returnFolderValue, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="return_page" value="<?= (int) $mediaPage ?>">
            <div id="bulk-metadata-paths"></div>

            <label style="display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--text-main); margin-bottom: 6px;">
                <input type="checkbox" name="apply_alt" value="1" style="margin: 0;">
                <?= _e('Update ALT text') ?>
            </label>
            <input class="form-control" type="text" name="bulk_alt" placeholder="<?= _e('ALT text') ?>" style="margin-bottom: 14px;">

            <label style="display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--text-main); margin-bottom: 6px;">
                <input type="checkbox" name="apply_caption" value="1" style="margin: 0;">
                <?= _e('Update caption') ?>
            </label>
            <input class="form-control" type="text" name="bulk_caption" placeholder="<?= _e('Caption') ?>" style="margin-bottom: 14px;">

            <p style="font-size: 11px; color: var(--text-muted); margin: 0 0 16px;">
                <?= _e('Only checked fields are changed. Leave a field empty to clear it for the selected files.') ?>
            </p>

            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn btn-secondary" onclick="closeBulkMetadataModal()"><?= _e('Cancel') ?></button>
                <button type="submit" class="btn btn-primary"><?= _e('Save') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk transform -->
<div id="bulk-transform-modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.75); z-index: 2000; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(4px);">
    <div class="card" style="width: 100%; max-width: 560px; padding: 24px; background: var(--bg-card, #111827); border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid var(--border-subtle);">
        <h3 style="font-size: 17px; font-weight: 700; margin: 0 0 16px; color: var(--text-main);"><?= _e('Transform selected images') ?></h3>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
            <div class="form-group" style="margin: 0;">
                <label class="form-label" for="bulk-rotate"><?= _e('Rotation') ?></label>
                <select class="form-control" id="bulk-rotate">
                    <option value="0"><?= _e('None') ?></option>
                    <option value="90"><?= _e('90° clockwise') ?></option>
                    <option value="180"><?= _e('180°') ?></option>
                    <option value="270"><?= _e('90° counter-clockwise') ?></option>
                </select>
            </div>
            <div class="form-group" style="margin: 0;">
                <label class="form-label" for="bulk-quality"><?= _e('Quality (JPEG/WebP)') ?></label>
                <input class="form-control" type="number" id="bulk-quality" min="1" max="100" value="82">
            </div>
            <div class="form-group" style="margin: 0;">
                <label class="form-label" for="bulk-max-width"><?= _e('Max width (px, 0 = keep)') ?></label>
                <input class="form-control" type="number" id="bulk-max-width" min="0" value="0">
            </div>
            <div class="form-group" style="margin: 0;">
                <label class="form-label" for="bulk-max-height"><?= _e('Max height (px, 0 = keep)') ?></label>
                <input class="form-control" type="number" id="bulk-max-height" min="0" value="0">
            </div>
        </div>

        <div style="display: flex; gap: 18px; flex-wrap: wrap; margin: 16px 0;">
            <label style="display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--text-main);">
                <input type="checkbox" id="bulk-flip-x" style="margin: 0;"> <?= _e('Flip horizontal') ?>
            </label>
            <label style="display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--text-main);">
                <input type="checkbox" id="bulk-flip-y" style="margin: 0;"> <?= _e('Flip vertical') ?>
            </label>
        </div>

        <label style="display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--text-main); margin-bottom: 8px;">
            <input type="radio" name="bulk-mode" value="overwrite" checked style="margin: 0;">
            <?= _e('Overwrite original files') ?>
        </label>
        <label style="display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--text-main); margin-bottom: 12px;">
            <input type="radio" name="bulk-mode" value="copy" style="margin: 0;">
            <?= _e('Save as new copies') ?>
        </label>

        <p style="font-size: 11px; color: var(--danger, #ef4444); margin: 0 0 16px;">
            <?= _e('Overwriting permanently replaces the originals. GIF/SVG files are skipped.') ?>
        </p>
        <p id="bulk-transform-status" style="font-size: 11px; color: var(--text-muted); margin: 0 0 16px;"></p>

        <div style="display: flex; gap: 10px; justify-content: flex-end;">
            <button type="button" class="btn btn-secondary" onclick="closeBulkTransformModal()"><?= _e('Cancel') ?></button>
            <button type="button" class="btn btn-primary" id="bulk-transform-apply"><?= _e('Apply to selected') ?></button>
        </div>
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
            <input type="hidden" name="return_page" value="<?= (int) $mediaPage ?>">
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
    <input type="hidden" name="return_page" value="<?= (int) $mediaPage ?>">
    <input type="hidden" name="folder_name" id="delete-folder-name" value="">
</form>

<form method="post" id="delete-files-form" style="display: none;">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="media_action" value="delete_files">
    <input type="hidden" name="return_folder" value="<?= htmlspecialchars($returnFolderValue, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="return_page" value="<?= (int) $mediaPage ?>">
</form>

<form method="post" id="delete-all-form" style="display: none;">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="media_action" value="delete_all">
    <input type="hidden" name="return_folder" value="<?= htmlspecialchars($returnFolderValue, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="return_page" value="<?= (int) $mediaPage ?>">
    <input type="hidden" name="view_folder" id="delete-all-folder" value="">
</form>

<div id="image-editor-modal" class="media-editor-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="media-editor-title">
    <div class="media-editor-dialog">
        <div class="media-editor-header">
            <h3 id="media-editor-title" class="media-editor-title"><?= _e('Edit Image') ?></h3>
            <button type="button" class="media-editor-close" onclick="closeImageEditor()" aria-label="<?= _e('Cancel') ?>">&times;</button>
        </div>

        <div class="media-editor-toolbar">
            <button type="button" class="btn btn-secondary media-editor-tool" onclick="editorRotate(-90)">
                <?= _e('Rotate left') ?>
            </button>
            <button type="button" class="btn btn-secondary media-editor-tool" onclick="editorRotate(90)">
                <?= _e('Rotate right') ?>
            </button>
            <button type="button" class="btn btn-secondary media-editor-tool" onclick="editorFlip('x')">
                <?= _e('Flip horizontal') ?>
            </button>
            <button type="button" class="btn btn-secondary media-editor-tool" onclick="editorFlip('y')">
                <?= _e('Flip vertical') ?>
            </button>
            <button type="button" class="btn btn-secondary media-editor-tool" onclick="editorReset()">
                <?= _e('Reset') ?>
            </button>
            <span class="media-editor-separator"></span>
            <label for="editor-ratio" class="media-editor-label"><?= _e('Crop') ?></label>
            <select id="editor-ratio" class="form-control media-editor-select" onchange="editorSetRatio(this.value)">
                <option value="free"><?= _e('Free') ?></option>
                <option value="original"><?= _e('Original') ?></option>
                <option value="1:1">1:1</option>
                <option value="4:3">4:3</option>
                <option value="3:2">3:2</option>
                <option value="16:9">16:9</option>
            </select>
        </div>

        <div class="media-editor-stage">
            <div class="media-editor-cropper-wrap">
                <img id="editor-image" src="" alt="">
            </div>
        </div>

        <div class="media-editor-footer">
            <div class="media-editor-meta">
                <span id="canvas-resolution"></span>
                <span id="editor-format"></span>
            </div>
            <div class="media-editor-actions">
                <button type="button" class="btn btn-secondary" onclick="closeImageEditor()"><?= _e('Cancel') ?></button>
                <button type="button" id="editor-save-copy" class="btn btn-secondary" onclick="saveEditedImage('copy')"><?= _e('Save as copy') ?></button>
                <button type="button" id="editor-save" class="btn btn-primary" onclick="saveEditedImage('overwrite')"><?= _e('Save') ?></button>
            </div>
        </div>
    </div>
</div>

<?php
$mediaToolsItems = [];
foreach ($pageItems as $item) {
    $storedPath = (string) $item['filepath'];
    $mediaToolsItems[] = [
        'url'    => MediaLibrary::urlForStoredPath($storedPath),
        'path'   => $storedPath,
        'name'   => (string) $item['filename'],
        'folder' => MediaLibrary::folderOfStoredPath($storedPath),
        'mime'   => (string) $item['mime_type'],
        'size'   => (int) $item['file_size'],
    ];
}

$mediaToolsCfg = [
    'items'   => $mediaToolsItems,
    'csrf'    => $csrfToken,
    'saveUrl' => 'media-save.php',
    'transformUrl' => 'media-transform.php',
    'i18n'    => [
        'close'                => __('Close'),
        'imagePreview'         => __('Image preview'),
        'zoomIn'               => __('Zoom in'),
        'zoomOut'              => __('Zoom out'),
        'resetZoom'            => __('Reset zoom'),
        'openOriginal'         => __('Open original'),
        'previousImage'        => __('Previous image'),
        'nextImage'            => __('Next image'),
        'edit'                 => __('Edit'),
        'copyUrl'              => __('Copy URL'),
        'editImage'            => __('Edit Image'),
        'deleteSelected'       => __('Delete selected'),
        'deleteSelectedTitle'  => __('Delete selected files?'),
        'deleteSelectedMessage' => __('This will permanently delete the selected files. This action cannot be undone.'),
        'deleteAllTitle'       => __('Delete all files in this folder?'),
        'deleteAllMessage'     => sprintf(__('This will permanently delete all %d file(s) in this folder. This action cannot be undone.'), $viewCount),
        'deleteFailed'         => __('Could not delete the selected files.'),
        'delete'               => __('Delete'),
        'cancel'               => __('Cancel'),
        'selectAtLeastOne'     => __('Select at least one file.'),
        'save'                 => __('Save'),
        'saveAsCopy'           => __('Save as copy'),
        'saving'               => __('Saving...'),
        'replaceTitle'         => __('Replace original image?'),
        'replaceMessage'       => __('The original file will be permanently replaced. This action cannot be undone.'),
        'copyOnlyHint'         => __('This image format can only be saved as a copy.'),
        'imageSaved'           => __('Image saved successfully.'),
        'couldNotLoad'         => __('Could not load the image.'),
        'saveError'            => __('Save Error'),
        'errorMissing'         => __('File not found.'),
        'errorInvalid'         => __('Invalid image.'),
        'errorReadonly'        => __('This image format can only be saved as a copy.'),
        'errorCsrf'            => __('Invalid security token. Please try again.'),
        'errorFailed'          => __('Could not save the image.'),
        'errorTooLarge'        => __('The image is too large to upload.'),
        'linkCopied'           => __('Link copied to clipboard'),
        'root'                 => __('Root'),
    ],
];
$mediaToolsJsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    | JSON_INVALID_UTF8_SUBSTITUTE;
?>
<script>
window.MEDIA_TOOLS_CFG = <?= json_encode($mediaToolsCfg, $mediaToolsJsonFlags) ?>;
</script>
<script src="assets/vendor/cropperjs/cropper.min.js"></script>
<script src="assets/js/media-tools.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/media-tools.js') ?>"></script>
<script>
const MEDIA_CURRENT_FOLDER = <?= json_encode($currentFolder ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const MEDIA_I18N = {
    selected: <?= json_encode(__('selected')) ?>,
    deleteFolderTitle: <?= json_encode(__('Delete Folder')) ?>,
    deleteFolderMessage: <?= json_encode(__('Are you sure you want to delete this folder?')) ?>,
    folderNotEmpty: <?= json_encode(__('Folder is not empty. Move its files first.')) ?>,
    selectAtLeastOne: <?= json_encode(__('Select at least one file.')) ?>,
    deleteSelectedTitle: <?= json_encode(__('Delete selected files?')) ?>,
    deleteSelectedMessage: <?= json_encode(__('This will permanently delete the selected files. This action cannot be undone.')) ?>,
    deleteAllTitle: <?= json_encode(__('Delete all files in this folder?')) ?>,
    deleteAllMessage: <?= json_encode(sprintf(__('This will permanently delete all %d file(s) in this folder. This action cannot be undone.'), $viewCount)) ?>,
    delete: <?= json_encode(__('Delete')) ?>,
    cancel: <?= json_encode(__('Cancel')) ?>
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

function selectedMediaPaths() {
    return Array.from(document.querySelectorAll('.media-select:checked')).map((cb) => cb.value);
}

function fillHiddenPaths(container, paths) {
    if (!container) return;
    container.innerHTML = '';
    paths.forEach((path) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'paths[]';
        input.value = path;
        container.appendChild(input);
    });
}

// ---------------------------------------------------------------------------
// Bulk metadata
// ---------------------------------------------------------------------------
const bulkMetadataModal = document.getElementById('bulk-metadata-modal');
const bulkMetadataPaths = document.getElementById('bulk-metadata-paths');

function openBulkMetadataModal() {
    const paths = selectedMediaPaths();
    if (!paths.length) {
        UI.alert({ title: <?= json_encode(__('Edit selected media')) ?>, message: MEDIA_I18N.selectAtLeastOne, danger: true });
        return;
    }
    fillHiddenPaths(bulkMetadataPaths, paths);
    if (bulkMetadataModal) bulkMetadataModal.style.display = 'flex';
}

function closeBulkMetadataModal() {
    if (bulkMetadataModal) bulkMetadataModal.style.display = 'none';
}

if (bulkMetadataModal) {
    bulkMetadataModal.addEventListener('click', (e) => {
        if (e.target === bulkMetadataModal) closeBulkMetadataModal();
    });
}

// ---------------------------------------------------------------------------
// Bulk transform
// ---------------------------------------------------------------------------
const bulkTransformModal = document.getElementById('bulk-transform-modal');
const bulkTransformApply = document.getElementById('bulk-transform-apply');
const bulkTransformStatus = document.getElementById('bulk-transform-status');

function openBulkTransformModal() {
    if (!selectedMediaPaths().length) {
        UI.alert({ title: <?= json_encode(__('Transform selected images')) ?>, message: MEDIA_I18N.selectAtLeastOne, danger: true });
        return;
    }
    if (bulkTransformModal) bulkTransformModal.style.display = 'flex';
}

function closeBulkTransformModal() {
    if (bulkTransformModal) bulkTransformModal.style.display = 'none';
}

if (bulkTransformModal) {
    bulkTransformModal.addEventListener('click', (e) => {
        if (e.target === bulkTransformModal) closeBulkTransformModal();
    });
}

if (bulkTransformApply) {
    bulkTransformApply.addEventListener('click', async () => {
        const paths = selectedMediaPaths();
        if (!paths.length) return;

        const modeInput = document.querySelector('input[name="bulk-mode"]:checked');
        const mode = modeInput ? modeInput.value : 'overwrite';
        if (mode === 'overwrite') {
            const ok = await UI.confirm({
                title: <?= json_encode(__('Overwrite original files?')) ?>,
                message: <?= json_encode(__('Selected images will be permanently overwritten. This action cannot be undone.')) ?>,
                okText: <?= json_encode(__('Overwrite')) ?>,
                cancelText: MEDIA_I18N.cancel,
                danger: true
            });
            if (!ok) return;
        }

        if (!window.ModoMediaBulk || typeof window.ModoMediaBulk.transform !== 'function') {
            UI.alert({ title: <?= json_encode(__('Transform selected images')) ?>, message: MEDIA_I18N.selectAtLeastOne, danger: true });
            return;
        }

        const options = {
            rotate: parseInt(document.getElementById('bulk-rotate')?.value || '0', 10) || 0,
            flipX: !!document.getElementById('bulk-flip-x')?.checked,
            flipY: !!document.getElementById('bulk-flip-y')?.checked,
            maxWidth: Math.max(0, parseInt(document.getElementById('bulk-max-width')?.value || '0', 10) || 0),
            maxHeight: Math.max(0, parseInt(document.getElementById('bulk-max-height')?.value || '0', 10) || 0),
            quality: Math.min(100, Math.max(1, parseInt(document.getElementById('bulk-quality')?.value || '82', 10) || 82)),
            mode
        };

        bulkTransformApply.disabled = true;
        if (bulkTransformStatus) bulkTransformStatus.textContent = <?= json_encode(__('Processing...')) ?>;

        try {
            const result = await window.ModoMediaBulk.transform(paths, options, (done, total, item) => {
                if (bulkTransformStatus) {
                    bulkTransformStatus.textContent = done + ' / ' + total + (item && item.error ? ' · ' + item.error : '');
                }
            });
            const failed = result.failed || 0;
            UI.alert({
                title: <?= json_encode(__('Transform selected images')) ?>,
                message: <?= json_encode(__('Processed')) ?> + ': ' + (result.done || 0) + ' / ' + (result.total || 0) + (failed ? ' · ' + <?= json_encode(__('errors')) ?> + ': ' + failed : ''),
                danger: failed > 0
            });
            window.location.reload();
        } catch (error) {
            if (bulkTransformStatus) bulkTransformStatus.textContent = <?= json_encode(__('The operation could not be completed.')) ?>;
            bulkTransformApply.disabled = false;
        }
    });
}

// ---------------------------------------------------------------------------
// Bulk thumbnail regeneration
// ---------------------------------------------------------------------------
async function regenerateSelectedThumbnails() {
    const paths = selectedMediaPaths();
    if (!paths.length) {
        UI.alert({ title: <?= json_encode(__('Regenerate thumbnails')) ?>, message: MEDIA_I18N.selectAtLeastOne, danger: true });
        return;
    }

    const csrf = <?= json_encode($csrfToken) ?>;
    let offset = 0;
    let done = false;
    let failed = 0;
    bulkCount.textContent = <?= json_encode(__('Generating thumbnails...')) ?>;

    while (!done) {
        const body = new URLSearchParams();
        body.set('csrf', csrf);
        body.set('scope', 'selection');
        body.set('force', '1');
        body.set('paths', JSON.stringify(paths));
        body.set('offset', String(offset));
        body.set('limit', '5');

        try {
            const response = await fetch('thumbs-backfill.php', { method: 'POST', body });
            const data = await response.json();
            if (!data.ok) throw new Error(data.error || 'failed');
            offset = Number(data.next) || 0;
            done = !!data.done;
            failed += Number(data.failed) || 0;
            bulkCount.textContent = offset + ' / ' + data.total + (failed > 0 ? ' · errors: ' + failed : '');
        } catch (error) {
            UI.alert({ title: <?= json_encode(__('Regenerate thumbnails')) ?>, message: <?= json_encode(__('Thumbnail generation failed.')) ?>, danger: true });
            return;
        }
    }

    UI.alert({ title: <?= json_encode(__('Regenerate thumbnails')) ?>, message: <?= json_encode(__('Thumbnails generated.')) ?>, danger: failed > 0 });
    window.location.reload();
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
// Bulk deletion
// ---------------------------------------------------------------------------
const deleteFilesForm = document.getElementById('delete-files-form');
const deleteAllForm = document.getElementById('delete-all-form');
const deleteAllFolderInput = document.getElementById('delete-all-folder');

function deleteSelectedFiles() {
    const paths = Array.from(document.querySelectorAll('.media-select:checked')).map((cb) => cb.value);
    if (!paths.length) {
        UI.alert({ title: MEDIA_I18N.deleteSelectedTitle, message: MEDIA_I18N.selectAtLeastOne, danger: true });
        return;
    }
    if (!deleteFilesForm) return;

    UI.confirm({
        title: MEDIA_I18N.deleteSelectedTitle,
        message: MEDIA_I18N.deleteSelectedMessage,
        okText: MEDIA_I18N.delete,
        cancelText: MEDIA_I18N.cancel,
        danger: true
    }).then((ok) => {
        if (!ok) return;

        deleteFilesForm.querySelectorAll('input[name="paths[]"]').forEach((input) => input.remove());
        paths.forEach((path) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'paths[]';
            input.value = path;
            deleteFilesForm.appendChild(input);
        });
        deleteFilesForm.submit();
    });
}

function deleteAllFiles(folder) {
    if (!deleteAllForm || !deleteAllFolderInput) return;

    UI.confirm({
        title: MEDIA_I18N.deleteAllTitle,
        message: MEDIA_I18N.deleteAllMessage,
        okText: MEDIA_I18N.delete,
        cancelText: MEDIA_I18N.cancel,
        danger: true
    }).then((ok) => {
        if (!ok) return;
        deleteAllFolderInput.value = folder || 'root';
        deleteAllForm.submit();
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
        formData.append('csrf', window.MODO_CSRF || '');

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
        let failed = 0;
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
                failed += Number(data.failed) || 0;
                const percent = data.total > 0 ? Math.min(100, Math.round((offset / data.total) * 100)) : 100;
                if (bar) bar.style.width = percent + '%';
                if (status) status.textContent = offset + ' / ' + data.total + (failed > 0 ? ' · errors: ' + failed : '');
            } catch (error) {
                if (status) status.textContent = <?= json_encode(__('Thumbnail generation failed.')) ?>;
                btn.disabled = false;
                return;
            }
        }

        if (status) status.textContent = <?= json_encode(__('Thumbnails generated.')) ?> + (failed > 0 ? ' · errors: ' + failed : '');
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
