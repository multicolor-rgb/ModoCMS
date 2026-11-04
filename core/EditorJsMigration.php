<?php
declare(strict_types=1);

namespace Core;

/**
 * Class EditorJsMigration
 *
 * One-time migration of content saved by the removed Editor.js block editor.
 * Legacy documents are converted to HTML with LegacyEditorContent::render(),
 * so TinyMCE and the rest of the CMS keep working on plain HTML afterwards.
 */
final class EditorJsMigration
{
    /** Settings flag marking the migration as completed. */
    public const OPTION = 'legacy_editorjs_migrated';

    /**
     * Tables that may contain content authored by Editor.js.
     *
     * @var list<string>
     */
    private const TABLES = ['pages', 'snippets', 'page_revisions'];

    /**
     * Whether the migration has already finished on this installation.
     */
    public static function isDone(): bool
    {
        return class_exists(Router::class)
            && Router::getOption(self::OPTION, '0') === '1';
    }

    /**
     * Runs the migration once, but only for admin requests. Frontend requests
     * keep using the legacy renderer until an administrator opens the panel.
     *
     * @return array<string,int>
     */
    public static function maybeRun(): array
    {
        if (!defined('IN_ADMIN') || IN_ADMIN !== true || self::isDone()) {
            return [];
        }

        try {
            return self::run();
        } catch (\Throwable $e) {
            // A failed migration must never take the admin panel down. The
            // legacy renderer keeps old content readable in the meantime.
            error_log('[ModoCMS] Editor.js migration failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Converts every legacy Editor.js document to HTML.
     *
     * @return array<string,int>
     */
    public static function run(): array
    {
        $db = Database::getConnection();
        $report = array_fill_keys(self::TABLES, 0);
        $total = 0;

        $db->beginTransaction();
        try {
            foreach (self::TABLES as $table) {
                $select = $db->prepare(
                    "SELECT id, content FROM {$table} WHERE content IS NOT NULL AND content <> '' AND content LIKE :blocks"
                );
                $select->execute([':blocks' => '%"blocks"%']);

                $update = $db->prepare("UPDATE {$table} SET content = :content WHERE id = :id");

                while ($row = $select->fetch(\PDO::FETCH_ASSOC)) {
                    $content = (string) ($row['content'] ?? '');
                    if (!LegacyEditorContent::isEditorJs($content)) {
                        continue;
                    }

                    $html = LegacyEditorContent::render($content);
                    if ($html === $content) {
                        continue;
                    }

                    $update->execute([
                        ':content' => $html,
                        ':id'      => (int) $row['id'],
                    ]);
                    $report[$table]++;
                    $total++;
                }
            }

            self::markDone($db);

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        if ($total > 0 && class_exists(PageCache::class)) {
            PageCache::purge();
        }

        $report['total'] = $total;
        return $report;
    }

    /**
     * Stores the completed flag and removes obsolete Editor.js settings.
     */
    private static function markDone(\PDO $db): void
    {
        $db->prepare(
            'INSERT OR REPLACE INTO settings (key, value) VALUES (:key, :value)'
        )->execute([
            ':key'   => self::OPTION,
            ':value' => '1',
        ]);

        $db->exec(
            "DELETE FROM settings WHERE key IN ('content_editor', 'editorjs_tools', 'editorjs_grid')"
        );
    }
}
