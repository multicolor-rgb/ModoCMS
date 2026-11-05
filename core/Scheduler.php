<?php
declare(strict_types=1);

namespace Core;

/**
 * Lightweight scheduled jobs for cron.
 *
 * Run from the command line (recommended):
 *   php tools/cron.php
 *
 * or over HTTP with the token stored in data/cron.key:
 *   https://example.com/cron.php?token=...
 */
final class Scheduler
{
    private static function secret(): string
    {
        $path = dirname(__DIR__) . '/data/cron.key';
        if (is_file($path)) {
            $secret = trim((string)@file_get_contents($path));
            if ($secret !== '') {
                return $secret;
            }
        }

        $secret = bin2hex(random_bytes(32));
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($path, $secret . "\n", LOCK_EX);
        @chmod($path, 0600);

        return $secret;
    }

    public static function token(): string
    {
        return self::secret();
    }

    public static function verifyToken(string $token): bool
    {
        return $token !== '' && hash_equals(self::token(), $token);
    }

    /**
     * @return array<string,mixed>
     */
    public static function run(): array
    {
        $started = microtime(true);
        $result = [
            'started_at' => date('c'),
            'pruned'     => 0,
            'trash'      => 0,
            'cache'      => false,
            'sitemap'    => false,
            'errors'     => [],
        ];

        try {
            Security::prune();
            Analytics::prune();
            $result['pruned'] = 1;
        } catch (\Throwable $e) {
            $result['errors'][] = 'prune: ' . $e->getMessage();
            Logger::exception($e, 'Cron prune failed');
        }

        try {
            $result['trash'] = self::purgeTrash();
        } catch (\Throwable $e) {
            $result['errors'][] = 'trash: ' . $e->getMessage();
            Logger::exception($e, 'Cron trash cleanup failed');
        }

        try {
            $lastRun = (string)Router::getOption('scheduler_last_run', '');
            $stmt = Database::getConnection()->prepare("
                SELECT COUNT(*) FROM pages
                WHERE status = 'published'
                  AND deleted_at IS NULL
                  AND published_at IS NOT NULL
                  AND published_at > :last
                  AND published_at <= datetime('now')
            ");
            $stmt->execute([':last' => $lastRun !== '' ? $lastRun : '1970-01-01 00:00:00']);
            if ((int)$stmt->fetchColumn() > 0) {
                PageCache::purge();
                $result['cache'] = true;
            }
        } catch (\Throwable $e) {
            $result['errors'][] = 'cache: ' . $e->getMessage();
        }

        try {
            Sitemap::generate();
            Sitemap::generateRobotsTxt();
            $result['sitemap'] = true;
        } catch (\Throwable $e) {
            $result['errors'][] = 'sitemap: ' . $e->getMessage();
            Logger::exception($e, 'Cron sitemap failed');
        }

        try {
            Database::getConnection()
                ->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('scheduler_last_run', :value)")
                ->execute([':value' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            // Non-fatal.
        }

        $result['duration_ms'] = (int)round((microtime(true) - $started) * 1000);
        Logger::info('Scheduled tasks completed', $result);

        return $result;
    }

    /** Permanently removes documents that have been in the trash longer than the retention period. */
    public static function purgeTrash(): int
    {
        $days = max(1, (int)Router::getOption('trash_retention_days', '30'));
        $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));
        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT id FROM pages WHERE deleted_at IS NOT NULL AND deleted_at < :cutoff");
        $stmt->execute([':cutoff' => $cutoff]);
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        if ($ids === []) {
            return 0;
        }

        $removed = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            foreach (['page_meta', 'page_tags', 'page_categories', 'page_revisions'] as $table) {
                $db->prepare("DELETE FROM {$table} WHERE page_id = :id")->execute([':id' => $id]);
            }
            $db->prepare("DELETE FROM pages WHERE id = :id")->execute([':id' => $id]);
            Audit::log('page.purge', 'page:' . $id);
            $removed++;
        }

        if ($removed > 0) {
            PageCache::purge();
        }

        return $removed;
    }
}
