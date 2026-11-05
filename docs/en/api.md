# Content API

The API is available at `/api/content.php` and authenticates with a personal
bearer token:

```http
Authorization: Bearer cms_...
```

Generate or regenerate a token in **My Profile → REST API Access**. The raw token
is shown only once; the database stores a SHA-256 hash. Tokens can optionally
expire (Settings → Security → *API token lifetime*).

## Capabilities

The token inherits the role of the user who created it:

| Action | Required capability |
|---|---|
| List / read | `manage_pages` |
| Create | `create_pages` |
| Publish | `publish_pages` |
| Update own entries | `edit_own_pages` |
| Update any entry | `edit_others_pages` |
| Delete | `delete_pages` |

Authors can only read/write their own entries and cannot publish.

## Endpoints

### List entries

```http
GET /api/content.php?type=post&status=published&lang=en&per_page=20&page=1&q=hello
```

Supports `type`, `status`, `lang`, `page_type`, `q`, `page`, `per_page`.

### Read one entry

```http
GET /api/content.php?id=42
```

### Create

```http
POST /api/content.php
Content-Type: application/json

{
  "title": "Hello",
  "content": "<p>Body</p>",
  "type": "post",
  "status": "draft",
  "lang": "en",
  "page_type": "article",
  "published_at": "2026-12-01 09:00:00",
  "meta": {
    "subtitle": "A short lead",
    "price": { "type": "number", "value": "19.99" }
  }
}
```

### Update

`PUT` or `PATCH` with the entry `id` in the body (or `?id=`). Only the fields
present in the request are changed; `meta` keys are upserted.

### Delete

```http
DELETE /api/content.php?id=42
```

## Responses

Successful responses use `{"status":"success","data":...}`. Errors use
`{"status":"error","message":"..."}` with `400`, `401`, `403`, `404`, `405`,
`422`, `429` or `500`.

Rate limit: 120 requests per minute per token. Cache and sitemap are refreshed
after every successful write.
