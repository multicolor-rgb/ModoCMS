<?php
declare(strict_types=1);

namespace Core;

/**
 * Class PageSlug
 * Builds URL friendly slugs that are guaranteed to be unique per language.
 */
final class PageSlug
{
    /**
     * Normalizes a value into a URL friendly slug.
     */
    public static function slugify(string $value): string
    {
        return Slug::slugify($value);
    }

    /**
     * Returns a slug that does not collide with an existing page or post in the
     * same language. Existing documents keep their slug because $ignoreId
     * excludes the document currently being saved.
     *
     * Duplicates are suffixed with -1, -2, -3, ... in the order they occur.
     */
    public static function unique(string $slug, string $lang, int $ignoreId = 0, ?\PDO $db = null): string
    {
        $base = self::slugify($slug);
        if ($base === '') {
            $base = 'page';
        }

        $db = $db ?? Database::getConnection();
        $candidate = $base;
        $suffix = 1;

        while (self::exists($db, $candidate, $lang, $ignoreId)) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Checks whether a slug is already used in the given language.
     */
    public static function exists(\PDO $db, string $slug, string $lang, int $ignoreId = 0): bool
    {
        $sql = "SELECT 1 FROM pages WHERE slug = :slug AND lang = :lang";
        $params = [':slug' => $slug, ':lang' => $lang];

        if ($ignoreId > 0) {
            $sql .= " AND id <> :id";
            $params[':id'] = $ignoreId;
        }

        $sql .= " LIMIT 1";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }
}
