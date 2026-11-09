# Using Modo CMS

[Polski](../pl/obsluga.md) | **English**

This is the day-to-day administrator guide: signing in, publishing content,
managing media, themes, users, backups and updates.

---

## 1. Signing in

Open `/admin/` (or `/subfolder/admin/`). Use the username or e-mail address and
the password chosen during installation.

- **Forgot password?** — enter your e-mail on the sign-in page. A one-hour reset
  link is sent. Resetting the password signs out other sessions and revokes the
  API token.
- **Two-factor authentication** — if enabled for your account, enter the
  6-digit code from your authenticator app after the password.
- **Locked out** — five failed attempts from one IP within 15 minutes trigger a
  temporary block. Wait for the window to pass or use a different network.

Your profile (**top-right avatar → My Profile**) lets you change the e-mail,
admin language, admin theme (`Auto`, `Light`, `Dark`), password, 2FA and API
token.

---

## 2. Dashboard

The dashboard shows:

- a **Site Health** score with actionable checks,
- page/post/user counts,
- traffic for today and the selected period,
- the most visited pages,
- recently updated documents,
- a Quick Draft form,
- PHP/database/theme information.

Quick Draft creates an unpublished blog draft. Edit it later in **Content**.

---

## 3. Content

**Content → All content** lists pages and posts. Use the type/language/page-type
filters to narrow the list. The bulk bar can delete entries or change status,
author and language.

### Pages and posts

| Field | Meaning |
|---|---|
| Title | Visible heading and default source for the slug. |
| Slug | URL segment. Changing it creates an automatic 301 redirect. |
| Content | TinyMCE content, media, grids and snippets. |
| Status | Published or draft. |
| Publish date | Optional future date — the entry stays hidden until then. |
| Page type | Reusable preset with default custom fields. |
| Parent page | Builds hierarchical URLs such as `/about/team`. |
| Featured image | Used by themes and social cards. |
| Excerpt / meta | Search/OG description and tags/categories. |
| Custom fields | Extra values read with `get_meta()` in the theme. |

### Page tree

Pages that have a parent are shown in **Content → All content** as an
expandable/collapsible tree. Use the chevron next to a title to show or hide its
subpages; **Expand all** and **Collapse all** are available above the table, and
the expansion state is remembered in the browser. Filters always keep matching
rows visible: if a parent is filtered out, its child is shown at the top level.
The trash view remains a flat list.

### Manual content ordering

In **Settings → Content**, enable **Enable manual ordering for posts and pages**.
Then drag posts in **Content → Posts** and pages in **Content → Pages**; pages
can be reordered only within the same parent. The ↑/↓ buttons next to the drag
handle are a convenient alternative on touch devices. New documents are appended
to the end of their group.

The order is used by the post loop, tag and category archives,
`get_recent_posts()`, `get_pages()` and the fallback page menu. Disabling the
option restores date order for posts and id order for pages without deleting the
saved positions.

### Categories

Blog categories support multiple nesting levels. In **Content → Categories**
choose a **Parent Category** to nest a category; leave it empty for a top-level
entry. The list is indented to show the hierarchy, and the post editor shows the
same indented checkbox list.

Deleting a category moves its subcategories up to the deleted category's parent.
Posts assigned to the deleted category are detached, but the category tree is
not destroyed. Category archives list posts assigned directly to that category;
the hierarchy itself is available to themes through `get_categories()`,
`get_category_tree()` and `get_category_children()`.

### Revisions and autosave

The editor keeps a revision history. Open **Revision History** in the editor to
compare, restore or delete versions. Autosave stores a draft revision at the
configured interval.

### Scheduling

Set **Publish Date** to a future date and keep the status **Published**. The
entry is excluded from the frontend, sitemap, archives and search until that
date. The page cache is refreshed when the entry is saved.

### Trash and preview

Deleting an entry moves it to **Trash**; restore it or delete it permanently
from the Trash view. Use the **Preview** button in the editor to create a signed
link for a client — it works without an account and shows the saved version.

### RSS

The site exposes `/feed.xml` (and `/pl/feed.xml` in multilingual mode). The
matching `<link rel="alternate">` is added automatically by `theme_head()`.

---

## 4. Editor

The bundled TinyMCE editor supports:

- headings, bold/italic, lists, quotes, links and tables,
- images from the Media Library or direct upload,
- the grid builder (Bootstrap, Bulma, Pico, UIkit, Foundation, Tailwind,
  Modo Grid or custom CSS),
- snippets via the `[snippet:name]` shortcode,
- code view and fullscreen.

Use the **Media** button for images. Uploaded images receive generated
thumbnails and, when enabled, a WebP copy.

---

## 5. Media library

**Media** supports folders, drag-and-drop upload, search/filter, ALT text and
captions, bulk editing and image transforms (rotate, flip, resize, quality).

- Use ALT text for accessibility and SEO.
- **Force regenerate thumbnails** in Settings → Performance rebuilds previews,
  gallery sizes and WebP copies.
- The library stores files in `uploads/`; the database keeps metadata.
- Do not delete files directly from disk — remove them from the library.

---

## 6. Menus

**Navigation** manages one or more menus. Each menu has a name, slug and a tree
of links. A link can point to a page, post, category/tag archive, custom path or
external URL. Set the header menu in **Settings → General → Header Menu**.

Themes render menus with `get_theme_menu()` or `menu('slug')`.

---

## 7. Appearance

### Themes

**Appearance → Themes** lists folders in `themes/`. Selecting a theme changes
the public site immediately. The customizer always edits the active theme.

### Theme Customizer

Open **Appearance → Customize** or the frontend **Edit template settings**
sidebar. You can:

- edit theme/plugin fields and fields created in the builder,
- use `tinymce` rich text, internal links (`pagelink`), menus and dates,
- preview changes live, publish or reset,
- create reusable sections/fields in **Manage settings** (stored in the
  database).

Full reference: [Theme Customizer settings](customizer.md).

### Theme editor

**Appearance → Theme Editor** edits PHP/CSS/JS/JSON/HTML files inside the
active theme. Only edit files you understand; a PHP error can take the site
down. Keep a backup before large changes.

---

## 8. Settings

| Tab | What it controls |
|---|---|
| General | Site title, description, canonical URL, homepage, theme, languages, branding, header menu, customizer defaults. |
| Content | Editor toolbar, grid framework, revisions, autosave. |
| SEO & Social | Title format, hreflang, Open Graph defaults, custom head/footer scripts. |
| Security | Brute-force limits, security headers/CSP, 2FA, session timeout, API token lifetime, trusted hosts. |
| Performance | Full-page cache and TTL, retention for statistics/audit logs, media/WebP/thumbnail settings. |
| Email | `mail()` or SMTP, sender details, test message. |
| Statistics | Counting mode, chart type/colour/period, reset. |
| Tools | Purge cache, redirects, activity log, domain migration. |
| System | Requirements, permissions, `.htaccess`/nginx rules, updates, config file. |

Settings are saved per section; the selected tab is kept in the URL and in the
browser.

---

## 9. Users and roles

| Role | Can |
|---|---|
| Administrator | Everything: settings, plugins, users, content, media, themes. |
| Editor | Manage all content, publish/delete, media, taxonomies, revisions. |
| Author | Create and edit own content, use media, view own revisions; cannot publish. |

**Users → Create New User** adds an account. You cannot delete your own account.
Use strong passwords (minimum 8 characters) and enable 2FA for administrators.

---

## 10. Backups and updates

- **Backups** creates a database snapshot or a full package (database +
  uploads) in `data/backups/`. Download them regularly and store them off the
  server.
- **System → Updates** checks GitHub releases, verifies the package checksum,
  backs up replaced files and rolls back on failure. Content, uploads,
  `modoconfig.php` and custom themes/plugins are not replaced.
- Keep `install.php` removed or locked after installation.

---

## 11. API access

Generate a token in **My Profile → REST API Access**. The raw token is shown
once; only its SHA-256 hash is stored. Use it with:

```http
Authorization: Bearer cms_...
```

See the [Content API guide](api.md) for endpoints, permissions, examples and
error codes.

---

## 12. Maintenance and troubleshooting

- **Maintenance mode** is configured in `modoconfig.php`; allow-listed IPs and
  signed-in administrators can still browse.
- **Blank page** — enable `debug` or `debug_log` in `modoconfig.php`, then check
  `data/logs/app.log`.
- **Changes not visible** — purge the page cache in **Settings → Tools**.
- **Uploads fail** — check `uploads/` permissions and PHP upload limits.
- **Broken links after moving the site** — use **Settings → Tools → Domain
  Migration**.
- **2FA lost** — an administrator can disable it for the account in the
  database (`totp_enabled = 0`, `totp_secret = NULL`) or restore a backup.
- **Locked out** — wait for the brute-force window or clear the
  `login_attempts` table.
- **Theme error** — switch to another theme in the database
  (`active_theme` setting) or restore the theme folder from a backup.

For server-level issues see [Installation](installation.md).

See [Automation and maintenance](automation.md) for Site Health, Trash, preview
links, RSS and cron.
