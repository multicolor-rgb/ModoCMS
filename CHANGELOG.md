# Changelog

Wszystkie istotne zmiany w projekcie Modo CMS. Format oparty na
[Keep a Changelog](https://keepachangelog.com/pl/1.1.0/), wersjonowanie zgodne
z [Semantic Versioning](https://semver.org/lang/pl/).

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
