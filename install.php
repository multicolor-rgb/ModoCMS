<?php
declare(strict_types=1);

$dataDir = __DIR__ . '/data';
$dbFile = $dataDir . '/cms.sqlite';

if (file_exists($dbFile)) {
    die('<div style="font-family:system-ui; max-width:500px; margin:50px auto; padding:20px; border:1px solid #cbd5e1; border-radius:8px;">
        <h2>Clean CMS is already installed</h2>
        <p>Database file <code>data/cms.sqlite</code> exists. If you want to reinstall, remove this file.</p>
        <a href="/admin/">Go to Admin Panel &rarr;</a>
    </div>');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $siteTitle = trim($_POST['site_title'] ?? 'Clean CMS');
    $username = trim($_POST['username'] ?? 'admin');
    $email = trim($_POST['email'] ?? 'admin@example.com');
    $password = $_POST['password'] ?? '';
    $defaultLang = trim($_POST['default_lang'] ?? 'en');

    if (empty($siteTitle) || empty($username) || empty($email) || strlen($password) < 6) {
        $error = 'Please fill all fields. Password must be at least 6 characters.';
    } else {
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0775, true);
        }

        try {
            $pdo = new PDO('sqlite:' . $dbFile);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $pdo->exec("
                CREATE TABLE users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT UNIQUE NOT NULL,
                    password_hash TEXT NOT NULL,
                    email TEXT UNIQUE NOT NULL,
                    role TEXT NOT NULL DEFAULT 'admin',
                    admin_lang TEXT NOT NULL DEFAULT 'en',
                    api_token TEXT UNIQUE,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                );

                CREATE TABLE pages (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
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

            // Seed starter content
            $pdo->exec("
                INSERT INTO pages (id, slug, title, content, type, status, lang, translation_group, author_id)
                VALUES (1, 'home', 'Welcome to your new website', '<p>Clean CMS has been successfully installed and configured.</p>', 'page', 'published', '{$defaultLang}', 'home-group', 1);

                INSERT INTO pages (id, slug, title, content, type, status, lang, translation_group, author_id)
                VALUES (2, 'first-post', 'First Post', '<p>This is your first article published using Clean CMS.</p>', 'post', 'published', '{$defaultLang}', 'post-group', 1);

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
        body { display:flex; align-items:center; justify-content:center; min-height:100vh; background:#0b0f19; padding:20px; }
        .install-box { width:100%; max-width:460px; background:#fff; border-radius:12px; padding:32px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.3); }
    </style>
</head>
<body>
    <div class="install-box">
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:20px;">
            <div class="brand-badge" style="width:36px; height:36px; font-size:16px;">C</div>
            <div>
                <h1 style="font-size:18px; font-weight:700;">Clean CMS Setup</h1>
                <p style="font-size:13px; color:var(--text-muted);">Install your new lightweight instance</p>
            </div>
        </div>

        <?php if ($error): ?>
            <div style="background:var(--danger-subtle); color:var(--danger); padding:10px; border-radius:6px; margin-bottom:15px; font-size:13px;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label class="form-label">Site Title</label>
                <input class="form-control" type="text" name="site_title" value="My Website" required>
            </div>
            <div class="form-group">
                <label class="form-label">Default Language</label>
                <select class="form-control" name="default_lang">
                    <option value="en">English (EN)</option>
                    <option value="pl">Polski (PL)</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Admin Username</label>
                <input class="form-control" type="text" name="username" value="admin" required>
            </div>
            <div class="form-group">
                <label class="form-label">Admin Email</label>
                <input class="form-control" type="email" name="email" value="admin@example.com" required>
            </div>
            <div class="form-group">
                <label class="form-label">Admin Password (min. 6 chars)</label>
                <input class="form-control" type="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%; padding:10px; font-size:14px; margin-top:8px;">
                Run Installation &rarr;
            </button>
        </form>
    </div>
</body>
</html>
