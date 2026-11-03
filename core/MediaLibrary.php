<?php
declare(strict_types=1);

namespace Core;

/**
 * Filesystem-backed media library helpers.
 *
 * Folders are real directories directly below /uploads (one level). This class
 * centralises validation, listing, moves and URL-reference rewriting so the
 * admin, page editor, customizer and plugins all behave the same way.
 */
final class MediaLibrary
{
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'];
    public const RESERVED_FOLDERS = ['cache', 'thumbs', 'root', 'all', '__all__'];

    /** Widths (in pixels) accepted by the thumbnail endpoint. */
    public const THUMBNAIL_WIDTHS = [80, 160, 240, 320];

    /** Widths generated eagerly for the admin previews. */
    public const THUMBNAIL_PREVIEW_WIDTHS = [80, 160, 240];

    /** Per-request cache for filesystem listings. */
    private static array $listImagesCache = [];

    /** Fallback transliteration for Polish names when iconv is unavailable. */
    private const TRANSLITERATION = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
        'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N',
        'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
    ];

    /** Absolute path to the uploads directory. */
    public static function rootPath(): string
    {
        return dirname(__DIR__) . '/uploads';
    }

    /** Public base path (honours installs in a sub-directory such as /cms). */
    public static function baseUrl(): string
    {
        $base = class_exists(Router::class) ? Router::getBaseSubdirectory() : '';
        return rtrim($base, '/');
    }

    /**
     * Converts a user supplied folder name into a filesystem-safe slug.
     * Returns null for empty / reserved names.
     */
    public static function normalizeFolderName(string $name): ?string
    {
        $name = trim($name);
        if ($name === '' || str_contains($name, "\0") || strpbrk($name, "/\\") !== false) {
            return null;
        }

        $name = strtr($name, self::TRANSLITERATION);

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            if ($converted !== false) {
                $name = $converted;
            }
        }

        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9_-]+/', '-', $name) ?? '';
        $name = preg_replace('/-{2,}/', '-', $name) ?? '';
        $name = trim($name, '-_');

        if ($name === '' || strlen($name) > 64) {
            return null;
        }
        if (in_array($name, self::RESERVED_FOLDERS, true)) {
            return null;
        }

        return $name;
    }

    /**
     * Resolves an existing folder name to its absolute path. Unlike
     * normalizeFolderName() this accepts legacy names (spaces, uppercase) so
     * folders created before this feature keep working.
     */
    public static function resolveFolder(string $folder): ?string
    {
        if ($folder === '' || $folder === '.' || $folder === '..' || str_contains($folder, "\0")) {
            return null;
        }
        if (strpbrk($folder, "/\\") !== false) {
            return null;
        }
        if (in_array(strtolower($folder), self::RESERVED_FOLDERS, true)) {
            return null;
        }

        $root = realpath(self::rootPath());
        if ($root === false) {
            return null;
        }

        $path = self::rootPath() . '/' . $folder;
        if (is_link($path) || !is_dir($path)) {
            return null;
        }

        $real = realpath($path);
        if ($real === false || dirname($real) !== $root) {
            return null;
        }

        return $real;
    }

    /**
     * Returns the first-level folder name stored in a media filepath.
     * Root files return an empty string.
     */
    public static function folderOfStoredPath(string $storedPath): string
    {
        $relative = ltrim(str_replace('\\', '/', $storedPath), '/');
        if (str_starts_with($relative, 'uploads/')) {
            $relative = substr($relative, strlen('uploads/'));
        }
        $parts = array_values(array_filter(explode('/', $relative), static fn (string $part): bool => $part !== ''));

        return count($parts) > 1 ? $parts[0] : '';
    }

    /** Converts a stored "/uploads/..." path into a public URL. */
    public static function urlForStoredPath(string $storedPath): string
    {
        if (preg_match('#^https?://#i', $storedPath) === 1) {
            return $storedPath;
        }

        $path = parse_url($storedPath, PHP_URL_PATH) ?: $storedPath;
        $relative = ltrim(str_replace('\\', '/', $path), '/');
        if (!str_starts_with($relative, 'uploads/')) {
            return $storedPath;
        }

        $segments = array_map('rawurlencode', explode('/', $relative));
        return self::baseUrl() . '/' . implode('/', $segments);
    }

    /**
     * Lists all folders directly below /uploads with their image counts.
     *
     * @return array<int,array{name:string,count:int,path:string}>
     */
    public static function folders(): array
    {
        $root = self::rootPath();
        if (!is_dir($root)) {
            return [];
        }

        // Count every folder from a single listing instead of rescanning each
        // directory (important when the library contains hundreds of files).
        $counts = [];
        foreach (self::listImages(null, true) as $item) {
            $folderName = (string) ($item['folder'] ?? '');
            if ($folderName !== '') {
                $counts[$folderName] = ($counts[$folderName] ?? 0) + 1;
            }
        }

        $folders = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '' || $entry[0] === '.') {
                continue;
            }
            if (in_array(strtolower($entry), self::RESERVED_FOLDERS, true)) {
                continue;
            }

            $path = $root . '/' . $entry;
            if (is_link($path) || !is_dir($path)) {
                continue;
            }

            $real = realpath($path);
            $realRoot = realpath($root);
            if ($real === false || $realRoot === false || dirname($real) !== $realRoot) {
                continue;
            }

            $folders[] = [
                'name'  => $entry,
                'count' => (int) ($counts[$entry] ?? 0),
                'path'  => self::urlForStoredPath('/uploads/' . $entry),
            ];
        }

        usort($folders, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return $folders;
    }

    /**
     * Lists image files.
     *
     * @param string|null $folder null = all files, "" = root only, otherwise one folder
     * @param bool        $recursive include nested sub-directories
     * @return array<int,array{name:string,path:string,url:string,folder:string,mtime:int,absolute:string}>
     */
    public static function listImages(?string $folder = null, bool $recursive = true): array
    {
        $cacheKey = ($folder ?? "\0all") . '|' . ($recursive ? '1' : '0');
        if (isset(self::$listImagesCache[$cacheKey])) {
            return self::$listImagesCache[$cacheKey];
        }

        $root = self::rootPath();
        if (!is_dir($root)) {
            return self::$listImagesCache[$cacheKey] = [];
        }

        $items = [];
        if ($folder === null) {
            self::collectImages($root, '', $items, $recursive);
        } elseif ($folder === '') {
            self::collectImages($root, '', $items, false);
        } else {
            $dir = self::resolveFolder($folder);
            if ($dir !== null) {
                self::collectImages($dir, $folder, $items, $recursive);
            }
        }

        usort($items, static function (array $a, array $b): int {
            return ($b['mtime'] <=> $a['mtime']) ?: strnatcasecmp($a['name'], $b['name']);
        });

        return self::$listImagesCache[$cacheKey] = $items;
    }

    /** @param array<int,array<string,mixed>> $items */
    private static function collectImages(string $dir, string $prefix, array &$items, bool $recurse): void
    {
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '' || $entry[0] === '.') {
                continue;
            }
            if ($prefix === '' && in_array(strtolower($entry), self::RESERVED_FOLDERS, true)) {
                continue;
            }

            $absolute = $dir . '/' . $entry;
            if (is_link($absolute)) {
                continue;
            }

            $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;

            if (is_dir($absolute)) {
                if ($recurse) {
                    self::collectImages($absolute, $relative, $items, true);
                }
                continue;
            }
            if (!is_file($absolute)) {
                continue;
            }

            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
                continue;
            }
            // Generated WebP siblings are not separate library assets.
            if ($ext === 'webp' && self::hasSourceSibling($absolute)) {
                continue;
            }

            $storedPath = '/uploads/' . $relative;
            $items[] = [
                'name'     => $entry,
                'path'     => $storedPath,
                'url'      => self::urlForStoredPath($storedPath),
                'folder'   => self::folderOfStoredPath($storedPath),
                'mtime'    => (int) @filemtime($absolute),
                'absolute' => $absolute,
            ];
        }
    }

    /**
     * Inserts media rows for images found on disk but missing from the media
     * table (for example files copied over FTP). Generated WebP siblings are
     * skipped automatically.
     */
    public static function syncMissingMedia(?int $userId = null): int
    {
        try {
            $db = Database::getConnection();
            $existing = [];
            $generatedWebp = [];
            foreach ($db->query("SELECT filepath, webp_path FROM media") as $row) {
                $existing[(string) $row['filepath']] = true;
                if (!empty($row['webp_path'])) {
                    $generatedWebp[(string) $row['webp_path']] = true;
                }
            }

            $insert = $db->prepare("
                INSERT INTO media (filename, filepath, mime_type, file_size, webp_path, user_id)
                VALUES (:name, :path, :mime, :size, :webp, :user)
            ");

            $added = 0;
            foreach (self::listImages(null, true) as $item) {
                $stored = (string) $item['path'];
                if (isset($existing[$stored]) || isset($generatedWebp[$stored])) {
                    continue;
                }

                $ext = strtolower(pathinfo((string) $item['name'], PATHINFO_EXTENSION));
                if ($ext === 'webp' && self::hasSourceSibling((string) $item['absolute'])) {
                    continue;
                }

                $webpStored = null;
                if ($ext !== 'webp') {
                    $webpCandidate = preg_replace('/\.[^.]+$/', '', (string) $item['absolute']) . '.webp';
                    if (is_file($webpCandidate)) {
                        $webpStored = self::storedPathFromAbsolute($webpCandidate);
                    }
                }

                try {
                    $insert->execute([
                        ':name' => (string) $item['name'],
                        ':path' => $stored,
                        ':mime' => self::mimeType((string) $item['absolute'], $ext),
                        ':size' => (int) @filesize((string) $item['absolute']),
                        ':webp' => $webpStored,
                        ':user' => $userId,
                    ]);
                    $existing[$stored] = true;
                    $added++;
                } catch (\Throwable $e) {
                    // Skip this file, keep syncing the rest of the library.
                }
            }

            return $added;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function hasSourceSibling(string $absoluteWebp): bool
    {
        $base = preg_replace('/\.[^.]+$/', '', $absoluteWebp);
        foreach (['jpg', 'jpeg', 'png'] as $ext) {
            if (is_file($base . '.' . $ext)) {
                return true;
            }
        }
        return false;
    }

    private static function mimeType(string $absolute, string $ext): string
    {
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = @$finfo->file($absolute);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            'svg'         => 'image/svg+xml',
            'avif'        => 'image/avif',
            default       => 'application/octet-stream',
        };
    }

    /** @return array{ok:bool,error?:string,name?:string} */
    public static function createFolder(string $name): array
    {
        $normalized = self::normalizeFolderName($name);
        if ($normalized === null) {
            return ['ok' => false, 'error' => 'invalid'];
        }

        $path = self::rootPath() . '/' . $normalized;
        if (file_exists($path)) {
            return ['ok' => false, 'error' => 'exists'];
        }

        if (!@mkdir($path, 0775, true)) {
            return ['ok' => false, 'error' => 'failed'];
        }

        return ['ok' => true, 'name' => $normalized];
    }

    /** @return array{ok:bool,error?:string,name?:string} */
    public static function renameFolder(string $oldName, string $newName): array
    {
        $oldPath = self::resolveFolder($oldName);
        if ($oldPath === null) {
            return ['ok' => false, 'error' => 'missing'];
        }

        $normalized = self::normalizeFolderName($newName);
        if ($normalized === null) {
            return ['ok' => false, 'error' => 'invalid'];
        }

        $oldBase = basename($oldPath);
        $newPath = self::rootPath() . '/' . $normalized;
        if ($oldBase === $normalized) {
            return ['ok' => true, 'name' => $normalized];
        }
        if (file_exists($newPath)) {
            return ['ok' => false, 'error' => 'exists'];
        }

        if (!@rename($oldPath, $newPath)) {
            return ['ok' => false, 'error' => 'failed'];
        }

        $oldPrefix = '/uploads/' . $oldBase . '/';
        $newPrefix = '/uploads/' . $normalized . '/';

        try {
            $db = Database::getConnection();
            $started = !$db->inTransaction();
            if ($started) {
                $db->beginTransaction();
            }

            $rows = $db->prepare("
                SELECT id, filepath, webp_path FROM media
                WHERE substr(filepath, 1, length(:prefix)) = :prefix
            ");
            $rows->execute([':prefix' => $oldPrefix]);

            $update = $db->prepare("UPDATE media SET filepath = :path, webp_path = :webp WHERE id = :id");
            foreach ($rows->fetchAll() as $row) {
                $filepath = (string) $row['filepath'];
                $webp = (string) ($row['webp_path'] ?? '');
                $newFilepath = $newPrefix . substr($filepath, strlen($oldPrefix));
                $update->execute([
                    ':path' => $newFilepath,
                    ':webp' => str_starts_with($webp, $oldPrefix)
                        ? $newPrefix . substr($webp, strlen($oldPrefix))
                        : ($webp !== '' ? $webp : null),
                    ':id'   => (int) $row['id'],
                ]);
                self::deleteThumbnails($filepath);
                self::deleteThumbnails($newFilepath);
            }

            self::replaceReferences($oldPrefix, $newPrefix);

            if ($started) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if (isset($db, $started) && $started && $db->inTransaction()) {
                $db->rollBack();
            }
            @rename($newPath, $oldPath);
            return ['ok' => false, 'error' => 'failed'];
        }

        self::purgeCache();

        return ['ok' => true, 'name' => $normalized];
    }

    /** @return array{ok:bool,error?:string} */
    public static function deleteFolder(string $name): array
    {
        $path = self::resolveFolder($name);
        if ($path === null) {
            return ['ok' => false, 'error' => 'missing'];
        }

        $entries = array_values(array_diff(scandir($path) ?: [], ['.', '..']));
        if ($entries !== []) {
            return ['ok' => false, 'error' => 'not_empty'];
        }

        if (!@rmdir($path)) {
            return ['ok' => false, 'error' => 'failed'];
        }

        return ['ok' => true];
    }

    /**
     * Moves a single media file (and its generated WebP sibling) to a folder.
     *
     * @param string|null $targetFolder null = root
     * @return array{ok:bool,error?:string,path?:string,url?:string}
     */
    public static function moveFile(string $storedPath, ?string $targetFolder): array
    {
        $source = self::absoluteFromStoredPath($storedPath);
        if ($source === null) {
            return ['ok' => false, 'error' => 'missing'];
        }

        $root = realpath(self::rootPath());
        if ($root === false) {
            return ['ok' => false, 'error' => 'failed'];
        }

        if ($targetFolder === null || $targetFolder === '' || $targetFolder === 'root') {
            $targetDir = $root;
            $targetNameBase = '';
        } else {
            $targetDir = self::resolveFolder($targetFolder);
            if ($targetDir === null) {
                return ['ok' => false, 'error' => 'invalid'];
            }
            $targetNameBase = basename($targetDir);
        }

        if (dirname($source) === $targetDir) {
            return ['ok' => true, 'path' => $storedPath, 'url' => self::urlForStoredPath($storedPath)];
        }

        $oldStored = self::storedPathFromAbsolute($source);
        if ($oldStored === null) {
            return ['ok' => false, 'error' => 'missing'];
        }

        $targetName = self::uniqueName($targetDir, basename($source));
        $destination = $targetDir . '/' . $targetName;

        $webpSource = null;
        $webpOldStored = null;
        $row = null;
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT id, filepath, webp_path FROM media WHERE filepath = :path LIMIT 1");
            $stmt->execute([':path' => $oldStored]);
            $row = $stmt->fetch() ?: null;

            if ($row !== null && !empty($row['webp_path'])) {
                $webpOldStored = (string) $row['webp_path'];
                $webpSource = self::absoluteFromStoredPath($webpOldStored);
            }
        } catch (\Throwable $e) {
            $row = null;
        }

        if ($webpSource === null && strtolower(pathinfo($source, PATHINFO_EXTENSION)) !== 'webp') {
            $candidate = preg_replace('/\.[^.]+$/', '', $source) . '.webp';
            if (is_file($candidate)) {
                $webpSource = $candidate;
                $webpOldStored = self::storedPathFromAbsolute($candidate);
            }
        }
        if ($webpSource !== null && realpath($webpSource) === realpath($source)) {
            $webpSource = null;
            $webpOldStored = null;
        }

        if (!@rename($source, $destination)) {
            return ['ok' => false, 'error' => 'failed'];
        }

        $newStored = '/uploads/' . ($targetNameBase !== '' ? $targetNameBase . '/' : '') . $targetName;
        $newWebpStored = null;
        $webpDestination = null;

        if ($webpSource !== null) {
            $webpName = preg_replace('/\.[^.]+$/', '', $targetName) . '.webp';
            $webpDestination = $targetDir . '/' . self::uniqueName($targetDir, $webpName);
            if (!@rename($webpSource, $webpDestination)) {
                @rename($destination, $source);
                return ['ok' => false, 'error' => 'failed'];
            }
            $newWebpStored = '/uploads/' . ($targetNameBase !== '' ? $targetNameBase . '/' : '') . basename($webpDestination);
        }

        try {
            $db = Database::getConnection();
            $started = !$db->inTransaction();
            if ($started) {
                $db->beginTransaction();
            }

            $update = $db->prepare("UPDATE media SET filepath = :path, webp_path = :webp WHERE filepath = :old");
            $update->execute([
                ':path' => $newStored,
                ':webp' => $newWebpStored,
                ':old'  => $oldStored,
            ]);

            self::replaceReferences($oldStored, $newStored);
            if ($webpOldStored !== null && $newWebpStored !== null) {
                self::replaceReferences($webpOldStored, $newWebpStored);
            }

            if ($started) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if (isset($db, $started) && $started && $db->inTransaction()) {
                $db->rollBack();
            }
            @rename($destination, $source);
            if ($webpDestination !== null && $webpSource !== null) {
                @rename($webpDestination, $webpSource);
            }
            return ['ok' => false, 'error' => 'failed'];
        }

        self::deleteThumbnails($oldStored);
        self::deleteThumbnails($newStored);

        self::purgeCache();

        return ['ok' => true, 'path' => $newStored, 'url' => self::urlForStoredPath($newStored)];
    }

    /** @return array{ok:bool,error?:string} */
    public static function deleteFile(string $storedPath): array
    {
        $result = self::deleteFileInternal($storedPath);
        self::purgeCache();

        return $result;
    }

    /**
     * Deletes several media files and clears the page cache only once.
     *
     * @param array<int,string> $paths
     * @return array{deleted:int,failed:int}
     */
    public static function deleteFiles(array $paths): array
    {
        $deleted = 0;
        $failed = 0;

        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $result = self::deleteFileInternal($path);
            if (!empty($result['ok'])) {
                $deleted++;
            } else {
                $failed++;
            }
        }

        self::purgeCache();

        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /** @return array{ok:bool,error?:string} */
    private static function deleteFileInternal(string $storedPath): array
    {
        $absolute = self::absoluteFromStoredPath($storedPath);
        if ($absolute === null) {
            // The file may already be gone; clean up a stale database row.
            self::deleteThumbnails($storedPath);
            try {
                $db = Database::getConnection();
                $stmt = $db->prepare("DELETE FROM media WHERE filepath = :path");
                $stmt->execute([':path' => $storedPath]);
                if ($stmt->rowCount() > 0) {
                    return ['ok' => true];
                }
            } catch (\Throwable $e) {
                // Fall through to the missing-file error.
            }
            return ['ok' => false, 'error' => 'missing'];
        }

        $webpAbsolute = null;
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT webp_path FROM media WHERE filepath = :path LIMIT 1");
            $stmt->execute([':path' => $storedPath]);
            $webp = (string) ($stmt->fetchColumn() ?: '');
            if ($webp !== '') {
                $webpAbsolute = self::absoluteFromStoredPath($webp);
            }
        } catch (\Throwable $e) {
            $webpAbsolute = null;
        }

        if ($webpAbsolute === null && strtolower(pathinfo($absolute, PATHINFO_EXTENSION)) !== 'webp') {
            $candidate = preg_replace('/\.[^.]+$/', '', $absolute) . '.webp';
            if (is_file($candidate)) {
                $webpAbsolute = $candidate;
            }
        }

        if (!@unlink($absolute)) {
            return ['ok' => false, 'error' => 'failed'];
        }
        if ($webpAbsolute !== null && is_file($webpAbsolute)) {
            @unlink($webpAbsolute);
        }

        self::deleteThumbnails($storedPath);

        try {
            $db = Database::getConnection();
            $db->prepare("DELETE FROM media WHERE filepath = :path")->execute([':path' => $storedPath]);
        } catch (\Throwable $e) {
            // The file is already gone; leaving a stale row is preferable to
            // blocking the deletion flow.
        }

        return ['ok' => true];
    }

    /**
     * Saves a browser-edited image, either replacing the original file or
     * creating a sibling copy, and refreshes its media record and thumbnails.
     *
     * @return array{ok:bool,error?:string,path?:string,url?:string,filename?:string}
     */
    public static function saveEditedFile(
        string $storedPath,
        string $sourceTmp,
        string $mime,
        bool $overwrite = true,
        ?int $userId = null
    ): array {
        $source = self::absoluteFromStoredPath($storedPath);
        if ($source === null || !is_file($sourceTmp)) {
            return ['ok' => false, 'error' => 'missing'];
        }

        $sourceStored = self::storedPathFromAbsolute($source);
        if ($sourceStored === null) {
            return ['ok' => false, 'error' => 'invalid'];
        }

        $extension = self::extensionForMime($mime);
        if ($extension === null) {
            return ['ok' => false, 'error' => 'invalid'];
        }

        $sourceExtension = strtolower((string) pathinfo($source, PATHINFO_EXTENSION));
        if ($overwrite) {
            if (!in_array($sourceExtension, ['jpg', 'jpeg', 'png', 'webp'], true)
                || !self::mimeMatchesExtension($mime, $sourceExtension)
            ) {
                return ['ok' => false, 'error' => 'readonly'];
            }
        }

        $directory = dirname($source);
        $baseName = (string) pathinfo($source, PATHINFO_FILENAME);

        if ($overwrite) {
            $targetName = basename($source);
            $destination = $source;
            $targetStored = $sourceStored;
        } else {
            $targetName = self::uniqueName($directory, $baseName . '-edited.' . $extension);
            $destination = $directory . '/' . $targetName;
            $targetStored = self::storedPathForAbsolute($destination);
            if ($targetStored === null) {
                return ['ok' => false, 'error' => 'invalid'];
            }
        }

        $oldWebpStored = null;
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT webp_path FROM media WHERE filepath = :path LIMIT 1");
            $stmt->execute([':path' => $targetStored]);
            $oldWebp = (string) ($stmt->fetchColumn() ?: '');
            if ($oldWebp !== '') {
                $oldWebpStored = $oldWebp;
            }
        } catch (\Throwable $e) {
            $oldWebpStored = null;
        }

        // Write to a temporary file in the same directory, then rename it over
        // the destination so a failed upload never leaves a half-written image.
        $tmp = $directory . '/.' . $targetName . '.tmp-' . bin2hex(random_bytes(6));
        if (!@copy($sourceTmp, $tmp) || !is_file($tmp) || (int) @filesize($tmp) <= 0) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            return ['ok' => false, 'error' => 'failed'];
        }
        @chmod($tmp, 0664);
        if (!@rename($tmp, $destination)) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'failed'];
        }
        @chmod($destination, 0664);
        clearstatcache(true, $destination);

        $webpStored = null;
        $canGenerateWebp = in_array($extension, ['jpg', 'png'], true)
            && class_exists(ImageOptimizer::class)
            && ImageOptimizer::isEnabled()
            && ImageOptimizer::isSupported();

        if ($canGenerateWebp) {
            $converted = self::regenerateWebpSibling($destination);
            if ($converted !== null) {
                $webpStored = self::storedPathForAbsolute($converted);
            } else {
                self::removeStaleWebpSibling($destination, $overwrite ? $oldWebpStored : null);
            }
        } else {
            self::removeStaleWebpSibling($destination, $overwrite ? $oldWebpStored : null);
        }

        $fileSize = (int) @filesize($destination);
        $filename = basename($destination);

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT id, filename FROM media WHERE filepath = :path LIMIT 1");
            $stmt->execute([':path' => $targetStored]);
            $existingRow = $stmt->fetch() ?: null;

            if ($existingRow !== null) {
                // Overwriting keeps the user-facing upload name; the stored
                // filename is usually a random slug generated by upload.php.
                $displayName = $overwrite && trim((string) ($existingRow['filename'] ?? '')) !== ''
                    ? (string) $existingRow['filename']
                    : $filename;

                $update = $db->prepare("
                    UPDATE media
                    SET filename = :name, mime_type = :mime, file_size = :size, webp_path = :webp
                    WHERE id = :id
                ");
                $update->execute([
                    ':name' => $displayName,
                    ':mime' => $mime,
                    ':size' => $fileSize,
                    ':webp' => $webpStored,
                    ':id'   => (int) $existingRow['id'],
                ]);
            } else {
                $insert = $db->prepare("
                    INSERT INTO media (filename, filepath, mime_type, file_size, webp_path, user_id)
                    VALUES (:name, :path, :mime, :size, :webp, :user)
                ");
                $insert->execute([
                    ':name' => $filename,
                    ':path' => $targetStored,
                    ':mime' => $mime,
                    ':size' => $fileSize,
                    ':webp' => $webpStored,
                    ':user' => $userId,
                ]);
            }
        } catch (\Throwable $e) {
            // The file is already saved; a stale media row must not undo it.
        }

        try {
            self::deleteThumbnails($targetStored);
            self::ensureThumbnails($targetStored, self::THUMBNAIL_PREVIEW_WIDTHS);
        } catch (\Throwable $e) {
            // Thumbnails are an optimisation and can be regenerated later.
        }

        self::purgeCache();

        return [
            'ok'       => true,
            'path'     => $targetStored,
            'url'      => self::urlForStoredPath($targetStored),
            'filename' => $filename,
        ];
    }

    private static function extensionForMime(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            default      => null,
        };
    }

    private static function mimeMatchesExtension(string $mime, string $extension): bool
    {
        $extension = strtolower($extension);

        return match ($mime) {
            'image/jpeg' => in_array($extension, ['jpg', 'jpeg'], true),
            'image/png'  => $extension === 'png',
            'image/webp' => $extension === 'webp',
            default      => false,
        };
    }

    private static function regenerateWebpSibling(string $absolute): ?string
    {
        if (!class_exists(ImageOptimizer::class)) {
            return null;
        }

        $sibling = preg_replace('/\.[^.]+$/', '', $absolute) . '.webp';
        $tmp = $sibling . '.tmp-' . bin2hex(random_bytes(6));

        try {
            $converted = ImageOptimizer::convertToWebp($absolute, $tmp);
        } catch (\Throwable $e) {
            $converted = null;
        }

        if ($converted === null || !is_file($tmp) || (int) @filesize($tmp) <= 0) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            return null;
        }

        @chmod($tmp, 0664);
        if (!@rename($tmp, $sibling)) {
            @unlink($tmp);
            return null;
        }

        return $sibling;
    }

    private static function removeStaleWebpSibling(string $absolute, ?string $storedWebp = null): void
    {
        $candidates = [];
        if ($storedWebp !== null && $storedWebp !== '') {
            $resolved = self::absoluteFromStoredPath($storedWebp);
            if ($resolved !== null) {
                $candidates[] = $resolved;
            }
        }

        if (strtolower((string) pathinfo($absolute, PATHINFO_EXTENSION)) !== 'webp') {
            $candidates[] = preg_replace('/\.[^.]+$/', '', $absolute) . '.webp';
        }

        $sourceReal = realpath($absolute) ?: $absolute;
        foreach (array_unique($candidates) as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }
            $candidateReal = realpath($candidate) ?: $candidate;
            if ($candidateReal === $sourceReal) {
                continue;
            }
            @unlink($candidate);
        }
    }

    private static function purgeCache(): void
    {
        if (!class_exists(PageCache::class)) {
            return;
        }
        try {
            PageCache::purge();
        } catch (\Throwable $e) {
            // Cache purging is an optimisation; never block media operations.
        }
    }

    private static function absoluteFromStoredPath(string $storedPath): ?string
    {
        $path = parse_url($storedPath, PHP_URL_PATH) ?: $storedPath;
        $relative = ltrim(str_replace('\\', '/', $path), '/');
        if (!str_starts_with($relative, 'uploads/')) {
            return null;
        }

        $root = realpath(self::rootPath());
        if ($root === false) {
            return null;
        }

        $absolute = dirname($root) . '/' . $relative;
        $real = realpath($absolute);
        if ($real === false || !is_file($real)) {
            return null;
        }
        if ($real !== $root && !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    private static function storedPathFromAbsolute(string $absolute): ?string
    {
        $root = realpath(self::rootPath());
        $real = realpath($absolute);
        if ($root === false || $real === false) {
            return null;
        }
        if (!str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return '/uploads/' . str_replace('\\', '/', substr($real, strlen($root) + 1));
    }

    /**
     * Converts an absolute uploads path to its stored form. Unlike
     * storedPathFromAbsolute() this also works for files that do not exist yet
     * (needed when saving an edited copy).
     */
    private static function storedPathForAbsolute(string $absolute): ?string
    {
        $root = realpath(self::rootPath());
        if ($root === false) {
            return null;
        }

        $normalized = str_replace('\\', '/', $absolute);
        $directory = realpath(dirname($normalized));
        $name = basename($normalized);
        if ($directory === false || $name === '' || $name === '.' || $name === '..') {
            return null;
        }
        if ($directory !== $root && !str_starts_with($directory, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return '/uploads/' . str_replace('\\', '/', substr($directory . '/' . $name, strlen($root) + 1));
    }

    private static function uniqueName(string $directory, string $filename): string
    {
        $candidate = $filename;
        if (!file_exists($directory . '/' . $candidate)) {
            return $candidate;
        }

        $info = pathinfo($filename);
        $base = (string) ($info['filename'] ?? $filename);
        $ext = isset($info['extension']) && $info['extension'] !== '' ? '.' . $info['extension'] : '';

        $i = 2;
        do {
            $candidate = $base . '-' . $i . $ext;
            $i++;
        } while (file_exists($directory . '/' . $candidate));

        return $candidate;
    }

    /**
     * Rewrites stored media URLs after a file/folder move.
     *
     * Handles plain paths, JSON-escaped slashes and percent-encoded variants.
     */
    public static function replaceReferences(string $old, string $new): void
    {
        if ($old === '' || $new === '' || $old === $new) {
            return;
        }

        $oldVariants = self::pathVariants($old);
        $newVariants = self::pathVariants($new);

        try {
            $db = Database::getConnection();

            $targets = [
                ['pages', 'content'],
                ['pages', 'featured_image'],
                ['pages', 'meta_title'],
                ['pages', 'meta_description'],
                ['page_meta', 'meta_value'],
                ['page_revisions', 'content'],
                ['page_revisions', 'featured_image'],
                ['snippets', 'content'],
                ['settings', 'value'],
                ['theme_mods', 'mod_value'],
                ['menu_items', 'url'],
                ['page_type_fields', 'default_value'],
            ];

            foreach (['modo_galleries', 'modo_carousels'] as $pluginTable) {
                if (self::tableExists($db, $pluginTable)) {
                    $targets[] = [$pluginTable, 'items'];
                }
            }

            foreach ($targets as [$table, $column]) {
                if (!self::tableExists($db, $table) || !self::columnExists($db, $table, $column)) {
                    continue;
                }

                foreach ($oldVariants as $key => $oldVariant) {
                    $newVariant = $newVariants[$key] ?? $new;
                    if ($oldVariant === $newVariant) {
                        continue;
                    }

                    $stmt = $db->prepare(
                        "UPDATE {$table} SET {$column} = REPLACE({$column}, :old, :new) " .
                        "WHERE instr({$column}, :needle) > 0"
                    );
                    $stmt->execute([
                        ':old'    => $oldVariant,
                        ':new'    => $newVariant,
                        ':needle' => $oldVariant,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Reference rewriting is best-effort; filesystem operations have
            // already succeeded at this point.
        }
    }

    /**
     * @return array<string,string>
     */
    private static function pathVariants(string $path): array
    {
        $raw = '/' . ltrim(str_replace('\\', '/', $path), '/');
        $segments = explode('/', ltrim($raw, '/'));
        $encoded = '/' . implode('/', array_map('rawurlencode', $segments));

        return [
            'raw'             => $raw,
            'encoded'         => $encoded,
            'json_raw'        => str_replace('/', '\\/', $raw),
            'json_encoded'    => str_replace('/', '\\/', $encoded),
            'percent'         => rawurlencode(ltrim($raw, '/')),
            'percent_leading' => '%2F' . rawurlencode(ltrim($raw, '/')),
        ];
    }

    private static function tableExists(\PDO $db, string $table): bool
    {
        try {
            $stmt = $db->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name LIMIT 1");
            $stmt->execute([':name' => $table]);
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function columnExists(\PDO $db, string $table, string $column): bool
    {
        try {
            foreach ($db->query("PRAGMA table_info(" . $table . ")") as $info) {
                if (($info['name'] ?? '') === $column) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // Treat unreadable schemas as missing columns.
        }
        return false;
    }

    /**
     * Detects the best available backend for generating admin thumbnails.
     * Returns "gd", "imagick", "cli" or "none".
     */
    public static function thumbnailBackend(): string
    {
        static $backend = null;
        if ($backend !== null) {
            return $backend;
        }

        if (function_exists('imagescale')
            && function_exists('imagecreatetruecolor')
            && (function_exists('imagewebp') || function_exists('imagejpeg'))
            && (function_exists('imagecreatefromjpeg')
                || function_exists('imagecreatefrompng')
                || function_exists('imagecreatefromwebp'))
        ) {
            return $backend = 'gd';
        }

        if (class_exists(\Imagick::class)) {
            return $backend = 'imagick';
        }

        if (self::cliConvertBinary() !== null) {
            return $backend = 'cli';
        }

        return $backend = 'none';
    }

    /** Whether this server can generate thumbnails at all. */
    public static function thumbnailSupported(): bool
    {
        return self::thumbnailBackend() !== 'none';
    }

    /**
     * Returns a picker-friendly preview URL for a media file. Uses a static
     * cached thumbnail when one exists, the on-demand endpoint otherwise, and
     * falls back to the generated WebP sibling (or the original image) when the
     * server cannot resize images.
     */
    public static function thumbnailUrl(string $storedPath, int $width = 160): string
    {
        $url = self::urlForStoredPath($storedPath);
        $fallback = function_exists('webp_url') ? \webp_url($url) : $url;

        if (preg_match('#^https?://#i', $storedPath) === 1 || !self::thumbnailSupported()) {
            return $fallback;
        }

        $width = in_array($width, self::THUMBNAIL_WIDTHS, true) ? $width : 160;
        $absolute = self::absoluteFromStoredPath($storedPath);
        if ($absolute === null || !self::isThumbnailSource($absolute)) {
            return $fallback;
        }

        $static = self::staticThumbnail($storedPath, $width, $absolute);
        if ($static !== null) {
            return self::baseUrl() . '/uploads/thumbs/' . rawurlencode(basename($static))
                . '?v=' . (int) @filemtime($absolute);
        }

        return self::baseUrl() . '/admin/thumb.php?' . http_build_query([
            'p' => $storedPath,
            'w' => $width,
            'v' => (int) @filemtime($absolute),
        ]);
    }

    /**
     * Creates (or reuses) cached thumbnails for the requested widths.
     *
     * @param array<int,int> $widths
     * @return array<int,string> width => absolute thumbnail path
     */
    public static function ensureThumbnails(string $storedPath, array $widths = [160, 240]): array
    {
        $backend = self::thumbnailBackend();
        if ($backend === 'none' || preg_match('#^https?://#i', $storedPath) === 1) {
            return [];
        }

        $widths = array_values(array_unique(array_filter(
            array_map('intval', $widths),
            static fn (int $width): bool => in_array($width, self::THUMBNAIL_WIDTHS, true)
        )));
        if ($widths === []) {
            return [];
        }

        $source = self::absoluteFromStoredPath($storedPath);
        if ($source === null || !self::isThumbnailSource($source)) {
            return [];
        }

        $info = @getimagesize($source);
        if (!is_array($info) || (int) $info[0] <= 0 || (int) $info[1] <= 0) {
            return [];
        }

        if (self::ensureThumbnailDir() === null) {
            return [];
        }

        $result = [];
        $missing = [];
        foreach ($widths as $width) {
            $existing = self::staticThumbnail($storedPath, $width, $source);
            if ($existing !== null) {
                $result[$width] = $existing;
            } else {
                $missing[] = $width;
            }
        }

        if ($missing === []) {
            return $result;
        }

        // Decoding is the expensive part, so every backend renders all missing
        // sizes from a single source image where possible.
        if ($backend === 'gd') {
            self::generateGdThumbnails($source, $storedPath, $missing, $result);
        } elseif ($backend === 'imagick') {
            self::generateImagickThumbnails($source, $storedPath, $missing, $result);
        } else {
            self::generateCliThumbnails($source, $storedPath, $missing, $result);
        }

        return $result;
    }

    /** Creates (or reuses) a single cached thumbnail. */
    public static function ensureThumbnail(string $storedPath, int $width = 160): ?string
    {
        $result = self::ensureThumbnails($storedPath, [$width]);
        return $result[$width] ?? null;
    }

    /**
     * Counts library images that do not have the full set of cached thumbnails.
     */
    public static function missingThumbnailCount(): int
    {
        if (!self::thumbnailSupported()) {
            return 0;
        }

        $missing = 0;
        $backend = self::thumbnailBackend();
        foreach (self::listImages() as $item) {
            $source = (string) ($item['absolute'] ?? '');
            if ($source === '' || !self::isThumbnailSource($source)) {
                continue;
            }
            $info = @getimagesize($source);
            if (!is_array($info) || (int) $info[0] <= 0 || (int) $info[1] <= 0) {
                continue;
            }
            if ($backend === 'gd' && (int) $info[0] * (int) $info[1] > 20000000) {
                // generateGdThumbnails() refuses these to protect memory.
                continue;
            }
            foreach (self::THUMBNAIL_PREVIEW_WIDTHS as $width) {
                if (self::staticThumbnail((string) $item['path'], $width, $source) === null) {
                    $missing++;
                    break;
                }
            }
        }

        return $missing;
    }

    /** Removes every cached thumbnail variant of a stored media path. */
    public static function deleteThumbnails(string $storedPath): void
    {
        $key = self::thumbnailCacheKey($storedPath);
        foreach ([self::rootPath() . '/thumbs', self::rootPath() . '/cache/thumbs'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach ([80, 160, 240, 320] as $width) {
                foreach (['webp', 'jpg'] as $extension) {
                    $file = $dir . '/' . $key . '-' . $width . '.' . $extension;
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
        }
    }

    private static function thumbnailCacheKey(string $storedPath): string
    {
        $path = parse_url($storedPath, PHP_URL_PATH) ?: $storedPath;
        $relative = ltrim(str_replace('\\', '/', (string) $path), '/');
        return sha1($relative);
    }

    private static function isThumbnailSource(string $absolute): bool
    {
        $extension = strtolower((string) pathinfo($absolute, PATHINFO_EXTENSION));
        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true);
    }

    private static function ensureThumbnailDir(): ?string
    {
        $dir = self::rootPath() . '/thumbs';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        // Thumbnail URLs carry a ?v=<source mtime> cache-buster, so they can be
        // cached aggressively by the browser.
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents(
                $htaccess,
                "<IfModule mod_headers.c>\n"
                . "    Header set Cache-Control \"public, max-age=31536000, immutable\"\n"
                . "</IfModule>\n"
            );
        }

        return $dir;
    }

    private static function thumbPath(string $storedPath, int $width, string $format): string
    {
        return self::rootPath() . '/thumbs/' . self::thumbnailCacheKey($storedPath)
            . '-' . $width . '.' . $format;
    }

    /**
     * Returns an existing, up-to-date static thumbnail or null. Stale files are
     * removed so the next request regenerates them.
     */
    private static function staticThumbnail(string $storedPath, int $width, string $source): ?string
    {
        $sourceMtime = (int) @filemtime($source);
        foreach (['webp', 'jpg'] as $format) {
            $file = self::thumbPath($storedPath, $width, $format);
            if (!is_file($file)) {
                continue;
            }
            if ((int) @filemtime($file) >= $sourceMtime) {
                return $file;
            }
            @unlink($file);
        }

        return null;
    }

    /** @param array<int,int> $widths @param array<int,string> $result */
    private static function generateGdThumbnails(string $source, string $storedPath, array $widths, array &$result): void
    {
        $info = @getimagesize($source);
        if (!is_array($info) || (int) $info[0] * (int) $info[1] > 20000000) {
            // Guard against exhausting memory on extremely large originals.
            return;
        }

        $image = self::loadImage($source);
        if ($image === null) {
            return;
        }

        $image = self::applyExifOrientation($image, $source);
        $sourceWidth = imagesx($image);
        $sourceHeight = imagesy($image);
        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            imagedestroy($image);
            return;
        }

        $format = function_exists('imagewebp') ? 'webp' : 'jpg';
        foreach ($widths as $width) {
            $thumb = $sourceWidth <= $width
                ? $image
                : @imagescale($image, $width, -1, IMG_BICUBIC);
            if ($thumb === false) {
                continue;
            }

            $target = self::thumbPath($storedPath, $width, $format);
            $tmp = $target . '.tmp-' . bin2hex(random_bytes(6));
            $saved = false;

            if ($format === 'webp') {
                if ($thumb !== $image) {
                    imagepalettetotruecolor($thumb);
                }
                imagealphablending($thumb, true);
                imagesavealpha($thumb, true);
                $saved = @imagewebp($thumb, $tmp, 75);
            } else {
                // JPEG has no alpha channel: flatten transparency onto white.
                $flat = imagecreatetruecolor(imagesx($thumb), imagesy($thumb));
                if ($flat !== false) {
                    $white = imagecolorallocate($flat, 255, 255, 255);
                    imagefilledrectangle($flat, 0, 0, imagesx($flat) - 1, imagesy($flat) - 1, $white);
                    imagecopy($flat, $thumb, 0, 0, 0, 0, imagesx($thumb), imagesy($thumb));
                    $saved = @imagejpeg($flat, $tmp, 80);
                    imagedestroy($flat);
                }
            }

            if ($thumb !== $image) {
                imagedestroy($thumb);
            }

            if ($saved && is_file($tmp)) {
                if (@rename($tmp, $target)) {
                    $result[$width] = $target;
                } else {
                    @unlink($tmp);
                }
            } elseif (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        imagedestroy($image);
    }

    /** @param array<int,int> $widths @param array<int,string> $result */
    private static function generateImagickThumbnails(string $source, string $storedPath, array $widths, array &$result): void
    {
        try {
            $image = new \Imagick($source);
        } catch (\Throwable $e) {
            return;
        }

        try {
            if (method_exists($image, 'autoOrient')) {
                $image->autoOrient();
            }
            $sourceWidth = (int) $image->getImageWidth();
            $sourceHeight = (int) $image->getImageHeight();
            if ($sourceWidth <= 0 || $sourceHeight <= 0) {
                return;
            }

            $useWebp = !empty(\Imagick::queryFormats('WEBP'));

            foreach ($widths as $width) {
                $copy = clone $image;
                try {
                    if ($sourceWidth > $width) {
                        $height = max(1, (int) round($sourceHeight * $width / $sourceWidth));
                        $copy->thumbnailImage($width, $height, true);
                    }

                    if ($useWebp) {
                        $copy->setImageFormat('webp');
                        $copy->setImageCompressionQuality(75);
                        $format = 'webp';
                    } else {
                        $copy->setImageBackgroundColor('white');
                        $copy->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                        $copy->setImageFormat('jpeg');
                        $copy->setImageCompressionQuality(80);
                        $format = 'jpg';
                    }

                    $target = self::thumbPath($storedPath, $width, $format);
                    $tmp = $target . '.tmp-' . bin2hex(random_bytes(6));
                    if ($copy->writeImage($tmp) && is_file($tmp) && @rename($tmp, $target)) {
                        $result[$width] = $target;
                    } elseif (is_file($tmp)) {
                        @unlink($tmp);
                    }
                } catch (\Throwable $e) {
                    // Fall through to the next size.
                } finally {
                    $copy->clear();
                }
            }
        } finally {
            $image->clear();
        }
    }

    /** @param array<int,int> $widths @param array<int,string> $result */
    private static function generateCliThumbnails(string $source, string $storedPath, array $widths, array &$result): void
    {
        $binary = self::cliConvertBinary();
        if ($binary === null) {
            return;
        }

        // Render every missing size in a single process so the (expensive)
        // source decode happens once. Largest size first, then downscale.
        $pending = array_values(array_filter($widths, static fn (int $width): bool => !isset($result[$width])));
        if ($pending === []) {
            return;
        }
        rsort($pending, SORT_NUMERIC);

        foreach (['webp' => '75', 'jpg' => '80'] as $format => $quality) {
            $pending = array_values(array_filter($pending, static fn (int $width): bool => !isset($result[$width])));
            if ($pending === []) {
                return;
            }
            rsort($pending, SORT_NUMERIC);

            $targets = [];
            $command = escapeshellarg($binary) . ' ' . escapeshellarg($source)
                . ' -auto-orient -strip';
            if ($format === 'jpg') {
                // JPEG has no alpha channel: flatten transparency onto white.
                $command .= ' -background white -alpha remove -alpha off';
            }

            foreach ($pending as $index => $width) {
                $target = self::thumbPath($storedPath, $width, $format);
                $tmp = $target . '.tmp-' . bin2hex(random_bytes(6));
                $targets[$width] = [$tmp, $target];
                $command .= ' -resize ' . escapeshellarg($width . 'x>')
                    . ' -quality ' . escapeshellarg($quality)
                    . ($index === count($pending) - 1
                        ? ' ' . escapeshellarg($format . ':' . $tmp)
                        : ' -write ' . escapeshellarg($format . ':' . $tmp));
            }
            $command .= ' 2>/dev/null';

            $code = 1;
            $output = [];
            @exec($command, $output, $code);

            $success = 0;
            foreach ($targets as $width => [$tmp, $target]) {
                if ($code === 0 && is_file($tmp) && (int) @filesize($tmp) > 0 && @rename($tmp, $target)) {
                    $result[$width] = $target;
                    $success++;
                } elseif (is_file($tmp)) {
                    @unlink($tmp);
                }
            }

            if ($success === count($pending)) {
                return;
            }
        }
    }

    /** Absolute path to a usable ImageMagick "convert" binary, or null. */
    private static function cliConvertBinary(): ?string
    {
        static $checked = false;
        static $binary = null;
        if ($checked) {
            return $binary;
        }
        $checked = true;

        if (!function_exists('exec')) {
            return null;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('exec', $disabled, true)) {
            return null;
        }

        foreach (['/usr/bin/convert', '/usr/local/bin/convert', '/opt/homebrew/bin/convert'] as $candidate) {
            if (@is_executable($candidate)) {
                return $binary = $candidate;
            }
        }

        $output = [];
        $code = 1;
        @exec('command -v convert 2>/dev/null', $output, $code);
        if ($code === 0 && !empty($output[0])) {
            $candidate = trim((string) $output[0]);
            if ($candidate !== '' && @is_executable($candidate)) {
                return $binary = $candidate;
            }
        }

        return null;
    }

    /** @return resource|\GdImage|null */
    private static function loadImage(string $source)
    {
        $info = @getimagesize($source);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';

        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($source) ?: null,
            'image/png'  => @imagecreatefrompng($source) ?: null,
            'image/webp' => function_exists('imagecreatefromwebp') ? (@imagecreatefromwebp($source) ?: null) : null,
            default      => null,
        };
    }

    /**
     * @param resource|\GdImage $image
     * @return resource|\GdImage
     */
    private static function applyExifOrientation($image, string $source)
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        $extension = strtolower((string) pathinfo($source, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg'], true)) {
            return $image;
        }

        $exif = @exif_read_data($source);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }

        $rotated = @imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image;
        }
        imagedestroy($image);

        return $rotated;
    }
}
