<?php
declare(strict_types=1);

$dataDir = __DIR__ . '/data';
$dbFile = $dataDir . '/cms.sqlite';

if (file_exists($dbFile)) {
    die('<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Clean CMS &bull; Already Installed</title>
        <link rel="stylesheet" href="/admin/assets/css/admin.css">
        <style>
            :root {
                --bg-body: #0b0f19;
                --bg-card: #111827;
                --border-color: #1f2937;
                --text-main: #f9fafb;
                --text-muted: #9ca3af;
                --accent-primary: #3b82f6;
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
                max-width: 440px;
                background: var(--bg-card);
                border: 1px solid var(--border-color);
                border-radius: 16px;
                padding: 32px;
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
            h2 { margin: 0 0 10px 0; font-size: 20px; font-weight: 700; }
            p { color: var(--text-muted); font-size: 14px; line-height: 1.6; margin: 0 0 24px 0; }
            code { background: #1f2937; color: #60a5fa; padding: 2px 6px; border-radius: 4px; font-size: 13px; }
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
            <div class="brand-badge">C</div>
            <h2>Clean CMS is already installed</h2>
            <p>Database file <code>data/cms.sqlite</code> exists. If you wish to reinstall, remove this file from your server.</p>
            <a href="/admin/" class="btn">Go to Admin Panel &rarr;</a>
        </div>
    </body>
    </html>');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $siteTitle = trim($_POST['site_title'] ?? 'Clean CMS');
    $username = trim($_POST['username'] ?? 'admin');
    $email = trim($_POST['email'] ?? 'admin@example.com');
    $password = $_POST['password'] ?? '';
    $defaultLang = trim($_POST['default_lang'] ?? 'en');

    if (empty($siteTitle) || empty($username) || empty($email) || strlen($password) < 6) {
        $error = 'Please fill all required fields. Password must be at least 6 characters.';
    } else {
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0775, true);
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

                CREATE TABLE theme_mods (
                    theme TEXT NOT NULL,
                    mod_key TEXT NOT NULL,
                    mod_value TEXT,
                    PRIMARY KEY (theme, mod_key)
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
                    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT);
                CREATE TABLE plugins (folder TEXT PRIMARY KEY, is_active INTEGER DEFAULT 0);

                CREATE TABLE visits (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    path TEXT NOT NULL,
                    ip_hash TEXT NOT NULL,
                    user_agent TEXT,
                    visited_at DATE DEFAULT (DATE('now'))
                );
                CREATE INDEX idx_visits_date ON visits(visited_at);
            ");

            // Seed admin account
            $stmt = $pdo->prepare("INSERT INTO users (id, username, password_hash, email, role, admin_lang) VALUES (1, :u, :p, :e, 'admin', :l)");
            $stmt->execute([
                ':u' => $username,
                ':p' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]),
                ':e' => $email,
                ':l' => $defaultLang
            ]);

            // Seed settings
            $settings = [
                'site_title' => $siteTitle,
                'site_description' => 'A fast and minimal SQLite-powered website',
                'active_theme' => 'default',
                'posts_per_page' => '6',
                'multilingual_frontend' => '0',
                'default_language' => $defaultLang,
                'available_languages' => 'en:English,pl:Polski'
            ];
            $setStmt = $pdo->prepare("INSERT INTO settings (key, value) VALUES (:k, :v)");
            foreach ($settings as $k => $v) {
                $setStmt->execute([':k' => $k, ':v' => $v]);
            }

            // Seed initial content
            $pdo->exec("
                INSERT INTO pages (id, parent_id, slug, title, content, type, status, lang, translation_group, author_id)
                VALUES (1, 0, 'home', 'Welcome to your new website', '<p>Clean CMS has been successfully installed and configured.</p>', 'page', 'published', '{$defaultLang}', 'home-group', 1);

                INSERT INTO pages (id, parent_id, slug, title, content, type, status, lang, translation_group, author_id)
                VALUES (2, 0, 'first-post', 'First Post', '<p>This is your first article published using Clean CMS.</p>', 'post', 'published', '{$defaultLang}', 'post-group', 1);

                INSERT INTO menus (id, name, slug) VALUES (1, 'Main Menu', 'main-menu');
                INSERT INTO menu_items (menu_id, parent_id, title, url, sort_order) VALUES (1, 0, 'Home', '/', 1);
                INSERT INTO menu_items (menu_id, parent_id, title, url, sort_order) VALUES (1, 0, 'Blog', '/blog', 2);
            ");

            header('Location: /admin/login.php?installed=1');
            exit;
        } catch (PDOException $e) {
            $error = 'Database installation error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clean CMS &bull; Installation</title>
    <link rel="stylesheet" href="/admin/assets/css/admin.css">
    <style>
        :root {
            --bg-body: #0b0f19;
            --bg-card: #111827;
            --border-color: #1f2937;
            --border-focus: #3b82f6;
            --text-main: #f9fafb;
            --text-muted: #9ca3af;
            --accent-primary: #3b82f6;
            --accent-hover: #2563eb;
            --danger-bg: rgba(239, 68, 68, 0.12);
            --danger-border: rgba(239, 68, 68, 0.3);
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
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
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
            grid-template-columns: 1fr 1fr;
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
        .form-control {
            width: 100%;
            padding: 11px 14px;
            background: #0b0f19;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-main);
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-control:focus {
            border-color: var(--border-focus);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
        }
        select.form-control {
            cursor: pointer;
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
            <div class="brand-badge">C</div>
            <div>
                <h1 class="header-title">Clean CMS Setup</h1>
                <p class="header-desc">Configure your lightweight instance</p>
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
                    <label class="form-label">Site Title</label>
                    <input class="form-control" type="text" name="site_title" value="My Website" required autofocus>
                </div>
                <div class="form-group">
                    <label class="form-label">Language</label>
                    <select class="form-control" name="default_lang">
                        <option value="en">English (EN)</option>
                        <option value="pl">Polski (PL)</option>
                    </select>
                </div>
            </div>

            <div class="divider"></div>

            <div class="form-group">
                <label class="form-label">Admin Username</label>
                <input class="form-control" type="text" name="username" value="admin" required autocomplete="username">
            </div>

            <div class="form-group">
                <label class="form-label">Admin Email</label>
                <input class="form-control" type="email" name="email" value="admin@example.com" required autocomplete="email">
            </div>

            <div class="form-group">
                <label class="form-label">Admin Password</label>
                <input class="form-control" type="password" name="password" required minlength="6" placeholder="Min. 6 characters" autocomplete="new-password">
            </div>

            <button type="submit" class="btn-submit">
                Complete Installation &rarr;
            </button>
        </form>
    </div>
</body>
</html>