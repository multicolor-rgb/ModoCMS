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

    /** Default widths used by custom-field galleries. */
    public const THUMBNAIL_GALLERY_WIDTHS = [480, 960];

    /** Widths generated eagerly for the admin previews. */
    public const THUMBNAIL_PREVIEW_WIDTHS = [80, 160, 240];

    /** Bump when the thumbnail pipeline changes (e.g. EXIF orientation). */
    private const THUMBNAIL_CACHE_VERSION = '2';

    /** Per-request cache for filesystem listings. */
    private static array $listImagesCache = [];

    /** Per-request cache for thumbnail settings. */
    private static ?array $thumbnailConfig = null;

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
     * Returns ALT text and captions for the given stored media paths.
     *
     * @param array<int,string> $paths
     * @return array<string,array{alt_text:string,caption:string}>
     */
    public static function metadataForPaths(array $paths): array
    {
        $paths = array_values(array_unique(array_filter(
            array_map(static fn ($path): string => trim((string) $path), $paths),
            static fn (string $path): bool => $path !== ''
        )));
        if ($paths === []) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($paths), '?'));
            $stmt = Database::getConnection()->prepare(
                "SELECT filepath, alt_text, caption FROM media WHERE filepath IN ({$placeholders})"
            );
            $stmt->execute($paths);

            $metadata = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $metadata[(string) $row['filepath']] = [
                    'alt_text' => (string) ($row['alt_text'] ?? ''),
                    'caption'  => (string) ($row['caption'] ?? ''),
                ];
            }
            return $metadata;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Updates ALT text / caption for stored media paths.
     *
     * A null value leaves the existing field unchanged; an empty string clears
     * it. Returns the number of updated media rows.
     *
     * @param array<int,string> $paths
     */
    public static function updateMetadata(array $paths, ?string $alt, ?string $caption): int
    {
        $paths = array_values(array_unique(array_filter(
            array_map(static fn ($path): string => trim((string) $path), $paths),
            static fn (string $path): bool => $path !== ''
        )));
        if ($paths === [] || ($alt === null && $caption === null)) {
            return 0;
        }

        try {
            // Make sure every file on disk has a media row before updating it.
            self::syncMissingMedia();

            $sets = [];
            $values = [];
            if ($alt !== null) {
                $sets[] = 'alt_text = ?';
                $values[] = trim($alt);
            }
            if ($caption !== null) {
                $sets[] = 'caption = ?';
                $values[] = trim($caption);
            }

            $placeholders = implode(',', array_fill(0, count($paths), '?'));
            $stmt = Database::getConnection()->prepare(
                'UPDATE media SET ' . implode(', ', $sets) . ' WHERE filepath IN (' . $placeholders . ')'
            );
            $stmt->execute(array_merge($values, $paths));

            return $stmt->rowCount();
        } catch (\Throwable $e) {
            return 0;
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
     * Deletes multiple files in one request and purges the page cache once.
     *
     * @param array<int,mixed> $paths
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
     * Saves a browser-edited image over an existing upload, or next to it.
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
        if ($source === null) {
            return ['ok' => false, 'error' => 'missing'];
        }

        $sourceStored = self::storedPathFromAbsolute($source);
        if ($sourceStored === null) {
            return ['ok' => false, 'error' => 'invalid'];
        }

        $mime = strtolower(trim($mime));
        $extension = self::extensionForMime($mime);
        if ($extension === null || !is_file($sourceTmp) || (int) @filesize($sourceTmp) <= 0) {
            return ['ok' => false, 'error' => 'invalid'];
        }

        $directory = dirname($source);
        $currentExtension = strtolower((string) pathinfo($source, PATHINFO_EXTENSION));
        $writableExtensions = ['jpg', 'jpeg', 'png', 'webp'];

        if ($overwrite) {
            if (!in_array($currentExtension, $writableExtensions, true)
                || !self::mimeMatchesExtension($mime, $currentExtension)
            ) {
                return ['ok' => false, 'error' => 'readonly'];
            }
            $target = $source;
            $newStored = $sourceStored;
        } else {
            $base = (string) pathinfo($source, PATHINFO_FILENAME);
            $target = $directory . '/' . self::uniqueName($directory, $base . '-edited.' . $extension);
            $newStored = self::storedPathForAbsolute($target);
        }

        if ($newStored === null) {
            return ['ok' => false, 'error' => 'invalid'];
        }

        // Write through a temporary file so a failed write never truncates the
        // original image.
        $targetName = basename($target);
        $tmp = $directory . '/.' . $targetName . '.tmp-' . bin2hex(random_bytes(6));
        if (!@copy($sourceTmp, $tmp) || !is_file($tmp) || (int) @filesize($tmp) <= 0) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            return ['ok' => false, 'error' => 'failed'];
        }
        @chmod($tmp, 0664);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'failed'];
        }
        @chmod($target, 0664);
        clearstatcache(true, $target);

        $webpStored = null;
        try {
            $db = Database::getConnection();

            // Remove the previously generated WebP file before rebuilding it
            // from the new pixels. Prefer the path recorded in the media row;
            // older installations may not follow the sibling naming scheme.
            $oldWebp = '';
            $stmt = $db->prepare("SELECT webp_path FROM media WHERE filepath = :path LIMIT 1");
            $stmt->execute([':path' => $newStored]);
            $oldWebp = (string) ($stmt->fetchColumn() ?: '');
            if ($oldWebp !== '') {
                $oldWebpAbsolute = self::absoluteFromStoredPath($oldWebp);
                if ($oldWebpAbsolute !== null && realpath($oldWebpAbsolute) !== realpath($target)) {
                    @unlink($oldWebpAbsolute);
                }
            }

            $webpStored = self::regenerateWebpSibling($target, $extension);
            $size = (int) @filesize($target);

            $lookup = $db->prepare("SELECT id, filename FROM media WHERE filepath = :path LIMIT 1");
            $lookup->execute([':path' => $newStored]);
            $existingRow = $lookup->fetch() ?: null;

            if ($existingRow !== null) {
                // Overwriting keeps the user-facing upload name; the stored
                // filename is usually a random slug generated by upload.php.
                $displayName = $overwrite && trim((string) ($existingRow['filename'] ?? '')) !== ''
                    ? (string) $existingRow['filename']
                    : $targetName;

                $db->prepare("
                    UPDATE media
                    SET filename = :name, file_size = :size, mime_type = :mime, webp_path = :webp
                    WHERE id = :id
                ")->execute([
                    ':name'  => $displayName,
                    ':size'  => $size,
                    ':mime'  => $mime,
                    ':webp'  => $webpStored,
                    ':id'    => (int) $existingRow['id'],
                ]);
            } else {
                $db->prepare("
                    INSERT INTO media (filename, filepath, mime_type, file_size, webp_path, user_id)
                    VALUES (:name, :path, :mime, :size, :webp, :user)
                ")->execute([
                    ':name'  => $targetName,
                    ':path'  => $newStored,
                    ':mime'  => $mime,
                    ':size'  => $size,
                    ':webp'  => $webpStored,
                    ':user'  => $userId,
                ]);
            }
        } catch (\Throwable $e) {
            // The edited file is already on disk. DB bookkeeping is best-effort
            // and syncMissingMedia() can repair a missing row later.
        }

        self::deleteThumbnails($newStored);
        try {
            // An explicit edit must rebuild every configured size immediately,
            // including gallery widths when galleries are enabled.
            self::ensureThumbnails($newStored, self::thumbnailGenerationWidths());
        } catch (\Throwable $e) {
            // Thumbnails can also be regenerated lazily on the next request.
        }

        self::purgeCache();

        return [
            'ok'       => true,
            'path'     => $newStored,
            'url'      => self::urlForStoredPath($newStored),
            'filename' => basename($target),
        ];
    }

    /**
     * Resolves a public uploads path to an absolute file path.
     */
    public static function absolutePath(string $storedPath): ?string
    {
        return self::absoluteFromStoredPath($storedPath);
    }

    /**
     * Returns the ImageMagick CLI binary, when available.
     */
    public static function imageCliBinary(): ?string
    {
        return self::cliConvertBinary();
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
        $map = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
        ];

        return ($map[$extension] ?? null) === $mime;
    }

    /**
     * Rebuilds the automatic WebP sibling after an in-place edit. Returns the
     * stored "/uploads/..." path, or null when WebP is disabled/unsupported.
     */
    /**
     * Rebuilds the generated WebP sibling for a stored image.
     *
     * Used by the forced thumbnail refresh so old phone photos that were
     * converted before EXIF orientation support are corrected too.
     */
    public static function regenerateWebpSiblingForPath(string $storedPath): ?string
    {
        $absolute = self::absoluteFromStoredPath($storedPath);
        if ($absolute === null) {
            return null;
        }

        $extension = strtolower((string) pathinfo($absolute, PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            return null;
        }
        if (!class_exists(ImageOptimizer::class)
            || !ImageOptimizer::isEnabled()
            || !ImageOptimizer::isSupported()
        ) {
            return null;
        }

        $converted = ImageOptimizer::convertToWebp($absolute);
        if ($converted === null) {
            return null;
        }

        $stored = self::storedPathForAbsolute($converted);
        if ($stored === null) {
            return null;
        }

        try {
            Database::getConnection()->prepare(
                'UPDATE media SET webp_path = :webp WHERE filepath = :path'
            )->execute([':webp' => $stored, ':path' => $storedPath]);
        } catch (\Throwable $e) {
            // The file is correct even if the optional DB row is missing.
        }

        return $stored;
    }

    private static function regenerateWebpSibling(string $absolute, string $extension): ?string
    {
        if (!in_array($extension, ['jpg', 'jpeg', 'png'], true)
            || !class_exists(ImageOptimizer::class)
            || !ImageOptimizer::isEnabled()
            || !ImageOptimizer::isSupported()
        ) {
            self::removeStaleWebpSibling($absolute);
            return null;
        }

        $converted = ImageOptimizer::convertToWebp($absolute);
        if ($converted === null) {
            self::removeStaleWebpSibling($absolute);
            return null;
        }

        return self::storedPathForAbsolute($converted);
    }

    private static function removeStaleWebpSibling(string $absolute): void
    {
        $sibling = preg_replace('/\.[^.]+$/', '', $absolute) . '.webp';
        if ($sibling === null || !is_file($sibling)) {
            return;
        }
        if (realpath($sibling) === realpath($absolute)) {
            return;
        }
        @unlink($sibling);
    }

    /**
     * Quotes an absolute path without requiring the file to exist yet.
     */
    private static function storedPathForAbsolute(string $absolute): ?string
    {
        $root = realpath(self::rootPath());
        $directory = realpath(dirname($absolute));
        if ($root === false || $directory === false) {
            return null;
        }
        if ($directory !== $root && !str_starts_with($directory, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $relative = $directory === $root ? '' : substr($directory, strlen($root) + 1);
        $relative = str_replace('\\', '/', $relative);

        return '/uploads/' . ($relative !== '' ? $relative . '/' : '') . basename($absolute);
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
        $path = rawurldecode((string) (parse_url($storedPath, PHP_URL_PATH) ?: $storedPath));
        $relative = ltrim(str_replace('\\', '/', $path), '/');

        // Stored paths may include the installation's base sub-directory
        // (e.g. /cms/uploads/photo.jpg on a sub-directory install).
        $base = class_exists(Router::class) ? trim(Router::getBaseSubdirectory(), '/') : '';
        if ($base !== '' && str_starts_with($relative, $base . '/')) {
            $relative = substr($relative, strlen($base) + 1);
        }

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
     * Reads thumbnail-related settings once per request.
     *
     * @return array{enabled:bool,auto:bool,widths:array<int,int>,quality:int}
     */
    private static function thumbnailConfig(): array
    {
        if (self::$thumbnailConfig !== null) {
            return self::$thumbnailConfig;
        }

        $enabled = true;
        $auto = true;
        $widthsRaw = '480,960';
        $quality = 78;

        try {
            if (class_exists(Router::class)) {
                $enabled = Router::getOption('gallery_thumbnails_enabled', '1') === '1';
                $auto = Router::getOption('gallery_thumbnails_auto', '1') === '1';
                $widthsRaw = Router::getOption('gallery_thumbnail_widths', '480,960');
                $quality = (int) Router::getOption('gallery_thumbnail_quality', '78');
            }
        } catch (\Throwable $e) {
            // Settings are optional; keep the defaults when the DB is unavailable.
        }

        return self::$thumbnailConfig = [
            'enabled' => $enabled,
            'auto'    => $auto,
            'widths'  => self::parseThumbnailWidths($widthsRaw),
            'quality' => max(1, min(100, $quality)),
        ];
    }

    /**
     * Parses a comma-separated width list into a sorted, unique array.
     *
     * @return array<int,int>
     */
    public static function parseThumbnailWidths(string $raw): array
    {
        $widths = [];
        foreach (explode(',', $raw) as $part) {
            $width = (int) trim($part);
            if ($width >= 80 && $width <= 1920) {
                $widths[$width] = $width;
            }
        }

        $widths = array_values($widths);
        sort($widths, SORT_NUMERIC);

        return $widths === [] ? self::THUMBNAIL_GALLERY_WIDTHS : array_slice($widths, 0, 4);
    }

    /** Returns the canonical comma-separated list stored in the settings form. */
    public static function normalizeThumbnailWidths(string $raw): string
    {
        return implode(',', self::parseThumbnailWidths($raw));
    }

    public static function galleryThumbnailsEnabled(): bool
    {
        return self::thumbnailConfig()['enabled'];
    }

    public static function galleryThumbnailsAuto(): bool
    {
        return self::thumbnailConfig()['auto'];
    }

    /** @return array<int,int> */
    public static function galleryThumbnailWidths(): array
    {
        return self::thumbnailConfig()['widths'];
    }

    public static function thumbnailQuality(): int
    {
        return self::thumbnailConfig()['quality'];
    }

    /**
     * Every width the admin thumbnail endpoint may serve.
     *
     * @return array<int,int>
     */
    public static function allowedThumbnailWidths(): array
    {
        $widths = array_merge(self::THUMBNAIL_WIDTHS, self::THUMBNAIL_PREVIEW_WIDTHS, self::galleryThumbnailWidths());
        $widths = array_values(array_unique(array_map('intval', $widths)));
        sort($widths, SORT_NUMERIC);

        return $widths;
    }

    /**
     * Widths that should exist on disk for previews and current galleries.
     *
     * @return array<int,int>
     */
    public static function thumbnailGenerationWidths(): array
    {
        $widths = self::THUMBNAIL_PREVIEW_WIDTHS;
        if (self::galleryThumbnailsEnabled()) {
            $widths = array_merge($widths, self::galleryThumbnailWidths());
        }

        $widths = array_values(array_unique(array_map('intval', $widths)));
        sort($widths, SORT_NUMERIC);

        return $widths;
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

    /** Whether PHP can write to the /uploads/thumbs cache directory. */
    public static function thumbnailDirectoryWritable(): bool
    {
        $root = self::rootPath();
        $dir = $root . '/thumbs';

        return is_dir($dir) ? is_writable($dir) : is_writable($root);
    }

    /** Returns true when a cached thumbnail exists for the given width. */
    public static function thumbnailExists(string $storedPath, int $width): bool
    {
        $absolute = self::absoluteFromStoredPath($storedPath);
        if ($absolute === null || !self::isThumbnailSource($absolute)) {
            return false;
        }

        return self::staticThumbnail($storedPath, $width, $absolute) !== null;
    }

    /** Whether a stored path points to a local image type we can resize. */
    public static function canGenerateThumbnail(string $storedPath): bool
    {
        $absolute = self::absoluteFromStoredPath($storedPath);

        return $absolute !== null && self::isThumbnailSource($absolute);
    }

    private static function logThumbnailFailure(string $storedPath, string $message): void
    {
        if (function_exists('error_log')) {
            @error_log('[thumbnails] ' . $storedPath . ': ' . $message);
        }
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

        if (!self::thumbnailSupported()) {
            return $fallback;
        }

        $width = in_array($width, self::allowedThumbnailWidths(), true) ? $width : 160;
        $absolute = self::absoluteFromStoredPath($storedPath);
        if ($absolute === null || !self::isThumbnailSource($absolute)) {
            return $fallback;
        }

        $static = self::staticThumbnail($storedPath, $width, $absolute);
        if ($static !== null) {
            return self::staticThumbnailUrl($static, $absolute);
        }

        return self::baseUrl() . '/admin/thumb.php?' . http_build_query([
            'p' => $storedPath,
            'w' => $width,
            'v' => (int) @filemtime($absolute),
        ]);
    }

    /**
     * Returns a public thumbnail URL of the requested width, with a fallback
     * to the original (or WebP) image when the thumbnail is missing.
     */
    public static function frontendThumbnailUrl(string $storedPath, int $width = 480, string $fallbackUrl = ''): string
    {
        $fallback = $fallbackUrl !== '' ? $fallbackUrl : self::urlForStoredPath($storedPath);
        if ($storedPath === '') {
            return $fallback;
        }

        $sources = self::frontendImageSources($storedPath, [$width], $fallback, false);
        return (string) ($sources['src'] ?? $fallback);
    }

    /**
     * Returns a thumbnail-based src/srcset for public galleries. Unlike
     * thumbnailUrl() this never points at the admin endpoint, so it is safe on
     * the frontend; when a thumbnail is missing the original image is used.
     *
     * @param array<int,int>|null $widths
     * @param string $fallbackUrl Fully resolved URL used when no thumbnail exists
     * @return array{src:string,srcset:string}
     */
    public static function frontendImageSources(string $storedPath, ?array $widths = null, string $fallbackUrl = '', bool $requireGallerySetting = true): array
    {
        $original = $fallbackUrl !== '' ? $fallbackUrl : self::urlForStoredPath($storedPath);
        $optimized = function_exists('webp_url') ? \webp_url($original) : $original;
        $fallback = ['src' => $optimized, 'srcset' => ''];

        if ($storedPath === '' || ($requireGallerySetting && !self::galleryThumbnailsEnabled())) {
            return $fallback;
        }

        $absolute = self::absoluteFromStoredPath($storedPath);
        if ($absolute === null || !self::isThumbnailSource($absolute)) {
            return $fallback;
        }

        // Prefer the configured gallery widths but also reuse any existing
        // preview thumbnail (240/320) so galleries benefit immediately after
        // the update, before a full backfill has run.
        $candidates = $widths ?? array_merge(self::THUMBNAIL_PREVIEW_WIDTHS, self::galleryThumbnailWidths());
        $candidates = array_values(array_unique(array_map('intval', $candidates)));
        sort($candidates, SORT_NUMERIC);

        $available = [];
        foreach ($candidates as $width) {
            if ($width < 240) {
                continue;
            }

            $file = self::staticThumbnail($storedPath, $width, $absolute);
            if ($file === null) {
                continue;
            }

            // Use the real file width so srcset descriptors stay accurate even
            // when the original image is narrower than the requested size.
            $info = @getimagesize($file);
            $actualWidth = (int) ($info[0] ?? 0);
            if ($actualWidth <= 0 || isset($available[$actualWidth])) {
                continue;
            }
            $available[$actualWidth] = self::staticThumbnailUrl($file, $absolute);
        }

        if ($available === []) {
            return $fallback;
        }

        ksort($available, SORT_NUMERIC);
        // No-srcset browsers get 480px when available, otherwise the largest
        // cached thumbnail — but never the full-size original.
        $src = (string) reset($available);
        foreach ($available as $actualWidth => $url) {
            if ($actualWidth >= 480) {
                $src = $url;
                break;
            }
        }
        $srcset = [];
        foreach ($available as $actualWidth => $url) {
            $srcset[] = $url . ' ' . $actualWidth . 'w';
        }

        return ['src' => $src, 'srcset' => implode(', ', $srcset)];
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
        if ($backend === 'none') {
            return [];
        }

        $allowed = self::allowedThumbnailWidths();
        $widths = array_values(array_unique(array_filter(
            array_map('intval', $widths),
            static fn (int $width): bool => in_array($width, $allowed, true)
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
            self::logThumbnailFailure($storedPath, 'uploads/thumbs is not writable');
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

        // GD refuses very large images to protect memory. ImageMagick CLI can
        // handle them, so use it as a per-image fallback instead of silently
        // skipping the file.
        $cli = self::cliConvertBinary();
        $pixels = (int) $info[0] * (int) $info[1];
        $largeForGd = $backend === 'gd' && $pixels > 20000000;
        $useCli = $backend === 'cli' || ($largeForGd && $cli !== null);

        // Decoding is the expensive part, so every backend renders all missing
        // sizes from a single source image where possible.
        if ($backend === 'gd' && !$useCli) {
            self::generateGdThumbnails($source, $storedPath, $missing, $result);
        } elseif ($backend === 'imagick') {
            self::generateImagickThumbnails($source, $storedPath, $missing, $result);
        }

        if ($useCli) {
            self::generateCliThumbnails($source, $storedPath, $missing, $result);
        } else {
            // Fall back to CLI if GD/Imagick failed for any reason (unsupported
            // colour profile, memory guard, broken image chunk, etc.).
            $remaining = array_values(array_filter(
                $missing,
                static fn (int $width): bool => !isset($result[$width])
            ));
            if ($remaining !== [] && $cli !== null) {
                self::generateCliThumbnails($source, $storedPath, $remaining, $result);
            }
        }

        $stillMissing = array_values(array_filter(
            $missing,
            static fn (int $width): bool => !isset($result[$width])
        ));
        if ($stillMissing !== []) {
            self::logThumbnailFailure(
                $storedPath,
                'could not generate widths: ' . implode(',', $stillMissing)
            );
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
        $cli = self::cliConvertBinary();
        foreach (self::listImages() as $item) {
            $source = (string) ($item['absolute'] ?? '');
            if ($source === '' || !self::isThumbnailSource($source)) {
                continue;
            }
            $info = @getimagesize($source);
            if (!is_array($info) || (int) $info[0] <= 0 || (int) $info[1] <= 0) {
                continue;
            }
            if ($backend === 'gd' && $cli === null && (int) $info[0] * (int) $info[1] > 20000000) {
                // GD refuses these and no CLI fallback is available.
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
    public static function deleteThumbnails(string $storedPath, ?array $widths = null): void
    {
        $key = self::thumbnailCacheKey($storedPath);
        $widths = $widths ?? self::allowedThumbnailWidths();
        $widths = array_values(array_unique(array_map('intval', $widths)));
        if ($widths === []) {
            return;
        }

        foreach ([self::rootPath() . '/thumbs', self::rootPath() . '/cache/thumbs'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach ($widths as $width) {
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
        return sha1($relative . '|v' . self::THUMBNAIL_CACHE_VERSION);
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

    /** Builds a cache-busted public URL for an existing static thumbnail. */
    private static function staticThumbnailUrl(string $file, string $source): string
    {
        return self::baseUrl() . '/uploads/thumbs/' . rawurlencode(basename($file))
            . '?v=' . (int) @filemtime($source);
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

        $image = \Core\ImageOrientation::applyGd($image, $source);
        $sourceWidth = imagesx($image);
        $sourceHeight = imagesy($image);
        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            imagedestroy($image);
            return;
        }

        $format = function_exists('imagewebp') ? 'webp' : 'jpg';
        $quality = self::thumbnailQuality();
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
                $saved = @imagewebp($thumb, $tmp, $quality);
            } else {
                // JPEG has no alpha channel: flatten transparency onto white.
                $flat = imagecreatetruecolor(imagesx($thumb), imagesy($thumb));
                if ($flat !== false) {
                    $white = imagecolorallocate($flat, 255, 255, 255);
                    imagefilledrectangle($flat, 0, 0, imagesx($flat) - 1, imagesy($flat) - 1, $white);
                    imagecopy($flat, $thumb, 0, 0, 0, 0, imagesx($thumb), imagesy($thumb));
                    $saved = @imagejpeg($flat, $tmp, $quality);
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
            $quality = self::thumbnailQuality();

            foreach ($widths as $width) {
                $copy = clone $image;
                try {
                    if ($sourceWidth > $width) {
                        $height = max(1, (int) round($sourceHeight * $width / $sourceWidth));
                        $copy->thumbnailImage($width, $height, true);
                    }

                    if ($useWebp) {
                        $copy->setImageFormat('webp');
                        $copy->setImageCompressionQuality($quality);
                        $format = 'webp';
                    } else {
                        $copy->setImageBackgroundColor('white');
                        $copy->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                        $copy->setImageFormat('jpeg');
                        $copy->setImageCompressionQuality($quality);
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

        $quality = self::thumbnailQuality();
        foreach (['webp', 'jpg'] as $format) {
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
                    . ' -quality ' . escapeshellarg((string) $quality)
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

}
