# Creating plugins (native Modo)

[Polski](../pl/pluginy.md) | **English**

This is a beginner-friendly guide to writing your own **native Modo plugins** using the hook system. If you are porting an existing GetSimple plugin, see [GetSimple-style plugins](getsimple-plugins.md) instead.

---

## 1. What is a plugin?

A plugin is a small PHP file (or a folder) that **adds new behaviour to Modo CMS without modifying the core**. You drop it into the `plugins/` folder, switch it on in the admin panel, and it starts working.

Think of the CMS as a house with **sockets** (called *hooks*) installed in the walls:

* Some sockets are **do-something** sockets (*actions*). When the page reaches that point, the CMS shouts “hey, does anyone want to do something here?” and every plugin listening reacts.
* Other sockets are **pass-me-the-data** sockets (*filters*). The CMS hands a value (e.g. a page's HTML) to every listener, and each listener may change it and pass it on.

That is the whole idea: your plugin does not edit core files — it **plugs into a socket**.

---

## 2. Where does a plugin live?

Modo's `Core\PluginLoader` scans the `plugins/` directory and loads the entry file of every **active** plugin. A plugin can be organised in three ways:

| Layout | Entry file that gets loaded |
|---|---|
| Single file | `plugins/hello-world.php` |
| Folder named after the plugin | `plugins/modo-form/modo-form.php` |
| Folder with a generic entry | `plugins/modo-gallery/plugin.php` or `plugins/modo-gallery/index.php` |

The loader checks, in this exact order: `plugins/<name>.php`, then `plugins/<name>/<name>.php`, then `plugins/<name>/plugin.php`, then `plugins/<name>/index.php`.

> The plugin **identifier** is the file name without `.php` (`hello-world`) or the folder name (`modo-form`). You will need this identifier to activate the plugin.

A directory-based plugin usually keeps its own assets next to the entry file:

```
plugins/
└── my-plugin/
    ├── my-plugin.php        ← entry file (loaded automatically)
    └── assets/
        ├── css/style.css
        └── js/script.js
```

---

## 3. Activating a plugin

1. Place the file/folder in `plugins/`.
2. In the admin panel open **Plugins** (`/admin/plugins.php`).
3. Click the toggle next to your plugin. This sets `is_active = 1` in the `plugins` table.
4. Reload the frontend — the plugin is now live.

The activation list is simply a database flag; the code itself is loaded on every request by `PluginLoader::loadActivePlugins()`, which then fires the `plugins_loaded` action.

---

## 4. The hook system (`Core\Hooks`)

All hooks live in the static class `Core\Hooks`. You use two methods to subscribe:

```php
<?php
use Core\Hooks;

// Actions: "when X happens, run my function"
Hooks::addAction(string $hook, callable $callback, int $priority = 10): void;

// Filters: "when X value is produced, let me change it"
Hooks::addFilter(string $filter, callable $callback, int $priority = 10): void;
```

### Action vs. filter — the difference

* **Action** — your function runs *for its side effects* (print HTML, send an email, save data). You do **not** return the value. Use `Hooks::addAction()`.
* **Filter** — your function receives a value, optionally changes it, and **must return it**. Use `Hooks::addFilter()`.

```php
// ACTION: put something into the <head>. Returns nothing.
Hooks::addAction('theme-header', function () {
    echo '<link rel="stylesheet" href="' . plugin_url('my-plugin') . '/assets/css/style.css">';
});

// FILTER: append a signature to every post. Must return the string.
Hooks::addFilter('the_content', function (string $content): string {
    return $content . '<p>— Thanks for reading!</p>';
});
```

### Priorities

The third argument is the **priority** (default `10`). Lower numbers run first.

| Priority | Typical use |
|---|---|
| `1` | Very early (e.g. converting a content format) |
| `5` | Early preprocessing |
| `10` | Default |
| `20` | Late — after other plugins, e.g. rendering shortcodes |

Example: `Core\EditorJs` converts block documents to HTML at priority `1`, before snippets (`5`) and plugin shortcodes (`20`) are expanded.

### Full `Hooks` API

```php
Hooks::addAction($hook, $callback, $priority = 10);   // subscribe to an action
Hooks::hasAction($hook);                               // bool — is anything listening?
Hooks::doAction($hook, ...$args);                      // fire an action (core/advanced use)

Hooks::addFilter($filter, $callback, $priority = 10);  // subscribe to a filter
Hooks::hasFilter($filter);                             // bool
Hooks::applyFilters($filter, $value, ...$args);        // run a value through filters (core/advanced use)
```

You normally only use `addAction()` and `addFilter()` in a plugin. The `do*`/`has*` methods are for the core or very advanced extensions.

A filter callback receives the value **plus any extra arguments** the core passed:

```php
Hooks::addFilter('clean_menu_raw_items', function (array $items, string $slug): array {
    // $slug is the extra argument supplied by menu rendering
    return $items;
});
```

---

## 5. Your first plugin — “Hello World”

Create `plugins/hello-world.php`:

```php
<?php
/**
 * Plugin Name: Hello World Filter
 * Description: Demonstrates hooks and content filtration in Modo CMS.
 *
 * @package HelloWorld
 */
declare(strict_types=1);

use Core\Hooks;

Hooks::addFilter('the_content', function (string $content): string {
    // Do not touch the admin panel.
    if (defined('IN_ADMIN') && IN_ADMIN === true) {
        return $content;
    }
    return $content . '<p style="color:#64748b;font-style:italic;">Powered by Modo CMS</p>';
});
```

Activate it in **Plugins** and every post will end with that line.

> 💡 `IN_ADMIN` is defined as `true` inside every admin file (e.g. `admin/plugins.php`). Guarding on it keeps your plugin out of the panel.

---

## 6. Recommended plugin structure

For anything larger than a snippet, use a folder plus a class guarded against double loading:

```php
<?php
/**
 * Plugin Name: My Plugin
 * Description: What it does in one sentence.
 * Version: 1.0.0
 * Author: Your Name
 *
 * @package MyPlugin
 */
declare(strict_types=1);

if (!class_exists('MyPlugin')) {

    final class MyPlugin
    {
        public const PLUGIN_ID = 'my-plugin';
        public const VERSION   = '1.0.0';

        private static bool $booted = false;

        public static function init(): void
        {
            if (self::$booted) { return; }
            self::$booted = true;

            if (defined('IN_ADMIN') && IN_ADMIN === true) {
                self::bootAdmin();
            } else {
                self::bootFrontend();
            }
        }

        private static function bootFrontend(): void
        {
            \Core\Hooks::addFilter('the_content', [self::class, 'renderShortcode'], 20);
            \Core\Hooks::addAction('theme-header', [self::class, 'headAssets']);
        }

        private static function bootAdmin(): void
        {
            \Core\Hooks::addAction('admin_head', [self::class, 'adminAssets']);
        }

        public static function renderShortcode(string $content): string { return $content; }
        public static function headAssets(): void {}
        public static function adminAssets(): void {}
    }

    MyPlugin::init();
}
```

Key habits:

* **`declare(strict_types=1);`** at the top of every file.
* Wrap the class in **`if (!class_exists(...))`** so the file can be included twice safely.
* **Split admin and frontend** boot paths using `IN_ADMIN`.
* Make `init()` **idempotent** with a `private static bool $booted` guard.
* Use array callables `[self::class, 'method']` — they are lazy and clean.

---

## 7. Building asset URLs

Never hard-code `/plugins/...`. Use the router's base-subdirectory helper so the plugin also works in a subdirectory:

```php
public static function assetsUrl(): string
{
    $base = class_exists('\Core\Router') ? rtrim(\Core\Router::getBaseSubdirectory(), '/') : '';
    return $base . '/plugins/' . self::PLUGIN_ID . '/assets';
}
```

Then inject scripts/styles on the frontend hooks:

```php
\Core\Hooks::addAction('theme-footer', function () {
    echo '<script src="' . MyPlugin::assetsUrl() . '/js/script.js" defer></script>';
});
```

The relevant injection points on the frontend are `theme-header` (inside `<head>`) and `theme-footer` (before `</body>`), fired by `theme_head()` / `theme_footer()`.

### A tiny URL helper

If you prefer a short global function, define it once inside your plugin:

```php
if (!function_exists('plugin_url')) {
    function plugin_url(string $id): string {
        $base = class_exists('\Core\Router') ? rtrim(\Core\Router::getBaseSubdirectory(), '/') : '';
        return $base . '/plugins/' . $id;
    }
}
```

---

## 8. Shortcodes via `the_content`

The most common frontend feature is a shortcode like `[my_box]Hello[/my_box]`. Register it as a filter on `the_content`:

```php
\Core\Hooks::addFilter('the_content', function (string $content): string {
    return preg_replace_callback(
        '/\[my_box\](.*?)\[\/my_box\]/is',
        static fn (array $m): string => '<div class="my-box">' . $m[1] . '</div>',
        $content
    );
}, 20);
```

Use a **high priority (20)** so it runs after the built-in snippet expansion (`render_snippets`, priority 5) and after the block editor conversion (priority 1).

---

## 9. Adding an admin page to the panel sidebar

If your plugin needs its own screen in the admin, register it with two helpers from the GetSimple compatibility layer (`core/GetSimpleCompat.php`), which are available globally:

```php
\Core\Hooks::addAction('plugins_loaded', function () {
    if (!function_exists('register_plugin') || !function_exists('createSideMenu')) {
        return;
    }

    register_plugin(
        'my-plugin',                       // id (must match the folder/file identifier)
        'My Plugin',                       // human-readable name
        '1.0.0',                           // version
        'Your Name',                       // author
        '',                                // author URL
        'Short description.',              // description
        'settings',                        // page type (free-form label)
        'MyPlugin::renderAdmin'            // callback that renders the settings screen
    );

    createSideMenu(
        'my-plugin',                       // id
        'My Plugin',                       // sidebar label
        'my-plugin',                       // action
        '<svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>'
    );
});
```

* `register_plugin(...)` stores metadata shown on the **Plugins** screen; its last argument is wired to the action `admin_plugin_view_<id>`.
* `createSideMenu(...)` adds a link to the admin sidebar (the last argument is an inline SVG; if omitted a default plugin icon is used).
* Implement the renderer as a `public static function renderAdmin(): void` that outputs your HTML (and handles form submissions before any output).

Full details of these helpers are in [GetSimple-style plugins](getsimple-plugins.md#4-helpers-reference).

---

## 10. Storing settings

### Reuse the global settings table

For a couple of options, `Router::getOption()` reads from the `settings` table:

```php
use Core\Router;

$enabled = Router::getOption('myplugin_enabled', '1') === '1';
```

To write options reliably, insert/update the `settings` table through PDO with a prepared statement. For anything non-trivial, prefer your own table:

```php
use Core\Database;

$db = Database::getConnection();
$db->exec("CREATE TABLE IF NOT EXISTS myplugin_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$stmt = $db->prepare("INSERT INTO myplugin_items (title) VALUES (:t)");
$stmt->execute([':t' => $title]);
```

Always create tables with `CREATE TABLE IF NOT EXISTS` at boot — plugins are self-contained and may run before the admin visits your screen.

---

## 11. Integrating with the Theme Customizer

You can add your own controls to the live customizer via the `modo_customize_register` action:

```php
use Core\Hooks;
use Core\Customizer;

Hooks::addAction('modo_customize_register', function () {
    Customizer::registerSection(['id' => 'myplugin', 'title' => 'My Plugin', 'priority' => 100]);

    Customizer::registerControl([
        'id'      => 'myplugin_show_banner',
        'type'    => 'checkbox',
        'label'   => 'Show banner',
        'section' => 'myplugin',
        'default' => '1',
    ]);

    Customizer::registerControl([
        'id'      => 'myplugin_accent',
        'type'    => 'color',
        'label'   => 'Accent colour',
        'section' => 'myplugin',
        'default' => '#0d6efd',
        'css_var' => 'myplugin-accent',   // emitted as a :root CSS variable
    ]);
});
```

Read the value in a theme or plugin with `get_theme_mod()`:

```php
$show = get_theme_mod('myplugin_show_banner', '0') === '1';
```

See [Theme functions](theme-functions.md#10-customizer-helpers) for `get_theme_mod`, `the_theme_mod` and `customizer_css`.

---

## 12. Making strings translatable

Wrap user-facing strings in the global `__()` / `_e()` helpers and add the keys to `languages/*.json` (key = the English source string):

```php
echo '<h2>' . __('My Plugin') . '</h2>';
_e('Save changes');   // echoes escaped
```

---

## 13. Checklist before you ship

* [ ] Entry file/folder name matches the identifier you activate in the panel.
* [ ] `declare(strict_types=1);` and a `class_exists` guard (for class-based plugins).
* [ ] Admin and frontend paths separated with `IN_ADMIN`.
* [ ] All URLs built with the base-subdirectory helper (works in a subfolder).
* [ ] All output escaped with `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`.
* [ ] All SQL uses prepared statements.
* [ ] Strings are translatable.
* [ ] No external CDN assets.
* [ ] No writes to core files — only hooks.

---

## Next

* [GetSimple-style plugins](getsimple-plugins.md)
* [Hooks reference](hooks.md)
* [Examples](examples.md)
