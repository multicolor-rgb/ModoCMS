<?php
declare(strict_types=1);

$dataDir = __DIR__ . '/data';
$backupDir = $dataDir . '/backups';
$uploadsDir = __DIR__ . '/uploads';
$uploadsCacheDir = $uploadsDir . '/cache';
$dbFile = $dataDir . '/cms.sqlite';
$langDir = __DIR__ . '/languages';

// Prevent re-installation if database exists
if (file_exists($dbFile)) {
    die('<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ModoCMS &bull; Already Installed</title>
        <link rel="stylesheet" href="admin/assets/css/admin.css">
        <style>
            :root {
                color-scheme: dark;
                --bg-body: #0b0f19;
                --bg-card: #111827;
                --border-color: #1f2937;
                --text-main: #f9fafb;
                --text-muted: #9ca3af;
                --accent-primary: #3b82f6;
                --warning-border: rgba(245, 158, 11, 0.3);
                --warning-bg: rgba(245, 158, 11, 0.08);
                --warning-text: #fbbf24;
            }
            body {
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                margin: 0;
                background: var(--bg-body);
                font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                color: var(--text-main);
                padding: 20px;
                box-sizing: border-box;
            }
            .notice-card {
                width: 100%;
                max-width: 460px;
                background: var(--bg-card);
                border: 1px solid var(--border-color);
                border-radius: 16px;
                padding: 36px 32px;
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
                text-align: center;
            }
            .brand-badge {
                width: 44px;
                height: 44px;
                background: linear-gradient(135deg, #2563eb, #1d4ed8);
                color: #fff;
                font-weight: 800;
                font-size: 20px;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                border-radius: 10px;
                margin-bottom: 20px;
                box-shadow: 0 8px 16px -4px rgba(37, 99, 235, 0.4);
            }
            h2 { margin: 0 0 12px 0; font-size: 20px; font-weight: 700; }
            p { color: var(--text-muted); font-size: 14px; line-height: 1.6; margin: 0 0 20px 0; }
            .warning-box {
                background: var(--warning-bg);
                border: 1px solid var(--warning-border);
                color: var(--warning-text);
                padding: 12px 14px;
                border-radius: 8px;
                font-size: 13px;
                line-height: 1.5;
                margin-bottom: 24px;
                text-align: left;
            }
            code { background: rgba(0, 0, 0, 0.3); color: #93c5fd; padding: 2px 6px; border-radius: 4px; font-size: 12px; font-family: ui-monospace, monospace; }
            .btn {
                display: inline-block;
                width: 100%;
                padding: 12px;
                background: var(--accent-primary);
                color: #fff;
                text-decoration: none;
                font-weight: 600;
                font-size: 14px;
                border-radius: 8px;
                box-sizing: border-box;
                transition: background 0.2s ease;
            }
            .btn:hover { background: #2563eb; }
        </style>
    </head>
    <body>
        <div class="notice-card">
            <div class="brand-badge">M</div>
            <h2>ModoCMS is already installed</h2>
            <p>Your website is already configured and running.</p>
            <div class="warning-box">
                <strong>Security recommendation:</strong><br>
                For security reasons, please delete the <code>install.php</code> file from your server root directory.
            </div>
            <a href="admin/" class="btn">Go to Admin Panel &rarr;</a>
        </div>
    </body>
    </html>');
}

// Auto-scan languages directory and parse JSON packs
$availableLangs = [];
$translations = [];

if (is_dir($langDir)) {
    foreach (glob($langDir . '/*.json') as $file) {
        $code = strtolower(pathinfo($file, PATHINFO_FILENAME));
        $content = @file_get_contents($file);
        if ($content) {
            $data = json_decode($content, true);
            if (is_array($data)) {
                $name = $data['_meta']['name'] ?? strtoupper($code);
                $availableLangs[$code] = $name;
                $translations[$code] = $data;
            }
        }
    }
}

// Fallback dictionary if languages directory is empty
if (empty($availableLangs)) {
    $availableLangs['en'] = 'English';
    $translations['en'] = [
        'setup_title' => 'ModoCMS Setup',
        'setup_desc' => 'Configure your lightweight instance',
        'site_title' => 'Site Title',
        'default_site_title' => 'My Website',
        'language' => 'Language',
        'admin_username' => 'Admin Username',
        'admin_email' => 'Admin Email',
        'admin_password' => 'Admin Password',
        'password_placeholder' => 'Min. 6 characters',
        'complete_install' => 'Complete Installation',
        'fill_all_fields' => 'Please fill all required fields. Password must be at least 6 characters.',
        'welcome_page_title' => 'Welcome to your new website',
        'welcome_page_content' => '<p>ModoCMS has been successfully installed and configured.</p>',
        'first_post_title' => 'First Post',
        'first_post_content' => '<p>This is your first article published using ModoCMS.</p>',
        'main_menu' => 'Main Menu',
        'home' => 'Home',
        'blog' => 'Blog'
    ];
}

$initialLocale = isset($_GET['lang']) && isset($availableLangs[$_GET['lang']]) ? $_GET['lang'] : (isset($availableLangs['pl']) ? 'pl' : array_key_first($availableLangs));

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $defaultLang = trim($_POST['default_lang'] ?? $initialLocale);
    if (!isset($availableLangs[$defaultLang])) {
        $defaultLang = array_key_first($availableLangs);
    }
    $t = $translations[$defaultLang] ?? $translations[array_key_first($translations)];

    $siteTitle = trim($_POST['site_title'] ?? ($t['default_site_title'] ?? 'My Website'));
    $username = trim($_POST['username'] ?? 'admin');
    $email = trim($_POST['email'] ?? 'admin@example.com');
    $password = $_POST['password'] ?? '';

    if (empty($siteTitle) || empty($username) || empty($email) || strlen($password) < 6) {
        $error = $t['fill_all_fields'] ?? 'Please fill all required fields. Password must be at least 6 characters.';
    } else {
        // Ensure system directories exist
        foreach ([$dataDir, $backupDir, $uploadsDir, $uploadsCacheDir] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }

        // Restrict direct HTTP access to data and backup directories
        $htaccessContent = "# Prevent direct access to databases and backups\n<IfModule authz_core_module>\n    Require all denied\n</IfModule>\n<IfModule !authz_core_module>\n    Deny from all\n</IfModule>\n";
        @file_put_contents($dataDir . '/.htaccess', $htaccessContent);
        @file_put_contents($backupDir . '/.htaccess', $htaccessContent);

        // Ensure a portable root .htaccess so the CMS runs both in the document
        // root and inside a subfolder (no hardcoded RewriteBase).
        // Generate it when missing, or self-heal a stale copy that hardcodes RewriteBase.
        $rootHtaccessPath = __DIR__ . '/.htaccess';
        $portableHtaccess = <<<'HTACCESS'
# Disable directory listing and enable symbolic links
Options -Indexes +FollowSymLinks
ServerSignature Off

# Security headers
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>

# Block direct access to database files, logs, hidden files, and SQLite caches
<FilesMatch "(^\..*|\.sqlite.*|\.db|\.sql|\.log|\.ini|\.json)$">
    Require all denied
</FilesMatch>

# Block direct access to data and languages directories
RedirectMatch 403 ^.*/data/.*$
RedirectMatch 403 ^.*/languages/.*$

# Rewrite Engine Configuration
<IfModule mod_rewrite.c>
    RewriteEngine On

    # NOTE: RewriteBase is intentionally NOT set. Apache derives the base path
    # from the directory that contains this .htaccess file, so the CMS works
    # both when installed in the document root (https://domain/) and inside a
    # subfolder (https://domain/subfolder/). Do not hardcode RewriteBase here.

    # Enforce trailing slash on /admin directory requests
    RewriteRule ^admin$ admin/ [R=301,L]

    # Rule A: Single-folder request -> nested-folder physical file
    # Example: plugins/autoLightbox/glightbox/g.js -> plugins/autoLightbox/autoLightbox/glightbox/g.js
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_URI} ^(.*)/plugins/([^/]+)/(.*)$
    RewriteCond %{DOCUMENT_ROOT}%1/plugins/%2/%2/%3 -f
    RewriteRule ^plugins/([^/]+)/(.*)$ plugins/$1/$1/$2 [L]

    # Rule B: Nested-folder request -> flat-folder physical file
    # Example: plugins/autoLightbox/autoLightbox/glightbox/g.js -> plugins/autoLightbox/glightbox/g.js
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_URI} ^(.*)/plugins/([^/]+)/([^/]+)/(.*)$
    RewriteCond %{DOCUMENT_ROOT}%1/plugins/%2/%4 -f
    RewriteRule ^plugins/([^/]+)/\1/(.*)$ plugins/$1/$2 [L]

    # Serve existing physical files and directories directly
    RewriteCond %{REQUEST_FILENAME} -f [OR]
    RewriteCond %{REQUEST_FILENAME} -d
    RewriteRule ^ - [L]

    # Route all frontend traffic through the front controller
    RewriteRule ^ index.php [L,QSA]
</IfModule>
HTACCESS;

        $existingRootHtaccess = is_file($rootHtaccessPath) ? (string)@file_get_contents($rootHtaccessPath) : null;
        $hasHardcodedRewriteBase = ($existingRootHtaccess !== null)
            && (bool)preg_match('/^\s*RewriteBase\s+\S+/mi', $existingRootHtaccess);

        if ($existingRootHtaccess === null || $hasHardcodedRewriteBase) {
            @file_put_contents($rootHtaccessPath, $portableHtaccess . "\n");
        }

        try {
            $pdo = new PDO('sqlite:' . $dbFile);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA journal_mode = WAL;');
            $pdo->exec('PRAGMA synchronous = NORMAL;');
            $pdo->exec('PRAGMA foreign_keys = ON;');

            $pdo->exec("
                CREATE TABLE users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT UNIQUE NOT NULL,
                    password_hash TEXT NOT NULL,
                    email TEXT UNIQUE NOT NULL,
                    role TEXT NOT NULL DEFAULT 'admin',
                    admin_lang TEXT NOT NULL DEFAULT 'en',
                    api_token TEXT UNIQUE,
                    reset_token TEXT UNIQUE,
                    reset_expires DATETIME,
                    totp_secret TEXT,
                    totp_enabled INTEGER NOT NULL DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE pages (
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

                CREATE UNIQUE INDEX idx_pages_slug_lang ON pages(slug, lang);
                CREATE INDEX idx_pages_trans_group ON pages(translation_group);
                CREATE INDEX idx_pages_parent_id ON pages(parent_id);

                CREATE TABLE page_meta (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
                    meta_key TEXT NOT NULL,
                    meta_value TEXT,
                    meta_type TEXT DEFAULT 'text',
                    UNIQUE(page_id, meta_key)
                );
                CREATE INDEX idx_page_meta_page ON page_meta(page_id);

                CREATE TABLE snippets (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL UNIQUE,
                    label TEXT,
                    content TEXT,
                    enabled INTEGER NOT NULL DEFAULT 1,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX idx_snippets_name ON snippets(name);

                CREATE TABLE theme_mods (
                    theme TEXT NOT NULL,
                    mod_key TEXT NOT NULL,
                    mod_value TEXT,
                    PRIMARY KEY (theme, mod_key)
                );

                CREATE TABLE customize_schema (
                    theme TEXT PRIMARY KEY,
                    schema TEXT NOT NULL
                );

                CREATE TABLE tags (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    slug TEXT NOT NULL UNIQUE
                );

                CREATE TABLE page_tags (
                    page_id INTEGER NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
                    tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
                    PRIMARY KEY (page_id, tag_id)
                );
                CREATE INDEX idx_page_tags_page ON page_tags(page_id);
                CREATE INDEX idx_page_tags_tag ON page_tags(tag_id);

                CREATE TABLE menus (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    slug TEXT UNIQUE NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE menu_items (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    menu_id INTEGER NOT NULL REFERENCES menus(id) ON DELETE CASCADE,
                    parent_id INTEGER DEFAULT 0,
                    title TEXT NOT NULL,
                    url TEXT NOT NULL,
                    target TEXT DEFAULT '_self',
                    sort_order INTEGER DEFAULT 0
                );

                CREATE TABLE media (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    filename TEXT NOT NULL,
                    filepath TEXT NOT NULL,
                    mime_type TEXT NOT NULL,
                    file_size INTEGER NOT NULL,
                    webp_path TEXT,
                    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE redirects (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    from_path TEXT NOT NULL UNIQUE,
                    to_path TEXT NOT NULL,
                    hits INTEGER NOT NULL DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX idx_redirects_from ON redirects(from_path);

                CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);
                CREATE TABLE plugins (folder TEXT PRIMARY KEY, is_active INTEGER DEFAULT 0);

                CREATE TABLE visits (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    path TEXT NOT NULL,
                    ip_hash TEXT NOT NULL,
                    user_agent TEXT,
                    visited_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX idx_visits_date ON visits(visited_at);

                CREATE TABLE login_attempts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    ip_address TEXT NOT NULL,
                    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX idx_login_attempts_ip_time ON login_attempts(ip_address, attempted_at);
            ");

            // Seed administrator account
            $stmt = $pdo->prepare("INSERT INTO users (id, username, password_hash, email, role, admin_lang) VALUES (1, :u, :p, :e, 'admin', :l)");
            $stmt->execute([
                ':u' => $username,
                ':p' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                ':e' => $email,
                ':l' => $defaultLang
            ]);

            // Compile available languages string (e.g. "en:English,pl:Polski")
            $langsList = [];
            foreach ($availableLangs as $c => $n) {
                $langsList[] = "{$c}:{$n}";
            }
            $availableLangsString = implode(',', $langsList);

            // Seed core settings
            $settings = [
                'site_title'                   => $siteTitle,
                'site_description'             => 'A fast and minimal SQLite-powered website',
                'site_url'                     => '',
                'active_theme'                 => 'default',
                'posts_per_page'               => '6',
                'homepage_type'                => 'page',
                'homepage_page_id'             => '1',
                'posts_page_id'                => '0',
                'multilingual_frontend'        => '0',
                'default_language'             => $defaultLang,
                'available_languages'          => $availableLangsString,
                'header_menu_slug'             => 'main-menu',
                'lang_switcher_style'          => 'inline',
                'custom_head_scripts'          => '',
                'custom_footer_scripts'        => '',
                'security_brute_force_enabled' => '1',
                'security_headers_enabled'     => '1',
                'security_brute_force_max'     => '5',
                'security_brute_force_window'  => '15',
                'security_2fa_enabled'         => '0',
                'cache_enabled'                => '0',
                'cache_ttl'                    => '3600',
                'webp_enabled'                 => '0',
                'webp_quality'                 => '82',
                'redirects_enabled'            => '1',
                'hreflang_enabled'             => '0'
            ];
            $setStmt = $pdo->prepare("INSERT INTO settings (key, value) VALUES (:k, :v)");
            foreach ($settings as $k => $v) {
                $setStmt->execute([':k' => $k, ':v' => $v]);
            }

            // Seed initial localized content using distinct prepared statements in a transaction
            $welcomeTitle = $t['welcome_page_title'] ?? 'Welcome to your new website';
            $welcomeContent = $t['welcome_page_content'] ?? '<p>ModoCMS has been successfully installed and configured.</p>';
            $firstPostTitle = $t['first_post_title'] ?? 'First Post';
            $firstPostContent = $t['first_post_content'] ?? '<p>This is your first article published using ModoCMS.</p>';
            $mainMenuName = $t['main_menu'] ?? 'Main Menu';
            $homeLabel = $t['home'] ?? 'Home';
            $blogLabel = $t['blog'] ?? 'Blog';

            $pdo->beginTransaction();

            $pageStmt = $pdo->prepare("
                INSERT INTO pages (id, parent_id, slug, title, content, type, status, lang, translation_group, author_id)
                VALUES (:id, 0, :slug, :title, :content, :type, 'published', :lang, :trans_group, 1)
            ");

            $pageStmt->execute([
                ':id'          => 1,
                ':slug'        => 'home',
                ':title'       => $welcomeTitle,
                ':content'     => $welcomeContent,
                ':type'        => 'page',
                ':lang'        => $defaultLang,
                ':trans_group' => 'home-group'
            ]);

            $pageStmt->execute([
                ':id'          => 2,
                ':slug'        => 'first-post',
                ':title'       => $firstPostTitle,
                ':content'     => $firstPostContent,
                ':type'        => 'post',
                ':lang'        => $defaultLang,
                ':trans_group' => 'post-group'
            ]);

            $menuStmt = $pdo->prepare("INSERT INTO menus (id, name, slug) VALUES (1, :name, 'main-menu')");
            $menuStmt->execute([':name' => $mainMenuName]);

            $itemStmt = $pdo->prepare("
                INSERT INTO menu_items (menu_id, parent_id, title, url, sort_order)
                VALUES (1, 0, :title, :url, :sort)
            ");
            $itemStmt->execute([':title' => $homeLabel, ':url' => '/', ':sort' => 1]);
            $itemStmt->execute([':title' => $blogLabel, ':url' => '/blog', ':sort' => 2]);

            $pdo->commit();

            // Generate initial robots.txt and sitemap
            try {
                $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
                $baseDir = str_replace('\\', '/', dirname($scriptName));
                $baseDir = ($baseDir === '/' || $baseDir === '.') ? '' : rtrim($baseDir, '/');
                $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $siteUrl = rtrim($scheme . $host . $baseDir, '/');

                $robotsContent = "User-agent: *\n" .
                                 "Allow: /\n" .
                                 "Disallow: /admin/\n" .
                                 "Disallow: /core/\n" .
                                 "Disallow: /data/\n" .
                                 "Disallow: /uploads/cache/\n\n" .
                                 "Sitemap: " . $siteUrl . "/sitemap.xml\n";
                @file_put_contents(__DIR__ . '/robots.txt', $robotsContent);

                if (file_exists(__DIR__ . '/core/bootstrap.php')) {
                    require_once __DIR__ . '/core/bootstrap.php';
                    if (class_exists('Core\Sitemap')) {
                        \Core\Sitemap::generate();
                    }
                }
            } catch (\Throwable $e) {}

            header('Location: admin/login.php?installed=1');
            exit;
        } catch (PDOException $e) {
            if ($pdo && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = 'Database installation error: ' . $e->getMessage();
        }
    }
}

$curT = $translations[$initialLocale] ?? reset($translations);
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($initialLocale, ENT_QUOTES, 'UTF-8') ?>" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ModoCMS &bull; Installation</title>
    <link rel="stylesheet" href="admin/assets/css/admin.css">
    <style>
        :root {
            color-scheme: dark;
            --bg-body: #0b0f19;
            --bg-card: #111827;
            --bg-input: #1e293b;
            --border-color: #334155;
            --border-focus: #3b82f6;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --accent-primary: #3b82f6;
            --accent-hover: #2563eb;
            --danger-bg: rgba(239, 68, 68, 0.15);
            --danger-border: rgba(239, 68, 68, 0.35);
            --danger-text: #f87171;
        }
        * { box-sizing: border-box; }
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            background-color: var(--bg-body);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: var(--text-main);
            padding: 30px 20px;
        }
        .install-box {
            width: 100%;
            max-width: 480px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 36px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6);
        }
        .header-area {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 28px;
        }
        .brand-badge {
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #ffffff;
            font-weight: 800;
            font-size: 19px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            box-shadow: 0 8px 16px -4px rgba(37, 99, 235, 0.4);
            flex-shrink: 0;
        }
        .header-title {
            font-size: 19px;
            font-weight: 700;
            margin: 0;
            color: var(--text-main);
            letter-spacing: -0.01em;
        }
        .header-desc {
            font-size: 13px;
            color: var(--text-muted);
            margin: 3px 0 0 0;
        }
        .alert-error {
            background: var(--danger-bg);
            border: 1px solid var(--danger-border);
            color: var(--danger-text);
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 22px;
            font-size: 13px;
            line-height: 1.5;
        }
        .form-group {
            margin-bottom: 18px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 14px;
        }
        .form-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-muted);
            margin-bottom: 7px;
        }
        .form-control,
        input.form-control,
        select.form-control {
            width: 100% !important;
            padding: 11px 14px !important;
            background-color: var(--bg-input) !important;
            background: var(--bg-input) !important;
            border: 1px solid var(--border-color) !important;
            border-radius: 8px !important;
            color: var(--text-main) !important;
            font-size: 14px !important;
            outline: none !important;
            transition: border-color 0.2s, box-shadow 0.2s !important;
            color-scheme: dark !important;
        }
        .form-control:focus,
        input.form-control:focus,
        select.form-control:focus {
            border-color: var(--border-focus) !important;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25) !important;
        }
        .form-control::placeholder,
        input.form-control::placeholder {
            color: #64748b !important;
            opacity: 1 !important;
        }
        input.form-control:-webkit-autofill,
        input.form-control:-webkit-autofill:hover, 
        input.form-control:-webkit-autofill:focus {
            -webkit-text-fill-color: var(--text-main) !important;
            -webkit-box-shadow: 0 0 0px 1000px var(--bg-input) inset !important;
            transition: background-color 5000s ease-in-out 0s !important;
        }
        select.form-control option {
            background-color: #0f172a !important;
            color: #f8fafc !important;
        }
        .btn-submit {
            width: 100%;
            padding: 12px 18px;
            background: var(--accent-primary);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
            margin-top: 10px;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        .btn-submit:hover {
            background: var(--accent-hover);
        }
        .btn-submit:active {
            transform: scale(0.99);
        }
        .divider {
            height: 1px;
            background: var(--border-color);
            margin: 24px 0 20px 0;
        }
    </style>
</head>
<body>
    <div class="install-box">
        <div class="header-area">
            <div class="brand-badge">M</div>
            <div>
                <h1 class="header-title" data-i18n="setup_title"><?= htmlspecialchars($curT['setup_title'] ?? 'ModoCMS Setup') ?></h1>
                <p class="header-desc" data-i18n="setup_desc"><?= htmlspecialchars($curT['setup_desc'] ?? 'Configure your lightweight instance') ?></p>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert-error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label" data-i18n="site_title"><?= htmlspecialchars($curT['site_title'] ?? 'Site Title') ?></label>
                    <input class="form-control" type="text" id="site_title_input" name="site_title" value="<?= htmlspecialchars($curT['default_site_title'] ?? 'My Website') ?>" required autofocus>
                </div>
                <div class="form-group">
                    <label class="form-label" data-i18n="language"><?= htmlspecialchars($curT['language'] ?? 'Language') ?></label>
                    <select class="form-control" name="default_lang" id="lang_selector">
                        <?php foreach ($availableLangs as $code => $name): ?>
                            <option value="<?= htmlspecialchars($code) ?>" <?= $code === $initialLocale ? 'selected' : '' ?>>
                                <?= htmlspecialchars($name) ?> (<?= strtoupper($code) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="divider"></div>

            <div class="form-group">
                <label class="form-label" data-i18n="admin_username"><?= htmlspecialchars($curT['admin_username'] ?? 'Admin Username') ?></label>
                <input class="form-control" type="text" name="username" value="admin" required autocomplete="username">
            </div>

            <div class="form-group">
                <label class="form-label" data-i18n="admin_email"><?= htmlspecialchars($curT['admin_email'] ?? 'Admin Email') ?></label>
                <input class="form-control" type="email" name="email" value="admin@example.com" required autocomplete="email">
            </div>

            <div class="form-group">
                <label class="form-label" data-i18n="admin_password"><?= htmlspecialchars($curT['admin_password'] ?? 'Admin Password') ?></label>
                <input class="form-control" type="password" id="password_input" name="password" required minlength="6" placeholder="<?= htmlspecialchars($curT['password_placeholder'] ?? 'Min. 6 characters') ?>" autocomplete="new-password">
            </div>

            <button type="submit" class="btn-submit" id="submit_button">
                <span data-i18n="complete_install"><?= htmlspecialchars($curT['complete_install'] ?? 'Complete Installation') ?></span> &rarr;
            </button>
        </form>
    </div>

    <script>
        const translations = <?= json_encode($translations, JSON_UNESCAPED_UNICODE) ?>;
        const langSelector = document.getElementById('lang_selector');
        const siteTitleInput = document.getElementById('site_title_input');
        const passwordInput = document.getElementById('password_input');

        function applyLocale(locale) {
            const t = translations[locale];
            if (!t) return;

            document.documentElement.lang = locale;

            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                if (t[key]) {
                    el.textContent = t[key];
                }
            });

            if (t['password_placeholder']) {
                passwordInput.placeholder = t['password_placeholder'];
            }

            const previousDefaults = Object.values(translations).map(dict => dict['default_site_title']);
            if (previousDefaults.includes(siteTitleInput.value.trim()) || siteTitleInput.value.trim() === '') {
                siteTitleInput.value = t['default_site_title'] || 'My Website';
            }
        }

        langSelector.addEventListener('change', (e) => {
            applyLocale(e.target.value);
        });
    </script>
</body>
</html>