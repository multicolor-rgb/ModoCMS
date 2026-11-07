# Changelog

All notable changes to Modo CMS. Format based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning follows
[Semantic Versioning](https://semver.org/).

## [3.3.1] — 2026-11-07

### Fixed

- **File uploads in the Media tab** — the "+ Upload File" button and drag-and-drop now send files to the server again. Since version 3.0.9 `admin/media.php` referenced an undefined `csrf` variable, so the browser aborted the request before sending it (`ReferenceError: csrf is not defined`) and the UI showed no error. The CSRF token is now read from `window.MODO_CSRF`, matching the other admin panels. The regression was introduced in commit `5c9f5d6` ("release: 3.0.9 — security hardening, content API and tooling").

## [3.3.0] — 2026-11-05

### Added

- **Hierarchical categories** — categories now have a `parent_id` and support multiple nesting levels. Existing categories become top-level entries, so the migration is fully backwards compatible.
- **Category tree in the admin** — the category form has a parent selector and the list is rendered hierarchically with indentation. Deleting a category promotes its subcategories to the deleted category's parent.
- **Category hierarchy in the post editor** — category checkboxes keep the tree order and indentation.
- **Page tree in the content list** — pages are displayed as an expandable/collapsible tree with persisted state and "Expand all" / "Collapse all" buttons.
- **Theme functions for the hierarchy** — `get_categories()`, `get_category_tree()` and `get_category_children()`; `get_post_categories()` now also returns `parent_id` and `depth`.

### Changed

- **Responsive admin tables** — tables get a horizontal scrollbar on medium and small screens instead of clipping their last columns. This covers the content list, categories and page types, among others.

### Fixed

- Fixed the missing horizontal scrollbar in admin tables at 641–900 px widths.

## [3.2.0] — 2026-11-05

### Added

- **Site Health dashboard card** — installation score and actionable checks: installer, permissions, HTTPS, debug, headers, cache, backups, cron, PHP and the default password.
- **Trash and restore** — deleting a document moves it to the trash; restore it or delete permanently. Automatic cleanup after `trash_retention_days`.
- **Signed preview links** — the editor's Preview button creates a 24-hour link to the saved draft, uncached and noindex.
- **RSS 2.0 feed** — `/feed.xml` and `/{lang}/feed.xml`, with an automatic `<link rel="alternate">` from `theme_head()`.
- **Scheduled tasks (cron)** — `tools/cron.php` and `cron.php?token=...`; scheduled publishing, trash cleanup, retention, sitemap and cache.
- **Cron card in Settings** — last run, CLI command, HTTP URL, token and a "Run now" button.

### Changed

- **Content API** — `DELETE` moves entries to the trash by default; `?force=1` deletes permanently and `?trash=1` lists the trash.
- **GitHub Pages documentation** — new "Automation and maintenance" page and expanded usage, API and theme guides.

### Fixed

- Deleted documents are consistently excluded from the site, menus, sitemap, RSS, API, search and counters.
- Fixed the `ThemeState` call in the maintenance response.

## [3.1.1] — 2026-11-05

### Fixed

- **Dark/light mode is now saved per account** in `users.admin_theme`; the choice survives page navigation, reload, logout/login and other devices.
- Unified the `localStorage` key (`modo_cms_theme`) and added migration for the old `clean_cms_theme` / `theme` values that caused the theme to reset.
- TinyMCE in the content editor now uses the correct dark/light skin.
- The selected Settings tab is also stored in the URL, so saving a form or reloading no longer jumps back to "General".

### Added

- **Complete GitHub Pages documentation**: a theme-creation guide, an administrator usage guide and an expanded Content API guide (tokens, CRUD, curl, permissions, errors).
- New documentation-hub entries, README links and cross-links.

## [3.1.0] — 2026-11-05

### Added

- **New Theme Customizer field types** — `tinymce` (rich text), `pagelink` (pick a published page or post as an internal link), `menu` and `date`.
- **Internal links** — pick from a list of pages/posts, store a relative path, validate on publish and use the new `get_theme_mod_link()` / `the_theme_mod_link()` helpers.
- **Rich text in themes** — `get_theme_mod_html()` / `the_theme_mod_html()` helpers plus HTML sanitisation on save.
- **Schema builder** — new field types and the `link_types` option.
- **GitHub Pages documentation** — a new "Theme Customizer settings" page with the full type table, `theme.json`, hook, builder and helper examples.

### Fixed

- Removed the duplicate `modo_footer_text` control in the default theme (the footer image is now `modo_footer_image`).
- The `url` field rejects dangerous schemes (`javascript:`, `data:`, `vbscript:`).

## [3.0.9] — 2026-11-05

### Security

- **Host-header poisoning fixed** — absolute URLs (password-reset link, canonical, sitemap) are no longer built from an unverified `HTTP_HOST`; extra domains can be allowed with `trusted_hosts`.
- **Installation gate** — the site and API stay unavailable until the wizard stores `install_completed`; the demo account uses a random password and `install.php` writes a lock file.
- **API tokens** — stored as SHA-256 hashes, with optional expiry, a 120 req/min limit and capability checks (`publish_pages`, `edit_others_pages`, etc.). The raw token is shown only once.
- **Password reset** — tokens are hashed, requests are rate-limited, and a password change invalidates sessions and API tokens.
- **2FA and login** — TOTP attempt limit, CSRF on login forms, `session.use_strict_mode` and an idle-session timeout.
- **Uploads and SVG** — the endpoint requires CSRF and SVG is sanitised through the DOM (scripts, `foreignObject`, animation and `javascript:` removed).
- **Headers** — removed deprecated `X-XSS-Protection`, added CSP, `X-Permitted-Cross-Domain-Policies` and static-asset caching.

### Fixed

- Maintenance-mode IP allow-list now matches a single address exactly (`203.0.113.7` no longer admits `.70`).
- The theme editor shows error messages instead of printing them before `<!DOCTYPE>`.
- API writes refresh the page cache and sitemap; cache writes are atomic.
- Added indexes for common queries and retention for `login_attempts`, `visits`, `rate_limits` and the audit log.
- Custom fields are loaded with one query per page instead of N+1.

### Added

- **Content API** — read, create, update and delete (`GET/POST/PUT/PATCH/DELETE`), documentation and OpenAPI.
- **Activity log** — admin and API operations in Settings → Tools.
- **Search** — `/search?q=...` with a form in the bundled themes.
- **Scheduled publishing** — publish-date field; future entries stay hidden until their date.
- **Test e-mail** — button in Settings → Email.
- **CLI** — `tools/modo.php` (`health`, `cache:purge`, `sitemap`, `thumbs`, `audit:prune`, `i18n`).
- **CI and smoke test** — PHP 8.0–8.4 matrix, lint, translation audit and `tests/smoke.sh`.

## [3.0.8] — 2026-11-05

### Changed

- **Customizer without sidebar reloads** — the theme settings panel and the page preview are now separate documents. The preview renders in an iframe and changes refresh only that frame, so the sidebar keeps its scroll position, expanded sections and focus.
- **CSS variable preview** — `css_var` values are applied directly inside the iframe document and re-applied after every preview refresh.
- **Publish and reset** — still reload the whole editor so the panel immediately shows the values stored in the database.

### Fixed

- The admin bar is not rendered inside the preview frame, so it no longer covers the page or duplicates navigation.

## [3.0.7] — 2026-11-04

### Added

- **`page_thumbnail_image()` / `post_thumbnail_image()`** — return the featured image thumbnail URL (480 px by default, with a width parameter).
- **`page_image_srcset()` / `post_image_srcset()`** — responsive `srcset` built from available thumbnails.
- **Translation audit** — `tools/i18n-audit.php` detects missing and empty keys in every language.
- Filled all missing translations in `en`, `pl`, `de` and `es`.

### Fixed

- **Phone photo orientation** — EXIF Orientation is now applied when generating WebP too, not only thumbnails. Portrait 9:16 photos are no longer turned into 16:9.
- **Old thumbnails and WebP copies** — the thumbnail cache version is bumped, and the force regeneration button rebuilds WebP copies as well.
- **`webp_url()`** — no longer returns an old WebP copy whose dimensions do not match the original orientation; it falls back to the original.
- **Editing and bulk transforms** — regenerate thumbnails and WebP copies with the correct orientation.

## [3.0.6] — 2026-11-04

### Added

- **"Select all" in the media picker** — a checkbox in multi-select mode selects every file matching the current folder filter, including items hidden by "Load more".
- **Bulk editing in the media library**:
  - bulk ALT text and caption updates for selected files,
  - bulk image processing: rotate, flip, resize and quality; overwrite originals or save copies,
  - forced thumbnail regeneration for selected files.
- **Force-regenerate all thumbnails** — a new button in Settings → Performance rebuilds 80/160/240 previews and 480/960 gallery thumbnails for the whole library.
- New `alt_text` and `caption` columns in the `media` table; existing databases are migrated automatically.

### Fixed

- Editing an image now rebuilds thumbnails in every configured size immediately, including gallery sizes — no backfill wait required.

## [3.0.5] — 2026-11-04

### Fixed

- **Gallery preview in the admin panel** — gallery field items now use system thumbnails (`thumb.php`, 160 px) instead of loading the original images; this also covers images added through the picker and upload.
- **Subdirectory installations** — `admin/thumb.php` now handles paths that include the base subdirectory (`/cms/uploads/...`).
- Thumbnails still fall back to the original image when generation is not possible.

### No database changes

- Updating from 3.0.4 requires no migration.

## [3.0.4] — 2026-11-04

### Added

- **Grid guides in TinyMCE** — rows and columns are visible in the editor through dashed outlines; the guides never reach the frontend.
- **Easier grid editing** — every column's content is wrapped in `<p>` automatically and existing grids are normalised when the editor opens. Changing a block format (e.g. `h1 → p`) no longer turns the column `<div>` into a heading and destroys the structure.
- **Automatic Editor.js migration** — legacy JSON documents are converted to HTML once (pages, snippets, revisions) on the first admin request; the CLI script `tools/migrate-editorjs.php` is also available.
- **Editor canvas padding** — 10 px inner spacing in TinyMCE.

### Changed

- **TinyMCE is the only content editor** — Editor.js was removed from the panel, settings, plugins and the release package.
- **The editor canvas is always light** — dark mode only changes the toolbar and dialogs; content is edited on a white background.
- **`box-sizing: border-box`** — fixed widths for grid containers and columns (bundled CSS + TinyMCE canvas).
- **`editor.css` is TinyMCE-only** — CSS scoping for the inline block editor was removed.

### Fixed

- No horizontal scrollbar in the editor.
- Grid columns no longer wrap under each other because of padding.
- Changing a block format inside a grid no longer destroys the column wrapper.
- Dark text on a dark background in dark mode.

### Removed

- **Editor.js block editor** — code, bundles, settings, the `editor-bridge.js` bridge, tools and Gallery/Form/Carousel integrations.
- Unused CSS scoping helpers (`scopeCss()`, `editorScopedCss()`, `ThemeEditorStyles::scopedCss()`).
- Test `robots.txt` / `sitemap.xml` from the repository (they are generated at runtime).

## [3.0.3] — 2026-11-04

### Added

- **Gallery thumbnails**:
  - custom-field galleries and the Modo Gallery plugin load thumbnails through
    `srcset`/`sizes` (480 and 960 px by default) instead of full-size images,
  - the lightbox still opens the original file,
  - existing 240/320 px previews are reused immediately, so galleries benefit
    before a full backfill,
  - thumbnails are generated on upload, image edit and gallery-field save,
  - new settings under **Settings → Performance → Gallery thumbnails**:
    enable/disable, automatic generation, widths (default `480,960`) and
    quality (default `78`),
  - the **Generate gallery thumbnails** button processes only images used by
    galleries, and the panel shows a missing-thumbnail counter,
  - after changing the widths, run the gallery backfill again to create the new
    sizes,
  - images above GD's 20 MP limit use the ImageMagick CLI (`/usr/bin/convert`)
    when available,
  - local full URLs (`https://domain/uploads/...`) and subdirectory paths
    (`/cms/uploads/...`) are recognised correctly,
  - without GD, Imagick or the ImageMagick CLI, galleries fall back to the
    original images.
- New `MediaLibrary::frontendImageSources()`, `galleryThumbnailWidths()`,
  `thumbnailGenerationWidths()`, `thumbnailExists()` and
  `canGenerateThumbnail()` methods, plus `MetaGallery::warmThumbnails()`,
  `allGalleryImagePaths()` and `missingGalleryThumbnailCount()`.
- **Media library**:
  - bulk deletion of selected files and folder contents with confirmation,
  - a lightbox with zoom, previous/next navigation, original preview and an
    edit action,
  - a browser image editor with rotate, flip, crop presets and save-as-copy or
    overwrite,
  - new `admin/media-save.php` and `admin/assets/js/media-tools.js`, plus a
    rebuilt `admin/media.php` and `core/MediaLibrary.php`.
- **System updater** under `Settings → System`:
  - checks the latest public release through the GitHub API (with a cache and
    a manual refresh button),
  - one-click installation of the latest release with HTTPS download,
  - SHA-256 package verification, zip-slip protection and size limits,
  - automatic database snapshot and a copy of every replaced file in
    `data/backups/` before updating,
  - skips `data/`, `uploads/`, `modoconfig.php` and `install.php`, and preserves
    a custom root `.htaccess`,
  - temporary maintenance mode while files are replaced, cache/OPcache purge and
    automatic rollback on failure.
- `core/Version.php` as the single source of the installed version.
- PL/EN/DE/ES translations and documentation for the new panel messages.

### Fixed

- **Gallery lightbox**: the `.modo-gallery` container is no longer treated as a
  theme lightbox trigger, so on “Realizacje” posts only the GLightbox from the
  custom field handles gallery images.
- **Thumbnail diagnostics**: the backfill reports generated, skipped and failed
  files; the interface shows errors instead of a false success and warns when
  the image backend is missing or `uploads/thumbs` is not writable; generation
  failures are written to the PHP error log.
- Restored image-editor translation keys removed by an earlier package version
  (`Invalid image.`, `The image is too large to upload.`,
  `Could not save the image.`, `Original`, and `Reset` in DE/ES) and preserved
  the display filename when overwriting an image.
- **System updater**: when the GitHub API limit is exhausted (HTTP 403/429), the
  latest release is read from the public `releases.atom` feed, so update checks
  no longer fail. Added an optional GitHub token (`update_github_token`) that
  raises the API limit from 60 to 5000 requests per hour, and extended the
  update-check cache to 12 hours.
- **Cookie Consent**: the banner no longer blocks page scrolling in the `bar`,
  `card`, `bottom-left` and `bottom-right` layouts; scrolling is locked only by
  the full-screen modal, whose content remains scrollable. Plugin bumped to
  `1.0.1`.

### No database changes

- No migration — settings are stored in the `settings` table on the first save
  in the panel; until then the defaults apply. The updater creates a database
  snapshot but does not change the schema.

## [3.0.2] — 2026-11-03

### Added

- **Optional theme styles in the content editor**:
  - a theme can ship `themes/<theme>/assets/css/editor.css`, detected solely by
    the existence of that file,
  - styles are applied to the TinyMCE canvas (`content_css`, `content_style`,
    optional `body_class`) and to the inline Editor.js canvas (CSS scoped to
    `#editorjs` and `#snippet-editorjs`),
  - relative `url(...)` references and `@import` rules are rewritten to
    absolute theme URLs, while `@import` stays the first rule in the `<style>`
    block,
  - Customizer variables are appended last, so they override the defaults from
    `editor.css`,
  - an optional `editor` block in `theme.json` supports `body_class` and
    `content_style`,
  - no file means no editor changes; there is no fallback to `style.css`, no new
    setting in Settings and no frontend change.
- New `Core\ThemeEditorStyles` class and new
  `Core\Customizer::cssVariables()` / `Core\CssFramework::scopeCss()` methods.
- Example `editor.css` files for the `default`, `default2` and `editorial`
  themes.
- [PL](docs/pl/styl-motywu-w-edytorze.md) /
  [EN](docs/en/theme-editor-styles.md) documentation and README links.

### Fixed

- `CssFramework::editorScopedCss()` now uses the shared `scopeCss()` parser,
  which correctly handles `@media`, `:root`/`html`/`body`, comments, and leaves
  `@keyframes` / `@font-face` untouched.

### No database changes

- No migration — updating from 3.0.1 only requires replacing the files.

## [3.0.1] — 2026-11-03

### Fixed

- **“Edit template settings” panel**:
  - settings sections are collapsed by default, while the ones expanded during
    editing stay open after a live-preview reload (state remembered per theme),
  - typing in text fields no longer reloads the page — the preview refreshes
    only once editing is committed (field blur, switch/option change, range
    release) and only when the value actually changed,
  - the draft is saved before the reload, so the preview shows the current value
    (removed the save/reload race),
  - the panel scroll position is restored after a reload,
  - **Publish** and **Reset** no longer recreate the draft after the action.
- No database schema changes — updating from 3.0.0 needs no migration, replacing
  the files is enough.

## [3.0.0] — 2026-11-02

### Added

- **Page and post types**:
  - new **Page Types** screen (`admin/page-types.php`) for defining presets
    with a name, slug, base type and a set of default custom fields,
  - presets can be created for **static pages and blog posts**; selecting a type
    in the editor automatically sets the correct base type,
  - new documents receive default fields with starter values, and existing ones
    can be completed with **Apply type defaults** (without overwriting values),
  - new theme functions: `get_page_type()`, `page_type_slug()` and `is_page_type()`,
  - type filter on the content list, quick creation via “New from type…” and
    `page_type` / `page_type_id` support in the API,
  - migrations: `pages.page_type_id`, `page_meta.meta_label` and
    `page_revisions.page_type_id`; restoring a revision keeps the document type,
  - new PL/EN/DE/ES translations and documentation
    [PL](docs/pl/typy-stron.md) / [EN](docs/en/page-types.md).

- **Media library folders**:
  - physical one-level subdirectories in `uploads/` with create, rename and
    delete-empty-folder actions,
  - upload directly to a selected folder, single and bulk file moves, and a
    breadcrumb-filtered view,
  - automatic URL reference updates in pages, posts, snippets, settings, theme,
    revisions and plugin data when files are moved or a folder is renamed,
  - folders are also visible in the media picker in the editor, customizer,
    gallery and carousel,
  - automatic synchronization of files from disk to the `media` table.

- **Media library thumbnails**:
  - the media picker, library, customizer, page list and gallery/carousel
    pickers now load lightweight thumbnails (80/160/240 px) instead of full
    images,
  - thumbnails are generated on upload, and an existing library can be completed
    with **Generate missing thumbnails** (in batches, with a progress bar),
  - static files go to `uploads/thumbs/`, so the browser downloads them without
    running PHP; the directory is hidden in the library view,
  - backend selection: GD → Imagick → ImageMagick CLI (`convert`) → original,
  - the media picker modal shows 24 tiles and a **Show more** button, so
    100–200 images do not download the whole library,
  - the media library list is paginated at 48 items,
  - lazy loading (`loading="lazy"`), `decoding="async"` and `srcset` for HiDPI
    screens,
  - automatic thumbnail cleanup when deleting, moving and renaming folders.

### Changed

- **Forms 1.2.0**:
  - e-mail notifications **enabled by default** — every form immediately sends
    an HTML submission e-mail without manual configuration,
  - new recipient fallback: form address → global address → first administrator
    account e-mail,
  - optional **Reply-To set to the sender's e-mail** (the first filled e-mail
    field), enabled by default with the global `reply_to_sender` switch and per
    form (`Inherit / Yes / No`),
  - redesigned, elegant message template: preheader, metadata (date, source
    page), full fields table, highlighted longer content, refreshed typography
    and a submission button,
  - new and updated translations (PL/EN/DE/ES).

## [2.0.0] — 2026-10-28

### Added

- **New analytics engine** (`core/Analytics.php`):
  - records every page view (GET/HEAD), skipping bots, the admin panel, the API,
    service pages and static files,
  - a daily rotating hash of the IP address (no plain-text IP storage),
  - separate **all views** and **unique visitors**,
  - traffic counting before the cache layer, so the cache cannot undercount
    results.
- **Statistics dashboard**:
  - cards with today's and period traffic,
  - bar / line / area chart,
  - palette or custom colour, chart height and 7/14/30-day period selection,
  - value, legend and grid toggles,
  - most visited pages table,
  - statistics reset with confirmation.
- **New “Statistics” tab** in settings with the `analytics_enabled` switch in
  `modoconfig.php`.
- **Forms 1.1.0**:
  - support for hCaptcha, Google reCAPTCHA, built-in protection and automatic
    mode,
  - built-in protection: honeypot, time trap and signed token,
  - safe fallback to built-in protection when provider keys are missing,
  - HTML e-mail notifications, Reply-To the person filling in the form, and a
    test e-mail,
  - inheritance of save and notification settings from the parent form,
  - automatic migrations for forms saved in older versions,
  - warnings about incomplete captcha and notification configuration.
- **Customizer**: media-library image selection, thumbnail previews and upload
  directly from the panel.
- **Translations**: new strings in Polish, English, German and Spanish.
- The `CHANGELOG.md` file and the GitHub release notes.

### Changed

- `index.php` delegates traffic tracking to `Core\Analytics` instead of
  duplicating the logic in the router.
- `PageCache` no longer caches pages containing forms (CSRF tokens and the time
  trap are session-bound).
- The plugins screen reads metadata from disabled plugins too.
- Expanded settings screen, CSS fixes and admin dashboard chart improvements.
- `robots.txt` and `sitemap.xml` are generated by the CMS and are no longer
  included in the release package.

### Fixed

- Statistics no longer lose views served from the full page cache.
- Forms no longer lose token validity after a page is cached.
- Old statistics records with only a date are normalised once to a full date and
  time.
- Restored the hardened `data/.htaccess` and `data/backups/.htaccess` files
  (`Require all denied`, `Options -Indexes -ExecCGI`).
- Saving a page or post no longer fails when the slug is already taken: the
  system automatically appends `-1`, `-2`, etc. within the same language
  (applies to the editor, quick draft, API and revision restore).

### Security

- Forms: signed tokens, honeypot, time trap and a safe captcha fallback.
- The `data/` and `data/backups/` directories block HTTP access and CGI
  execution again.
- Removed local `localhost` addresses from `robots.txt` and `sitemap.xml` in the
  release package.

### Packaging

- The release archive built with `git archive` contains only the application:
  no database, uploads, `site/` directory, CI configuration or development
  files.
- The ZIP is published automatically by the `Release` workflow after pushing a
  `v*.*.*` tag.

## [1.0.0] — 2026-10-18

- First public release of Modo CMS: a lightweight CMS on PHP 8 and SQLite3,
  TinyMCE/Editor.js editors, themes, plugins, custom fields, categories, mail,
  backups and the installer.
