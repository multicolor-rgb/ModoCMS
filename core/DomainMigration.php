<?php
declare(strict_types=1);

namespace Core;

use PDO;
use Throwable;

/**
 * Class DomainMigration
 *
 * Rewrites every occurrence of an old host/domain into a new one across all
 * content-bearing tables and settings. This enables safe site moves such as
 * moving from "localhost" to a production domain or renaming a live domain.
 */
final class DomainMigration
{
    /**
     * Table => metadata describing its primary key and the columns that may
     * contain absolute URLs / references to the site host.
     */
    private const TARGETS = [
        'pages'      => ['pk' => ['id'],               'columns' => ['content', 'meta_title', 'meta_description', 'featured_image']],
        'page_meta'  => ['pk' => ['id'],               'columns' => ['meta_value']],
        'menu_items' => ['pk' => ['id'],               'columns' => ['url']],
        'settings'   => ['pk' => ['key'],              'columns' => ['value']],
        'theme_mods' => ['pk' => ['theme', 'mod_key'], 'columns' => ['mod_value']],
    ];

    /**
     * Normalizes a user supplied domain or URL into a bare, lowercase host.
     * Examples: "https://Old.Example.com/path/" -> "old.example.com",
     *           "http://localhost:8000/"        -> "localhost:8000".
     */
    public static function normalizeHost(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }

        // Strip scheme (http://, https://, ftp://, ...) and protocol-relative "//"
        $input = (string)preg_replace('#^[a-z][a-z0-9+.\-]*://#i', '', $input);
        $input = (string)preg_replace('#^//#', '', $input);

        // Keep only the authority component (drop path, query, fragment)
        $authority = preg_split('~[/?#]~', $input);
        $input = $authority[0] ?? $input;

        return strtolower(rtrim($input, '/'));
    }

    /**
     * Returns the alternate www / non-www representation of a host, or '' when
     * a sensible counterpart cannot be derived (IPs, ports, "localhost").
     */
    public static function wwwVariant(string $host): string
    {
        if ($host === '' || str_contains($host, ':') || filter_var($host, FILTER_VALIDATE_IP)) {
            return '';
        }

        if (str_starts_with($host, 'www.')) {
            $bare = substr($host, 4);
            return $bare !== '' ? $bare : '';
        }

        return str_contains($host, '.') ? 'www.' . $host : '';
    }

    /**
     * Builds the ordered [search => replace] map used for the replacement.
     *
     * @return array<string,string>
     */
    public static function buildPairs(string $oldHost, string $newHost, bool $includeWww): array
    {
        $pairs = [];

        if ($oldHost !== '' && $newHost !== '' && $oldHost !== $newHost) {
            $pairs[$oldHost] = $newHost;
        }

        if ($includeWww) {
            $oldWww = self::wwwVariant($oldHost);
            $newWww = self::wwwVariant($newHost);
            if ($oldWww !== '' && $newWww !== '' && $oldWww !== $newWww && !isset($pairs[$oldWww])) {
                $pairs[$oldWww] = $newWww;
            }
        }

        return $pairs;
    }

    /**
     * Dry run: counts how many rows would be rewritten by the migration.
     *
     * @return array<string,int> keyed by table name.
     */
    public static function preview(string $oldDomain, ?string $newDomain = null, bool $includeWww = false): array
    {
        $oldHost = self::normalizeHost($oldDomain);
        if ($oldHost === '') {
            return [];
        }

        $newHost = $newDomain !== null ? self::normalizeHost($newDomain) : '';

        if ($newHost !== '' && $newHost !== $oldHost) {
            $pairs = self::buildPairs($oldHost, $newHost, $includeWww);
        } else {
            // No target provided: count every occurrence of the old host.
            $pairs = [$oldHost => ''];
            if ($includeWww) {
                $variant = self::wwwVariant($oldHost);
                if ($variant !== '') {
                    $pairs[$variant] = '';
                }
            }
        }

        return self::process(array_keys($pairs), array_values($pairs), true);
    }

    /**
     * Runs the migration and refreshes generated SEO artifacts.
     *
     * @return array<string,int> number of rewritten rows per table.
     */
    public static function migrate(string $oldDomain, string $newDomain, bool $includeWww = false): array
    {
        $oldHost = self::normalizeHost($oldDomain);
        $newHost = self::normalizeHost($newDomain);

        if ($oldHost === '' || $newHost === '') {
            throw new \InvalidArgumentException('Both the old and the new domain are required.');
        }
        if ($oldHost === $newHost) {
            throw new \InvalidArgumentException('The old and the new domain are identical.');
        }

        $pairs = self::buildPairs($oldHost, $newHost, $includeWww);
        if (empty($pairs)) {
            return [];
        }

        $stats = self::process(array_keys($pairs), array_values($pairs), false);

        // Refresh static SEO artifacts so they point at the new domain.
        try {
            if (class_exists('Core\\Sitemap')) {
                Sitemap::generate();
                Sitemap::generateRobotsTxt();
            }
        } catch (Throwable $e) {
            // Non-fatal: sitemap/robots regeneration should never block the migration.
        }

        return $stats;
    }

    /**
     * Core replacement routine shared by preview() and migrate().
     *
     * @param string[] $search
     * @param string[] $replace
     * @return array<string,int>
     */
    private static function process(array $search, array $replace, bool $dryRun): array
    {
        $search = array_values(array_filter($search, static fn (string $s): bool => $s !== ''));
        if (empty($search)) {
            return [];
        }
        $replace = array_values($replace);

        $db = Database::getConnection();
        $stats = [];

        if (!$dryRun) {
            $db->beginTransaction();
        }

        try {
            foreach (self::TARGETS as $table => $meta) {
                $pkColumns = $meta['pk'];
                $columns = $meta['columns'];
                $selectColumns = array_values(array_unique(array_merge($pkColumns, $columns)));

                $selectSql = 'SELECT ' . implode(', ', array_map([self::class, 'quote'], $selectColumns))
                           . ' FROM ' . self::quote($table);

                $rows = $db->query($selectSql)->fetchAll(PDO::FETCH_ASSOC);
                $affected = 0;

                foreach ($rows as $row) {
                    $updates = [];
                    foreach ($columns as $column) {
                        $original = (string)($row[$column] ?? '');
                        if ($original === '') {
                            continue;
                        }

                        $replaced = str_ireplace($search, $replace, $original);
                        if ($replaced !== $original) {
                            $updates[$column] = $replaced;
                        }
                    }

                    if (empty($updates)) {
                        continue;
                    }

                    $affected++;

                    if ($dryRun) {
                        continue;
                    }

                    $setParts = [];
                    $params = [];
                    foreach ($updates as $column => $value) {
                        $placeholder = ':set_' . $column;
                        $setParts[] = self::quote($column) . ' = ' . $placeholder;
                        $params[$placeholder] = $value;
                    }

                    $whereParts = [];
                    foreach ($pkColumns as $keyColumn) {
                        $placeholder = ':pk_' . $keyColumn;
                        $whereParts[] = self::quote($keyColumn) . ' = ' . $placeholder;
                        $params[$placeholder] = $row[$keyColumn] ?? null;
                    }

                    $updateSql = 'UPDATE ' . self::quote($table)
                               . ' SET ' . implode(', ', $setParts)
                               . ' WHERE ' . implode(' AND ', $whereParts);

                    $db->prepare($updateSql)->execute($params);
                }

                $stats[$table] = $affected;
            }

            if (!$dryRun) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if (!$dryRun && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return $stats;
    }

    /**
     * Quotes a SQLite identifier (table or column name).
     */
    private static function quote(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
