<?php
declare(strict_types=1);

namespace Core;

/**
 * Class Redirects
 *
 * Stores and resolves permanent (301) redirects created automatically when a
 * page slug — or the slug of one of its ancestors — changes. Paths are stored
 * relative to the installation root (subfolder prefix excluded), exactly the
 * form Router::dispatch() normalises the request path into.
 */
final class Redirects
{
    public static function isEnabled(): bool
    {
        return Router::getOption('redirects_enabled', '1') === '1';
    }

    /** Canonicalises a path: "/", "/blog", "/about/team" (no trailing slash). */
    public static function normalize(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH) ?: $path;
        $path = '/' . ltrim((string)$path, '/');
        return $path !== '/' ? rtrim($path, '/') : $path;
    }

    /** Registers or refreshes a redirect; ignores loops and the site root. */
    public static function add(string $from, string $to): void
    {
        $from = self::normalize($from);
        $to = self::normalize($to);
        if ($from === '/' || $from === $to) {
            return;
        }
        try {
            $db = Database::getConnection();
            $db->prepare("
                INSERT INTO redirects (from_path, to_path) VALUES (:f, :t)
                ON CONFLICT(from_path) DO UPDATE SET to_path = :t
            ")->execute([':f' => $from, ':t' => $to]);
        } catch (\Throwable $e) {
            // Never block the caller on redirect bookkeeping failure.
        }
    }

    public static function find(string $path): ?array
    {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT id, from_path, to_path FROM redirects WHERE from_path = :p LIMIT 1");
            $stmt->execute([':p' => self::normalize($path)]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function hit(int $id): void
    {
        try {
            $db = Database::getConnection();
            $db->prepare("UPDATE redirects SET hits = hits + 1 WHERE id = :id")->execute([':id' => $id]);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public static function all(): array
    {
        try {
            $db = Database::getConnection();
            return $db->query("SELECT id, from_path, to_path, hits, created_at FROM redirects ORDER BY id DESC")->fetchAll();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function count(): int
    {
        try {
            return (int)Database::getConnection()->query("SELECT COUNT(*) FROM redirects")->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function delete(int $id): void
    {
        try {
            Database::getConnection()->prepare("DELETE FROM redirects WHERE id = :id")->execute([':id' => $id]);
        } catch (\Throwable $e) {
            // ignore
        }
    }

    public static function clear(): void
    {
        try {
            Database::getConnection()->exec("DELETE FROM redirects");
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /** Loads a compact id => row map to resolve hierarchies in one query. */
    public static function pageLookup(): array
    {
        $lookup = [];
        try {
            foreach (Database::getConnection()->query("SELECT id, parent_id, slug, lang, type FROM pages") as $row) {
                $lookup[(int)$row['id']] = $row;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return $lookup;
    }

    /**
     * Resolves the public request path for a page (language prefix included for
     * multilingual sites, subfolder prefix excluded).
     */
    public static function publicPathFromLookup(int $pageId, array $lookup): string
    {
        if (!isset($lookup[$pageId])) {
            return '';
        }

        $page = $lookup[$pageId];
        $segments = [];

        if (($page['type'] ?? '') === 'post') {
            $segments[] = Router::getPostsPageSlug();
            $segments[] = (string)$page['slug'];
        } else {
            $current = $pageId;
            $guard = 0;
            while ($current > 0 && isset($lookup[$current]) && $guard++ < 50) {
                if (($lookup[$current]['slug'] ?? '') !== 'home') {
                    array_unshift($segments, (string)$lookup[$current]['slug']);
                }
                $current = (int)($lookup[$current]['parent_id'] ?? 0);
            }
        }

        $prefix = '';
        $lang = (string)($page['lang'] ?? '');
        if (Router::getOption('multilingual_frontend', '0') === '1' && $lang !== '' && $lang !== I18n::getDefaultLocale()) {
            $prefix = rawurlencode($lang);
        }

        $full = trim($prefix . '/' . implode('/', array_map('rawurlencode', $segments)), '/');
        return $full === '' ? '/' : '/' . $full;
    }

    public static function publicPath(int $pageId): string
    {
        return self::publicPathFromLookup($pageId, self::pageLookup());
    }

    /** Ids of every descendant of a page (breadth-first). */
    public static function descendantIds(int $pageId, array $lookup): array
    {
        $children = [];
        foreach ($lookup as $id => $row) {
            $children[(int)($row['parent_id'] ?? 0)][] = $id;
        }

        $result = [];
        $queue = $children[$pageId] ?? [];
        $guard = 0;
        while (!empty($queue) && $guard++ < 5000) {
            $current = array_shift($queue);
            $result[] = $current;
            foreach ($children[$current] ?? [] as $child) {
                $queue[] = $child;
            }
        }

        return $result;
    }
}
