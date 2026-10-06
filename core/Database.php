<?php
declare(strict_types=1);

namespace Core;

use PDO;
use PDOException;

/**
 * Class Database
 * Handles SQLite3 PDO connection, Write-Ahead Logging (WAL) configuration, and schema initialization.
 */
final class Database {
    private static ?PDO $instance = null;

    /**
     * Retrieves the shared PDO database instance (Singleton pattern).
     */
    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dataDir = __DIR__ . '/../data';
            if (!is_dir($dataDir)) {
                @mkdir($dataDir, 0775, true);
            }

            $dbPath = $dataDir . '/cms.sqlite';
            $isFirstRun = !file_exists($dbPath);

            try {
                self::$instance = new PDO('sqlite:' . $dbPath);
                self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

                // Optimization pragmas for concurrency and durability
                self::$instance->exec('PRAGMA journal_mode = WAL;');
                self::$instance->exec('PRAGMA synchronous = NORMAL;');
                self::$instance->exec('PRAGMA foreign_keys = ON;');
                self::$instance->exec('PRAGMA busy_timeout = 5000;');

                if ($isFirstRun) {
                    self::initSchema(self::$instance);
                } else {
                    // Apply additive migrations (idempotent) to existing databases.
                    self::ensureSchemaAdditions(self::$instance);
                }
            } catch (PDOException $e) {
                http_response_code(500);
                die('Database initialization error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
            }
        }
        return self::$instance;
    }

    /**
     * Initializes database table structures and populates default configuration records.
     */
    private static function initSchema(PDO $db): void {
        $db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                email TEXT UNIQUE NOT NULL,
                role TEXT NOT NULL DEFAULT 'editor',
                admin_lang TEXT NOT NULL DEFAULT 'en',
                api_token TEXT UNIQUE,
                reset_token TEXT UNIQUE,
                reset_expires DATETIME,
                totp_secret TEXT,
                totp_enabled INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS pages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER DEFAULT 0,
                slug TEXT NOT NULL,
                title TEXT NOT NULL,
                content TEXT,
                type TEXT NOT NULL DEFAULT 'page',
                status TEXT DEFAULT 'published',
                lang TEXT NOT NULL DEFAULT 'en',
                translation_group TEXT NOT NULL,
                featured_image TEXT,
                meta_title TEXT,
                meta_description TEXT,
                author_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE UNIQUE INDEX IF NOT EXISTS idx_pages_slug_lang ON pages(slug, lang);
            CREATE INDEX IF NOT EXISTS idx_pages_trans_group ON pages(translation_group);
            CREATE INDEX IF NOT EXISTS idx_pages_parent_id ON pages(parent_id);

            -- Tabela na pola niestandardowe (Custom Fields) z obsługą typów danych
            CREATE TABLE IF NOT EXISTS page_meta (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
                meta_key TEXT NOT NULL,
                meta_value TEXT,
                meta_type TEXT DEFAULT 'text',
                UNIQUE(page_id, meta_key)
            );
            CREATE INDEX IF NOT EXISTS idx_page_meta_page ON page_meta(page_id);

            -- Tabela na ustawienia Theme Customizera
            CREATE TABLE IF NOT EXISTS theme_mods (
                theme TEXT NOT NULL,
                mod_key TEXT NOT NULL,
                mod_value TEXT,
                PRIMARY KEY (theme, mod_key)
            );

            -- Tabela na schemę customizera tworzoną w builderze GUI (sekcje + kontrolki)
            CREATE TABLE IF NOT EXISTS customize_schema (
                theme TEXT PRIMARY KEY,
                schema TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS tags (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT NOT NULL UNIQUE
            );

            CREATE TABLE IF NOT EXISTS page_tags (
                page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
                tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
                PRIMARY KEY (page_id, tag_id)
            );
            CREATE INDEX IF NOT EXISTS idx_page_tags_page ON page_tags(page_id);
            CREATE INDEX IF NOT EXISTS idx_page_tags_tag ON page_tags(tag_id);

            CREATE TABLE IF NOT EXISTS menus (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT UNIQUE NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS menu_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                menu_id INTEGER NOT NULL REFERENCES menus(id) ON DELETE CASCADE,
                parent_id INTEGER DEFAULT 0,
                title TEXT NOT NULL,
                url TEXT NOT NULL,
                target TEXT DEFAULT '_self',
                sort_order INTEGER DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS media (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                filename TEXT NOT NULL,
                filepath TEXT NOT NULL,
                mime_type TEXT NOT NULL,
                file_size INTEGER NOT NULL,
                webp_path TEXT,
                user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT
            );

            CREATE TABLE IF NOT EXISTS plugins (
                folder TEXT PRIMARY KEY,
                is_active INTEGER DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS visits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                path TEXT NOT NULL,
                ip_hash TEXT NOT NULL,
                user_agent TEXT,
                visited_at DATE DEFAULT (DATE('now'))
            );
            CREATE INDEX IF NOT EXISTS idx_visits_date ON visits(visited_at);

            CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip_address TEXT NOT NULL,
                attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_time ON login_attempts(ip_address, attempted_at);

            CREATE TABLE IF NOT EXISTS redirects (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                from_path TEXT NOT NULL UNIQUE,
                to_path TEXT NOT NULL,
                hits INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE INDEX IF NOT EXISTS idx_redirects_from ON redirects(from_path);
        ");

        // Seed default administrator: admin / admin123
        $stmt = $db->prepare("INSERT OR IGNORE INTO users (id, username, password_hash, email, role, admin_lang) VALUES (1, 'admin', :h, 'admin@example.com', 'admin', 'en')");
        $stmt->execute([':h' => password_hash('admin123', PASSWORD_BCRYPT, ['cost' => 12])]);

        // Seed configuration settings
        $defaultSettings = [
            'site_title' => 'Modo CMS',
            'site_description' => 'Lightweight, modern SQLite3 powered CMS',
            'site_url' => '',
            'active_theme' => 'default',
            'posts_per_page' => '6',
            'multilingual_frontend' => '0',
            'default_language' => 'en',
            'available_languages' => 'en:English,pl:Polski',
            'header_menu_slug' => 'main-menu',
            'lang_switcher_style' => 'inline',
            'security_brute_force_enabled' => '1',
            'security_headers_enabled' => '1',
            'security_brute_force_max' => '5',
            'security_brute_force_window' => '15',
            'security_2fa_enabled' => '0',
            'cache_enabled' => '0',
            'cache_ttl' => '3600',
            'webp_enabled' => '0',
            'webp_quality' => '82',
            'redirects_enabled' => '1',
            'hreflang_enabled' => '0',
        ];

        $setStmt = $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (:k, :v)");
        foreach ($defaultSettings as $k => $v) {
            $setStmt->execute([':k' => $k, ':v' => $v]);
        }

        // Demo content: English
        $db->exec("
            INSERT OR IGNORE INTO pages (id, parent_id, slug, title, content, type, status, lang, translation_group, author_id)
            VALUES (1, 0, 'home', 'Welcome to Modo CMS', '<p>Welcome to your fast, modular and secure CMS powered by SQLite3.</p>', 'page', 'published', 'en', 'home-group', 1);

            INSERT OR IGNORE INTO pages (id, parent_id, slug, title, content, type, status, lang, translation_group, author_id)
            VALUES (2, 0, 'first-post', 'First Blog Post', '<p>This is your first blog post generated inside the articles loop.</p>', 'post', 'published', 'en', 'first-post-group', 1);
        ");

        // Demo content: Polish translation
        $db->exec("
            INSERT OR IGNORE INTO pages (id, parent_id, slug, title, content, type, status, lang, translation_group, author_id)
            VALUES (3, 0, 'home', 'Witaj w Modo CMS', '<p>Witamy w szybkim, modułowym i bezpiecznym systemie CMS opartym o SQLite3.</p>', 'page', 'published', 'pl', 'home-group', 1);

            INSERT OR IGNORE INTO pages (id, parent_id, slug, title, content, type, status, lang, translation_group, author_id)
            VALUES (4, 0, 'pierwszy-artykul', 'Pierwszy artykuł na blogu', '<p>To jest Twój pierwszy wpis wygenerowany w pętli artykułów.</p>', 'post', 'published', 'pl', 'first-post-group', 1);
        ");

        // Demo Custom Fields dla strony domowej (przykład wykorzystania page_meta)
        $db->exec("
            INSERT OR IGNORE INTO page_meta (page_id, meta_key, meta_value, meta_type)
            VALUES (1, 'banner_subtitle', 'Fast, lightweight and independent CMS', 'text');
            
            INSERT OR IGNORE INTO page_meta (page_id, meta_key, meta_value, meta_type)
            VALUES (3, 'banner_subtitle', 'Szybki, lekki i niezależny CMS', 'text');
        ");

        // Default Main Menu
        $db->exec("INSERT OR IGNORE INTO menus (id, name, slug) VALUES (1, 'Main Menu', 'main-menu')");
        $db->exec("INSERT OR IGNORE INTO menu_items (menu_id, parent_id, title, url, sort_order) VALUES (1, 0, 'Home', '/', 1)");
        $db->exec("INSERT OR IGNORE INTO menu_items (menu_id, parent_id, title, url, sort_order) VALUES (1, 0, 'Blog', '/blog', 2)");
    }

    /**
     * Applies additive, idempotent schema changes so databases created by older
     * versions of the CMS pick up newly introduced tables without a reinstall.
     */
    private static function ensureSchemaAdditions(PDO $db): void
    {
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS customize_schema (
                    theme TEXT PRIMARY KEY,
                    schema TEXT NOT NULL
                );

                CREATE TABLE IF NOT EXISTS login_attempts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    ip_address TEXT NOT NULL,
                    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_time ON login_attempts(ip_address, attempted_at);

                CREATE TABLE IF NOT EXISTS redirects (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    from_path TEXT NOT NULL UNIQUE,
                    to_path TEXT NOT NULL,
                    hits INTEGER NOT NULL DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_redirects_from ON redirects(from_path);
            ");
        } catch (PDOException $e) {
            // Never block the request on an optional migration failure.
        }

        // Additive column migrations (idempotent — guarded by a column presence check).
        self::ensureColumn($db, 'users', 'totp_secret', 'TEXT');
        self::ensureColumn($db, 'users', 'totp_enabled', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn($db, 'media', 'webp_path', 'TEXT');

        // Seed newly introduced settings on databases created by older versions.
        try {
            $seed = [
                'security_brute_force_enabled' => '1',
                'security_headers_enabled' => '1',
                'security_brute_force_max' => '5',
                'security_brute_force_window' => '15',
                'security_2fa_enabled' => '0',
                'cache_enabled' => '0',
                'cache_ttl' => '3600',
                'webp_enabled' => '0',
                'webp_quality' => '82',
                'redirects_enabled' => '1',
                'hreflang_enabled' => '0',
            ];
            $stmt = $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (:k, :v)");
            foreach ($seed as $k => $v) {
                $stmt->execute([':k' => $k, ':v' => $v]);
            }
        } catch (PDOException $e) {
            // Optional — ignore.
        }
    }

    /**
     * Adds a column to an existing table only when it is not already present.
     * SQLite has no "ADD COLUMN IF NOT EXISTS", so the current schema is
     * inspected through PRAGMA table_info first.
     */
    private static function ensureColumn(PDO $db, string $table, string $column, string $definition): void
    {
        try {
            $exists = false;
            foreach ($db->query("PRAGMA table_info(" . $table . ")") as $info) {
                if (($info['name'] ?? '') === $column) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        } catch (PDOException $e) {
            // Never block the request on an optional migration failure.
        }
    }
}