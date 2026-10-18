# Hooks reference (actions & filters)

[Polski](../pl/hooki.md) | **English**

A **hook** is a named point in the code where plugins can “plug in”. Modo CMS has two kinds:

* **Actions** — something happens; your function runs for its side effects. Subscribe with `Hooks::addAction($hook, $callback, $priority = 10)`.
* **Filters** — a value is produced; your function receives it and **returns** the (possibly modified) value. Subscribe with `Hooks::addFilter($filter, $callback, $priority = 10)`.

For a beginner-friendly introduction, see [Creating plugins](plugins.md#4-the-hook-system-corehooks). This page is the **complete list** shipped with the core.

> The first table column is the exact hook name to pass to `addAction()` / `addFilter()`.

---

## Actions

| Hook | Fires in | Typical use |
|---|---|---|
| `theme_head` | `theme_head()` (`core/frontend.php`) | Inject `<head>` assets. `Core\CssFramework` uses it at priority `5`. |
| `theme-header` | `theme_head()` and `get_header()` | Inject CSS/meta into `<head>` (main plugin entry point for head assets). |
| `theme_footer` | `theme_footer()` | Inject scripts before `</body>`. |
| `theme-footer` | `theme_footer()` / `get_footer()` | Main plugin entry point for footer scripts. |
| `plugins_loaded` | `PluginLoader::loadActivePlugins()` | Runs once after all active plugins are loaded — a good place to register admin pages/sidebars. |
| `before_the_content` | Content rendering (mapped from GetSimple `content-top`) | Output just before page content. |
| `after_the_content` | Content rendering (mapped from GetSimple `content-bottom`) | Output just after page content. |
| `before_render_menu` | `menu()` (`core/frontend.php`) | Runs before a menu is rendered (receives menu slug). |
| `after_render_menu` | `menu()` | Runs after a menu is rendered (receives menu slug). |
| `admin_head` | `admin/views/header.php` | Inject CSS/JS into the admin panel `<head>`. |
| `admin_footer` | `admin/views/footer.php` | Inject scripts at the end of admin pages. |
| `admin_dashboard_widgets` | `admin/index.php` | Add widgets to the dashboard. |
| `admin_dashboard_sidebar` | `admin/index.php` | Add content to the dashboard sidebar. |
| `admin-edit-form` | `admin/page-edit.php`, `admin/snippets.php` | Add fields/UI to the page/post/snippet editor (receives the record id). |
| `admin-edit-form-content` | `admin/page-edit.php` | Add UI to the content tab of the editor (receives the id). |
| `admin-save-page` | `admin/page-edit.php`, `core/Revisions.php` | React after a page is saved (e.g. save custom fields). |
| `admin_page_edit_bottom` | Mapped from GetSimple `edit-extras` | Bottom of the edit page (compatibility). |
| `admin_plugin_view_<id>` | `admin/plugins.php` | Render a plugin's own settings screen (`?id=<id>`). Wired automatically by `register_plugin()`. |
| `pages-sidebar` / `plugins-sidebar` / `settings-sidebar` / `theme-sidebar` | `render_admin_plugin_navigation()` | Add custom items to the respective admin sidebar sections. |
| `nav-tab` | `render_admin_plugin_navigation()` | Add a navigation tab in the admin. |
| `modo_customize_register` | `Customizer::loadCodeSources()` | Register customizer sections/controls (see below). |

### Registering customizer controls

`modo_customize_register` is an action, but it is normally used together with `Customizer::registerSection()` / `registerControl()`:

```php
use Core\Hooks;
use Core\Customizer;

Hooks::addAction('modo_customize_register', function () {
    Customizer::registerSection(['id' => 'plugin', 'title' => 'Plugin']);
    Customizer::registerControl([
        'id'      => 'plugin_show_banner',
        'type'    => 'checkbox',
        'label'   => 'Show banner',
        'section' => 'plugin',
        'default' => '1',
    ]);
});
```

---

## Filters

| Filter | Receives | Extra arguments | Fires in |
|---|---|---|---|
| `the_content` | Post/page HTML (string) | — | `page_content()` (`core/frontend.php`). Core listens at priority `1` (`EditorJs`) and `5` (`render_snippets`). |
| `the_title` | Title (string) | — | `page_title()` |
| `the_excerpt` | Excerpt (string) | — | `post_excerpt()` |
| `page_date` | Formatted date (string) | — | `page_date()` |
| `page_author` | Author name (string) | — | `page_author()` |
| `featured_image_url` | Image URL (string) | — | `page_image()` |
| `site_title` | Site title (string) | — | `site_title()` |
| `site_description` | Site description (string) | — | `site_desc()` |
| `clean_menu_raw_items` | Menu item rows (array) | menu slug (string) | `menu()`, `get_theme_menu_items()` |
| `modo_customize_schema` | Full schema tree `['sections' => [...]]` | — | `Customizer::loadCodeSources()` — inject a whole schema instead of individual controls |
| `modo_advanced_options` | Advanced option definitions (array) | — | `core/Config.php` — extend the advanced settings page |
| `theme_mod_<key>` | A single customizer value | — | `Customizer::getMod()` — dynamically filter one setting, e.g. `theme_mod_modo_footer_text` |

### Example — modify the title

```php
\Core\Hooks::addFilter('the_title', function (string $title): string {
    return '★ ' . $title;
}, 20);
```

### Example — receive the extra menu argument

```php
\Core\Hooks::addFilter('clean_menu_raw_items', function (array $items, string $slug): array {
    if ($slug === 'main-menu') {
        // reorder / rename items…
    }
    return $items;
});
```

---

## Creating your own hooks (advanced)

Plugins and themes can define their **own** hooks so other code can extend them, using `Hooks::doAction()` / `Hooks::applyFilters()`:

```php
use Core\Hooks;

// Somewhere in your plugin:
Hooks::doAction('myplugin_after_save', $itemId);

$html = Hooks::applyFilters('myplugin_button_html', $html, $itemId);
// …later use $html
```

Other code then subscribes with `addAction('myplugin_after_save', …)` / `addFilter('myplugin_button_html', …)`.

> Follow the naming convention: prefix with your plugin id (e.g. `myplugin_`) to avoid collisions.

---

## GetSimple bridge (compatibility)

If you write in the GetSimple style, `add_action()` / `add_filter()` translate the classic names onto the hooks above. The full mapping is in [GetSimple-style plugins](getsimple-plugins.md#5-actions-and-filters). Key mappings:

| GetSimple name | Modo hook |
|---|---|
| `theme-header` | `theme_head` |
| `theme-footer` | `theme_footer` |
| `header` | `admin_head` (in admin) / `theme_head` |
| `footer` | `admin_footer` (in admin) / `theme_footer` |
| `index-pretemplate` | `plugins_loaded` |
| `edit-extras` | `admin_page_edit_bottom` |
| `content-top` | `before_the_content` |
| `content-bottom` | `after_the_content` |
| `content` (filter) | `the_content` |
| `title` (filter) | `the_title` |
| `excerpt` (filter) | `the_excerpt` |

---

## Priority cheat sheet

| Priority | When it runs |
|---|---|
| `1` | First (core format conversion) |
| `5` | Early (snippet expansion) |
| `10` | Default |
| `20` | Late (plugin shortcodes) |
| `100+` | Very late / cleanup |

---

## Next

* [Examples](examples.md)
* [Creating plugins](plugins.md)
