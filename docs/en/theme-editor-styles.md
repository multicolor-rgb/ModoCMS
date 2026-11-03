# Theme styles in the content editor

Modo CMS lets a theme provide typography for the content editor canvas only — without affecting the frontend and without a new setting in the admin panel.

## How it works

The feature is **optional** and activates only when this file exists:

```text
themes/<theme>/assets/css/editor.css
```

- No file means no change: TinyMCE and Editor.js behave exactly as before.
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

## Editor.js

Editor.js renders inline in the admin panel, so theme CSS is scoped to the canvas:

- `#editorjs` on the content editor screen,
- `#snippet-editorjs` on the snippets screen.

Selectors `:root`, `html` and `body` map directly to the scope, while `:root X`, `html X` and `body X` become `<scope> X`. `@media` rules are processed recursively.

Relative `url(...)` references and `@import` rules are rewritten to absolute theme URLs because the CSS is inlined into the admin page. `@import` rules are moved to the top of the `<style>` block, before the grid CSS.

Customizer variables are appended **last** to the scoped CSS so they override the defaults declared in `editor.css`.

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

The path is relative to `editor.css` and is automatically rewritten to an absolute theme URL for Editor.js.

## Adding support to another theme

1. Create `themes/<theme>/assets/css/` if it does not exist.
2. Add an `editor.css` file with content typography.
3. Do not copy header, footer, hero or section layout into it — content styles only.
4. Optionally add the `"editor"` block in `theme.json`.
5. Test the editor with the theme selected in **Settings → Content** for both TinyMCE and Editor.js.

## Changed files

- `core/ThemeEditorStyles.php` — new `Core\ThemeEditorStyles` class,
- `core/Customizer.php` — new `cssVariables()` method,
- `core/CssFramework.php` — new `scopeCss()` method,
- `admin/page-edit.php` — payload and configuration for both editors,
- `admin/snippets.php` — payload and configuration for the snippet editor,
- `themes/default/assets/css/editor.css`,
- `themes/default2/assets/css/editor.css`,
- `themes/editorial/assets/css/editor.css`,
- PL/EN documentation and README links.

## Manual test

1. **Without `editor.css`** — open a page and a snippet editor; the editor must look unchanged.
2. **With `editor.css`** — TinyMCE typography should match the file.
3. **Editor.js** — styles must apply only inside `#editorjs` / `#snippet-editorjs`, with no leak into the admin UI.
4. **`@import`** — in the page source the `@import` rule must be the first rule in the `<style>` block, before the grid CSS.
5. **Customizer** — changing a colour or font size in the Customizer must override the default from `editor.css`.
6. **Frontend** — the public site must not change.

## Troubleshooting

- Styles do not appear — verify the exact `themes/<theme>/assets/css/editor.css` path and that the file is readable.
- Styles appear only in TinyMCE — make sure the correct theme is active for Editor.js and refresh the browser cache.
- `body_class` has no effect — check that `theme.json` is valid JSON and that `editor.css` exists.
- Broken font/image paths — use paths relative to `editor.css`; Modo CMS rewrites them to theme URLs.
