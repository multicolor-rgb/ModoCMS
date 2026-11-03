# Changelog

All notable changes to Modo CMS. Format based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versioning follows
[Semantic Versioning](https://semver.org/).

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
