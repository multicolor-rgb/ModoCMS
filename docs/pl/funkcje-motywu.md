# Funkcje motywu — opis dla laika

**Polski** | [English](../en/theme-functions.md)

Każdy motyw i plugin w Modo CMS korzysta z zestawu **globalnych funkcji pomocniczych**. Ta strona wyjaśnia każdą z nich po ludzku: *co robi*, *co jej podajesz*, *co zwraca* i *jak jej użyć*. Nie musisz być ekspertem od PHP — większość to jednolinijkowce.

> Większość funkcji **domyślnie wypisuje wynik** (`$echo = true`) i jednocześnie **go zwraca**, więc możesz albo użyć `<?php page_title(); ?>` do wypisania, albo `$t = page_title(false);` do zapisania w zmiennej.

## Spis treści

1. [Wartości globalne strony](#1-wartosci-globalne-strony)
2. [Aktualna strona / pojedynczy wpis](#2-aktualna-strona--pojedynczy-wpis)
3. [Pętla wpisów (archiwum)](#3-petla-wpisow-archiwum)
4. [Tagi i kategorie](#4-tagi-i-kategorie)
5. [Menu](#5-menu)
6. [Przełącznik języka i SEO](#6-przelacznik-jezyka-i-seo)
7. [Head, footer i szkielet strony](#7-head-footer-i-szkielet-strony)
8. [Media i URL-e](#8-media-i-url-e)
9. [Helpery siatek CSS](#9-helpery-siatek-css)
10. [Helpery customizera](#10-helpery-customizera)
11. [Pola niestandardowe (meta)](#11-pola-niestandardowe-meta)
12. [Snippety (bloki wielokrotnego użytku)](#12-snippety-bloki-wielokrotnego-uzytku)
13. [Narzędzia treści](#13-narzedzia-tresci)
14. [Tłumaczenia](#14-tlumaczenia)

---

<a id="1-wartosci-globalne-strony"></a>

## 1. Wartości globalne strony

### `site_title(bool $echo = true): string`

Wypisuje/zwraca nazwę strony (z **Ustawienia → Ogólne**). Escapowana pod bezpieczny HTML.

```php
<h1><?php site_title(); ?></h1>
```

### `site_desc(bool $echo = true): string`

Wypisuje/zwraca opis (tagline) strony. Escapowany.

```php
<p><?php site_desc(); ?></p>
```

### `site_logo(bool $echo = true): string`

Wypisuje `<img>` logo strony, jeśli jest ustawione, w przeciwnym razie sam tytuł strony. Zwraca HTML.

```php
<?php site_logo(); ?>
```

### `site_url(string $path = '', bool $echo = true): string`

Buduje URL w Twojej witrynie, automatycznie uwzględniając podkatalog instalacji i (przy włączonym multilingualu) prefiks języka.

| Parametr | Znaczenie |
|---|---|
| `$path` | Ścieżka względna, np. `'blog'` lub `'blog/moj-wpis'` |
| `$echo` | `true` wypisuje; `false` zwraca |

```php
<a href="<?php site_url('kontakt'); ?>">Kontakt</a>
<?php $home = site_url('', false); // np. "/modocms/" ?>
```

### `get_site_title()` / `get_site_description()` / `get_site_url()`

Warianty nie-wypisujące, zachowane dla zgodności motywów. **Zwracają** wartość.

```php
$title = get_site_title();
```

---

## 2. Aktualna strona / pojedynczy wpis

### `page_title(bool $echo = true): string`

Tytuł aktualnie wyświetlanej strony. Przechodzi przez filtr `the_title`, więc pluginy mogą go zmienić.

```php
<h1><?php page_title(); ?></h1>
```

### `page_content(bool $echo = true): string`

Główna treść aktualnej strony **po** wszystkich filtrach — tu zachodzi konwersja edytora blokowego, snippetów i shortcode'ów pluginów. Wynik celowo **nie jest escapowany** (to HTML). Przechodzi przez `the_content`.

```php
<article><?php page_content(); ?></article>
```

### `page_date(string $format = 'd.m.Y', bool $echo = true): string`

Data utworzenia aktualnej strony, sformatowana jak w `date()`. Przechodzi przez filtr `page_date`.

```php
<time><?php page_date('Y-m-d'); ?></time>
```

### `page_author(bool $echo = true): string`

Nazwa autora strony (z wartością zapasową). Przechodzi przez filtr `page_author`.

### `has_image(): bool`

`true`, jeśli aktualna strona ma obrazek wyróżniający.

### `page_image(bool $echo = true): string`

URL obrazka wyróżniającego (świadomy podkatalogu). Escapowany. Przechodzi przez filtr `featured_image_url`.

```php
<?php if (has_image()): ?>
    <img src="<?php page_image(); ?>" alt="<?php page_title(); ?>">
<?php endif; ?>
```

### `page_url(?array $page = null, bool $echo = true): string`

Buduje pełny URL aktualnej strony albo dowolnego wiersza strony, który podasz. Sam obsługuje hierarchię rodzic/dziecko i permalinki bloga.

```php
<a href="<?php page_url(); ?>">Bezpośredni link</a>
```

### `resolve_page_hierarchy_path(string $slug): string`

Zamienia slug strony na pełną ścieżkę hierarchiczną (z rodzicami). Przydatne, gdy masz tylko slug, a potrzebujesz czystej ścieżki.

```php
$path = resolve_page_hierarchy_path('zespol'); // "o-nas/zespol"
```

---

<a id="3-petla-wpisow-archiwum"></a>

## 3. Pętla wpisów (archiwum)

Motyw archiwum przechodzi po wpisach tak:

```php
<?php while (have_posts()): the_post(); ?>
    <h2><a href="<?php post_url(); ?>"><?php post_title(); ?></a></h2>
    <p><?php post_excerpt(); ?></p>
<?php endwhile; ?>
```

### `have_posts(): bool`

`true`, dopóki w bieżącej pętli są kolejne wpisy.

### `the_post(): void`

Przechodzi do kolejnego wpisu w pętli i czyni go „aktualną stroną”, więc poniższe funkcje odnoszą się do niego.

### `post_title()` / `post_url()` / `post_image()`

Skróty dla wpisu w bieżącej pętli (delegują do `page_title()` / `page_url()` / `page_image()`).

### `post_excerpt(int $limit = 140, bool $echo = true): string`

Krótki wyciąg wpisu w czystym tekście. Najpierw próbuje **meta description**, potem treści (działa i dla HTML, i dla JSON edytora blokowego). Przechodzi przez `the_excerpt`. Escapowany.

```php
<p><?php post_excerpt(160); ?></p>
```

### `get_recent_posts(int $limit = 6): array`

Zwraca ostatnie opublikowane wpisy (do sidebarów/stopek).

```php
foreach (get_recent_posts(3) as $p) {
    echo '<a href="' . htmlspecialchars(page_url($p, false), ENT_QUOTES, 'UTF-8') . '">'
       . htmlspecialchars($p['title'], ENT_QUOTES, 'UTF-8') . '</a>';
}
```

---

## 4. Tagi i kategorie

Każda funkcja przyjmuje opcjonalne `$pageId`; bez niego używa aktualnej strony.

### `get_post_tags(?int $pageId = null): array`

Zwraca tablicę tagów (`id`, `name`, `slug`).

### `has_tags(?int $pageId = null): bool`

`true`, jeśli wpis ma tagi.

### `post_tags(?int $pageId = null, string $cssClass = 'post-tags', bool $echo = true): string`

Wypisuje linki do tagów (każdy z klasą `tag-badge`) w `<div class="$cssClass">`.

```php
<?php post_tags(); ?>
```

### `get_post_categories(?int $pageId = null): array`

Zwraca tablicę kategorii (`id`, `name`, `slug`, `description`).

### `has_categories(?int $pageId = null): bool`

`true`, jeśli wpis ma kategorie.

### `post_categories(?int $pageId = null, string $cssClass = 'post-categories', bool $echo = true): string`

Wypisuje linki do archiwów kategorii (każdy z klasą `category-badge`).

```php
<?php if (has_categories()): ?>
    <div class="cats"><?php post_categories(); ?></div>
<?php endif; ?>
```

---

## 5. Menu

### `menu(string $slug, string $cssClass = 'nav-menu'): void`

Renderuje menu (zbudowane w **Wygląd → Nawigacja**) jako zagnieżdżony `<ul>` z podaną klasą. `$slug` to identyfikator menu (np. `main-menu`).

```php
<nav><?php menu('main-menu'); ?></nav>
```

### `get_theme_menu(string $ulClass = 'navbar-nav ms-auto mb-2 mb-lg-0'): void`

Renderuje pasek nawigacji **Bootstrap 5** z menu wybranego w ustawieniu *Header Menu*. Jeśli menu jest puste, cofa się do automatycznej listy opublikowanych stron najwyższego poziomu.

```php
<?php get_theme_menu(); ?>
```

### `get_theme_menu_items(string $slug = 'main-menu'): array`

Zwraca linki menu jako **zagnieżdżone drzewo** (każdy element z tablicą `children`) zamiast je wypisywać — przydatne, gdy chcesz pełną kontrolę nad znacznikami.

```php
foreach (get_theme_menu_items('main-menu') as $item) {
    echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8');
}
```

> Wokół renderowania `menu()` odpalają się akcje `before_render_menu` i `after_render_menu`.

---

<a id="6-przelacznik-jezyka-i-seo"></a>

## 6. Przełącznik języka i SEO

Istotne tylko przy włączonym multilingualu frontendu.

### `lang_switch(string $cssClass = 'lang-switcher'): void`

Wypisuje przełącznik języka. Wygląd reguluje ustawienie *Language switcher style* (`inline`, `inline_full`, `flags`, `dropdown`, `select`).

```php
<?php lang_switch(); ?>
```

### `hreflang_tags(bool $echo = true): string`

Wypisuje znaczniki `<link rel="alternate" hreflang="…">` dla aktualnego dokumentu (lub stron głównych języków). Respektuje ustawienia multilingualu i hreflang.

### `modo_locale_url(array $page, string $lang, string $defaultLang, string $basePrefix): string`

Helper zaawansowany: buduje URL świadomy języka i hierarchii dla wiersza strony w wybranym języku. Używany wewnętrznie przez przełącznik i hreflang.

---

## 7. Head, footer i szkielet strony

### `theme_head(): void`

**Wywołaj to w `<head>` swojego `header.php`.** Wypisuje tytuł dokumentu, meta description, Open Graph, URL kanoniczny, favicon, hreflang, niestandardowe skrypty head, zmienne CSS customizera, a następnie odpala hooki `theme_head` i `theme-header` (gdzie pluginy wstrzykują CSS/JS).

```php
<head>
    <meta charset="utf-8">
    <?php theme_head(); ?>
</head>
```

### `theme_footer(): void`

**Wywołaj to tuż przed `</body>`.** Wypisuje niestandardowe skrypty stopki i odpala hooki `theme_footer` oraz `theme-footer` (gdzie pluginy wstrzykują JS).

```php
    <?php theme_footer(); ?>
</body>
```

### `get_header()` / `get_footer()`

Aliasy zgodności. `get_header()` odpala hook `theme-header`; `get_footer()` odpala `theme-footer`. Zapewnione, by motywy z ery GetSimple działały dalej.

### `theme_header()` / `theme_footer()` (warianty z template tags)

Helpery zgodności (definiowane tylko, gdy jeszcze nie istnieją). Wypisują niestandardowe skrypty head/footer i odpalają `theme-header` / `theme-footer`. W nowych motywach używaj `theme_head()` / `theme_footer()`.

---

## 8. Media i URL-e

### `resolve_media_url(string $path): string`

Zamienia zapisaną ścieżkę media na działający URL, zawsze z podkatalogiem instalacji (bezwzględne `http(s)://` zostawia bez zmian).

```php
<img src="<?= htmlspecialchars(resolve_media_url(get_theme_mod('hero_image')), ENT_QUOTES, 'UTF-8') ?>">
```

### `webp_url(string $url): string`

Jeśli obok pliku `.jpg`/`.png` istnieje pasujący `.webp`, zwraca URL WebP; w przeciwnym razie oryginalny URL. Świetne do szybszych obrazów.

```php
<img src="<?= htmlspecialchars(webp_url(page_image(false)), ENT_QUOTES, 'UTF-8') ?>">
```

### `get_theme_url(): string`

Publiczny URL folderu aktywnego motywu.

```php
<link rel="stylesheet" href="<?php echo get_theme_url(); ?>/assets/css/style.css">
```

---

## 9. Helpery siatek CSS

Dotyczy **siatki / frameworka CSS** wybranego w Ustawieniach.

### `active_grid(): string`

Zwraca id aktywnego frameworka (`none`, `bootstrap`, `bulma`, `pico`, `uikit`, `foundation`, `tailwind`, `modo`, `custom`).

### `grid_css_urls(): array`

Zwraca publiczne URL-e dołączonych arkuszy aktywnego frameworka (użycie zaawansowane — frontend już je wstrzykuje przez `theme_head()`).

---

## 10. Helpery customizera

### `get_theme_mod(string $key, mixed $default = ''): mixed`

Czyta wartość customizera (opublikowaną w tabeli `theme_mods` albo szkic podglądu na żywo).

```php
$footerText = get_theme_mod('modo_footer_text', 'Zbudowano na Modo CMS');
```

### `the_theme_mod(string $key, mixed $default = ''): void`

To samo, ale **wypisuje** wartość z escapowaniem HTML.

```php
<p><?php the_theme_mod('modo_footer_text'); ?></p>
```

### `customizer_css(): void`

Wypisuje zmienne CSS `:root` wygenerowane z kontrolek deklarujących `css_var`. `theme_head()` już ją wywołuje; ręcznie tylko w szczególnych przypadkach.

---

## 11. Pola niestandardowe (meta)

### `get_meta(string $key, mixed $default = '', ?int $pageId = null): mixed`

Czyta pole niestandardowe przypisane do strony. Wartość jest rzutowana wg typu pola (text/number/boolean/json). Bez `$pageId` używa aktualnej strony.

```php
$phone = get_meta('telefon', '—');
```

### `the_meta(string $key, mixed $default = ''): void`

Wypisuje wartość pola niestandardowego z escapowaniem.

```php
<span><?php the_meta('telefon'); ?></span>
```

### `get_all_meta(?int $pageId = null): array`

Zwraca **wszystkie** pola niestandardowe strony jako `klucz => wartość`.

```php
foreach (get_all_meta() as $key => $value) {
    echo '<dt>' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '</dt>';
    echo '<dd>' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</dd>';
}
```

---

<a id="12-snippety-bloki-wielokrotnego-uzytku"></a>

## 12. Snippety (bloki wielokrotnego użytku)

### `snippet_content(string $name, string $default = ''): string`

Zwraca wyrenderowaną treść snippetu (globalnego bloku wielokrotnego użytku) po nazwie. Zagnieżdżone shortcode'y `[snippet:nazwa]` rozwijane są bezpiecznie.

```php
$promo = snippet_content('promo-banner');
```

### `the_snippet(string $name): void`

Wypisuje snippet (rozwinięty, surowy HTML).

```php
<?php the_snippet('promo-banner'); ?>
```

### `render_snippets(string $content): string`

Rozwija każdy shortcode `[snippet:nazwa]` znaleziony w tekście. Jest już podpięty do `the_content` (priorytet 5); ręcznie tylko dla własnych strumieni treści.

---

<a id="13-narzedzia-tresci"></a>

## 13. Narzędzia treści

### `content_to_text(?string $content, int $limit = 0): string`

Zamienia dowolną wartość treści na **czysty tekst**, obcinając do `$limit` znaków (0 = bez limitu). Działa niezależnie od tego, czy treść napisano w TinyMCE (HTML), czy w edytorze blokowym (JSON).

```php
$plain = content_to_text(page_content(false), 120);
```

### `content_excerpt(?string $content, int $limit = 140): string`

Wygodny wrapper na `content_to_text()` dla wyciągów.

---

<a id="14-tlumaczenia"></a>

## 14. Tłumaczenia

### `__(string $key, ?string $lang = null): string`

Zwraca tłumaczenie angielskiego tekstu źródłowego w aktualnym języku panelu/frontendu.

```php
echo __('Home');
```

### `_e(string $key, ?string $lang = null): void`

To samo, ale wypisuje z escapowaniem.

```php
<h1><?php _e('Welcome'); ?></h1>
```

---

## Szybki indeks

`site_title`, `site_desc`, `site_logo`, `site_url`, `get_site_title`, `get_site_description`, `get_site_url`, `page_title`, `page_content`, `page_date`, `page_author`, `has_image`, `page_image`, `page_url`, `resolve_page_hierarchy_path`, `have_posts`, `the_post`, `post_title`, `post_url`, `post_image`, `post_excerpt`, `get_recent_posts`, `get_post_tags`, `has_tags`, `post_tags`, `get_post_categories`, `has_categories`, `post_categories`, `menu`, `get_theme_menu`, `get_theme_menu_items`, `lang_switch`, `hreflang_tags`, `modo_locale_url`, `theme_head`, `theme_footer`, `get_header`, `get_footer`, `theme_header`, `resolve_media_url`, `webp_url`, `get_theme_url`, `active_grid`, `grid_css_urls`, `get_theme_mod`, `the_theme_mod`, `customizer_css`, `get_meta`, `the_meta`, `get_all_meta`, `snippet_content`, `the_snippet`, `render_snippets`, `content_to_text`, `content_excerpt`, `__`, `_e`.

---

## Dalej

* [Hooki — referencja](hooki.md)
* [Przykłady](przyklady.md)
