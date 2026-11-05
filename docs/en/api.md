# Content API

[Polski](../pl/api.md) | **English**

The Content API lets external tools create, read, update and delete pages and
posts. It is a small JSON API built into the CMS — no extra package is needed.

---

## 1. Authentication

Generate a token in **My Profile → REST API Access → Generate API Token**.

- The raw token (`cms_...`) is shown **once**.
- The database stores only its SHA-256 hash.
- Regenerating the token immediately invalidates the old one.
- An administrator can set an expiry in **Settings → Security → API token
  lifetime** (`0` = never).
- Tokens inherit the role of the user who created them.

Send the token on every request:

```http
Authorization: Bearer cms_...
Content-Type: application/json
```

Tokens are personal. Do not embed them in public JavaScript or commit them to a
repository.

---

## 2. Capabilities

| Action | Required capability |
|---|---|
| List / read | `manage_pages` |
| Create | `create_pages` |
| Publish (`status: published`) | `publish_pages` |
| Update own entries | `edit_own_pages` |
| Update any entry | `edit_others_pages` |
| Delete | `delete_pages` |

An **author** token can only read, create, update and delete its own drafts; it
cannot publish. An **editor** or **administrator** token can manage all entries.

---

## 3. List entries

```bash
curl -sS \
  -H "Authorization: Bearer $MODO_TOKEN" \
  "https://example.com/api/content.php?type=post&status=published&lang=en&per_page=20&page=1"
```

| Parameter | Default | Description |
|---|---|---|
| `type` | all | `page` or `post`. |
| `status` | all | `published` or `draft`. |
| `lang` | all | Language code, e.g. `en`. |
| `page_type` | all | Page type slug, e.g. `article`. |
| `q` | — | Search in title and content. |
| `page` | `1` | Page number. |
| `per_page` | `20` | 1–100 entries. |
| `trash` | — | `1` lists deleted entries (trash). |

Response:

```json
{
  "status": "success",
  "data": [
    {
      "id": 42,
      "title": "Hello",
      "slug": "hello",
      "content": "<p>Body</p>",
      "type": "post",
      "status": "published",
      "lang": "en",
      "page_type_id": null,
      "parent_id": 0,
      "translation_group": "…",
      "featured_image": "",
      "meta_title": "",
      "meta_description": "",
      "published_at": "2026-12-01 09:00:00",
      "deleted_at": null,
      "created_at": "2026-11-05 10:00:00",
      "updated_at": "2026-11-05 10:00:00",
      "author_id": 1,
      "meta": {},
      "author": "admin",
      "page_type": ""
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 20,
    "total": 1,
    "pages": 1
  }
}
```

`per_page` and `page` are clamped to safe values. Authors only see their own
entries; editors/administrators see all.

---

## 4. Read one entry

```bash
curl -sS \
  -H "Authorization: Bearer $MODO_TOKEN" \
  "https://example.com/api/content.php?id=42"
```

`meta` contains custom fields as `{ "value": ..., "type": "..." }`.

---

## 5. Create an entry

```bash
curl -sS -X POST \
  -H "Authorization: Bearer $MODO_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Hello",
    "slug": "hello",
    "content": "<p>Body</p>",
    "type": "post",
    "status": "draft",
    "lang": "en",
    "page_type": "article",
    "featured_image": "/uploads/photo.webp",
    "meta_title": "Hello",
    "meta_description": "A short summary",
    "meta": {
      "subtitle": "A short lead",
      "price": { "type": "number", "value": "19.99" },
      "gallery": { "type": "gallery", "value": { "items": [] } }
    }
  }' \
  "https://example.com/api/content.php"
```

Fields:

| Field | Required | Notes |
|---|---|---|
| `title` | yes | Non-empty. |
| `slug` | no | Generated from the title when empty; unique per language. |
| `content` | no | HTML body. |
| `type` | no | `post` (default) or `page`. |
| `status` | no | `published` (default) or `draft`. Publishing needs `publish_pages`. |
| `lang` | no | Must be an available language; defaults to the site language. |
| `page_type` / `page_type_id` | no | Preset slug or id. |
| `parent_id` | no | Parent page for hierarchical pages. |
| `translation_group` | no | Groups translations; generated when empty. |
| `featured_image` | no | Media path or URL. |
| `meta_title`, `meta_description` | no | SEO fields. |
| `published_at` | no | `YYYY-MM-DD HH:MM:SS`; future dates schedule the entry. |
| `meta` | no | Custom fields. Each value may be a scalar or `{ "type": …, "value": … }`. |

Allowed custom-field types: `text`, `textarea`, `number`, `checkbox`, `boolean`,
`color`, `image`, `gallery`, `json`, `richtext`.

A successful create returns `201` with the created entry and `data.url`.

---

## 6. Update an entry

Use `PUT` (or `PATCH`) with the `id` in the body or query string. Only the fields
present in the request are changed; omitted fields keep their current value.
`meta` keys are upserted.

```bash
curl -sS -X PATCH \
  -H "Authorization: Bearer $MODO_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "id": 42,
    "status": "published",
    "meta": { "subtitle": "Updated lead" }
  }' \
  "https://example.com/api/content.php"
```

Changing `status` from `draft` to `published` requires `publish_pages`.
Changing the slug or language creates a unique slug when needed. Authors can
only update their own entries.

---

## 7. Delete an entry

```bash
curl -sS -X DELETE \
  -H "Authorization: Bearer $MODO_TOKEN" \
  "https://example.com/api/content.php?id=42"
```

By default the entry is moved to the trash (`deleted_at`), so it disappears from
the site, search, feed, sitemap and normal API lists. Add `?force=1` to delete it
permanently, including its tags, categories and custom fields:

```bash
curl -sS -X DELETE \
  -H "Authorization: Bearer $MODO_TOKEN" \
  "https://example.com/api/content.php?id=42&force=1"
```

Use `GET /api/content.php?trash=1` to list trashed entries. Revisions are kept
for audit purposes.

---

## 8. Errors and limits

Successful responses:

```json
{ "status": "success", "data": { } }
```

Errors:

```json
{ "status": "error", "message": "Entry not found." }
```

| Status | Meaning |
|---|---|
| `400` | Malformed request. |
| `401` | Missing, invalid or expired token. |
| `403` | Token role lacks the required capability. |
| `404` | Entry not found. |
| `405` | Unsupported HTTP method. |
| `422` | Validation error (e.g. missing title, invalid date). |
| `429` | Rate limit exceeded (120 requests/minute per token). |
| `500` | Internal error; details are written to the server log. |

After every successful write the page cache is purged and `sitemap.xml` is
regenerated. If a write succeeds but the frontend still shows old content, purge
the cache in **Settings → Tools**.

The OpenAPI description is available in
[`docs/openapi.yaml`](https://github.com/multicolor-rgb/ModoCMS/blob/main/docs/openapi.yaml).

---

## 9. Quick troubleshooting

- **401** — check the `Bearer` prefix, token expiry and that the token belongs
  to an active user.
- **403** — the user role cannot publish/edit others; use an editor token or
  create the entry as a draft.
- **422** — title is required and dates must use `YYYY-MM-DD HH:MM:SS`.
- **429** — wait a minute or reduce request frequency.
- **CORS** — the endpoint sends `Access-Control-Allow-Origin: *`; custom headers
  must be allowed by your client/proxy.
