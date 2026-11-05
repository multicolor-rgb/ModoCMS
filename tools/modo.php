<?php
declare(strict_types=1);

/**
 * Small maintenance CLI for Modo CMS.
 *
 * Usage:
 *   php tools/modo.php health
 *   php tools/modo.php cache:purge
 *   php tools/modo.php sitemap
 *   php tools/modo.php thumbs
 *   php tools/modo.php audit:prune
 *   php tools/modo.php i18n
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$root = dirname(__DIR__);
require_once $root . '/core/bootstrap.php';

use Core\Audit;
use Core\Database;
use Core\Logger;
use Core\MediaLibrary;
use Core\PageCache;
use Core\Router;
use Core\Security;
use Core\Setup;
use Core\Sitemap;

$command = $argv[1] ?? 'health';

function out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

switch ($command) {
    case 'health':
        $checks = [
            'PHP >= 8.0'        => version_compare(PHP_VERSION, '8.0.0', '>='),
            'pdo_sqlite'        => extension_loaded('pdo_sqlite'),
            'sqlite3'           => extension_loaded('sqlite3'),
            'mbstring'          => extension_loaded('mbstring'),
            'data/ writable'    => is_writable($root . '/data'),
            'uploads/ writable' => is_writable($root . '/uploads'),
            'data/logs/ writable' => is_writable($root . '/data/logs'),
            'installed'         => Setup::isApplicationInstalled(),
            'database'          => is_file($root . '/data/cms.sqlite'),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            out(sprintf('[%s] %s', $ok ? 'OK' : 'FAIL', $label));
            $failed = $failed || !$ok;
        }

        try {
            $db = Database::getConnection();
            out('[OK] database connection (' . $db->query('SELECT sqlite_version()')->fetchColumn() . ')');
        } catch (\Throwable $e) {
            $failed = true;
            out('[FAIL] database connection: ' . $e->getMessage());
        }

        exit($failed ? 1 : 0);

    case 'cache:purge':
        $removed = PageCache::purge();
        out('Removed ' . $removed . ' cached page(s).');
        break;

    case 'sitemap':
        $xml = Sitemap::generate();
        Sitemap::generateRobotsTxt();
        out('Generated sitemap.xml (' . strlen($xml) . ' bytes) and robots.txt.');
        break;

    case 'thumbs':
        $db = Database::getConnection();
        $widths = MediaLibrary::thumbnailGenerationWidths();
        $count = 0;
        foreach ($db->query("SELECT filepath FROM media ORDER BY id ASC")->fetchAll() as $row) {
            $path = (string)($row['filepath'] ?? '');
            if ($path === '') {
                continue;
            }
            try {
                MediaLibrary::ensureThumbnails($path, $widths);
                MediaLibrary::regenerateWebpSiblingForPath($path);
                $count++;
            } catch (\Throwable $e) {
                Logger::exception($e, 'CLI thumbnail generation failed', ['path' => $path]);
            }
        }
        out('Processed ' . $count . ' media item(s).');
        break;

    case 'audit:prune':
        Audit::prune((int)Router::getOption('audit_retention_days', '365'));
        Security::prune();
        out('Pruned audit, login-attempt and rate-limit rows.');
        break;

    case 'i18n':
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/i18n-audit.php'), $status);
        exit((int)$status);

    default:
        fail('Unknown command: ' . $command . PHP_EOL . 'Available: health, cache:purge, sitemap, thumbs, audit:prune, i18n');
}
