# Automation, previews and maintenance

[Polski](../pl/automatyzacja.md) | **English**

Modo CMS includes a few small tools that keep a site healthy without constant
manual work: Site Health, Trash, signed preview links, RSS and scheduled tasks.

---

## 1. Site Health

The dashboard starts with a **Site Health** card. It scores the installation and
lists actionable problems:

- installer still present,
- `data/` or `uploads/` not writable,
- legacy default administrator password,
- debug mode enabled,
- security headers or page cache disabled,
- no backups,
- cron not running,
- old PHP version,
- HTTP instead of HTTPS.

Each issue links to the screen where it can be fixed. A score of 100% means every
check passed.

---

## 2. Trash and restore

Deleting a document from **Content** moves it to **Trash** instead of removing
it immediately. Trash is a pill next to All / Posts / Pages.

- **Restore** puts the document back with its old status and slug.
- **Delete permanently** removes the document and its tags/categories/custom
  fields.
- The scheduler permanently removes trashed documents after
  **Settings → Performance → Keep trash (days)** (default 30 days).

Deleted documents are excluded from the site, menus, search, sitemap, feed and
the API by default. The API can inspect the trash with `?trash=1`.

---

## 3. Preview links for drafts

Open a saved document in the editor and click **Preview**. The generated link is
signed and works without an admin session:

```text
https://example.com/preview?token=...
```

- Links are signed with `data/preview.key` (created automatically).
- The default lifetime is 24 hours.
- Preview pages are `noindex`, private and never cached.
- The link shows the **saved** version, not unsaved editor changes.

Send the link to a client for approval, then publish when ready.

---

## 4. RSS feed

Every site exposes an RSS 2.0 feed:

- `/feed.xml` — current language,
- `/pl/feed.xml` — a specific language when multilingual mode is enabled.

The feed contains published posts only, respects scheduled publishing and
includes `content:encoded`. `theme_head()` adds the matching
`<link rel="alternate" type="application/rss+xml">` automatically.

---

## 5. Scheduled tasks (cron)

Cron keeps the site tidy and refreshes time-dependent data:

- publishes scheduled content by clearing the page cache when a future date is
  reached,
- deletes expired trash,
- prunes login attempts, visits, rate limits and the audit log,
- regenerates `sitemap.xml` and `robots.txt`,
- records the last run.

### Command line (recommended)

```bash
php /var/www/html/tools/cron.php
```

Example crontab (every 15 minutes):

```cron
0,15,30,45 * * * * php /var/www/html/tools/cron.php >> /var/log/modocms-cron.log 2>&1
```

### HTTP fallback (shared hosting)

Use the token stored in `data/cron.key`:

```text
https://example.com/cron.php?token=...
```

You can see the exact URL and token in **Settings → Tools → Scheduled tasks**.
The same card has a **Run now** button and shows the last run.

> Keep `data/cron.key` secret. Regenerate it by deleting the file; the next
> request creates a new token.

---

## 6. Recommended schedule

| Task | Frequency |
|---|---|
| Cron | every 15 minutes |
| Database backup | daily or weekly |
| Update check | weekly |
| Trash cleanup | automatic via cron |

For backups, use **Backups** or a server-side `sqlite3 .backup`/`VACUUM INTO`
job and copy the result off the server.
