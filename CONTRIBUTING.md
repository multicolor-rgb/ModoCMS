# Contributing to Modo CMS

[Polski](#polski) | [English](#english)

---

## English

Thanks for your interest in Modo CMS! This guide explains how to prepare the environment, submit changes and report issues.

### Code of conduct

Be respectful, constructive and patient. We are all here to make the project better.

### Requirements

* PHP **8.0+** with the `pdo_sqlite`, `sqlite3` and `mbstring` extensions.
* Git.
* A local web server (Apache, Nginx) or the PHP built-in server (`php -S`).

### Setting up a development copy

```bash
git clone git@github.com:multicolor-rgb/ModoCMS.git
cd ModoCMS
chmod -R 775 data uploads
php -S localhost:8000
# open http://localhost:8000/install.php and complete the wizard
```

Remove `install.php` after the first installation. Never commit the contents of `data/` (the SQLite database) or `uploads/`.

### Coding standards

* Every PHP file starts with `declare(strict_types=1);`.
* Follow the existing style — the project is close to PSR-12: 4-space indentation, braces on the same line for functions and classes.
* Use type hints for parameters and return types (PHP 8+, union types, `mixed`, nullable `?type`).
* Prefer **PDO prepared statements** for all SQL. Never concatenate user input into a query.
* Namespaced code lives under `Core\`; global theme/plugin helper functions are guarded with `function_exists()` or `if (!class_exists(...))`.
* Escape all output: `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')`.
* Verify permissions (`Auth::requireCapability()`) and CSRF tokens in every admin action.
* Keep strings translatable: use `__('English source string')` / `_e(...)` and add keys to `languages/*.json`.
* No external CDN assets — all JS/CSS libraries ship locally in `assets/` or `admin/assets/vendor/`.

### Testing

There is no automated test suite yet. Before opening a pull request:

1. Test your change in the admin panel **and** on the frontend.
2. Test an installation in a **subdirectory** (not only in the root) — the `.htaccess` has no `RewriteBase` on purpose.
3. Test with both content editors (TinyMCE and Editor.js) if you touch the editor or the grid system.
4. Check the browser console and the PHP error log.

### Branching and commits

* Create a branch per feature/fix: `git checkout -b feature/short-name` or `fix/short-name`.
* Commit messages should be short, imperative and descriptive (e.g. `Add category archive to sitemap`).
* Keep commits focused; avoid mixing refactors with functional changes.
* Rebase onto the latest `main`/`master` before opening the PR.

### Pull request checklist

* [ ] The change builds and runs without PHP notices/warnings.
* [ ] Existing behaviour is not broken.
* [ ] New strings are translatable.
* [ ] No files from `data/`, `uploads/` or temporary artefacts are committed.
* [ ] The description explains *what* and *why*.
* [ ] Screenshots are attached for UI changes.

### Reporting bugs

Open an issue using the bug report template and include: PHP version, server (Apache/Nginx), installation location (root/subdirectory), steps to reproduce, expected vs. actual result and relevant log excerpts.

### Security

Please do **not** open a public issue for security vulnerabilities. Contact the maintainers privately first and give us time to prepare a fix.

### License

By contributing, you agree that your contribution is licensed under the [MIT License](LICENSE).

---

## Polski

Dziękujemy za zainteresowanie Modo CMS! Ten przewodnik wyjaśnia, jak przygotować środowisko, zgłaszać zmiany i raportować problemy.

### Kodeks postępowania

Bądź życzliwy, konstruktywny i cierpliwy. Wszyscy jesteśmy tu po to, żeby projekt był lepszy.

### Wymagania

* PHP **8.0+** z rozszerzeniami `pdo_sqlite`, `sqlite3` i `mbstring`.
* Git.
* Lokalny serwer WWW (Apache, Nginx) albo wbudowany serwer PHP (`php -S`).

### Przygotowanie kopii do pracy

```bash
git clone git@github.com:multicolor-rgb/ModoCMS.git
cd ModoCMS
chmod -R 775 data uploads
php -S localhost:8000
# otwórz http://localhost:8000/install.php i przejdź kreator
```

Po pierwszej instalacji usuń plik `install.php`. Nigdy nie commituj zawartości `data/` (baza SQLite) ani `uploads/`.

### Standardy kodu

* Każdy plik PHP zaczyna się od `declare(strict_types=1);`.
* Trzymaj się istniejącego stylu — projekt jest blisko PSR-12: wcięcia 4 spacje, nawiasy klamrowe w tej samej linii dla funkcji i klas.
* Stosuj type hinty dla parametrów i typów zwracanych (PHP 8+, typy unii, `mixed`, `?typ`).
* We wszystkich zapytaniach SQL używaj **PDO Prepared Statements**. Nigdy nie sklejaj danych od użytkownika z zapytaniem.
* Kod z namespace leży pod `Core\`; globalne funkcje pomocnicze motywu/wtyczek są chronione przez `function_exists()` lub `if (!class_exists(...))`.
* Escapuj całe wyjście: `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')`.
* W każdej akcji panelu weryfikuj uprawnienia (`Auth::requireCapability()`) oraz token CSRF.
* Teksty trzymaj tłumaczalne: używaj `__('English source string')` / `_e(...)` i dodawaj klucze do `languages/*.json`.
* Bez zasobów z CDN — wszystkie biblioteki JS/CSS są dołączone lokalnie w `assets/` lub `admin/assets/vendor/`.

### Testowanie

Nie ma jeszcze automatycznych testów. Przed zgłoszeniem pull requesta:

1. Przetestuj zmianę w panelu admina **i** na frontendzie.
2. Przetestuj instalację w **podkatalogu** (nie tylko w root) — `.htaccess` celowo nie ma `RewriteBase`.
3. Przetestuj z oboma edytorami treści (TinyMCE i Editor.js), jeśli dotykasz edytora lub systemu siatek.
4. Sprawdź konsolę przeglądarki oraz log błędów PHP.

### Gałęzie i commity

* Twórz gałąź na funkcję/poprawkę: `git checkout -b feature/krotka-nazwa` lub `fix/krotka-nazwa`.
* Komunikaty commitów: krótkie, rozkazujące i opisowe (np. `Add category archive to sitemap`).
* Commity powinny być skupione; nie mieszaj refaktoryzacji ze zmianą funkcjonalną.
* Przed otwarciem PR zrób rebase na aktualny `main`/`master`.

### Checklista pull requesta

* [ ] Zmiana działa bez notice'ów/ostrzeżeń PHP.
* [ ] Istniejące zachowanie nie zostało zepsute.
* [ ] Nowe teksty są tłumaczalne.
* [ ] Nie zacommitowano plików z `data/`, `uploads/` ani artefaktów tymczasowych.
* [ ] Opis wyjaśnia *co* i *dlaczego*.
* [ ] Dla zmian UI dołączone są zrzuty ekranu.

### Zgłaszanie błędów

Otwórz issue korzystając z szablonu bug report i podaj: wersję PHP, serwer (Apache/Nginx), lokalizację instalacji (root/podkatalog), kroki do odtworzenia, wynik oczekiwany vs. faktyczny oraz odpowiednie fragmenty logów.

### Bezpieczeństwo

**Nie** zgłaszaj publicznie podatności bezpieczeństwa. Najpierw skontaktuj się prywatnie z opiekunami projektu i daj nam czas na przygotowanie poprawki.

### Licencja

Wnosząc wkład, zgadzasz się, że jest on udostępniany na licencji [MIT](LICENSE).
