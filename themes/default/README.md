# Modo Bootstrap Light (`default`) — motyw Modo CMS

Responsywny motyw dla Modo CMS oparty na **Bootstrap 5.3.8** + **Bootstrap Icons**.

## Instalacja
1. Folder `default/` znajduje się w `themes/`.
2. W panelu: **Settings → Active Theme** wybierz `default` i zapisz.
   (Nowe instalacje używają go domyślnie.)

## Struktura
- `header.php` — `<head>`, hook `theme_head()`, responsywny navbar (Bootstrap collapse), `get_theme_menu()`, `lang_switch()`.
- `footer.php` — stopka 3-kolumnowa (brand / `menu('main-menu')` / ostatnie wpisy `get_recent_posts()`), `theme_footer()`, back-to-top.
- `page.php` — strona statyczna (hero z `has_image()/page_image()`).
- `single.php` — wpis blogowy + sidebar (`get_recent_posts()`), tagi (`post_tags()`).
- `archive.php` — listing wpisów (pętla `have_posts()/the_post()`), paginacja (`$totalPages`, `$currentPage`).
- `front-page.php` — strona główna (treść + ostatnie wpisy).
- `404.php` — strona błędu.
- `assets/css/style.css`, `assets/js/theme.js` — style i skrypty motywu.
- `theme.json` — metadane motywu.

## Używane funkcje API motywów
`theme_head()`, `theme_footer()`, `get_theme_menu()`, `menu()`, `lang_switch()`,
`site_logo()`, `site_title()`, `site_desc()`, `site_url()`, `page_title()`, `page_content()`,
`page_date()`, `page_author()`, `has_image()`, `page_image()`, `page_url()`, `have_posts()`,
`the_post()`, `post_title()`, `post_url()`, `post_image()`, `post_excerpt()`, `has_tags()`,
`post_tags()`, `get_post_tags()`, `get_recent_posts()`, `get_theme_url()`, `resolve_media_url()`,
`_e()`, oraz hooki `Hooks::*`.

Zasoby Bootstrapa/CSS/JS ładowane z CDN (`cdn.jsdelivr.net`).