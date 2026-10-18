# Creating GetSimple-style plugins

[Polski](../pl/wtyczki.md) | **English**

Modo CMS ships with a **GetSimple compatibility layer** (`core/GetSimpleCompat.php`). It lets plugins written for **GetSimple CMS** run on Modo with little or no changes, and it also provides handy global helpers (`register_plugin`, `createSideMenu`, `i18n_merge`) that native plugins use too.

Use this style when you are **porting an existing GetSimple plugin** or when you prefer the classic procedural API. For all-new plugins, the [native hook style](plugins.md) is recommended.

---

## 1. What the layer provides

| Area | Functions / constants |
|---|---|
| Environment | `IN_GS`, `GSVERSION`, `GSPLUGINPATH`, `GSDATAOTHERPATH`, `GSDATAPAGESPATH`, `$GLOBALS['SITEURL']` |
| Registration & admin UI | `register_plugin()`, `createSideMenu()`, `create_side_menu()`, `createNavTab()`, `render_admin_plugin_navigation()`, `render_admin_sidebar_icon()` |
| Actions & filters | `add_action()`, `exec_action()`, `add_filter()`, `exec_filter()` |
| Localisation | `i18n_merge()`, `i18n_r()`, `i18n()` |
| Paths, URLs & theme | `get_site_url()`, `find_url()`, `get_header()`, `get_footer()`, `get_page_slug()`, `get_page_content()` |
| Data & XML | `getXML()`, `XMLsave()` |

All of these are defined globally (no namespace), so a GetSimple plugin can call them directly.

---

## 2. Environment constants

| Constant | Meaning on Modo |
|---|---|
| `IN_GS` | `true` — makes GetSimple plugins believe they run inside GetSimple. |
| `GSVERSION` | Reported as `3.3.16`. |
| `GSPLUGINPATH` | Absolute path to `plugins/`. |
| `GSDATAOTHERPATH` | `data/other/` (auto-created). |
| `GSDATAPAGESPATH` | `data/pages/` (auto-created). |
| `$GLOBALS['SITEURL']` | Site base URL with a trailing slash, subdirectory-aware. |

Plugins that check `if (!defined('IN_GS')) die();` at the top will therefore work unchanged.

---

## 3. Registering a plugin

`register_plugin()` records metadata (shown on the **Plugins** screen) and, optionally, wires up a settings-page renderer:

```php
register_plugin(
    string $id,
    string $name,
    string $version,
    string $author,
    string $author_url,
    string $description,
    string $page_type = '',
    string $load_function = ''
): void;
```

When `$load_function` is a callable, it is attached to the action `admin_plugin_view_<id>`. That action is fired by `admin/plugins.php` when the panel is opened with `?id=<id>`, so the function becomes the plugin's **settings page**.

```php
register_plugin(
    'my-gs-plugin',            // id — must match the folder/file identifier in plugins/
    'My GS Plugin',
    '1.0.0',
    'Your Name',
    '',
    'Example plugin using the GetSimple API.',
    'settings',
    'MyGsPlugin::settings'     // rendered on ?id=my-gs-plugin
);
```

---

## 4. Helpers reference

### `createSideMenu()`

Adds an item to the admin sidebar.

```php
createSideMenu(
    string $id,          // plugin id
    string $title,       // label (translatable with i18n_r)
    string $action = '', // defaults to $id
    string $icon = ''    // inline SVG, an SVG "d" path, or '' for the default icon
): void;
```

Alias: `create_side_menu()`. The GetSimple-compatible `createNavTab($name, $plugin, $label, $action, $icon)` is also available and forwards to `createSideMenu()`.

The sidebar itself is rendered by `render_admin_plugin_navigation()` (called from `admin/views/header.php`), which also fires the `pages-sidebar`, `plugins-sidebar`, `settings-sidebar`, `theme-sidebar` and `nav-tab` actions so you can inject custom items.

### `render_admin_sidebar_icon()`

Builds the icon markup. Accepts a full `<svg>` string, a bare SVG `d` path, or an empty string (default puzzle icon).

```php
render_admin_sidebar_icon('<svg …></svg>');   // custom SVG
render_admin_sidebar_icon('M12 4v16m8-8H4');  // path only
render_admin_sidebar_icon();                  // default icon
```

---

## 5. Actions and filters

The compatibility layer maps GetSimple hook names onto Modo's hooks.

### `add_action( $hook, $function, $args = [] )`

```php
add_action('theme-footer', 'my_plugin_footer');
```

| GetSimple hook | Mapped to (Modo) |
|---|---|
| `theme-header` | `theme_head` |
| `theme-footer` | `theme_footer` |
| `header` | `admin_head` (in admin) / `theme_head` (frontend) |
| `footer` | `admin_footer` (in admin) / `theme_footer` (frontend) |
| `index-pretemplate` | `plugins_loaded` |
| `edit-extras` | `admin_page_edit_bottom` |
| `content-top` | `before_the_content` |
| `content-bottom` | `after_the_content` |
| *anything else* | same name (passed through) |

If the hook fires with arguments, they are forwarded to your function; otherwise the static `$args` array you supplied is used.

### `add_filter( $filter, $function, $args = [] )`

```php
add_filter('content', 'my_plugin_filter_content');

function my_plugin_filter_content($content) {
    return $content . '<p>Appended.</p>';
}
```

| GetSimple filter | Mapped to (Modo) |
|---|---|
| `content` | `the_content` |
| `title` | `the_title` |
| `excerpt` | `the_excerpt` |
| *anything else* | same name (passed through) |

> ⚠️ GetSimple's filter signature is `function($value)` — one argument. The compatibility wrapper passes only the value; extra arguments a Modo filter might provide are not forwarded through this helper. If you need them, subscribe with `Hooks::addFilter()` directly (see [native plugins](plugins.md)).

### `exec_action()` / `exec_filter()`

Manually fire a hook:

```php
exec_action('my_custom_event', $arg1, $arg2);
$value = exec_filter('my_custom_filter', $value);
```

---

## 6. Localisation (i18n)

`i18n_merge()` loads a language file from the plugin's `lang/` folder and registers all its `$i18n[...]` entries:

```php
i18n_merge('my-gs-plugin');          // or i18n_merge('my-gs-plugin', 'en')
i18n_merge('my-gs-plugin', 'en_US'); // explicit fallback language
```

It looks in:

```
plugins/my-gs-plugin/lang/
plugins/my-gs-plugin/my-gs-plugin/lang/
```

for `<current-locale>.php`, then `en_US.php`, then `en.php`. The current locale comes from `Core\I18n::getLocale()`.

Example language file (`plugins/my-gs-plugin/lang/en_US.php`):

```php
<?php
$i18n = [
    'MYPLUGIN_TITLE' => 'My Plugin',
    'MYPLUGIN_SAVED' => 'Settings saved.',
];
```

Use the strings with the plugin prefix:

```php
echo i18n_r('my-gs-plugin/MYPLUGIN_TITLE');  // returns the string
i18n('my-gs-plugin/MYPLUGIN_TITLE');         // echoes it, HTML-escaped
```

`i18n_r()` first looks in the registry, then strips the prefix and falls back to Modo's own `__()` translation.

---

## 7. Path, URL and theme helpers

```php
get_site_url(bool $echo = false): string          // base URL with trailing slash
find_url(string $slug, string $parent = ''): string // build an internal URL
get_header(): void                                // calls theme_head()
get_footer(): void                                // calls theme_footer()
get_page_slug(bool $echo = false): string         // slug of the current page
get_page_content(): string                        // content of the current page
```

---

## 8. Data helpers

```php
$xml = getXML($file);            // file path -> SimpleXMLElement|null
XMLsave($xml, $file);            // writes the XML back to disk
```

These exist for compatibility with plugins that store data as XML (a common GetSimple pattern).

---

## 9. Full example

`plugins/my-gs-plugin/my-gs-plugin.php`:

```php
<?php
/**
 * Plugin Name: My GS Plugin
 * Description: A GetSimple-style plugin for Modo CMS.
 * Version: 1.0.0
 * Author: Your Name
 *
 * @package MyGsPlugin
 */
declare(strict_types=1);

if (!defined('IN_GS')) { define('IN_GS', true); } // normally already defined

class MyGsPlugin
{
    public static function init(): void
    {
        // Register metadata + settings renderer.
        register_plugin(
            'my-gs-plugin',
            'My GS Plugin',
            '1.0.0',
            'Your Name',
            '',
            'Adds a footer note and a settings page.',
            'settings',
            [self::class, 'settings']
        );

        // Add a sidebar item.
        createSideMenu('my-gs-plugin', 'My GS Plugin', 'my-gs-plugin');

        // Frontend: append a note to the footer.
        add_action('theme-footer', [self::class, 'renderFooter']);

        // Frontend: modify post content.
        add_filter('content', [self::class, 'filterContent']);

        // Load translations if present.
        if (function_exists('i18n_merge')) {
            i18n_merge('my-gs-plugin', 'en_US');
        }
    }

    public static function renderFooter(): void
    {
        echo '<div class="my-gs-footer">Hello from a GetSimple-style plugin</div>';
    }

    public static function filterContent(string $content): string
    {
        return $content . '<p><small>Processed by My GS Plugin</small></p>';
    }

    public static function settings(): void
    {
        echo '<h2>' . htmlspecialchars((string) i18n_r('my-gs-plugin/MYPLUGIN_TITLE'), ENT_QUOTES, 'UTF-8') . '</h2>';
        echo '<p>This screen is rendered at ?id=my-gs-plugin.</p>';
    }
}

MyGsPlugin::init();
```

Activate **my-gs-plugin** in the **Plugins** panel and open it in the sidebar.

---

## 10. Differences and limitations

* Not every GetSimple API is emulated. Theme-side GetSimple functions such as `get_page_content()`, `get_header()`/`get_footer()` are provided, but if a plugin calls something missing you will get a fatal “undefined function” error — add the missing function in the plugin or switch it to the [native API](plugins.md).
* `add_filter()` forwards only the filtered value, not extra arguments.
* Selecting a completely new plugin? Prefer the native `Core\Hooks` API — it exposes the full hook surface, priorities and extra arguments.

---

## Next

* [Native plugins](plugins.md)
* [Hooks reference](hooks.md)
* [Examples](examples.md)
