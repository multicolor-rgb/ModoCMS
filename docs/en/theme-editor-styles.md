# Theme styles in the content editor

Modo CMS lets a theme provide typography for the content editor canvas only — without affecting the frontend and without a new setting in the admin panel.

## How it works

The feature is **optional** and activates only when this file exists:

```text
themes/<theme>/assets/css/editor.css
```

- No file means no change: TinyMCE behaves exactly as before.
- There is no fallback to `style.css` or any other theme stylesheet.
- There is no new setting in **Settings**.
- Styles are injected into the editor canvas only, never into the frontend.

The active theme is read from `Router::getOption('active_theme', 'default')`, and the path is sanitized with `basename()`.

## TinyMCE

When `editor.css` exists, the editor receives:

- `content_css` — the theme stylesheet URL with a `?v=<filemtime>` cache buster,
- `content_style` — Customizer CSS variables in a `:root { ... }` block plus optional `editor.content_style`,
- `body_class` — an optional class from `theme.json` (empty by default).

The `content_css` order is: TinyMCE skin → grid CSS → theme CSS. This lets theme typography override grid settings.

The content canvas always starts from TinyMCE's light `default` content stylesheet, regardless of the admin panel's dark mode. Dark mode only changes the editor UI chrome (`oxide-dark` skin: toolbar and dialogs), so content is previewed on a white background like on the frontend. A theme that intentionally wants a different canvas background can still override it in `editor.css`.

## Editor.js (removed)

The optional Editor.js block editor was removed. Legacy documents are converted to HTML automatically; `editor.css` is now used exclusively by TinyMCE. Any `editor.css` rules that were written for the old inline canvas still apply, because TinyMCE loads the same file inside its iframe.

## Optional `editor` block in `theme.json`

A theme may add editor metadata:

```json
{
    "editor": {
        "body_class": "prose",
        "content_style": ":root { --editor-max-width: 72ch; }"
    }
}
```

- `body_class` — a class added to the TinyMCE canvas `body`. Set it only when the theme actually scopes typography under that class. It is empty by default; Modo CMS does **not** force the `prose` class.
- `content_style` — extra CSS for TinyMCE, appended after the Customizer variables.

Both fields are ignored when `editor.css` does not exist.

## Example `editor.css`

```css
body {
    margin: 0;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    font-size: var(--modo-base-font-size, 16px);
    line-height: 1.65;
    color: #212529;
}

h1, h2, h3, h4, h5, h6 {
    margin: 0 0 .75em;
    font-weight: 700;
    line-height: 1.25;
}

a {
    color: var(--modo-primary, #0d6efd);
}

blockquote {
    margin: 1.5rem 0;
    padding: .35rem 1rem;
    border-left: 4px solid var(--modo-primary, #0d6efd);
}

img {
    max-width: 100%;
    height: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th, td {
    padding: .5rem .75rem;
    border: 1px solid #dee2e6;
}

.lead {
    font-size: 1.25rem;
    font-weight: 300;
}

.hl {
    padding: .1em .25em;
    background: #fff3cd;
    border-radius: .2em;
}
```

If the theme ships `themes/<theme>/assets/fonts/fonts.css`, include it at the top of the file:

```css
@import url("../fonts/fonts.css");
```

The path is relative to `editor.css` and is resolved against the theme directory; TinyMCE loads it inside its own iframe.

## Adding support to another theme

1. Create `themes/<theme>/assets/css/` if it does not exist.
2. Add an `editor.css` file with content typography.
3. Do not copy header, footer, hero or section layout into it — content styles only.
4. Optionally add the `"editor"` block in `theme.json`.
5. Test the editor with the theme selected in **Settings → Content**.

## Changed files

- `core/ThemeEditorStyles.php` — `Core\ThemeEditorStyles` class,
- `core/Customizer.php` — `cssVariables()` method,
- `admin/page-edit.php` — TinyMCE payload and configuration,
- `admin/snippets.php` — snippet editor payload and configuration,
- `themes/default/assets/css/editor.css`,
- `themes/default2/assets/css/editor.css`,
- `themes/editorial/assets/css/editor.css`,
- PL/EN documentation and README links.

## Manual test

1. **Without `editor.css`** — open a page and a snippet editor; the editor must look unchanged.
2. **With `editor.css`** — TinyMCE typography should match the file.
3. **`@import`** — the imported font stylesheet must load inside the TinyMCE iframe.
4. **Customizer** — changing a colour or font size in the Customizer must override the default from `editor.css`.
5. **Frontend** — the public site must not change.

## Troubleshooting

- Styles do not appear — verify the exact `themes/<theme>/assets/css/editor.css` path and that the file is readable.
- `body_class` has no effect — check that `theme.json` is valid JSON and that `editor.css` exists.
- Broken font/image paths — use paths relative to `editor.css`; Modo CMS rewrites them to theme URLs.
