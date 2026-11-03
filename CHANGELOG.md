# Changelog

Wszystkie istotne zmiany w projekcie Modo CMS. Format oparty na
[Keep a Changelog](https://keepachangelog.com/pl/1.1.0/), wersjonowanie zgodne
z [Semantic Versioning](https://semver.org/lang/pl/).

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
