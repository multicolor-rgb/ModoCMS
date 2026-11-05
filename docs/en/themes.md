# Creating themes

[Polski](../pl/motywy.md) | **English**

A Modo CMS theme is a folder inside `themes/` containing PHP templates, a
`theme.json` manifest and optional CSS/JS assets. Themes are plain PHP: there
is no build step and no template language to learn.

---

## 1. Folder structure

```text
themes/my-theme/
├── theme.json          # metadata + customizer schema (recommended)
├── header.php          # <head>, site header, navigation
├── footer.php          # footer, scripts, </body>
├── page.php            # static page
├── single.php          # single blog post (falls back to page.php)
├── archive.php         # blog/tag/category listing (falls back to index.php/page.php)
├── front-page.php      # optional homepage template
├── 404.php             # optional not-found page
├── search.php          # optional search results (falls back to archive.php)
├── maintenance.php     # optional 503 page
├── editor.css          # optional TinyMCE typography
└── assets/
    ├── css/style.css
    └── js/theme.js
```

Only `header.php` and `footer.php` are effectively required — every other
template falls back to a safe default (`page.php` → `index.php`/`404.php`).
Shipping all common templates gives the best result.

### Template selection

| Request | Template |
|---|---|
| Static page | `page.php` |
| Blog post | `single.php`, then `page.php` |
| Homepage / configured front page | `front-page.php`, then `page.php` |
| Blog archive, tag, category | `archive.php`, then `index.php`, then `page.php` |
| Search (`/search?q=...`) | `search.php`, then `archive.php` |
| Missing page | `404.php` |
| Maintenance mode | `maintenance.php`, then the built-in page |

---

## 2. `theme.json`

```json
{
  "name": "My Theme",
  "slug": "my-theme",
  "version": "1.0.0",
  "author": "Your Name",
  "description": "A short description shown in the theme list.",
  "files": [
    "header.php",
    "footer.php",
    "page.php",
    "single.php",
    "archive.php",
    "front-page.php",
    "404.php",
    "assets/css/style.css"
  ],
  "editor": {
    "body_class": "editor-content"
  },
  "customize": {
    "sections": [
      {
        "id": "branding",
        "title": "Branding",
        "controls": [
          {
            "id": "accent",
            "type": "color",
            "label": "Accent colour",
            "default": "#2563eb",
            "css_var": "my-accent"
          }
        ]
      }
    ]
  }
}
```

- `name`, `slug`, `version`, `author`, `description` are shown in **Appearance →
  Themes**.
- The `slug` should match the folder name.
- `files` is informational; it can be used by tooling and documentation.
- `editor` configures the TinyMCE content canvas. See
  [Theme editor styles](theme-editor-styles.md).
- `customize` defines the live Theme Customizer sidebar. A complete reference
  with all field types, `tinymce`, `pagelink`, `menu`, `date` and helpers is in
  [Theme Customizer settings](customizer.md).

Keep the manifest valid JSON. An invalid file is ignored and the theme still
works, but its customizer controls and metadata will not be available.

---

## 3. Header and footer

`header.php` must print the document head and open the layout. Call
`theme_head()` — it outputs the title, meta description, canonical URL,
Open Graph tags, favicon, hreflang, customizer CSS, grid CSS, custom head
scripts and the `theme_head` hooks.

```php
<?php
if (!function_exists('site_title')) { die('Modo CMS theme'); }
$themeUrl = htmlspecialchars(get_theme_url(), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\Core\I18n::getLocale(), ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php theme_head(); ?>
    <link rel="stylesheet" href="<?= $themeUrl ?>/assets/css/style.css">
</head>
<body class="my-theme">
    <header class="site-header">
        <a href="<?= htmlspecialchars(site_url('', false), ENT_QUOTES, 'UTF-8') ?>">
            <?= site_logo(false) ?>
        </a>
        <?php get_theme_menu('nav-list'); ?>
        <?php lang_switch('lang-switcher'); ?>
    </header>
    <main id="main-content">
```

`footer.php` closes the layout and calls `theme_footer()`:

```php
    </main>
    <footer class="site-footer">
        <p>&copy; <?= date('Y') ?> <?= site_title(false) ?></p>
    </footer>
    <?php theme_footer(); ?>
    <script src="<?= $themeUrl ?>/assets/js/theme.js"></script>
</body>
</html>
```

`theme_head()` and `theme_footer()` are required for plugins, analytics,
customizer CSS and the admin bar to work.

---

## 4. Pages and posts

A static page template:

```php
<?php require __DIR__ . '/header.php'; ?>

<article class="page">
    <h1><?php page_title(); ?></h1>
    <?php if (has_image()): ?>
        <img src="<?php page_image(); ?>" alt="<?php page_title(); ?>">
    <?php endif; ?>
    <?php page_content(); ?>
</article>

<?php require __DIR__ . '/footer.php'; ?>
```

A single post can use the same functions plus date, author, categories and tags:

```php
<h1><?php page_title(); ?></h1>
<p><?php page_date(); ?> · <?php page_author(); ?></p>
<?php page_content(); ?>
<?php post_categories(); ?>
<?php post_tags(); ?>
```

The blog loop:

```php
<?php if (have_posts()): ?>
    <?php while (have_posts()): the_post(); ?>
        <article>
            <h2><a href="<?php post_url(); ?>"><?php post_title(); ?></a></h2>
            <p><?php post_excerpt(140); ?></p>
        </article>
    <?php endwhile; ?>
<?php else: ?>
    <p><?= _e('No posts found.') ?></p>
<?php endif; ?>
```

Inside the loop these functions work without arguments:
`post_title()`, `post_url()`, `post_excerpt()`, `post_image()`,
`post_thumbnail_image()`, `page_date()`, `page_author()`, `post_tags()`,
`post_categories()`, `get_meta()`.

Archive pagination receives `$currentPage` and `$totalPages` from the router.

---

## 5. Navigation, languages and URLs

```php
<?php get_theme_menu('main-nav'); ?>          // menu from Settings/Navigation
<?php menu('footer-menu', 'footer-links'); ?> // explicit menu slug
<?php lang_switch('lang-switcher'); ?>
```

- `site_url('about', false)` returns a URL relative to the installation
  (works in a subdirectory).
- `get_theme_url()` returns the active theme URL.
- `page_url($page, false)` / `post_url()` return the current document URL.
- `resolve_media_url($value)` turns a stored media path into a public URL.

Use `htmlspecialchars()` for every value printed into an attribute or text
node unless a helper already escapes it.

---

## 6. Customizer values

Read settings published in the Theme Customizer:

```php
<?php $title = get_theme_mod('hero_title', 'Welcome'); ?>
<h1><?php the_theme_mod('hero_title', 'Welcome'); ?></h1>

<a href="<?php the_theme_mod_link('hero_cta', '/'); ?>">Read more</a>
<div class="hero-text"><?php the_theme_mod_html('hero_text'); ?></div>
```

- `the_theme_mod()` escapes plain text.
- `the_theme_mod_link()` resolves internal links and escapes the URL.
- `the_theme_mod_html()` prints admin-authored rich text (`tinymce` field).

CSS variables declared with `css_var` in `theme.json` are emitted by
`theme_head()` automatically.

---

## 7. Reusing admin editor styles

Create `editor.css` and point to it from `theme.json`:

```json
"editor": {
  "body_class": "editor-content"
}
```

Place `editor.css` in the theme root — Modo CMS detects it automatically and loads
it only inside the TinyMCE canvas, so headings, lists, tables and links in the
editor match the frontend. See
[Theme editor styles](theme-editor-styles.md).

---

## 8. Hooks and plugins

Themes can use the same hook system as plugins:

```php
\Core\Hooks::addAction('theme-footer', function (): void {
    echo '<script src="/assets/js/extra.js"></script>';
});

\Core\Hooks::addFilter('the_content', function (string $html): string {
    return $html;
});
```

Use `theme_head` / `theme-header` for head assets and `theme_footer` /
`theme-footer` for scripts. See the [Hooks reference](hooks.md).

---

## 9. Security and quality checklist

- Escape all output; prefer `the_*` helpers where available.
- Do not use `eval`, `extract()` on request data or raw SQL.
- Do not hardcode `RewriteBase` or absolute domain names.
- Test in the domain root **and** in a subdirectory.
- Test with multilingual frontend enabled and disabled.
- Test the homepage, a page with a parent, a post, a tag, a category, search
  and a 404 page.
- Check the browser console and PHP error log.
- Make the layout responsive and keyboard-accessible; keep a visible focus
  style and a skip link.
- Do not load external CDNs if you want the site to work offline; bundle assets
  in the theme folder.

---

## 10. Packaging a theme

1. Use a lowercase folder name with hyphens, e.g. `my-theme/`.
2. Keep `slug` in `theme.json` identical to the folder name.
3. Remove development files (`node_modules`, `.git`, screenshots you do not
   need).
4. Zip the folder so `my-theme/theme.json` is at the archive root.
5. Install by extracting it into `themes/` and selecting it in **Appearance →
   Themes**.

Before publishing, run the site with `debug_log` enabled and check
`data/logs/app.log` for PHP notices from your templates.
