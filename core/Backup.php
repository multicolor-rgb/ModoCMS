<?php
declare(strict_types=1);

namespace Core;

/**
 * Class Backup
 * Handles 1-Click database snapshots and full media ZIP archives.
 */
final class Backup {
    private static function getBackupDir(): string {
        $dir = __DIR__ . '/../data/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return realpath($dir) ?: $dir;
    }

    /**
     * Creates a consistent SQLite database snapshot.
     * Flushes WAL pages to disk before snapshot.
     */
    public static function createDatabaseSnapshot(): string {
        $db = Database::getConnection();
        
        // Ensure all WAL changes are flushed into the main database file
        try {
            $db->exec('PRAGMA wal_checkpoint(TRUNCATE);');
        } catch (\Throwable $e) {}

        $backupDir = self::getBackupDir();
        $filename = 'modocms-db-' . date('Y-m-d-His') . '.sqlite';
        $destPath = $backupDir . '/' . $filename;

        // Use native VACUUM INTO if supported (SQLite 3.27+), fallback to clean copy
        try {
            $stmt = $db->prepare("VACUUM INTO :dest");
            $stmt->execute([':dest' => $destPath]);
        } catch (\Throwable $e) {
            $sourceFile = __DIR__ . '/../data/cms.sqlite';
            if (!@copy($sourceFile, $destPath)) {
                throw new \RuntimeException('Failed to copy SQLite database.');
            }
        }

        return $filename;
    }

    /**
     * Creates a full ZIP archive containing cms.sqlite and /uploads/ directory.
     */
    public static function createFullArchive(): string {
        if (!class_exists('\ZipArchive')) {
            throw new \RuntimeException('PHP ZipArchive extension is required for full ZIP backups.');
        }

        $backupDir = self::getBackupDir();
        $zipFilename = 'modocms-full-backup-' . date('Y-m-d-His') . '.zip';
        $zipPath = $backupDir . '/' . $zipFilename;

        // Create temporary DB snapshot first to prevent locking/WAL discrepancies
        $dbTempSnapshot = self::createDatabaseSnapshot();
        $dbTempPath = $backupDir . '/' . $dbTempSnapshot;

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($dbTempPath);
            throw new \RuntimeException('Cannot create ZIP archive.');
        }

        // Add SQLite database into the root of ZIP
        $zip->addFile($dbTempPath, 'cms.sqlite');

        // Add uploads directory recursively
        $uploadsDir = realpath(__DIR__ . '/../uploads');
        if ($uploadsDir && is_dir($uploadsDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($uploadsDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = 'uploads/' . ltrim(substr($filePath, strlen($uploadsDir)), '/\\');
                    $zip->addFile($filePath, $relativePath);
                }
            }
        }

        $zip->close();

        // Clean up temporary database snapshot
        @unlink($dbTempPath);

        return $zipFilename;
    }

    /**
     * Lists existing backups.
     */
    public static function listBackups(): array {
        $backupDir = self::getBackupDir();
        $files = glob($backupDir . '/*.*');
        $backups = [];

        if (!$files) {
            return [];
        }

        foreach ($files as $file) {
            $filename = basename($file);
            if ($filename === '.gitkeep' || $filename === 'index.html') {
                continue;
            }

            $backups[] = [
                'filename' => $filename,
                'path'     => $file,
                'size'     => filesize($file),
                'date'     => filemtime($file),
                'type'     => str_ends_with($filename, '.zip') ? 'full' : 'db'
            ];
        }

        // Sort latest first
        usort($backups, fn($a, $b) => $b['date'] <=> $a['date']);

        return $backups;
    }

    /**
     * Safely deletes a backup file.
     */
    public static function deleteBackup(string $filename): bool {
        $safeName = basename($filename);
        $filePath = self::getBackupDir() . '/' . $safeName;

        if (file_exists($filePath)) {
            return @unlink($filePath);
        }
        return false;
    }

    /**
     * Streams backup file directly to browser for download.
     */
    public static function downloadBackup(string $filename): void {
        $safeName = basename($filename);
        $filePath = self::getBackupDir() . '/' . $safeName;

        if (!file_exists($filePath)) {
            http_response_code(404);
            die('Backup file not found.');
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }

    public static function formatBytes(int $bytes, int $precision = 2): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}