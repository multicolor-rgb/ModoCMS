# Tworzenie motywów

[English](../en/themes.md) | **Polski**

Motyw Modo CMS to katalog w `themes/` zawierający szablony PHP, manifest
`theme.json` oraz opcjonalne pliki CSS/JS. Motywy to zwykły PHP — nie ma kroku
budowania ani własnego języka szablonów.

---

## 1. Struktura katalogów

```text
themes/moj-motyw/
├── theme.json          # metadane + schemat customizera (zalecane)
├── header.php          # <head>, nagłówek, nawigacja
├── footer.php          # stopka, skrypty, </body>
├── page.php            # strona statyczna
├── single.php          # pojedynczy wpis (fallback: page.php)
├── archive.php         # lista wpisów/tagów/kategorii (fallback: index.php/page.php)
├── front-page.php      # opcjonalna strona główna
├── 404.php             # opcjonalna strona błędu
├── search.php          # opcjonalna wyszukiwarka (fallback: archive.php)
├── maintenance.php     # opcjonalna strona 503
├── editor.css          # opcjonalna typografia TinyMCE
└── assets/
    ├── css/style.css
    └── js/theme.js
```

W praktyce wymagane są `header.php` i `footer.php` — pozostałe szablony mają
bezpieczne fallbacki (`page.php` → `index.php`/`404.php`). Warto dostarczyć
wszystkie typowe pliki.

### Wybór szablonu

| Żądanie | Szablon |
|---|---|
| Strona statyczna | `page.php` |
| Wpis blogowy | `single.php`, potem `page.php` |
| Strona główna / skonfigurowana strona | `front-page.php`, potem `page.php` |
| Archiwum bloga, tag, kategoria | `archive.php`, potem `index.php`, potem `page.php` |
| Wyszukiwanie (`/search?q=...`) | `search.php`, potem `archive.php` |
| Nie znaleziono | `404.php` |
| Tryb konserwacji | `maintenance.php`, potem strona wbudowana |

---

## 2. `theme.json`

```json
{
  "name": "Mój motyw",
  "slug": "moj-motyw",
  "version": "1.0.0",
  "author": "Twoje imię",
  "description": "Krótki opis widoczny na liście motywów.",
  "files": [
    "header.php",
    "footer.php",
    "page.php",
    "single.php",
    "archive.php",
    "front-page.php",
    "404.php",
    "assets/css/style.css"
  ],
  "editor": {
    "body_class": "editor-content"
  },
  "customize": {
    "sections": [
      {
        "id": "branding",
        "title": "Branding",
        "controls": [
          {
            "id": "accent",
            "type": "color",
            "label": "Kolor akcentu",
            "default": "#2563eb",
            "css_var": "my-accent"
          }
        ]
      }
    ]
  }
}
```

- `name`, `slug`, `version`, `author`, `description` są widoczne w **Wygląd →
  Motywy**.
- `slug` powinien być taki sam jak nazwa katalogu.
- `files` jest informacyjne — może być używane przez narzędzia i dokumentację.
- `editor` konfiguruje kanwę TinyMCE. Zobacz
  [Styl motywu w edytorze](styl-motywu-w-edytorze.md).
- `customize` definiuje panel Theme Customizera. Pełna lista typów pól,
  `tinymce`, `pagelink`, `menu`, `date` i helperów:
  [Ustawienia theme customizera](customizer.md).

Manifest musi być poprawnym JSON-em. Niepoprawny plik jest ignorowany — motyw
nadal działa, ale jego kontrolki i metadane nie będą dostępne.

---

## 3. Nagłówek i stopka

`header.php` wypisuje `<head>` i otwiera layout. Wywołaj `theme_head()` — to
ona wypisuje tytuł, meta description, canonical, Open Graph, favicon, hreflang,
zmienne CSS customizera, CSS gridu, skrypty head i hooki `theme_head`.

```php
<?php
if (!function_exists('site_title')) { die('Modo CMS theme'); }
$themeUrl = htmlspecialchars(get_theme_url(), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\Core\I18n::getLocale(), ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php theme_head(); ?>
    <link rel="stylesheet" href="<?= $themeUrl ?>/assets/css/style.css">
</head>
<body class="moj-motyw">
    <header class="site-header">
        <a href="<?= htmlspecialchars(site_url('', false), ENT_QUOTES, 'UTF-8') ?>">
            <?= site_logo(false) ?>
        </a>
        <?php get_theme_menu('nav-list'); ?>
        <?php lang_switch('lang-switcher'); ?>
    </header>
    <main id="main-content">
```

`footer.php` zamyka layout i wywołuje `theme_footer()`:

```php
    </main>
    <footer class="site-footer">
        <p>&copy; <?= date('Y') ?> <?= site_title(false) ?></p>
    </footer>
    <?php theme_footer(); ?>
    <script src="<?= $themeUrl ?>/assets/js/theme.js"></script>
</body>
</html>
```

`theme_head()` i `theme_footer()` są wymagane, aby działały pluginy, analityka,
CSS customizera i pasek administratora.

---

## 4. Strony i wpisy

Szablon strony statycznej:

```php
<?php require __DIR__ . '/header.php'; ?>

<article class="page">
    <h1><?php page_title(); ?></h1>
    <?php if (has_image()): ?>
        <img src="<?php page_image(); ?>" alt="<?php page_title(); ?>">
    <?php endif; ?>
    <?php page_content(); ?>
</article>

<?php require __DIR__ . '/footer.php'; ?>
```

Pojedynczy wpis może użyć tych samych funkcji oraz daty, autora, kategorii i tagów:

```php
<h1><?php page_title(); ?></h1>
<p><?php page_date(); ?> · <?php page_author(); ?></p>
<?php page_content(); ?>
<?php post_categories(); ?>
<?php post_tags(); ?>
```

Pętla wpisów:

```php
<?php if (have_posts()): ?>
    <?php while (have_posts()): the_post(); ?>
        <article>
            <h2><a href="<?php post_url(); ?>"><?php post_title(); ?></a></h2>
            <p><?php post_excerpt(140); ?></p>
        </article>
    <?php endwhile; ?>
<?php else: ?>
    <p><?= _e('Nie znaleziono wpisów.') ?></p>
<?php endif; ?>
```

Wewnątrz pętli działają bez argumentów: `post_title()`, `post_url()`,
`post_excerpt()`, `post_image()`, `post_thumbnail_image()`, `page_date()`,
`page_author()`, `post_tags()`, `post_categories()`, `get_meta()`.

Paginacja archiwum otrzymuje `$currentPage` i `$totalPages` z routera.

---

## 5. Nawigacja, języki i adresy

```php
<?php get_theme_menu('main-nav'); ?>          // menu z Ustawień/Nawigacji
<?php menu('footer-menu', 'footer-links'); ?> // konkretny slug menu
<?php lang_switch('lang-switcher'); ?>
```

- `site_url('o-nas', false)` zwraca adres względny instalacji (działa też w
  podkatalogu).
- `get_theme_url()` zwraca adres aktywnego motywu.
- `page_url($page, false)` / `post_url()` zwracają adres dokumentu.
- `resolve_media_url($value)` zamienia ścieżkę mediów na publiczny URL.

Wartości w atrybutach i tekstach escapuj przez `htmlspecialchars()`, chyba że
helper robi to sam.

---

## 6. Wartości customizera

Odczyt ustawień opublikowanych w Theme Customizerze:

```php
<?php $title = get_theme_mod('hero_title', 'Witaj'); ?>
<h1><?php the_theme_mod('hero_title', 'Witaj'); ?></h1>

<a href="<?php the_theme_mod_link('hero_cta', '/'); ?>">Czytaj więcej</a>
<div class="hero-text"><?php the_theme_mod_html('hero_text'); ?></div>
```

- `the_theme_mod()` escapuje zwykły tekst.
- `the_theme_mod_link()` rozwiązuje linki wewnętrzne i escapuje URL.
- `the_theme_mod_html()` wypisuje HTML administratora (`tinymce`).

Zmienne CSS zadeklarowane przez `css_var` w `theme.json` są wypisywane przez
`theme_head()` automatycznie.

---

## 7. Style edytora

Utwórz `editor.css` i wskaż go w `theme.json`:

```json
"editor": {
  "body_class": "editor-content"
}
```

Umieść `editor.css` w katalogu głównym motywu — Modo CMS wykryje go
automatycznie i załaduje wyłącznie w kanwie TinyMCE, więc nagłówki, listy,
tabele i linki w edytorze będą wyglądać jak na stronie. Zobacz
[Styl motywu w edytorze](styl-motywu-w-edytorze.md).

---

## 8. Hooki i pluginy

Motywy mogą korzystać z tego samego systemu hooków co pluginy:

```php
\Core\Hooks::addAction('theme-footer', function (): void {
    echo '<script src="/assets/js/extra.js"></script>';
});

\Core\Hooks::addFilter('the_content', function (string $html): string {
    return $html;
});
```

Do zasobów w `<head>` używaj `theme_head` / `theme-header`, a do skryptów
`theme_footer` / `theme-footer`. Zobacz [Hooki](hooki.md).

---

## 9. Bezpieczeństwo i checklista jakości

- Escapuj całe wyjście; używaj helperów `the_*`, jeśli są dostępne.
- Nie używaj `eval`, `extract()` na danych żądania ani surowego SQL.
- Nie hardkoduj `RewriteBase` ani pełnej domeny.
- Przetestuj instalację w katalogu głównym **i** w podkatalogu.
- Przetestuj z włączonym i wyłączonym trybem wielojęzycznym.
- Sprawdź stronę główną, stronę z rodzicem, wpis, tag, kategorię, wyszukiwanie
  i 404.
- Sprawdź konsolę przeglądarki i log błędów PHP.
- Zadbaj o responsywność i dostępność klawiaturową; zostaw widoczny focus i
  skip link.
- Nie ładuj zewnętrznych CDN, jeśli strona ma działać offline; dołącz zasoby do
  motywu.

---

## 10. Pakowanie motywu

1. Użyj małej nazwy katalogu z myślnikami, np. `moj-motyw/`.
2. Ustaw `slug` w `theme.json` identycznie jak nazwa katalogu.
3. Usuń pliki deweloperskie (`node_modules`, `.git`, niepotrzebne zrzuty).
4. Spakuj katalog tak, aby `moj-motyw/theme.json` był w katalogu głównym ZIP-a.
5. Zainstaluj, rozpakowując do `themes/` i wybierając motyw w **Wygląd →
   Motywy**.

Przed publikacją włącz `debug_log` i sprawdź `data/logs/app.log` pod kątem
ostrzeżeń PHP z szablonów.
