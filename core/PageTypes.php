<?php
declare(strict_types=1);

namespace Core;

use PDO;

/**
 * Predefined content types (for pages and posts) with a reusable set of
 * default custom fields.
 *
 * A page type describes the *preset* only: the document keeps its base type
 * in `pages.type` (`page` or `post`) and points to the preset through
 * `pages.page_type_id`. Custom field values still live in `page_meta`, so
 * themes keep using get_meta() / the_meta_gallery() unchanged.
 */
class PageTypes
{
    public const BASE_TYPES = ['page', 'post'];

    public const FIELD_TYPES = [
        'text',
        'textarea',
        'richtext',
        'tinymce',
        'gallery',
        'image',
        'checkbox',
        'number',
        'html',
        'php',
        'js',
        'css',
        'json',
        'code',
    ];

    private static function db(): PDO
    {
        return Database::getConnection();
    }

    public static function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);

        return trim($value, '-');
    }

    public static function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = (string) preg_replace('/[^a-z0-9_\-]+/', '_', $key);

        return trim($key, '_');
    }

    public static function normalizeFieldType(string $type): string
    {
        $type = strtolower(trim($type));

        return in_array($type, self::FIELD_TYPES, true) ? $type : 'text';
    }

    public static function normalizeBaseType(string $baseType): string
    {
        return in_array($baseType, self::BASE_TYPES, true) ? $baseType : 'page';
    }

    /**
     * Returns every type ordered by sort_order. When $withFields is true each
     * row also contains a `fields` array.
     */
    public static function all(bool $withFields = false): array
    {
        $rows = self::db()->query("SELECT * FROM page_types ORDER BY sort_order ASC, name ASC")->fetchAll();

        return $withFields ? self::attachFields($rows) : $rows;
    }

    public static function forBaseType(string $baseType, bool $withFields = false): array
    {
        $baseType = self::normalizeBaseType($baseType);
        $stmt = self::db()->prepare("SELECT * FROM page_types WHERE base_type = :base ORDER BY sort_order ASC, name ASC");
        $stmt->execute([':base' => $baseType]);
        $rows = $stmt->fetchAll();

        return $withFields ? self::attachFields($rows) : $rows;
    }

    /**
     * Finds a type by id and always includes its field definitions.
     */
    public static function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = self::db()->prepare("SELECT * FROM page_types WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $row['id'] = (int) $row['id'];
        $row['fields'] = self::fields((int) $row['id']);

        return $row;
    }

    public static function findBySlug(string $slug): ?array
    {
        $slug = self::slugify($slug);
        if ($slug === '') {
            return null;
        }

        $stmt = self::db()->prepare("SELECT * FROM page_types WHERE slug = :slug LIMIT 1");
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $row['id'] = (int) $row['id'];

        return $row;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function fields(int $typeId): array
    {
        if ($typeId <= 0) {
            return [];
        }

        $stmt = self::db()->prepare("SELECT * FROM page_type_fields WHERE type_id = :id ORDER BY sort_order ASC, id ASC");
        $stmt->execute([':id' => $typeId]);

        return array_map([self::class, 'castField'], $stmt->fetchAll());
    }

    /**
     * Creates or updates a type together with its field definitions.
     *
     * @param array{id?:int,name?:string,slug?:string,description?:string,base_type?:string,sort_order?:int} $data
     * @param array<int,array<string,mixed>> $fields
     * @throws \InvalidArgumentException
     */
    public static function save(array $data, array $fields = []): int
    {
        $id = (int) ($data['id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Page type name is required.');
        }

        $slug = self::slugify((string) ($data['slug'] ?? ''));
        if ($slug === '') {
            $slug = self::slugify($name);
        }
        if ($slug === '') {
            throw new \InvalidArgumentException('Page type slug is required.');
        }

        $baseType = self::normalizeBaseType((string) ($data['base_type'] ?? 'page'));
        $description = trim((string) ($data['description'] ?? ''));
        $sortOrder = (int) ($data['sort_order'] ?? 0);

        $db = self::db();
        $db->beginTransaction();

        try {
            if ($id > 0) {
                $stmt = $db->prepare("
                    UPDATE page_types
                    SET slug = :slug, name = :name, description = :description,
                        base_type = :base_type, sort_order = :sort_order,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':slug'        => $slug,
                    ':name'        => $name,
                    ':description' => $description,
                    ':base_type'   => $baseType,
                    ':sort_order'  => $sortOrder,
                    ':id'          => $id,
                ]);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO page_types (slug, name, description, base_type, sort_order)
                    VALUES (:slug, :name, :description, :base_type, :sort_order)
                ");
                $stmt->execute([
                    ':slug'        => $slug,
                    ':name'        => $name,
                    ':description' => $description,
                    ':base_type'   => $baseType,
                    ':sort_order'  => $sortOrder,
                ]);
                $id = (int) $db->lastInsertId();
            }

            // Replace the field set. Existing page values are untouched because
            // page_meta stores plain keys, not field ids.
            $db->prepare("DELETE FROM page_type_fields WHERE type_id = :id")->execute([':id' => $id]);

            $insert = $db->prepare("
                INSERT INTO page_type_fields
                    (type_id, field_key, label, field_type, default_value, required, sort_order)
                VALUES
                    (:type_id, :field_key, :label, :field_type, :default_value, :required, :sort_order)
            ");

            $seen = [];
            $order = 0;
            foreach ($fields as $field) {
                if (!is_array($field)) {
                    continue;
                }

                $key = self::normalizeKey((string) ($field['field_key'] ?? $field['key'] ?? ''));
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $label = trim((string) ($field['label'] ?? ''));
                if ($label === '') {
                    $label = $key;
                }

                $insert->execute([
                    ':type_id'       => $id,
                    ':field_key'     => $key,
                    ':label'         => $label,
                    ':field_type'    => self::normalizeFieldType((string) ($field['field_type'] ?? $field['type'] ?? 'text')),
                    ':default_value' => (string) ($field['default_value'] ?? $field['value'] ?? ''),
                    ':required'      => !empty($field['required']) ? 1 : 0,
                    ':sort_order'    => $order++,
                ]);
            }

            $db->commit();

            return $id;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Copies a type and all of its field definitions under a new slug.
     */
    public static function duplicate(int $id): ?int
    {
        $type = self::find($id);
        if (!$type) {
            return null;
        }

        $baseSlug = $type['slug'] . '-copy';
        $slug = $baseSlug;
        $suffix = 2;
        while (self::findBySlug($slug) !== null) {
            $slug = $baseSlug . '-' . $suffix++;
        }

        $fields = array_map(static function (array $field): array {
            return [
                'field_key'     => $field['field_key'],
                'label'         => $field['label'],
                'field_type'    => $field['field_type'],
                'default_value' => $field['default_value'],
                'required'      => $field['required'],
            ];
        }, $type['fields']);

        return self::save([
            'name'        => $type['name'] . ' (copy)',
            'slug'        => $slug,
            'description' => $type['description'] ?? '',
            'base_type'   => $type['base_type'],
            'sort_order'  => (int) $type['sort_order'] + 1,
        ], $fields);
    }

    /**
     * Deletes a type. Documents using it keep their content and custom fields;
     * only the `page_type_id` reference is cleared.
     */
    public static function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $db = self::db();
        $db->beginTransaction();

        try {
            $db->prepare("UPDATE pages SET page_type_id = NULL WHERE page_type_id = :id")->execute([':id' => $id]);
            $stmt = $db->prepare("DELETE FROM page_types WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $db->commit();

            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Builds the initial page_meta rows for a *new* document.
     *
     * @return array<int,array{meta_key:string,meta_value:string,meta_type:string,meta_label:string}>
     */
    public static function defaultFieldsFor(int $typeId): array
    {
        return array_map(static function (array $field): array {
            return [
                'meta_key'   => $field['field_key'],
                'meta_value' => $field['default_value'],
                'meta_type'  => $field['field_type'],
                'meta_label' => $field['label'] !== '' ? $field['label'] : $field['field_key'],
            ];
        }, self::fields($typeId));
    }

    /**
     * Adds missing fields (and optionally refreshes type/label metadata) on an
     * existing document. Never overwrites a non-empty value unless $overwrite
     * is true (and even then only metadata is refreshed).
     *
     * @return int Number of fields inserted.
     */
    public static function applyDefaults(int $pageId, int $typeId, bool $overwrite = false): int
    {
        if ($pageId <= 0 || $typeId <= 0) {
            return 0;
        }

        $fields = self::fields($typeId);
        if (empty($fields)) {
            return 0;
        }

        $db = self::db();
        $existingStmt = $db->prepare("SELECT meta_key FROM page_meta WHERE page_id = :pid");
        $existingStmt->execute([':pid' => $pageId]);
        $existing = [];
        foreach ($existingStmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
            $existing[(string) $key] = true;
        }

        $insert = $db->prepare("
            INSERT OR IGNORE INTO page_meta (page_id, meta_key, meta_value, meta_type, meta_label)
            VALUES (:pid, :key, :value, :type, :label)
        ");
        $update = $overwrite
            ? $db->prepare("
                UPDATE page_meta SET meta_type = :type, meta_label = :label
                WHERE page_id = :pid AND meta_key = :key
            ")
            : $db->prepare("
                UPDATE page_meta SET meta_type = :type, meta_label = :label
                WHERE page_id = :pid AND meta_key = :key AND (meta_label IS NULL OR meta_label = '')
            ");

        $inserted = 0;
        foreach ($fields as $field) {
            $key = $field['field_key'];
            $label = $field['label'] !== '' ? $field['label'] : $key;

            if (!isset($existing[$key])) {
                $insert->execute([
                    ':pid'   => $pageId,
                    ':key'   => $key,
                    ':value' => $field['default_value'],
                    ':type'  => $field['field_type'],
                    ':label' => $label,
                ]);
                $inserted++;
                continue;
            }

            // Keep the field definition in sync without touching its value.
            $update->execute([
                ':type'  => $field['field_type'],
                ':label' => $label,
                ':pid'   => $pageId,
                ':key'   => $key,
            ]);
        }

        return $inserted;
    }

    /**
     * Number of documents currently using the type.
     */
    public static function usageCount(int $typeId): int
    {
        if ($typeId <= 0) {
            return 0;
        }

        $stmt = self::db()->prepare("SELECT COUNT(*) FROM pages WHERE page_type_id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => $typeId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function attachFields(array $rows): array
    {
        $grouped = self::fieldsGrouped();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['fields'] = $grouped[(int) $row['id']] ?? [];
        }
        unset($row);

        return $rows;
    }

    /**
     * @return array<int,array<int,array<string,mixed>>>
     */
    private static function fieldsGrouped(): array
    {
        $rows = self::db()->query("SELECT * FROM page_type_fields ORDER BY type_id ASC, sort_order ASC, id ASC")->fetchAll();
        $grouped = [];
        foreach ($rows as $row) {
            $field = self::castField($row);
            $grouped[(int) $field['type_id']][] = $field;
        }

        return $grouped;
    }

    /**
     * @param array<string,mixed> $field
     * @return array<string,mixed>
     */
    private static function castField(array $field): array
    {
        return [
            'id'            => (int) ($field['id'] ?? 0),
            'type_id'       => (int) ($field['type_id'] ?? 0),
            'field_key'     => (string) ($field['field_key'] ?? ''),
            'label'         => (string) ($field['label'] ?? ''),
            'field_type'    => self::normalizeFieldType((string) ($field['field_type'] ?? 'text')),
            'default_value' => (string) ($field['default_value'] ?? ''),
            'required'      => (int) ($field['required'] ?? 0),
            'sort_order'    => (int) ($field['sort_order'] ?? 0),
        ];
    }
}
