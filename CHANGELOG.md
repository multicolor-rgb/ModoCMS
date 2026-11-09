# Changelog

Wszystkie istotne zmiany w projekcie Modo CMS. Format oparty na
[Keep a Changelog](https://keepachangelog.com/pl/1.1.0/), wersjonowanie zgodne
z [Semantic Versioning](https://semver.org/lang/pl/).

## [3.5.0] — 2026-11-09

### Dodane

- **Ręczne porządkowanie wpisów i stron** — opcjonalna opcja w Ustawienia → Treść pozwala ustawiać kolejność wpisów i stron przez przeciąganie lub strzałki. Kolejność trafia do pętli wpisów, archiwów tagów i kategorii, `get_recent_posts()`, nowej funkcji `get_pages()` oraz fallbackowego menu stron.
- **Responsywna tabela treści** — lista dokumentów dopasowuje się do mniejszych laptopów, tabletów i telefonów: drugorzędne kolumny są ukrywane progresywnie, a tytuł, status i akcje pozostają widoczne bez poziomego przewijania.

## [3.4.0] — 2026-11-09

### Dodane

- **Wygodniejsze porządkowanie galerii w polach niestandardowych i pluginie Modo Gallery** — widok siatki/listy, przeciąganie kafelków, pole pozycji, strzałki, sortowanie A–Z/Z–A po nazwie pliku, odwracanie kolejności oraz zaznaczanie i zbiorcze przenoszenie lub usuwanie zdjęć. Zmiana kolejności nie odświeża już całej listy miniaturek.
- **Pamięć folderu w media pickerze** — po wybraniu folderu i zamknięciu okna ponowne otwarcie pickera w polach niestandardowych oraz w pluginie Modo Gallery przywraca ten folder (do zamknięcia karty).

### Poprawione

- **Filtr Kosza w liście treści** — kliknięcie Wpisy, Strony lub Wszystkie zawsze opuszcza Kosz, a sam Kosz pokazuje wszystkie usunięte dokumenty bez filtra typu i typu strony.

## [3.3.1] — 2026-11-07

### Poprawione

- **Wgrywanie plików w zakładce Media** — przycisk „+ Upload File” i przeciąganie plików znów wysyłają pliki na serwer. Od wersji 3.0.9 `admin/media.php` odwoływał się do niezdefiniowanej zmiennej `csrf`, więc przeglądarka przerywała żądanie przed wysłaniem (`ReferenceError: csrf is not defined`), a interfejs nie pokazywał żadnego błędu. Token CSRF jest teraz pobierany z `window.MODO_CSRF`, tak jak w pozostałych panelach administracyjnych. Regresję wprowadził commit `5c9f5d6` („release: 3.0.9 — security hardening, content API and tooling”).

## [3.3.0] — 2026-11-05

### Dodane

- **Hierarchiczne kategorie** — kategorie mają teraz `parent_id` i obsługują wiele poziomów zagnieżdżenia. Istniejące kategorie stają się kategoriami głównymi, więc migracja jest w pełni zgodna wstecz.
- **Drzewo kategorii w adminie** — formularz kategorii ma wybór rodzica, a lista jest wyświetlana hierarchicznie z wcięciami. Usunięcie kategorii przepina jej podkategorie do kategorii nadrzędnej.
- **Hierarchia kategorii w edytorze wpisu** — checkboxy kategorii zachowują kolejność i wcięcia drzewa.
- **Drzewo stron w liście treści** — strony są wyświetlane jako drzewo z rozwijaniem i zwijaniem, zapamiętywaniem stanu oraz przyciskami „Rozwiń wszystkie” i „Zwiń wszystkie”.
- **Funkcje motywu dla hierarchii** — `get_categories()`, `get_category_tree()` i `get_category_children()`; `get_post_categories()` zwraca teraz również `parent_id` i `depth`.

### Zmienione

- **Responsywne tabele w adminie** — tabele mają poziomy suwak na średnich i małych ekranach, zamiast ucinać ostatnie kolumny. Dotyczy to m.in. listy treści, kategorii i typów stron.

### Poprawione

- Naprawiono brak widocznego suwaka poziomego w tabelach admina na ekranach o szerokości 641–900 px.

## [3.2.0] — 2026-11-05

### Dodane

- **Site Health na dashboardzie** — ocena instalacji i lista problemów: installer, uprawnienia, HTTPS, debug, nagłówki, cache, backupy, cron, PHP i domyślne hasło.
- **Kosz i przywracanie** — usuwanie dokumentów trafia do kosza, z możliwością przywrócenia lub trwałego usunięcia; automatyczne czyszczenie po `trash_retention_days`.
- **Podpisane linki podglądu** — przycisk Podgląd w edytorze generuje link do zapisanej wersji szkicu, ważny 24 h, bez cache i z `noindex`.
- **Kanał RSS 2.0** — `/feed.xml` oraz `/{lang}/feed.xml`, z automatycznym `<link rel="alternate">` w `theme_head()`.
- **Zadania cykliczne (cron)** — `tools/cron.php` i `cron.php?token=...`; publikacja zaplanowana, czyszczenie kosza, retencja, sitemap i cache.
- **Panel cron w Ustawieniach** — ostatnie uruchomienie, polecenie CLI, URL HTTP, token i przycisk „Uruchom teraz”.

### Zmienione

- **API treści** — `DELETE` domyślnie przenosi do kosza; `?force=1` usuwa trwale, `?trash=1` pokazuje kosz.
- **Dokumentacja GitHub Pages** — nowe strony „Automatyzacja i konserwacja”, rozbudowane „Obsługa systemu”, API, motywy i table of contents.

### Poprawione

- Usunięte dokumenty są konsekwentnie wykluczane ze strony, menu, sitemapy, RSS, API, wyszukiwania i liczników.
- Naprawiono wywołanie `ThemeState` w komunikacie trybu konserwacji.

## [3.1.1] — 2026-11-05

### Naprawione

- **Tryb ciemny/jasny zapisuje się teraz per konto** w kolumnie `users.admin_theme`; wybór przetrwa przejście między podstronami, odświeżenie, wylogowanie i ponowne logowanie oraz inne urządzenie.
- Ujednolicono klucz `localStorage` (`modo_cms_theme`) i dodano migrację starych wartości `clean_cms_theme` / `theme`, które wcześniej powodowały reset motywu.
- TinyMCE w edytorze treści korzysta z właściwego trybu ciemnego/jasnego.
- Zakładka Ustawień jest zapisywana także w adresie URL, więc zapis formularza i odświeżenie nie wracają do „Ogólne”.

### Dodane

- **Pełna dokumentacja GitHub Pages**: przewodnik po tworzeniu motywów, obsłudze systemu oraz rozbudowany przewodnik po API treści (tokeny, CRUD, `curl`, uprawnienia, błędy).
- Nowe wpisy w hubie dokumentacji, README i linki między stronami.

## [3.1.0] — 2026-11-05

### Dodane

- **Nowe typy pól w Theme Customizerze** — `tinymce` (edycja rich text), `pagelink` (wybór opublikowanej strony lub wpisu jako link wewnętrzny), `menu` (wybór menu) i `date` (pole daty).
- **Linki wewnętrzne** — wybór z listy stron/wpisy, zapis ścieżki względnej, walidacja przy publikacji i nowe helpery `get_theme_mod_link()` / `the_theme_mod_link()`.
- **Rich text w motywie** — helpery `get_theme_mod_html()` / `the_theme_mod_html()` oraz sanityzacja HTML przy zapisie.
- **Builder schematu** — obsługa `link_types` i nowych typów pól.
- **Dokumentacja GitHub Pages** — nowa strona „Ustawienia motywu (Customizer)” z pełną tabelą typów, przykładami `theme.json`, hooków, buildera i helperów.

### Poprawione

- Usunięto zduplikowaną kontrolkę `modo_footer_text` w domyślnym motywie (obraz w stopce ma teraz osobne `modo_footer_image`).
- Pole `url` odrzuca niebezpieczne schematy (`javascript:`, `data:`, `vbscript:`).

## [3.0.9] — 2026-11-05

### Bezpieczeństwo

- **Ochrona przed zatruciem nagłówka Host** — absolutne adresy (link resetu hasła, canonical, sitemap) nie są już budowane z niezweryfikowanego `HTTP_HOST`; można dodać zaufane domeny przez `trusted_hosts`.
- **Bramka instalacji** — strona i API nie działają, dopóki kreator nie zapisze `install_completed`; domyślne konto demo ma losowe hasło, a `install.php` zapisuje plik-lock.
- **Tokeny API** — przechowywane jako hash SHA-256, z opcjonalnym terminem ważności, limitem 120 żądań/min i kontrolą uprawnień (`publish_pages`, `edit_others_pages` itd.). Surowy token jest pokazywany tylko raz.
- **Reset hasła** — tokeny są hashowane, wysyłki limitowane, a zmiana hasła unieważnia sesje i tokeny API.
- **2FA i logowanie** — limit prób kodu TOTP, CSRF na formularzach logowania, `session.use_strict_mode` i limit bezczynności sesji.
- **Upload i SVG** — endpoint wymaga CSRF, a SVG jest czyszczony przez DOM (usuwa skrypty, `foreignObject`, animacje i `javascript:`).
- **Nagłówki** — usunięto przestarzały `X-XSS-Protection`, dodano CSP, `X-Permitted-Cross-Domain-Policies` i cache statyki.

### Poprawione

- Allow-lista IP w trybie konserwacji dopasowuje pojedynczy adres dokładnie (wcześniej `203.0.113.7` pasowało też do `.70`).
- Edytor motywów pokazuje komunikaty błędów zamiast wypisywać je przed `<!DOCTYPE>`.
- Zapis przez API odświeża cache strony i sitemapę; zapis cache jest atomowy.
- Dodano indeksy dla typowych zapytań oraz retencję `login_attempts`, `visits`, `rate_limits` i dziennika audytu.
- Pola własne są pobierane jednym zapytaniem na stronę zamiast N+1.

### Dodane

- **API treści** — odczyt, tworzenie, aktualizacja i usuwanie (`GET/POST/PUT/PATCH/DELETE`), dokumentacja i OpenAPI.
- **Dziennik aktywności** — log operacji administracyjnych i API w Ustawienia → Narzędzia.
- **Wyszukiwarka** — `/search?q=...` z formularzem w motywach.
- **Planowanie publikacji** — pole daty publikacji; wpisy z przyszłą datą są ukryte do czasu publikacji.
- **Testowy e-mail** — przycisk w Ustawienia → E-mail.
- **CLI** — `tools/modo.php` (`health`, `cache:purge`, `sitemap`, `thumbs`, `audit:prune`, `i18n`).
- **CI i test dymny** — macierz PHP 8.0–8.4, lint, audyt tłumaczeń i `tests/smoke.sh`.

## [3.0.8] — 2026-11-05

### Zmienione

- **Customizer bez przeładowywania panelu** — panel ustawień motywu i podgląd strony są teraz osobnymi dokumentami. Podgląd renderuje się w iframe, a zmiany odświeżają wyłącznie tę ramkę, więc sidebar zachowuje przewinięcie, rozwinięte sekcje i fokus.
- **Podgląd zmiennych CSS** — wartości `css_var` są aplikowane bezpośrednio w dokumencie iframe i ponownie po każdym odświeżeniu podglądu.
- **Publikacja i reset** — nadal przeładowują cały edytor, aby panel od razu pokazywał wartości zapisane w bazie.

### Poprawione

- Pasek administratora nie jest renderowany w ramce podglądu, więc nie zasłania strony ani nie dubluje nawigacji.

## [3.0.7] — 2026-11-04

### Dodane

- **`page_thumbnail_image()` / `post_thumbnail_image()`** — zwracają URL miniatury obrazka wyróżniającego (domyślnie 480 px, z parametrem szerokości).
- **`page_image_srcset()` / `post_image_srcset()`** — responsywny `srcset` z dostępnych miniatur.
- **Audyt tłumaczeń** — `tools/i18n-audit.php` wykrywa brakujące i puste klucze we wszystkich językach.
- Uzupełniono wszystkie brakujące tłumaczenia w `en`, `pl`, `de` i `es`.

### Naprawione

- **Orientacja zdjęć z telefonu** — EXIF Orientation jest teraz stosowany również przy generowaniu WebP, nie tylko miniatur. Zdjęcia 9:16 nie są już obracane do 16:9.
- **Stare miniatury i WebP** — wersja cache miniatur została podniesiona, a przycisk wymuszonego generowania odtwarza także kopie WebP.
- **`webp_url()`** — nie zwraca starej kopii WebP, jeśli jej wymiary nie zgadzają się z orientacją oryginału; wtedy używa oryginału.
- **Edycja i masowa obróbka** — odtwarzają miniatury i WebP z poprawną orientacją.

## [3.0.6] — 2026-11-04

### Dodane

- **„Zaznacz wszystko” w oknie wyboru mediów** — checkbox w trybie wielokrotnego wyboru zaznacza wszystkie pliki pasujące do filtra folderu, także ukryte przez „Load more”.
- **Masowa edycja w bibliotece mediów**:
  - masowa zmiana ALT i podpisu dla zaznaczonych plików,
  - masowa obróbka zdjęć: obrót, odbicie, zmiana rozmiaru i jakości; nadpisanie oryginałów lub zapis kopii,
  - wymuszona regeneracja miniatur dla zaznaczonych plików.
- **Wymuszone generowanie wszystkich miniatur** — nowy przycisk w Ustawieniach → Wydajność generuje ponownie podglądy 80/160/240 oraz miniatury galerii 480/960 dla całej biblioteki.
- Nowe kolumny `alt_text` i `caption` w tabeli `media`; migracja istniejącej bazy jest automatyczna.

### Naprawione

- Po edycji zdjęcia miniatury są od razu odtwarzane we wszystkich skonfigurowanych rozmiarach, także galeryjnych — bez czekania na backfill.

## [3.0.5] — 2026-11-04

### Naprawione

- **Podgląd galerii w panelu** — elementy pola gallery korzystają teraz z systemowych miniatur (`thumb.php`, 160 px) zamiast ładować oryginalne obrazy; dotyczy to także obrazów dodanych przez picker i upload.
- **Instalacje w podkatalogu** — `admin/thumb.php` poprawnie obsługuje ścieżki z base subdirectory (`/cms/uploads/...`).
- Miniatury nadal mają fallback do oryginału, gdy nie da się ich wygenerować.

### Bez zmian w bazie

- Aktualizacja z 3.0.4 nie wymaga migracji.

## [3.0.4] — 2026-11-04

### Dodane

- **Prowadnice siatek w TinyMCE** — wiersze i kolumny są widoczne w edytorze dzięki przerywanym ramkom; prowadnice nie trafiają na frontend.
- **Wygodniejsze edytowanie siatek** — treść każdej kolumny jest automatycznie owijana w `<p>`, a stare siatki są normalizowane przy otwarciu edytora. Zmiana formatu bloku (np. `h1 → p`) nie zamienia już `<div>` kolumny w nagłówek i nie niszczy struktury.
- **Automatyczna migracja treści Editor.js** — stare dokumenty JSON są jednorazowo konwertowane do HTML (strony, snippety, rewizje) przy pierwszym wejściu do panelu; dostępny jest też skrypt CLI `tools/migrate-editorjs.php`.
- **Padding kanwy edytora** — wewnętrzny odstęp 10 px w TinyMCE.

### Zmienione

- **TinyMCE jedynym edytorem treści** — Editor.js został usunięty z panelu, ustawień, wtyczek i paczki.
- **Kanwa edytora zawsze jasna** — tryb ciemny panelu zmienia tylko pasek narzędzi i dialogi; treść jest edytowana na białym tle.
- **`box-sizing: border-box`** — poprawione szerokości kontenerów i kolumn siatek (bundled CSS + kanwa TinyMCE).
- **`editor.css` tylko dla TinyMCE** — usunięto scopowanie CSS dla inline'owego edytora blokowego.

### Naprawione

- Brak poziomego paska przewijania w edytorze.
- Kolumny siatek nie zawijają się już pod siebie z powodu paddingów.
- Zmiana formatu bloku wewnątrz siatki nie niszczy wrappera kolumny.
- Ciemny tekst na ciemnym tle w trybie ciemnym.

### Usunięte

- **Edytor blokowy Editor.js** — kod, bundle, ustawienia, mostek `editor-bridge.js`, narzędzia i integracje w Gallery/Form/Carousel.
- Nieużywane helpery scopowania CSS (`scopeCss()`, `editorScopedCss()`, `ThemeEditorStyles::scopedCss()`).
- Testowe `robots.txt` / `sitemap.xml` z repozytorium (są generowane w runtime).

## [3.0.3] — 2026-11-04

### Dodane

- **Miniatury galerii**:
  - galerie pól niestandardowych i plugin Modo Gallery ładują miniatury przez
    `srcset`/`sizes` (domyślnie 480 i 960 px) zamiast pełnych obrazów,
  - lightbox nadal otwiera oryginalny plik,
  - istniejące miniatury 240/320 px są wykorzystywane od razu, więc galerie
    korzystają z miniaturek jeszcze przed pełnym backfillem,
  - miniatury generują się przy uploadzie, edycji obrazu i zapisie pola galerii,
  - nowe ustawienia **Ustawienia → Wydajność → Miniatury galerii**: włączanie,
    automatyczne generowanie, szerokości (domyślnie `480,960`) i jakość
    (domyślnie `78`),
  - przycisk **Generuj miniatury galerii** przetwarza wyłącznie zdjęcia używane
    w galeriach, a panel pokazuje licznik brakujących miniaturek,
  - po zmianie szerokości należy ponownie uruchomić backfill galerii, aby
    powstały nowe rozmiary,
  - gdy obraz przekracza limit 20 MP GD, używany jest ImageMagick CLI
    (`/usr/bin/convert`), jeśli jest dostępny,
  - lokalne pełne URL-e (`https://domena/uploads/...`) i ścieżki z podkatalogiem
    (`/cms/uploads/...`) są rozpoznawane poprawnie,
  - brak GD, Imagicka lub ImageMagick CLI oznacza powrót do oryginalnych obrazów.
- Nowe metody `MediaLibrary::frontendImageSources()`,
  `galleryThumbnailWidths()`, `thumbnailGenerationWidths()`,
  `thumbnailExists()`, `canGenerateThumbnail()` oraz
  `MetaGallery::warmThumbnails()`, `allGalleryImagePaths()` i
  `missingGalleryThumbnailCount()`.
- **Biblioteka mediów**:
  - zbiorcze usuwanie zaznaczonych plików oraz zawartości folderów
    z potwierdzeniem,
  - lightbox z zoomem, nawigacją poprzedni/następny, podglądem oryginału
    i przejściem do edycji,
  - edytor obrazów w przeglądarce: obrót, odbicie, kadrowanie z proporcjami
    oraz zapis jako kopia lub nadpisanie oryginału,
  - nowe `admin/media-save.php` i `admin/assets/js/media-tools.js` oraz
    przebudowa `admin/media.php` i `core/MediaLibrary.php`.
- **Aktualizator systemu** w `Ustawienia → System`:
  - sprawdzanie najnowszego publicznego wydania przez GitHub API (z cache
    i przyciskiem wymuszonego odświeżenia),
  - jednoklikowa instalacja najnowszego wydania z pobieraniem po HTTPS,
  - weryfikacja SHA-256 paczki, ochrona przed zip-slip i limitami rozmiaru,
  - automatyczna kopia bazy danych i kopia każdego podmienianego pliku
    w `data/backups/` przed aktualizacją,
  - pomijanie `data/`, `uploads/`, `modoconfig.php` i `install.php`, zachowanie
    własnego głównego `.htaccess`,
  - tymczasowy tryb konserwacji na czas podmiany plików, czyszczenie cache
    i OPcache oraz automatyczny rollback w razie błędu.
- `core/Version.php` z bieżącą wersją aplikacji.
- Tłumaczenia PL/EN/DE/ES oraz dokumentacja nowych komunikatów w panelu.

### Poprawione

- **Lightbox galerii**: kontener `.modo-gallery` nie jest już traktowany jako
  trigger lightboxa motywu, więc na wpisach „Realizacje” działa tylko GLightbox
  z pola niestandardowego.
- **Diagnostyka miniaturek**: backfill zwraca liczbę wygenerowanych, pominiętych
  i nieudanych plików; panel pokazuje błędy zamiast fałszywego sukcesu oraz
  ostrzega o braku backendu grafiki lub braku zapisu do `uploads/thumbs`; błędy
  generowania trafiają do logu PHP.
- Przywrócono klucze tłumaczeń edytora obrazów usunięte przez wcześniejszą
  wersję paczki (`Invalid image.`, `The image is too large to upload.`,
  `Could not save the image.`, `Original`, a w DE/ES także `Reset`) i zachowano
  nazwę wyświetlaną pliku przy nadpisywaniu obrazu.
- **Aktualizator systemu**: po wyczerpaniu limitu GitHub API (HTTP 403/429)
  najnowsze wydanie jest odczytywane z publicznego kanału `releases.atom`, więc
  sprawdzanie aktualizacji nie kończy się błędem. Dodano opcjonalny token GitHub
  (`update_github_token`), który podnosi limit API z 60 do 5000 zapytań na
  godzinę, oraz wydłużono cache sprawdzeń do 12 godzin.
- **Cookie Consent**: baner nie blokuje przewijania strony w układach `bar`,
  `card`, `bottom-left` i `bottom-right`; blokada działa tylko w pełnoekranowym
  modalu, którego treść można przewijać. Plugin podniesiony do `1.0.1`.

### Bez zmian w bazie

- Brak migracji — ustawienia zapisują się w tabeli `settings` przy pierwszym
  zapisie w panelu; do tego czasu obowiązują wartości domyślne. Aktualizator
  tworzy snapshot bazy, ale nie zmienia jej schematu.

## [3.0.2] — 2026-11-03

### Dodane

- **Opcjonalne style motywu w edytorze treści**:
  - motyw może dostarczyć `themes/<motyw>/assets/css/editor.css`, który jest
    wykrywany wyłącznie po istnieniu pliku,
  - styl trafia do kanwy TinyMCE (`content_css`, `content_style`, opcjonalny
    `body_class`) oraz do inline'owego Editor.js (CSS scopowany do `#editorjs`
    i `#snippet-editorjs`),
  - względne `url(...)` i `@import` są zamieniane na bezwzględne URL-e motywu,
    a `@import` pozostaje pierwszą regułą bloku `<style>`,
  - zmienne Customizera są dodawane na końcu, więc nadpisują wartości domyślne
    z `editor.css`,
  - opcjonalny blok `editor` w `theme.json` obsługuje `body_class` oraz
    `content_style`,
  - brak pliku oznacza zero zmian w edytorach; nie ma fallbacku na `style.css`,
    nowego ustawienia w Settings ani zmian na frontendzie.
- Nowa klasa `Core\ThemeEditorStyles` oraz nowe metody
  `Core\Customizer::cssVariables()` i `Core\CssFramework::scopeCss()`.
- Przykładowe pliki `editor.css` w motywach `default`, `default2` i `editorial`.
- Dokumentacja [PL](docs/pl/styl-motywu-w-edytorze.md) /
  [EN](docs/en/theme-editor-styles.md) oraz linki w README.

### Poprawione

- `CssFramework::editorScopedCss()` korzysta teraz ze wspólnego parsera
  `scopeCss()`, który poprawnie obsługuje `@media`, `:root`/`html`/`body`,
  komentarze oraz pozostawia `@keyframes` i `@font-face` bez zmian.

### Bez zmian w bazie

- Brak migracji — aktualizacja z 3.0.1 wymaga jedynie podmiany plików.

## [3.0.1] — 2026-11-03

### Poprawione

- **Panel „Edytuj ustawienia szablonu”**:
  - sekcje ustawień są domyślnie zwinięte, a te rozwinięte podczas edycji
    pozostają otwarte po przeładowaniu podglądu (stan zapamiętywany per motyw),
  - pisanie w polach tekstowych nie przeładowuje już strony — podgląd odświeża
    się dopiero po zakończeniu edycji (wyjście z pola, zmiana przełącznika,
    wybór opcji, puszczenie suwaka) i tylko gdy wartość faktycznie się zmieniła,
  - szkic jest zapisywany przed przeładowaniem, więc podgląd pokazuje aktualną
    wartość (usunięty wyścig zapis/reload),
  - pozycja przewinięcia panelu jest przywracana po odświeżeniu,
  - **Publikuj** i **Reset** nie odtwarzają już szkicu po wykonaniu akcji.
- Bez zmian w schemacie bazy danych — aktualizacja z 3.0.0 nie wymaga migracji,
  wystarczy podmienić pliki.

## [3.0.0] — 2026-11-02

### Dodane

- **Typy stron i wpisów**:
  - nowy ekran **Typy stron** (`admin/page-types.php`) do definiowania presetów
    z nazwą, slugiem, typem bazowym i zestawem domyślnych pól niestandardowych,
  - presety można tworzyć dla **stron statycznych i wpisów bloga**; wybór typu
    w edytorze automatycznie ustawia właściwy typ bazowy,
  - nowe dokumenty otrzymują domyślne pola z wartościami startowymi, a istniejące
    można uzupełnić przyciskiem **Zastosuj domyślne pola typu** (bez nadpisywania
    wartości),
  - nowe funkcje motywu: `get_page_type()`, `page_type_slug()` i `is_page_type()`,
  - filtr typu na liście treści, szybkie tworzenie „Nowy z typu…” oraz obsługa
    `page_type` / `page_type_id` w API,
  - migracje: `pages.page_type_id`, `page_meta.meta_label` i
    `page_revisions.page_type_id`; przywracanie wersji zachowuje typ dokumentu,
  - nowe tłumaczenia PL/EN/DE/ES oraz dokumentacja
    [PL](docs/pl/typy-stron.md) / [EN](docs/en/page-types.md).

- **Foldery w bibliotece mediów**:
  - fizyczne podkatalogi w `uploads/` (jeden poziom) z tworzeniem, zmianą nazwy
    i usuwaniem pustych folderów,
  - upload bezpośrednio do wybranego folderu, przenoszenie plików pojedynczo
    i zbiorczo oraz filtrowanie widoku z breadcrumbem,
  - automatyczna aktualizacja odwołań URL w stronach, wpisach, snippetach,
    ustawieniach, motywie, rewizjach i danych pluginów przy przenoszeniu plików
    lub zmianie nazwy folderu,
  - foldery widoczne również w wyborze mediów w edytorze, customizerze,
    galerii i karuzeli,
  - automatyczna synchronizacja plików z dysku do tabeli `media`.

- **Miniatury biblioteki mediów**:
  - wybór mediów, biblioteka, customizer, lista stron oraz pickery galerii
    i karuzeli ładują teraz lekkie miniatury (80/160/240 px) zamiast
    pełnych zdjęć,
  - miniatury są generowane od razu przy uploadzie, a istniejącą bibliotekę
    można uzupełnić przyciskiem **Wygeneruj brakujące miniatury** (partiami,
    z paskiem postępu),
  - statyczne pliki trafiają do `uploads/thumbs/`, więc przeglądarka pobiera je
    bez uruchamiania PHP; katalog jest ukryty w widoku biblioteki,
  - wybór backendu: GD → Imagick → ImageMagick CLI (`convert`) → oryginał,
  - modal wyboru mediów pokazuje 24 kafelki i przycisk **Pokaż więcej**,
    dzięki czemu przy 100–200 zdjęciach nie pobiera całej biblioteki,
  - lista biblioteki mediów jest paginowana po 48 pozycji,
  - leniwe ładowanie (`loading="lazy"`), `decoding="async"` i `srcset` dla
    ekranów HiDPI,
  - automatyczne czyszczenie miniatur przy usuwaniu, przenoszeniu i zmianie
    nazwy folderu.

### Zmienione

- **Formularze 1.2.0**:
  - powiadomienia e-mail **domyślnie włączone** — każdy formularz od razu
    wysyła e-mail HTML ze zgłoszeniem, bez ręcznej konfiguracji,
  - nowy fallback odbiorcy: adres formularza → adres globalny → e-mail
    pierwszego konta administratora,
  - opcjonalne **Reply-To na adres e-mail nadawcy** (pierwsze wypełnione pole
    typu e-mail), domyślnie włączone z przełącznikiem globalnym
    `reply_to_sender` oraz per formularz (`Dziedzicz / Tak / Nie`),
  - przeprojektowany, elegancki szablon wiadomości: preheader, metadane
    (data, strona źródłowa), pełna tabela pól, wyróżniona dłuższa treść,
    odświeżona typografia i przycisk do zgłoszeń,
  - nowe i zaktualizowane tłumaczenia (PL/EN/DE/ES).

## [2.0.0] — 2026-10-28

### Dodane

- **Nowy silnik statystyk** (`core/Analytics.php`):
  - zapis każdej odsłony strony (GET/HEAD) z pominięciem botów, panelu admina,
    API, podstron serwisowych i plików statycznych,
  - rotowany codziennie hash adresu IP (bez przechowywania IP w postaci jawnej),
  - rozdzielenie **wszystkich odsłon** i **unikalnych odwiedzających**,
  - liczenie ruchu przed warstwą cache, dzięki czemu cache nie zaniża wyników.
- **Dashboard statystyk**:
  - karty z dzisiejszym i okresowym ruchem,
  - wykres słupkowy / liniowy / obszarowy,
  - wybór palety lub własnego koloru, wysokości wykresu oraz okresu 7/14/30 dni,
  - przełączniki wartości, legendy i siatki,
  - tabela najczęściej odwiedzanych stron,
  - reset statystyk z potwierdzeniem.
- **Nowa zakładka „Statystyki”** w ustawieniach wraz z przełącznikiem
  `analytics_enabled` w `modoconfig.php`.
- **Formularze 1.1.0**:
  - obsługa hCaptcha, Google reCAPTCHA, własnej ochrony oraz trybu automatycznego,
  - własna ochrona: honeypot, pułapka czasowa i podpisany token,
  - bezpieczny fallback do własnej ochrony, gdy brakuje kluczy dostawcy,
  - powiadomienia e-mail w HTML, odpowiedź bezpośrednio do osoby wypełniającej
    formularz (Reply-To), testowa wiadomość e-mail,
  - dziedziczenie ustawień zapisu i powiadomień z formularza nadrzędnego,
  - automatyczne migracje formularzy zapisanych w starszych wersjach,
  - ostrzeżenia o niekompletnej konfiguracji captchy i powiadomień.
- **Customizer**: wybór obrazów z biblioteki mediów, podgląd miniatur i upload
  bezpośrednio z panelu.
- **Tłumaczenia**: nowe ciągi w językach polskim, angielskim, niemieckim
  i hiszpańskim.
- Plik `CHANGELOG.md` oraz opis zmian w wydaniu GitHub.

### Zmienione

- `index.php` przekazuje śledzenie ruchu do `Core\Analytics`, zamiast duplikować
  logikę w routerze.
- `PageCache` nie cache'uje stron zawierających formularze (tokeny CSRF i pułapki
  czasowej są powiązane z sesją).
- Panel pluginów odczytuje metadane także z wyłączonych wtyczek.
- Rozbudowany panel ustawień, poprawki CSS oraz wykresów w panelu admina.
- `robots.txt` i `sitemap.xml` są generowane przez CMS i nie trafiają już do
  paczki wydania.

### Naprawione

- Statystyki nie gubią odsłon serwowanych z pełnego cache.
- Formularze nie tracą ważności tokenów po zapisaniu strony w cache.
- Stare wpisy statystyk z samą datą są jednorazowo normalizowane do pełnej daty
  i godziny.
- Przywrócono wzmocnione pliki `data/.htaccess` i `data/backups/.htaccess`
  (`Require all denied`, `Options -Indexes -ExecCGI`).
- Zapis strony lub wpisu nie kończy się już błędem, gdy slug jest zajęty:
  system automatycznie dopisuje `-1`, `-2` itd. w obrębie tego samego języka
  (dotyczy edytora, szybkiego szkicu, API i przywracania rewizji).

### Bezpieczeństwo

- Formularze: podpisane tokeny, honeypot, pułapka czasowa i bezpieczny fallback
  captchy.
- Katalogi `data/` i `data/backups/` ponownie blokują dostęp HTTP i wykonanie CGI.
- Usunięto z paczki wydania lokalne adresy `localhost` z `robots.txt`
  i `sitemap.xml`.

### Pakowanie

- Archiwum wydania budowane przez `git archive` zawiera wyłącznie aplikację:
  bez bazy, uploadów, katalogu `site/`, konfiguracji CI i plików deweloperskich.
- ZIP jest publikowany automatycznie przez workflow `Release` po wypchnięciu
  taga `v*.*.*`.

## [1.0.0] — 2026-10-18

- Pierwsze publiczne wydanie Modo CMS: lekki CMS na PHP 8 i SQLite3,
  edytory TinyMCE/Editor.js, motywy, pluginy, custom fields, kategorie,
  poczta, kopie zapasowe i instalator.
