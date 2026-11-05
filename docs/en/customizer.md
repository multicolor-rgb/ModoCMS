# Theme Customizer settings

[Polski](../pl/customizer.md) | **English**

The Theme Customizer is the "Edit template settings" sidebar available to
administrators on the frontend. It combines three sources into one schema:

1. the `customize` block in the active theme's `theme.json` (recommended for
   settings shipped with a theme),
2. sections and controls registered from a plugin with
   `Customizer::registerSection()` / `Customizer::registerControl()`,
3. settings created with the GUI builder in **Appearance → Customize → Manage
   settings** (stored in the database).

Published values live in the `theme_mods` table. Unsaved values used by the
live preview are stored per user in the session, so a preview never changes the
public site until **Publish** is clicked.

---

## Schema structure

```json
{
  "customize": {
    "sections": [
      {
        "id": "hero",
        "title": "Hero",
        "description": "Homepage hero settings",
        "priority": 10,
        "controls": [
          {
            "id": "hero_title",
            "type": "text",
            "label": "Hero title",
            "default": "Welcome",
            "priority": 10
          }
        ]
      }
    ]
  }
}
```

| Key | Required | Description |
|---|---|---|
| `id` | yes | Unique id. Used by `get_theme_mod()`, so keep it stable. |
| `type` | yes | One of the types listed below. |
| `label` | yes | Label shown in the sidebar. |
| `description` | no | Small help text below the field. |
| `default` | no | Value used before the administrator publishes anything. |
| `section` | yes in hooks | Section id; in `theme.json` the parent section is implicit. |
| `priority` | no | Lower numbers appear first. Default `100`. |
| `options` | select/radio | Object of `value => label`. |
| `min`, `max`, `step` | number/range | Numeric limits. |
| `css_var` | no | Emits a `:root` CSS custom property for instant preview. |
| `css_unit` | no | Unit appended to numeric CSS values, e.g. `px`. |
| `link_types` | pagelink | `page`, `post` or `page,post` (default). |

---

## Control types

| Type | Stored value | Rendered as |
|---|---|---|
| `text` | plain text | single-line input |
| `textarea` | plain text | multi-line textarea |
| `number` | number | number input |
| `range` | number | range slider |
| `checkbox` / `toggle` | `1` / `0` | switch |
| `select` | option value | select |
| `radio` | option value | radio buttons |
| `color` | `#rrggbb`, `rgb()`, `hsl()` | colour picker + text mirror |
| `image` | media URL/path | media library picker |
| `url` | relative path or `http(s)`, `mailto:`, `tel:` URL | URL input |
| `email` | valid e-mail or empty | e-mail input |
| `code` | raw text | code textarea |
| `tinymce` | sanitised HTML | TinyMCE rich-text editor |
| `pagelink` | site-relative path | list of published pages/posts |
| `menu` | menu slug | list of menus |
| `date` | `YYYY-MM-DD` | date input |
| `heading` | — | section-like heading (no value) |
| `hr` | — | separator (no value) |

`heading` and `hr` are layout-only controls and are never saved.

### Rich text (`tinymce`)

```json
{
  "id": "hero_text",
  "type": "tinymce",
  "label": "Hero content",
  "default": "<p>Welcome to our site.</p>"
}
```

The editor is bundled locally (no CDN). Content is sanitised on save: scripts,
frames, forms, event-handler attributes and `javascript:` URLs are removed.
Read it in a template with the HTML helper:

```php
<div class="hero-text"><?php the_theme_mod_html('hero_text'); ?></div>
```

`the_theme_mod()` escapes HTML, so use it only for plain text. `tinymce`
values are intended for administrators; do not use them as a place for
untrusted visitor input.

### Internal links (`pagelink`)

```json
{
  "id": "hero_cta",
  "type": "pagelink",
  "label": "Hero button target",
  "link_types": "page,post",
  "default": "/about"
}
```

The sidebar shows a grouped list of published static pages and blog posts. The
stored value is a site-relative path (for multilingual sites it already
contains the language prefix). Use the link helper so a subdirectory install
also works:

```php
<a href="<?php the_theme_mod_link('hero_cta', '/'); ?>">Read more</a>
```

`get_theme_mod_link()` leaves absolute URLs, `mailto:`, `tel:` and `#fragment`
unchanged, and prepends the installation subdirectory for internal paths.
If a page is deleted or unpublished, the old value is rejected on the next
publish; the sidebar shows it as "Unavailable page or post" until it is changed.

### Menus (`menu`)

```json
{
  "id": "footer_menu",
  "type": "menu",
  "label": "Footer menu",
  "default": "main-menu"
}
```

Use the stored slug with the theme menu helper:

```php
<?php menu((string) get_theme_mod('footer_menu', 'main-menu'), 'footer-nav'); ?>
```

### Dates (`date`)

```json
{
  "id": "event_date",
  "type": "date",
  "label": "Event date",
  "default": "2026-12-31"
}
```

The value is stored as `YYYY-MM-DD` and invalid dates are rejected.

---

## Registering controls from a plugin

```php
use Core\Hooks;
use Core\Customizer;

Hooks::addAction('modo_customize_register', function (): void {
    Customizer::registerSection([
        'id'       => 'myplugin',
        'title'    => 'My Plugin',
        'priority' => 100,
    ]);

    Customizer::registerControl([
        'id'         => 'myplugin_cta',
        'type'       => 'pagelink',
        'label'      => 'Call-to-action target',
        'section'    => 'myplugin',
        'link_types' => 'page,post',
        'default'    => '/',
    ]);

    Customizer::registerControl([
        'id'      => 'myplugin_note',
        'type'    => 'tinymce',
        'label'   => 'Notice',
        'section' => 'myplugin',
        'default' => '',
    ]);
});
```

The control id must be unique across the theme, plugins and the GUI builder.
Plugin sections are read-only in the GUI builder but appear in the frontend
panel like any other control.

---

## Reading values

```php
$title  = get_theme_mod('hero_title', 'Welcome');
$link   = get_theme_mod_link('hero_cta', '/');
$isOn   = get_theme_mod('hero_show', '0') === '1';
$html   = get_theme_mod_html('hero_text', '');
```

| Helper | Output |
|---|---|
| `get_theme_mod($key, $default)` | raw value (apply your own escaping when needed) |
| `the_theme_mod($key, $default)` | escaped HTML text |
| `get_theme_mod_link($key, $default)` | resolved URL suitable for `href` |
| `the_theme_mod_link($key, $default)` | escaped resolved URL |
| `get_theme_mod_html($key, $default)` | raw admin-authored HTML |
| `the_theme_mod_html($key, $default)` | raw admin-authored HTML printed directly |

Always escape values that come from text/url fields when printing them:

```php
<p><?php the_theme_mod('hero_title'); ?></p>
<a href="<?php the_theme_mod_link('hero_cta'); ?>">Read more</a>
```

---

## CSS variables and live preview

A control with `css_var` is instantly previewed without reloading the iframe:

```json
{
  "id": "accent",
  "type": "color",
  "label": "Accent colour",
  "default": "#0d6efd",
  "css_var": "modo-accent"
}
```

`theme_head()` prints the generated `:root` variables via
`customizer_css()`. Values are also sanitised before being written into CSS.

Controls without `css_var` refresh only the preview iframe after editing, so
the sidebar keeps its scroll position, open sections and focus. **Publish**
and **Reset** reload the whole editor because they change the committed values.

---

## GUI builder

Open **Appearance → Customize** (or the "Manage settings" tab) to create your
own sections and fields without editing `theme.json`. The builder supports the
same types, `options`, `min/max/step`, CSS variables and `link_types`.

Settings created in the builder are stored in the database and are editable
there. Theme and plugin settings are read-only in the builder, so upgrading a
theme or plugin never overwrites your custom fields.

---

## Security and compatibility

- Only users with `manage_settings` can open the customizer or publish values.
- `url` values reject dangerous schemes (`javascript:`, `data:`, `vbscript:`).
- `tinymce` HTML is sanitised on save; still treat it as administrator content.
- `pagelink` accepts only currently published pages/posts. When a slug changes,
  the automatic 301 redirect keeps old links working until the next publish.
- Adding a new control to `theme.json` is safe: existing published values stay
  in the database and are reused when the id is unchanged.
- Removing a control stops it from being rendered and published; its old value
  may remain in `theme_mods` but is ignored.
