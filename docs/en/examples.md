# Examples

[Polski](../pl/przyklady.md) | **English**

Copy-paste-ready examples for extending Modo CMS. Each one is a complete plugin file — drop it into `plugins/`, activate it in **Plugins**, and it works.

> All examples start with `declare(strict_types=1);` and avoid external CDNs, in line with the project's conventions.

---

## 1. Hello World — append a note to every post

`plugins/hello-world.php`

```php
<?php
/**
 * Plugin Name: Hello World Filter
 * Description: Appends a small note to every post on the frontend.
 *
 * @package HelloWorld
 */
declare(strict_types=1);

use Core\Hooks;

Hooks::addFilter('the_content', function (string $content): string {
    if (defined('IN_ADMIN') && IN_ADMIN === true) {
        return $content;
    }
    return $content . '<p style="color:#64748b;font-style:italic;">Powered by Modo CMS</p>';
});
```

**Concepts:** filters, `the_content`, guarding against the admin panel.

---

## 2. Dashboard widget

Shows a box on the admin dashboard with the number of published posts.

`plugins/admin-stats.php`

```php
<?php
/**
 * Plugin Name: Admin Stats
 * Description: Adds a small dashboard widget with a post counter.
 *
 * @package AdminStats
 */
declare(strict_types=1);

use Core\Hooks;
use Core\Database;

Hooks::addAction('admin_dashboard_widgets', function (): void {
    try {
        $db  = Database::getConnection();
        $count = (int) $db->query("SELECT COUNT(*) FROM pages WHERE type = 'post' AND status = 'published'")->fetchColumn();
    } catch (\Throwable $e) {
        $count = 0;
    }

    echo '<div class="card" style="padding:16px;">';
    echo '<h3 style="margin:0 0 8px;">Published posts</h3>';
    echo '<p style="font-size:28px;font-weight:700;margin:0;">' . $count . '</p>';
    echo '</div>';
});
```

**Concepts:** actions, `admin_dashboard_widgets`, reading the database safely.

---

## 3. Plugin with its own admin screen and settings table

The `[guestbook]` shortcode lists entries; the admin screen lets you add them.

`plugins/guestbook/guestbook.php`

```php
<?php
/**
 * Plugin Name: Guestbook
 * Description: A tiny guestbook with a shortcode and its own admin screen.
 * Version: 1.0.0
 * Author: Your Name
 *
 * @package Guestbook
 */
declare(strict_types=1);

if (!class_exists('Guestbook')) {

    final class Guestbook
    {
        public const PLUGIN_ID = 'guestbook';

        public static function init(): void
        {
            self::ensureTable();

            if (defined('IN_ADMIN') && IN_ADMIN === true) {
                // Register the sidebar entry + settings screen renderer.
                if (function_exists('register_plugin') && function_exists('createSideMenu')) {
                    register_plugin(
                        self::PLUGIN_ID,
                        'Guestbook',
                        '1.0.0',
                        'Your Name',
                        '',
                        'Tiny guestbook with a shortcode and admin screen.',
                        'settings',
                        [self::class, 'renderAdmin']
                    );
                    createSideMenu(self::PLUGIN_ID, 'Guestbook', self::PLUGIN_ID);
                }
            } else {
                \Core\Hooks::addFilter('the_content', [self::class, 'shortcode'], 20);
            }
        }

        private static function db(): ?\PDO
        {
            try { return \Core\Database::getConnection(); } catch (\Throwable $e) { return null; }
        }

        private static function ensureTable(): void
        {
            $db = self::db();
            if (!$db) { return; }
            $db->exec("CREATE TABLE IF NOT EXISTS guestbook_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                message TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )");
        }

        /** Replaces [guestbook] with the list of entries. */
        public static function shortcode(string $content): string
        {
            if (!str_contains($content, '[guestbook]')) {
                return $content;
            }

            $db = self::db();
            $rows = $db ? $db->query("SELECT message FROM guestbook_entries ORDER BY id DESC LIMIT 50")->fetchAll() : [];

            $html = '<ul class="guestbook">';
            foreach ($rows as $row) {
                $html .= '<li>' . htmlspecialchars((string) $row['message'], ENT_QUOTES, 'UTF-8') . '</li>';
            }
            $html .= '</ul>';

            return str_replace('[guestbook]', $html, $content);
        }

        /** Renders the admin settings screen (called via admin_plugin_view_guestbook). */
        public static function renderAdmin(): void
        {
            $db = self::db();

            // Handle the form POST before any output.
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['guestbook_message'])) {
                if (\Core\Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
                    $message = trim((string) $_POST['guestbook_message']);
                    if ($message !== '' && $db) {
                        $stmt = $db->prepare("INSERT INTO guestbook_entries (message) VALUES (:m)");
                        $stmt->execute([':m' => $message]);
                    }
                }
            }

            echo '<h2>Guestbook</h2>';
            echo '<form method="post" style="display:flex;gap:8px;margin-bottom:16px;">';
            echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(\Core\Security::generateCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
            echo '<input type="text" name="guestbook_message" class="form-control" placeholder="New entry" required>';
            echo '<button class="btn">Add</button>';
            echo '</form>';

            $rows = $db ? $db->query("SELECT message, created_at FROM guestbook_entries ORDER BY id DESC")->fetchAll() : [];
            echo '<table class="pro-table"><thead><tr><th>Message</th><th>Date</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                echo '<tr><td>' . htmlspecialchars((string) $row['message'], ENT_QUOTES, 'UTF-8') . '</td>'
                   . '<td>' . htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
            }
            echo '</tbody></table>';
        }
    }

    Guestbook::init();
}
```

**Concepts:** folder plugin, `IN_ADMIN` split, own table, shortcode via `the_content`, `register_plugin()` + `createSideMenu()`, prepared statements.

---

## 4. Customizer control

Adds a “Show banner” toggle and an accent colour to the live customizer.

`plugins/plugin-branding.php`

```php
<?php
/**
 * Plugin Name: Plugin Branding
 * Description: Adds customizer controls from a plugin.
 *
 * @package PluginBranding
 */
declare(strict_types=1);

use Core\Hooks;
use Core\Customizer;

Hooks::addAction('modo_customize_register', function (): void {
    Customizer::registerSection([
        'id'       => 'plugin_branding',
        'title'    => 'Plugin Branding',
        'priority' => 100,
    ]);

    Customizer::registerControl([
        'id'      => 'pb_show_banner',
        'type'    => 'checkbox',
        'label'   => 'Show banner',
        'section' => 'plugin_branding',
        'default' => '1',
    ]);

    Customizer::registerControl([
        'id'      => 'pb_banner_color',
        'type'    => 'color',
        'label'   => 'Banner colour',
        'section' => 'plugin_branding',
        'default' => '#2563eb',
        'css_var' => 'pb-banner',          // -> :root { --pb-banner: … }
    ]);
});

// Render the banner on the frontend.
Hooks::addAction('theme-header', function (): void {
    if (defined('IN_ADMIN') && IN_ADMIN === true) {
        return;
    }
    if (get_theme_mod('pb_show_banner', '0') !== '1') {
        return;
    }
    echo '<div style="background:var(--pb-banner);color:#fff;padding:8px 16px;text-align:center;">'
       . 'Hello from Plugin Branding</div>';
});
```

**Concepts:** `modo_customize_register`, `registerSection` / `registerControl`, `css_var`, reading values with `get_theme_mod()`.

---

## 5. Shortcode box (styling + inner content)

`[box]…[/box]` renders a bordered callout.

`plugins/shortcode-box.php`

```php
<?php
/**
 * Plugin Name: Shortcode Box
 * Description: Adds a [box]...[/box] callout shortcode.
 *
 * @package ShortcodeBox
 */
declare(strict_types=1);

use Core\Hooks;

Hooks::addAction('theme-footer', function (): void {
    if (defined('IN_ADMIN') && IN_ADMIN === true) {
        return;
    }
    echo '<style>.md-box{border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:16px;margin:1em 0;background:#f8fafc;}</style>';
});

Hooks::addFilter('the_content', function (string $content): string {
    return preg_replace_callback(
        '/\[box\](.*?)\[\/box\]/is',
        static fn (array $m): string => '<div class="md-box">' . $m[1] . '</div>',
        $content
    );
}, 20);
```

**Concepts:** shortcodes, `preg_replace_callback`, injecting a stylesheet on the frontend, high priority.

---

## 6. GetSimple-style plugin (full)

A classic-style plugin using `register_plugin()`, `add_action()`, `add_filter()` and `i18n_merge()`.

`plugins/my-gs-plugin/my-gs-plugin.php`

```php
<?php
/**
 * Plugin Name: My GS Plugin
 * Description: GetSimple-style plugin (see docs).
 * Version: 1.0.0
 * Author: Your Name
 *
 * @package MyGsPlugin
 */
declare(strict_types=1);

class MyGsPlugin
{
    public static function init(): void
    {
        register_plugin(
            'my-gs-plugin', 'My GS Plugin', '1.0.0', 'Your Name', '',
            'GetSimple-style example.', 'settings', [self::class, 'settings']
        );

        createSideMenu('my-gs-plugin', 'My GS Plugin', 'my-gs-plugin');

        add_action('theme-footer', [self::class, 'footer']);
        add_filter('content', [self::class, 'content']);

        i18n_merge('my-gs-plugin', 'en_US');
    }

    public static function footer(): void
    {
        echo '<div style="text-align:center;padding:8px;">Hello from a GetSimple-style plugin</div>';
    }

    public static function content(string $content): string
    {
        return $content . '<p><small>Processed by My GS Plugin</small></p>';
    }

    public static function settings(): void
    {
        echo '<h2>' . htmlspecialchars((string) i18n_r('my-gs-plugin/MYPLUGIN_TITLE'), ENT_QUOTES, 'UTF-8') . '</h2>';
        echo '<p>Rendered at ?id=my-gs-plugin</p>';
    }
}

MyGsPlugin::init();
```

**Concepts:** the compatibility layer — see [GetSimple-style plugins](getsimple-plugins.md).

---

## 7. Theme snippet — a custom archive card

Inside a theme template (`themes/<theme>/archive.php`), using the loop:

```php
<?php while (have_posts()): the_post(); ?>
    <article class="post-card">
        <?php if (has_image()): ?>
            <img src="<?php page_image(); ?>" alt="<?php page_title(); ?>">
        <?php endif; ?>
        <h2><a href="<?php post_url(); ?>"><?php post_title(); ?></a></h2>
        <p><?php post_excerpt(140); ?></p>
        <?php if (has_categories()): ?>
            <div class="post-cats"><?php post_categories(); ?></div>
        <?php endif; ?>
    </article>
<?php endwhile; ?>
```

**Concepts:** the posts loop, `have_posts()` / `the_post()`, featured image, excerpt, categories. See [Theme functions](theme-functions.md#3-the-posts-loop-archive).

---

## Next

* [Creating plugins](plugins.md)
* [GetSimple-style plugins](getsimple-plugins.md)
* [Theme functions](theme-functions.md)
* [Hooks reference](hooks.md)
