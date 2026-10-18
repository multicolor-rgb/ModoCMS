# Theme functions reference (plain-language)

[Polski](../pl/funkcje-motywu.md) | **English**

Every theme and plugin in Modo CMS is built from a set of **global helper functions**. This page explains each one in plain language: *what it does*, *what you give it*, *what it gives back*, and *how to use it*. You do not need to be a PHP expert to start — most of them are one-liners.

> Most functions **print output by default** (`$echo = true`) and also **return** the value, so you can either use `<?php page_title(); ?>` to print, or `$t = page_title(false);` to store.

## Contents

1. [Site-wide values](#1-site-wide-values)
2. [The current page / single post](#2-the-current-page--single-post)
3. [The posts loop (archive)](#3-the-posts-loop-archive)
4. [Tags and categories](#4-tags-and-categories)
5. [Menus](#5-menus)
6. [Language switching & SEO](#6-language-switching--seo)
7. [Head, footer and page shell](#7-head-footer-and-page-shell)
8. [Media & URLs](#8-media--urls)
9. [CSS grid helpers](#9-css-grid-helpers)
10. [Customizer helpers](#10-customizer-helpers)
11. [Custom fields (meta)](#11-custom-fields-meta)
12. [Snippets (reusable blocks)](#12-snippets-reusable-blocks)
13. [Content utilities](#13-content-utilities)
14. [Translations](#14-translations)

---

## 1. Site-wide values

### `site_title(bool $echo = true): string`

Prints/returns the site name (from **Settings → General**). Escaped for safe HTML output.

```php
<h1><?php site_title(); ?></h1>
```

### `site_desc(bool $echo = true): string`

Prints/returns the site tagline/description. Escaped.

```php
<p><?php site_desc(); ?></p>
```

### `site_logo(bool $echo = true): string`

Prints the site logo `<img>` if one is configured, otherwise the plain site title. Returns the HTML.

```php
<?php site_logo(); ?>
```

### `site_url(string $path = '', bool $echo = true): string`

Builds a URL on your site, automatically including the installation subfolder and (if multilingual frontend is on) the language prefix.

| Param | Meaning |
|---|---|
| `$path` | Relative path, e.g. `'blog'` or `'blog/my-post'` |
| `$echo` | `true` prints it; `false` returns it |

```php
<a href="<?php site_url('contact'); ?>">Contact</a>
<?php $home = site_url('', false); // e.g. "/modocms/" ?>
```

### `get_site_title()` / `get_site_description()` / `get_site_url()`

Non-printing variants kept for theme compatibility. They **return** the value.

```php
$title = get_site_title();
```

---

## 2. The current page / single post

### `page_title(bool $echo = true): string`

The title of the page currently being shown. Runs through the `the_title` filter, so plugins can change it.

```php
<h1><?php page_title(); ?></h1>
```

### `page_content(bool $echo = true): string`

The main content of the current page **after** all filters — this is where block-editor conversion, snippets and plugin shortcodes happen. Output is intentionally **not escaped** (it is HTML). Runs through `the_content`.

```php
<article><?php page_content(); ?></article>
```

### `page_date(string $format = 'd.m.Y', bool $echo = true): string`

The creation date of the current page, formatted with PHP's `date()` format. Runs through the `page_date` filter.

```php
<time><?php page_date('Y-m-d'); ?></time>
```

### `page_author(bool $echo = true): string`

The display name of the page author (falls back to a generic label). Runs through the `page_author` filter.

### `has_image(): bool`

`true` if the current page has a featured image.

### `page_image(bool $echo = true): string`

The featured image URL (base-subfolder aware). Escaped. Runs through the `featured_image_url` filter.

```php
<?php if (has_image()): ?>
    <img src="<?php page_image(); ?>" alt="<?php page_title(); ?>">
<?php endif; ?>
```

### `page_url(?array $page = null, bool $echo = true): string`

Builds the full URL of the current page, or of any page row you pass in. Handles parent/child hierarchy and blog permalinks automatically.

```php
<a href="<?php page_url(); ?>">Permalink</a>
```

### `resolve_page_hierarchy_path(string $slug): string`

Turns a page slug into its full hierarchical path (parents included). Useful when you only have a slug and need a clean path.

```php
$path = resolve_page_hierarchy_path('team'); // "about/team"
```

---

## 3. The posts loop (archive)

A theme archive loops through posts like this:

```php
<?php while (have_posts()): the_post(); ?>
    <h2><a href="<?php post_url(); ?>"><?php post_title(); ?></a></h2>
    <p><?php post_excerpt(); ?></p>
<?php endwhile; ?>
```

### `have_posts(): bool`

`true` while there are more posts in the current loop.

### `the_post(): void`

Moves the loop to the next post and makes it the “current page”, so the functions below refer to it.

### `post_title()` / `post_url()` / `post_image()`

Shortcuts for the current loop post (they delegate to `page_title()` / `page_url()` / `page_image()`).

### `post_excerpt(int $limit = 140, bool $echo = true): string`

A short plain-text extract of the post. It first tries the post's **meta description**, then the content (works for both HTML and block-editor JSON). Runs through `the_excerpt`. Escaped.

```php
<p><?php post_excerpt(160); ?></p>
```

### `get_recent_posts(int $limit = 6): array`

Returns recent published posts (for sidebars/footers).

```php
foreach (get_recent_posts(3) as $p) {
    echo '<a href="' . htmlspecialchars(page_url($p, false), ENT_QUOTES, 'UTF-8') . '">'
       . htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') . '</a>';
}
```

---

## 4. Tags and categories

Each function accepts an optional `$pageId`; when omitted it uses the current page.

### `get_post_tags(?int $pageId = null): array`

Returns an array of tags (`id`, `name`, `slug`).

### `has_tags(?int $pageId = null): bool`

`true` if the post has tags.

### `post_tags(?int $pageId = null, string $cssClass = 'post-tags', bool $echo = true): string`

Prints links to the tags (each with class `tag-badge`) wrapped in a `<div class="$cssClass">`.

```php
<?php post_tags(); ?>
```

### `get_post_categories(?int $pageId = null): array`

Returns an array of categories (`id`, `name`, `slug`, `description`).

### `has_categories(?int $pageId = null): bool`

`true` if the post has categories.

### `post_categories(?int $pageId = null, string $cssClass = 'post-categories', bool $echo = true): string`

Prints links to the category archives (each with class `category-badge`).

```php
<?php if (has_categories()): ?>
    <div class="cats"><?php post_categories(); ?></div>
<?php endif; ?>
```

---

## 5. Menus

### `menu(string $slug, string $cssClass = 'nav-menu'): void`

Renders a menu (built in **Appearance → Navigation**) as a nested `<ul>` with the given class. The `$slug` is the menu's identifier (e.g. `main-menu`).

```php
<nav><?php menu('main-menu'); ?></nav>
```

### `get_theme_menu(string $ulClass = 'navbar-nav ms-auto mb-2 mb-lg-0'): void`

Renders a **Bootstrap 5** navigation bar from the menu selected in the *Header Menu* setting. If that menu is empty it falls back to an auto-generated list of top-level published pages.

```php
<?php get_theme_menu(); ?>
```

### `get_theme_menu_items(string $slug = 'main-menu'): array`

Returns the menu links as a **nested tree** (each item with a `children` array) instead of printing them — handy when you want full markup control.

```php
foreach (get_theme_menu_items('main-menu') as $item) {
    echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8');
}
```

> Action hooks `before_render_menu` and `after_render_menu` fire around `menu()` rendering.

---

## 6. Language switching & SEO

Only relevant when the multilingual frontend is enabled.

### `lang_switch(string $cssClass = 'lang-switcher'): void`

Prints a language switcher. Its look is controlled by the *Language switcher style* setting (`inline`, `inline_full`, `flags`, `dropdown`, `select`).

```php
<?php lang_switch(); ?>
```

### `hreflang_tags(bool $echo = true): string`

Prints `<link rel="alternate" hreflang="…">` tags for the current document (or the language homepages). Respects the settings for multilingual + hreflang.

### `modo_locale_url(array $page, string $lang, string $defaultLang, string $basePrefix): string`

Advanced helper: builds a locale- and hierarchy-aware URL for a page row in a target language. Used internally by the switcher and hreflang output.

---

## 7. Head, footer and page shell

### `theme_head(): void`

**Call this in your `header.php` `<head>`.** It prints the document title, meta description, Open Graph tags, canonical URL, favicon, hreflang tags, custom head scripts, the customizer CSS variables, and then fires the `theme_head` and `theme-header` hooks (where plugins inject their CSS/JS).

```php
<head>
    <meta charset="utf-8">
    <?php theme_head(); ?>
</head>
```

### `theme_footer(): void`

**Call this just before `</body>`.** It prints custom footer scripts and fires the `theme_footer` and `theme-footer` hooks (where plugins inject their JS).

```php
    <?php theme_footer(); ?>
</body>
```

### `get_header()` / `get_footer()`

Compatibility aliases. `get_header()` fires the `theme-header` hook; `get_footer()` fires `theme-footer`. Provided so GetSimple-era themes keep working.

### `theme_header()` / `theme_footer()` (template-tag variants)

Compatibility helpers (defined only if not already present). They output custom head/footer scripts and fire `theme-header` / `theme-footer`. In new themes use `theme_head()` / `theme_footer()`.

---

## 8. Media & URLs

### `resolve_media_url(string $path): string`

Turns a stored media path into a working URL, always including the installation subfolder (and leaving absolute `http(s)://` URLs untouched).

```php
<img src="<?= htmlspecialchars(resolve_media_url(get_theme_mod('hero_image')), ENT_QUOTES, 'UTF-8') ?>">
```

### `webp_url(string $url): string`

If a matching `.webp` file exists next to a `.jpg`/`.png`, returns the WebP URL; otherwise returns the original URL. Great for faster images.

```php
<img src="<?= htmlspecialchars(webp_url(page_image(false)), ENT_QUOTES, 'UTF-8') ?>">
```

### `get_theme_url(): string`

The public URL of the currently active theme folder.

```php
<link rel="stylesheet" href="<?php echo get_theme_url(); ?>/assets/css/style.css">
```

---

## 9. CSS grid helpers

These relate to the **CSS grid / framework** chosen in Settings.

### `active_grid(): string`

Returns the active framework id (`none`, `bootstrap`, `bulma`, `pico`, `uikit`, `foundation`, `tailwind`, `modo`, `custom`).

### `grid_css_urls(): array`

Returns the public URLs of the active framework's bundled stylesheets (advanced use — the frontend already injects them via `theme_head()`).

---

## 10. Customizer helpers

### `get_theme_mod(string $key, mixed $default = ''): mixed`

Reads a customizer value (published in the `theme_mods` table, or the live draft preview).

```php
$footerText = get_theme_mod('modo_footer_text', 'Built with Modo CMS');
```

### `the_theme_mod(string $key, mixed $default = ''): void`

Same as above, but **prints** the value escaped for HTML.

```php
<p><?php the_theme_mod('modo_footer_text'); ?></p>
```

### `customizer_css(): void`

Prints the `:root` CSS custom properties generated from controls that declare a `css_var`. `theme_head()` already calls it; call it manually only in special cases.

---

## 11. Custom fields (meta)

### `get_meta(string $key, mixed $default = '', ?int $pageId = null): mixed`

Reads a custom field attached to a page. The value is cast by the field type (text/number/boolean/json). Without `$pageId` it uses the current page.

```php
$phone = get_meta('phone', '—');
```

### `the_meta(string $key, mixed $default = ''): void`

Prints a custom field value, escaped.

```php
<span><?php the_meta('phone'); ?></span>
```

### `get_all_meta(?int $pageId = null): array`

Returns **all** custom fields of a page as `key => value`.

```php
foreach (get_all_meta() as $key => $value) {
    echo '<dt>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</dt>';
    echo '<dd>' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</dd>';
}
```

## 12. Snippets (reusable blocks)

### `snippet_content(string $name, string $default = ''): string`

Returns the rendered content of a snippet (a global reusable block) by name. Nested `[snippet:name]` shortcodes are expanded safely.

```php
$promo = snippet_content('promo-banner');
```

### `the_snippet(string $name): void`

Prints a snippet (expanded, raw HTML).

```php
<?php the_snippet('promo-banner'); ?>
```

### `render_snippets(string $content): string`

Expands every `[snippet:name]` shortcode found in a string. Already wired into `the_content` (priority 5); call it manually only for custom content streams.

---

## 13. Content utilities

### `content_to_text(?string $content, int $limit = 0): string`

Converts any content value to **plain text**, truncating to `$limit` characters (0 = no limit). Works whether the content was written in TinyMCE (HTML) or the block editor (JSON).

```php
$plain = content_to_text(page_content(false), 120);
```

### `content_excerpt(?string $content, int $limit = 140): string`

A convenience wrapper around `content_to_text()` for excerpts.

---

## 14. Translations

### `__(string $key, ?string $lang = null): string`

Returns the translation of an English source string in the current admin/frontend language.

```php
echo __('Home');
```

### `_e(string $key, ?string $lang = null): void`

Same, but prints it escaped.

```php
<h1><?php _e('Welcome'); ?></h1>
```

---

## Quick index

`site_title`, `site_desc`, `site_logo`, `site_url`, `get_site_title`, `get_site_description`, `get_site_url`, `page_title`, `page_content`, `page_date`, `page_author`, `has_image`, `page_image`, `page_url`, `resolve_page_hierarchy_path`, `have_posts`, `the_post`, `post_title`, `post_url`, `post_image`, `post_excerpt`, `get_recent_posts`, `get_post_tags`, `has_tags`, `post_tags`, `get_post_categories`, `has_categories`, `post_categories`, `menu`, `get_theme_menu`, `get_theme_menu_items`, `lang_switch`, `hreflang_tags`, `modo_locale_url`, `theme_head`, `theme_footer`, `get_header`, `get_footer`, `theme_header`, `resolve_media_url`, `webp_url`, `get_theme_url`, `active_grid`, `grid_css_urls`, `get_theme_mod`, `the_theme_mod`, `customizer_css`, `get_meta`, `the_meta`, `get_all_meta`, `snippet_content`, `the_snippet`, `render_snippets`, `content_to_text`, `content_excerpt`, `__`, `_e`.

---

## Next

* [Hooks reference](hooks.md)
* [Examples](examples.md)
