<?php
declare(strict_types=1);

namespace Core;

/**
 * Helpers for working with the hierarchical category tree.
 *
 * Categories use the same convention as pages: parent_id = 0 means a
 * top-level entry. The methods below tolerate missing parents and cycles in
 * legacy/corrupted data so the admin never enters an infinite loop.
 */
final class CategoryTree
{
    /**
     * Loads every category, optionally with a direct post counter.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function all(bool $withPostCounts = false): array
    {
        $db = Database::getConnection();

        if ($withPostCounts) {
            $sql = "
                SELECT c.*, (
                    SELECT COUNT(*)
                    FROM page_categories pc
                    JOIN pages p ON p.id = pc.page_id AND p.deleted_at IS NULL
                    WHERE pc.category_id = c.id AND p.type = 'post'
                ) AS post_count
                FROM categories c
                ORDER BY c.name ASC, c.id ASC
            ";
        } else {
            $sql = "SELECT c.* FROM categories c ORDER BY c.name ASC, c.id ASC";
        }

        $rows = $db->query($sql)->fetchAll();
        $categories = [];

        foreach ($rows as $row) {
            $categories[] = self::normalizeRow($row);
        }

        return $categories;
    }

    /**
     * Returns a flat pre-order list with a "depth" key on every entry.
     *
     * @param array<int,array<string,mixed>> $categories
     * @return array<int,array<string,mixed>>
     */
    public static function flatten(array $categories): array
    {
        $byId = self::normalizeAll($categories);
        $children = self::childrenMap($byId);
        $result = [];
        $visited = [];

        $walk = static function (int $parentId, int $depth) use (&$walk, &$children, &$byId, &$result, &$visited): void {
            foreach ($children[$parentId] ?? [] as $id) {
                if (isset($visited[$id])) {
                    continue;
                }
                $visited[$id] = true;
                $item = $byId[$id];
                $item['depth'] = $depth;
                $result[] = $item;
                $walk($id, $depth + 1);
            }
        };

        $walk(0, 0);

        // Safety net for cycles or entries whose parents were removed before
        // parent validation existed. Treat them as top-level entries.
        foreach ($byId as $id => $item) {
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            $item['parent_id'] = 0;
            $item['depth'] = 0;
            $result[] = $item;
            $walk($id, 1);
        }

        return $result;
    }

    /**
     * Returns a nested tree. Every entry receives a "children" array.
     *
     * @param array<int,array<string,mixed>> $categories
     * @return array<int,array<string,mixed>>
     */
    public static function tree(array $categories): array
    {
        $byId = self::normalizeAll($categories);
        $children = self::childrenMap($byId);
        $visited = [];

        $build = static function (int $parentId, int $depth) use (&$build, &$children, &$byId, &$visited): array {
            $branch = [];
            foreach ($children[$parentId] ?? [] as $id) {
                if (isset($visited[$id])) {
                    continue;
                }
                $visited[$id] = true;
                $item = $byId[$id];
                $item['depth'] = $depth;
                $item['children'] = $build($id, $depth + 1);
                $branch[] = $item;
            }
            return $branch;
        };

        $tree = $build(0, 0);

        foreach ($byId as $id => $item) {
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            $item['parent_id'] = 0;
            $item['depth'] = 0;
            $item['children'] = $build($id, 1);
            $tree[] = $item;
        }

        return $tree;
    }

    /**
     * Returns the IDs of all direct and indirect children of a category.
     *
     * @param array<int,array<string,mixed>> $categories
     * @return array<int,int>
     */
    public static function descendantIds(array $categories, int $categoryId): array
    {
        $byId = self::normalizeAll($categories);
        if (!isset($byId[$categoryId])) {
            return [];
        }

        $children = self::childrenMap($byId);
        $result = [];
        $queue = [$categoryId];
        $visited = [$categoryId => true];

        while ($queue !== []) {
            $current = (int) array_shift($queue);
            foreach ($children[$current] ?? [] as $childId) {
                if (isset($visited[$childId])) {
                    continue;
                }
                $visited[$childId] = true;
                $result[] = $childId;
                $queue[] = $childId;
            }
        }

        return $result;
    }

    /**
     * Checks whether $parentId can be assigned to the category $categoryId.
     * Pass 0 as $categoryId when creating a new category.
     *
     * @param array<int,array<string,mixed>> $categories
     */
    public static function isValidParent(array $categories, int $categoryId, int $parentId): bool
    {
        if ($parentId === 0) {
            return true;
        }

        $byId = self::normalizeAll($categories);
        if (!isset($byId[$parentId])) {
            return false;
        }

        if ($categoryId > 0) {
            if ($parentId === $categoryId) {
                return false;
            }
            if (in_array($parentId, self::descendantIds($categories, $categoryId), true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns a category by ID, or null when it does not exist.
     *
     * @param array<int,array<string,mixed>> $categories
     * @return array<string,mixed>|null
     */
    public static function find(array $categories, int $categoryId): ?array
    {
        foreach ($categories as $category) {
            if ((int) ($category['id'] ?? 0) === $categoryId) {
                return self::normalizeRow($category);
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function normalizeRow(array $row): array
    {
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['parent_id'] = (int) ($row['parent_id'] ?? 0);
        if (isset($row['post_count'])) {
            $row['post_count'] = (int) $row['post_count'];
        }

        return $row;
    }

    /**
     * @param array<int,array<string,mixed>> $categories
     * @return array<int,array<string,mixed>>
     */
    private static function normalizeAll(array $categories): array
    {
        $byId = [];

        foreach ($categories as $category) {
            $item = self::normalizeRow($category);
            if ($item['id'] > 0) {
                $byId[$item['id']] = $item;
            }
        }

        // Break references to missing/self parents before building the tree.
        foreach ($byId as $id => &$item) {
            $parentId = (int) $item['parent_id'];
            if ($parentId !== 0 && (!isset($byId[$parentId]) || $parentId === $id)) {
                $item['parent_id'] = 0;
            }
        }
        unset($item);

        return $byId;
    }

    /**
     * @param array<int,array<string,mixed>> $byId
     * @return array<int,array<int,int>>
     */
    private static function childrenMap(array $byId): array
    {
        $children = [];

        foreach ($byId as $id => $item) {
            $children[(int) $item['parent_id']][] = (int) $id;
        }

        return $children;
    }
}
