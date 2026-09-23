# Nazwa Motywu — motyw dla Clean CMS

Profesjonalny, w pełni responsywny motyw oparty na Bootstrap 5.3.8 i czcionce Inter (Google Fonts).

## Instalacja

1. Skopiuj cały folder `nazwa-motywu/` do:
   `/var/www/html/cleancms/themes/nazwa-motywu/`
2. Zaloguj się do panelu admina Clean CMS.
3. Przejdź do **Settings** (`admin/settings.php`).
4. W polu **Active Theme** wybierz `nazwa-motywu` i zapisz.

## Struktura

- `header.php` — nagłówek, sticky navbar, hook `theme-header`.
- `footer.php` — stopka 3-kolumnowa, hook `theme-footer`, przycisk „Do góry”.
- `page.php` — szablon stron statycznych.
- `single.php` — szablon wpisu blogowego z sidebarem.
- `archive.php` — listing wpisów w formie kart.
- `404.php` — strona błędu.
- `assets/css/style.css` — style motywu.
- `assets/js/theme.js` — skrypty motywu.

## Wymagane funkcje CMS

Motyw korzysta z: `get_header()`, `get_footer()`, `get_site_title()`, `get_site_description()`,
`get_site_url()`, `get_theme_url()`, `get_theme_menu()`, `get_recent_posts()`, `I18n`, `Security`, `_e()`.
Każdy plik `.php` zaczyna się od `if (!defined('IN_CMS')) die();`.
