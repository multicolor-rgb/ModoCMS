# Page types

Page types are reusable content presets: a name, a base type (`Static Page` or `Blog Post`) and a set of default custom fields. They let editors create a “Product page”, “Service page” or “Podcast episode” without manually rebuilding the same field set every time.

Page types are managed in **Admin → Page Types** (`admin/page-types.php`).

## Creating a page type

1. Open **Page Types** and click **New Page Type**.
2. Enter a name and, optionally, a slug. The slug is generated from the name when left empty and is used by themes and the API.
3. Choose the **Base type**:
   - **Static Page** — the document keeps the page hierarchy, parent selection and page URL.
   - **Blog Post** — the document keeps the blog URL, tags and categories.
4. Add default custom fields. Each field has:
   - **Field key** — used in the theme, e.g. `get_meta('price')`.
   - **Label** — a human-readable name shown in the editor.
   - **Type** — text, textarea, rich text, gallery, image, checkbox, number, HTML, PHP, JavaScript, CSS, JSON or code.
   - **Default value** — pre-filled for new documents.
   - **Required** — informational only; it does not block saving.
5. Save the page type.

For gallery fields the default value may be a JSON object:

```json
{"items":[],"options":{"columns":"3","gap":"14","ratio":"1","lightbox":"1","captions":"1"}}
```

## Creating content from a page type

Use **New from type…** on the Content screen, the Dashboard, or open:

```text
/admin/page-edit.php?type=page&page_type=product-page
/admin/page-edit.php?type=post&page_type=podcast
```

The preset's fields are added automatically with their default values. If the type is selected later while editing an existing document, use **Apply type defaults**. Only missing fields are added; existing fields and values are never overwritten.

The **Page Type** selector in the editor is filtered by the current base type, so post presets are only available for posts and page presets only for pages.

## Reading page types in a theme

```php
get_page_type(?int $pageId = null): ?array
page_type_slug(?int $pageId = null): string
is_page_type(string $slug, ?int $pageId = null): bool
```

Example:

```php
<?php if (is_page_type('product-page')): ?>
    <div class="price"><?= get_meta('price') ?></div>
    <p><?= get_meta('short_description') ?></p>
    <?php the_meta_gallery('gallery'); ?>
<?php endif; ?>
```

`get_page_type()` returns the type row (including its `fields` definitions) or `null` for classic pages/posts.

## Deleting a page type

Deleting a type does **not** delete documents. Their custom fields and content stay intact; only the `page_type_id` reference is cleared. Themes should therefore check `is_page_type()` or `get_page_type()` and provide a sensible fallback.

## API

`api/content.php` accepts `page_type` (slug) or `page_type_id`:

```json
{
  "title": "New product",
  "type": "page",
  "page_type": "product-page"
}
```

The response includes `page_type_id` and `page_type`.
