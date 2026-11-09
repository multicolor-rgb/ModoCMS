<?php
declare(strict_types=1);

namespace Core;

use PDO;
use RuntimeException;

/**
 * Optional manual ordering for posts and pages.
 *
 * The feature is off by default. When it is enabled, documents keep a
 * per-language sort_order scoped to their type (posts) or parent page
 * (pages). Public loops use that order instead of the date-based default.
 */
final class ContentOrder
{
    public static function isEnabled(): bool
    {
        return Router::getOption('manual_content_order', '0') === '1';
    }

    /**
     * Assigns an order to documents that do not have one yet. Existing
     * manually ordered rows are left untouched.
     */
    public static function normalizeMissing(): void
    {
        $db = Database::getConnection();

        self::normalizeMissingPosts($db);
        self::normalizeMissingPages($db);
    }

    /**
     * SQL fragment for post loops. Callers append "ORDER BY " themselves.
     */
    public static function postsOrder(string $alias = 'p', string $dateColumn = 'created_at'): string
    {
        $alias = $alias !== '' ? $alias . '.' : '';
        $dateColumn = str_contains($dateColumn, '.') ? $dateColumn : $alias . $dateColumn;

        if (self::isEnabled()) {
            return $alias . 'sort_order ASC, ' . $dateColumn . ' DESC, ' . $alias . 'id DESC';
        }

        return $dateColumn . ' DESC, ' . $alias . 'id DESC';
    }

    /**
     * SQL fragment for page lists. Pages are normally listed in id order when
     * manual ordering is disabled.
     */
    public static function pagesOrder(string $alias = 'p'): string
    {
        $alias = $alias !== '' ? $alias . '.' : '';

        if (self::isEnabled()) {
            return $alias . 'sort_order ASC, ' . $alias . 'id ASC';
        }

        return $alias . 'id ASC';
    }

    /**
     * Returns the next position at the end of a sibling group.
     */
    public static function nextSortOrder(string $type, int $parentId, string $lang): int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT COALESCE(MAX(sort_order), 0) + 1
            FROM pages
            WHERE type = :type AND parent_id = :parent AND lang = :lang AND deleted_at IS NULL
        ");
        $stmt->execute([
            ':type' => $type === 'post' ? 'post' : 'page',
            ':parent' => $type === 'post' ? 0 : $parentId,
            ':lang' => $lang,
        ]);

        return max(1, (int) $stmt->fetchColumn());
    }

    /**
     * Persists a new order for a flat list (posts) or a sibling group (pages).
     *
     * @param array<int,int> $ids Ordered document IDs.
     */
    public static function reorder(array $ids, string $type, int $parentId = 0, string $lang = ''): int
    {
        if (!self::isEnabled()) {
            throw new RuntimeException('Manual content ordering is disabled.');
        }

        $type = $type === 'post' ? 'post' : 'page';
        $parentId = $type === 'post' ? 0 : max(0, $parentId);
        $db = Database::getConnection();

        $sql = "UPDATE pages SET sort_order = :sort_order
                WHERE id = :id AND type = :type AND parent_id = :parent AND deleted_at IS NULL";
        $params = [
            ':sort_order' => 0,
            ':id' => 0,
            ':type' => $type,
            ':parent' => $parentId,
        ];
        if ($lang !== '' && $lang !== 'all') {
            $sql .= " AND lang = :lang";
            $params[':lang'] = $lang;
        }

        $stmt = $db->prepare($sql);
        $updated = 0;
        $db->beginTransaction();
        try {
            foreach (array_values($ids) as $index => $id) {
                $id = (int) $id;
                if ($id <= 0) {
                    continue;
                }
                $params[':sort_order'] = $index + 1;
                $params[':id'] = $id;
                $stmt->execute($params);
                $updated += $stmt->rowCount();
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return $updated;
    }

    private static function normalizeMissingPosts(PDO $db): void
    {
        $maxStmt = $db->query("
            SELECT lang, COALESCE(MAX(sort_order), 0) AS max_order
            FROM pages
            WHERE type = 'post' AND deleted_at IS NULL
            GROUP BY lang
        ");
        $maxByLang = [];
        foreach ($maxStmt->fetchAll() as $row) {
            $maxByLang[(string) $row['lang']] = (int) $row['max_order'];
        }

        $stmt = $db->query("
            SELECT id, lang
            FROM pages
            WHERE type = 'post' AND deleted_at IS NULL AND sort_order = 0
            ORDER BY lang ASC, created_at DESC, id DESC
        ");
        $update = $db->prepare("UPDATE pages SET sort_order = :sort_order WHERE id = :id");
        foreach ($stmt->fetchAll() as $row) {
            $lang = (string) $row['lang'];
            $maxByLang[$lang] = ($maxByLang[$lang] ?? 0) + 1;
            $update->execute([
                ':sort_order' => $maxByLang[$lang],
                ':id' => (int) $row['id'],
            ]);
        }
    }

    private static function normalizeMissingPages(PDO $db): void
    {
        $maxStmt = $db->query("
            SELECT parent_id, lang, COALESCE(MAX(sort_order), 0) AS max_order
            FROM pages
            WHERE type = 'page' AND deleted_at IS NULL
            GROUP BY parent_id, lang
        ");
        $maxByGroup = [];
        foreach ($maxStmt->fetchAll() as $row) {
            $maxByGroup[(int) $row['parent_id'] . '|' . (string) $row['lang']] = (int) $row['max_order'];
        }

        $stmt = $db->query("
            SELECT id, parent_id, lang
            FROM pages
            WHERE type = 'page' AND deleted_at IS NULL AND sort_order = 0
            ORDER BY parent_id ASC, lang ASC, updated_at DESC, id ASC
        ");
        $update = $db->prepare("UPDATE pages SET sort_order = :sort_order WHERE id = :id");
        foreach ($stmt->fetchAll() as $row) {
            $key = (int) $row['parent_id'] . '|' . (string) $row['lang'];
            $maxByGroup[$key] = ($maxByGroup[$key] ?? 0) + 1;
            $update->execute([
                ':sort_order' => $maxByGroup[$key],
                ':id' => (int) $row['id'],
            ]);
        }
    }
}
