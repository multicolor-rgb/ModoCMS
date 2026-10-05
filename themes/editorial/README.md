# Modo Editorial (`editorial`) — motyw Modo CMS

Magazynowy, responsywny motyw dla Modo CMS oparty na **Bootstrap 5.3.8**
z serifowymi nagłówkami (Playfair Display) i wyróżnionym wpisem na liście.

## Instalacja
1. Folder `editorial/` znajduje się w `themes/`.
2. W panelu: **Settings → Active Theme** wybierz `editorial` i zapisz.

## Struktura i API
- `header.php` — masthead + górny navbar (`get_theme_menu()`, `lang_switch()`), hook `theme_head()`.
- `footer.php` — stopka (brand / `menu('main-menu')` / `get_recent_posts()`), `theme_footer()`, back-to-top.
- `archive.php` — pierwszy wpis jako wyróżniony (duży), pozostałe w siatce; paginacja.
- `page.php`, `single.php`, `front-page.php`, `404.php` — pozostałe szablony.
- `assets/css/style.css`, `assets/js/theme.js`, `theme.json`, `README.md`.

Wykorzystuje całe API motywów: `theme_head/theme_footer`, `get_theme_menu`, `menu`, `lang_switch`,
`site_logo/title/desc/url`, `page_*`, `post_*`, `has_tags/post_tags`, `get_recent_posts`,
`get_theme_url`, `resolve_media_url`, `_e()` oraz hooki `Hooks::*`.

Bootstrap / ikony / czcionki ładowane z CDN.