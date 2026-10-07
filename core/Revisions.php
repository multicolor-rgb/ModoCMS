<?php
declare(strict_types=1);

namespace Core;

use PDO;

/**
 * Class Revisions
 * Lightweight version history (backups) for posts and pages stored in SQLite.
 *
 * A "revision" is an immutable snapshot of a document (core fields, custom
 * fields and tags) taken before an overwrite, automatically while editing
 * (autosave) or on demand (manual). Any revision can be restored, and the
 * pre-restore state is itself snapshotted so the operation is reversible.
 */
final class Revisions
{
    private const ALLOWED_SOURCES = ['save', 'autosave', 'manual', 'restore'];

    private static function db(): PDO
    {
        return Database::getConnection();
    }

    /** Whether the version-history feature is enabled in Settings. */
    public static function isEnabled(): bool
    {
        return Router::getOption('revisions_enabled', '1') === '1';
    }

    /** Maximum number of revisions kept per document. */
    public static function maxPerPage(): int
    {
        $max = (int) Router::getOption('revisions_max', '30');
        return max(1, min(500, $max));
    }

    /** Autosave interval in seconds. */
    public static function autosaveInterval(): int
    {
        $seconds = (int) Router::getOption('autosave_interval', '60');
        return max(15, min(3600, $seconds));
    }

    /**
     * Snapshots the CURRENT database state of a document before it is
     * overwritten. Returns the new revision id, or null when nothing was stored
     * (feature disabled, document missing, or identical to the latest revision).
     */
    public static function snapshot(int $pageId, string $source = 'save', ?string $note = null): ?int
    {
        if ($pageId <= 0 || !self::isEnabled()) {
            return null;
        }

        $stmt = self::db()->prepare("SELECT * FROM pages WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $pageId]);
        $page = $stmt->fetch();
        if (!$page) {
            return null;
        }

        return self::store($pageId, $page, $source, $note, true);
    }

    /**
     * Stores a revision from an arbitrary data set (used both by snapshot()
     * and by the autosave endpoint, which supplies unsaved editor content).
     * When $dedupe is true the insert is skipped if it matches the newest
     * revision of that page, preventing a flood of identical rows.
     */
    public static function store(int $pageId, array $data, string $source = 'save', ?string $note = null, bool $dedupe = true): ?int
    {
        if ($pageId <= 0 || !self::isEnabled()) {
            return null;
        }

        if (!in_array($source, self::ALLOWED_SOURCES, true)) {
            $source = 'save';
        }

        $fields   = self::normalize($data);
        $metaJson = isset($data['meta_json']) && $data['meta_json'] !== null
            ? (string) $data['meta_json']
            : self::collectMeta($pageId);
        $tags     = isset($data['tags']) && $data['tags'] !== null
            ? (string) $data['tags']
            : self::collectTags($pageId);

        $hash = sha1(
            $fields['title'] . "\x00" . $fields['content'] . "\x00" . $fields['slug'] . "\x00"
            . $fields['status'] . "\x00" . $metaJson . "\x00" . $tags
        );

        if ($dedupe) {
            $last = self::db()->prepare("SELECT content_hash FROM page_revisions WHERE page_id = :pid ORDER BY id DESC LIMIT 1");
            $last->execute([':pid' => $pageId]);
            $lastHash = $last->fetchColumn();
            if ($lastHash !== false && $lastHash === $hash) {
                return null;
            }
        }

        $authorId = $fields['author_id'] > 0 ? $fields['author_id'] : (int) Auth::id();

        $stmt = self::db()->prepare("
            INSERT INTO page_revisions (
                page_id, title, slug, content, type, status, parent_id, lang,
                translation_group, featured_image, meta_title, meta_description,
                meta_json, tags, author_id, source, note, content_hash
            ) VALUES (
                :page_id, :title, :slug, :content, :type, :status, :parent_id, :lang,
                :translation_group, :featured_image, :meta_title, :meta_description,
                :meta_json, :tags, :author_id, :source, :note, :content_hash
            )
        ");
        $stmt->execute([
            ':page_id'           => $pageId,
            ':title'             => $fields['title'],
            ':slug'              => $fields['slug'],
            ':content'           => $fields['content'],
            ':type'              => $fields['type'],
            ':status'            => $fields['status'],
            ':parent_id'         => $fields['parent_id'],
            ':lang'              => $fields['lang'],
            ':translation_group' => $fields['translation_group'],
            ':featured_image'    => $fields['featured_image'],
            ':meta_title'        => $fields['meta_title'],
            ':meta_description'  => $fields['meta_description'],
            ':meta_json'         => $metaJson,
            ':tags'              => $tags,
            ':author_id'         => $authorId,
            ':source'            => $source,
            ':note'              => $note,
            ':content_hash'      => $hash,
        ]);

        $revisionId = (int) self::db()->lastInsertId();
        self::prune($pageId);

        return $revisionId;
    }

    /**
     * Lists revisions for a document (newest first) without the heavy content
     * payload, so the editor panel stays light. Set $limit > 0 to cap results.
     */
    public static function list(int $pageId, int $limit = 0): array
    {
        $sql = "SELECT id, page_id, title, slug, type, status, source, note, author_id,
                       created_at, LENGTH(content) AS content_len
                FROM page_revisions
                WHERE page_id = :pid
                ORDER BY created_at DESC, id DESC";
        if ($limit > 0) {
            $sql .= " LIMIT :lim";
        }

        $stmt = self::db()->prepare($sql);
        $stmt->bindValue(':pid', $pageId, PDO::PARAM_INT);
        if ($limit > 0) {
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** Returns a full revision row (including content & meta snapshot). */
    public static function get(int $revisionId): ?array
    {
        $stmt = self::db()->prepare("SELECT * FROM page_revisions WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $revisionId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** Number of stored revisions for a document. */
    public static function count(int $pageId): int
    {
        $stmt = self::db()->prepare("SELECT COUNT(*) FROM page_revisions WHERE page_id = :pid");
        $stmt->execute([':pid' => $pageId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Restores a document from a revision. The current (pre-restore) state is
     * snapshotted first, so a restore is always reversible:
     *   1. core page row   2. custom fields (page_meta)   3. tags (posts).
     */
    public static function restore(int $revisionId): bool
    {
        $rev = self::get($revisionId);
        if (!$rev) {
            return false;
        }

        $pageId = (int) $rev['page_id'];
        if ($pageId <= 0) {
            return false;
        }

        $db = self::db();

        // Make the restore itself reversible.
        self::snapshot($pageId, 'restore');

        // 1) Core page row.
        $update = $db->prepare("
            UPDATE pages SET
                title = :title, slug = :slug, content = :content, type = :type, status = :status,
                parent_id = :parent_id, lang = :lang, translation_group = :translation_group,
                featured_image = :featured_image, meta_title = :meta_title, meta_description = :meta_description,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $update->execute([
            ':title'             => (string) $rev['title'],
            ':slug'              => (string) $rev['slug'],
            ':content'           => (string) $rev['content'],
            ':type'              => in_array($rev['type'] ?? '', ['post', 'page'], true) ? $rev['type'] : 'page',
            ':status'            => (string) ($rev['status'] ?: 'published'),
            ':parent_id'         => (int) $rev['parent_id'],
            ':lang'              => (string) $rev['lang'],
            ':translation_group' => (string) $rev['translation_group'],
            ':featured_image'    => (string) $rev['featured_image'],
            ':meta_title'        => (string) $rev['meta_title'],
            ':meta_description'  => (string) $rev['meta_description'],
            ':id'                => $pageId,
        ]);

        // 2) Custom fields.
        $db->prepare("DELETE FROM page_meta WHERE page_id = :pid")->execute([':pid' => $pageId]);
        $metaFields = json_decode((string) $rev['meta_json'], true);
        if (is_array($metaFields)) {
            $metaInsert = $db->prepare("INSERT OR REPLACE INTO page_meta (page_id, meta_key, meta_value, meta_type) VALUES (:pid, :k, :v, :t)");
            $seen = [];
            foreach ($metaFields as $m) {
                $metaKey = strtolower(trim((string) preg_replace('/[^A-Za-z0-9_\-]+/', '_', (string) ($m['key'] ?? '')), '_'));
                if ($metaKey === '' || in_array($metaKey, $seen, true)) {
                    continue;
                }
                $seen[] = $metaKey;
                $metaInsert->execute([
                    ':pid' => $pageId,
                    ':k'   => $metaKey,
                    ':v'   => (string) ($m['value'] ?? ''),
                    ':t'   => (string) ($m['type'] ?? 'text'),
                ]);
            }
        }

        // 3) Tags (posts only), using the same normalization as the editor save.
        $db->prepare("DELETE FROM page_tags WHERE page_id = :pid")->execute([':pid' => $pageId]);
        if (($rev['type'] ?? '') === 'post' && !empty($rev['tags'])) {
            $tagList = array_unique(array_filter(array_map('trim', explode(',', (string) $rev['tags']))));
            foreach ($tagList as $rawTag) {
                $tagSlug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $rawTag), '-'));
                if ($tagSlug === '') {
                    continue;
                }
                $tagCheck = $db->prepare("SELECT id FROM tags WHERE slug = :s LIMIT 1");
                $tagCheck->execute([':s' => $tagSlug]);
                $tagId = $tagCheck->fetchColumn();
                if (!$tagId) {
                    $tagInsert = $db->prepare("INSERT INTO tags (name, slug) VALUES (:n, :s)");
                    $tagInsert->execute([':n' => $rawTag, ':s' => $tagSlug]);
                    $tagId = (int) $db->lastInsertId();
                }
                $db->prepare("INSERT OR IGNORE INTO page_tags (page_id, tag_id) VALUES (:pid, :tid)")
                   ->execute([':pid' => $pageId, ':tid' => (int) $tagId]);
            }
        }

        // Invalidate caches and fire the same hook as a regular save.
        if (class_exists(PageCache::class)) {
            PageCache::purge();
        }
        Hooks::doAction('admin-save-page', $pageId);

        return true;
    }

    /** Deletes a single revision. */
    public static function delete(int $revisionId): bool
    {
        $stmt = self::db()->prepare("DELETE FROM page_revisions WHERE id = :id");
        $stmt->execute([':id' => $revisionId]);

        return $stmt->rowCount() > 0;
    }

    /** Keeps only the newest N revisions for a document. */
    public static function prune(int $pageId): void
    {
        $max = self::maxPerPage();
        $db = self::db();

        $keep = $db->prepare("SELECT id FROM page_revisions WHERE page_id = :pid ORDER BY created_at DESC, id DESC LIMIT :lim");
        $keep->bindValue(':pid', $pageId, PDO::PARAM_INT);
        $keep->bindValue(':lim', $max, PDO::PARAM_INT);
        $keep->execute();
        $ids = $keep->fetchAll(PDO::FETCH_COLUMN);

        if (empty($ids)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $del = $db->prepare("DELETE FROM page_revisions WHERE page_id = ? AND id NOT IN ($placeholders)");
        $del->execute(array_merge([$pageId], $ids));
    }

    /** Normalizes a page/revision row into the scalar fields we persist. */
    private static function normalize(array $row): array
    {
        return [
            'title'             => (string) ($row['title'] ?? ''),
            'slug'              => (string) ($row['slug'] ?? ''),
            'content'           => (string) ($row['content'] ?? ''),
            'type'              => (string) ($row['type'] ?? 'page'),
            'status'            => (string) ($row['status'] ?? 'published'),
            'parent_id'         => (int) ($row['parent_id'] ?? 0),
            'lang'              => (string) ($row['lang'] ?? ''),
            'translation_group' => (string) ($row['translation_group'] ?? ''),
            'featured_image'    => (string) ($row['featured_image'] ?? ''),
            'meta_title'        => (string) ($row['meta_title'] ?? ''),
            'meta_description'  => (string) ($row['meta_description'] ?? ''),
            'author_id'         => (int) ($row['author_id'] ?? 0),
        ];
    }

    /** Serializes the document's custom fields into a JSON snapshot. */
    private static function collectMeta(int $pageId): string
    {
        $stmt = self::db()->prepare("SELECT meta_key, meta_value, meta_type FROM page_meta WHERE page_id = :pid ORDER BY id ASC");
        $stmt->execute([':pid' => $pageId]);

        $fields = [];
        foreach ($stmt->fetchAll() as $m) {
            $fields[] = [
                'key'   => (string) $m['meta_key'],
                'value' => (string) $m['meta_value'],
                'type'  => (string) $m['meta_type'],
            ];
        }

        return (string) json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Serializes the document's tags into a comma-separated list. */
    private static function collectTags(int $pageId): string
    {
        $stmt = self::db()->prepare("
            SELECT t.name
            FROM tags t
            JOIN page_tags pt ON pt.tag_id = t.id
            WHERE pt.page_id = :pid
            ORDER BY t.name ASC
        ");
        $stmt->execute([':pid' => $pageId]);

        return implode(', ', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
