# Modo Bootstrap Dark (`default2`) — motyw Modo CMS

Nowoczesny, ciemny motyw dla Modo CMS oparty na **Bootstrap 5.3.8** (tryb `data-bs-theme="dark"`),
z gradientowymi hero i szklanymi kartami.

## Instalacja
1. Folder `default2/` znajduje się w `themes/`.
2. W panelu: **Settings → Active Theme** wybierz `default2` i zapisz.

## Struktura i API
- `header.php` — head + hook `theme_head()`, ciemny navbar (`get_theme_menu()`, `lang_switch()`).
- `footer.php` — stopka (brand / `menu('main-menu')` / `get_recent_posts()`), `theme_footer()`, back-to-top.
- `page.php`, `single.php`, `archive.php`, `front-page.php`, `404.php` — szablony stron/wpisu/listy/strony głównej/błędu.
- `assets/css/style.css`, `assets/js/theme.js`, `theme.json`, `README.md`.

Wykorzystuje całe API motywów: `theme_head/theme_footer`, `get_theme_menu`, `menu`, `lang_switch`,
`site_logo/title/desc/url`, `page_*`, `post_*`, `has_tags/post_tags`, `get_recent_posts`,
`get_theme_url`, `resolve_media_url`, `_e()` oraz hooki `Hooks::*`.

Bootstrap / ikony ładowane z CDN.