# Modo CMS

**Modo CMS** to lekki, szybki i modułowy system zarządzania treścią (CMS) oparty na **PHP 8.x** oraz bazie danych **SQLite3**. Zaprojektowany z myślą o programistach ceniących czytelną architekturę opartą na prostych funkcjach proceduralnych, brak zbędnego narzutu obiektowego oraz pełną kontrolę nad kodem szablonów i wtyczek.

---

## 🚀 Główne cechy

* **Prosta architektura:** Minimalistyczne, łatwe do zapamiętania funkcje szablonu.
* **Baza SQLite3:** Brak konieczności konfiguracji zewnętrznych serwerów bazodanowych – baza w jednym pliku, łatwa do migracji i kopii zapasowych.
* **Dynamiczny Theme Customizer:** Konfiguracja wyglądu z podglądem na żywo (`iframe`). Sekcje i pola (wiele typów) deklaruje się w `theme.json`, przez hooki wtyczek (`modo_customize_register`) lub tworzy bezpośrednio w panelu (builder GUI zapisywany w bazie).
* **Wbudowane pola niestandardowe (Custom Fields):** Obsługa różnych typów pól (tekst, textarea, liczba, checkbox, kolor, obrazek).
* **Architektura wtyczek (Hooks):** System akcji i filtrów pozwalający na łatwą rozbudowę panelu admina oraz frontendu.
* **Bezpieczeństwo:** Ochrona przed SQL Injection (PDO Prepared Statements), CSRF, weryfikacja uprawnień i sanityzacja danych wyjściowych.

---

## ⚙️ Wymagania systemowe

* PHP **8.0** lub nowszy
* Rozszerzenia PHP: **pdo_sqlite**, **sqlite3**, **mbstring**
* Serwer WWW: **Apache** (z `mod_rewrite` i obsługą `.htaccess`) lub **Nginx**

---

## 🌍 Obsługiwane lokalizacje instalacji

Modo CMS działa zarówno w **katalogu głównym domeny**, jak i w **podkatalogu (subfolderze)** — bez ręcznej zmiany konfiguracji:

* katalog główny: `https://example.com/`
* podkatalog: `https://example.com/modocms/`

Bazowa ścieżka jest wykrywana dynamicznie (na podstawie `SCRIPT_NAME` / lokalizacji plików przez `Router::getBaseSubdirectory()`), a plik `.htaccess` **nie zawiera `RewriteBase`** — Apache sam wylicza bazę z katalogu, w którym leży plik. Dzięki temu te same pliki działają w obu przypadkach.

> 🔒 **Nie dodawaj `RewriteBase /nazwa/`** do `.htaccess` — to zepsułoby instalację w katalogu głównym. Pozostaw plik bez tej dyrektywy.

---

## 📦 Instalacja w katalogu głównym (Root)

1. Skopiuj lub sklonuj zawartość projektu **bezpośrednio** do katalogu głównego serwera WWW:
   ```bash
   cd /var/www/html
   git clone <adres-repozytorium> .
   ```
2. Nadaj prawa zapisu katalogom `data/` i `uploads/`:
   ```bash
   chmod -R 775 data uploads
   ```
3. Otwórz w przeglądarce `https://example.com/install.php` i przejdź przez kreator instalacji.
4. Po zakończeniu instalacji usuń plik `install.php`.

Adresy po instalacji: strona główna `https://example.com/`, panel `https://example.com/admin/`.

---

## 📁 Instalacja w podkatalogu (Subfolder)

1. Skopiuj lub sklonuj projekt do podkatalogu:
   ```bash
   cd /var/www/html
   mkdir modocms
   git clone <adres-repozytorium> modocms
   # lub po prostu skopiuj pliki do: /var/www/html/modocms
   ```
2. Nadaj prawa zapisu katalogom `data/` i `uploads/`:
   ```bash
   chmod -R 775 modocms/data modocms/uploads
   ```
3. Otwórz `https://example.com/modocms/install.php` i przejdź przez kreator.

Adresy po instalacji: strona główna `https://example.com/modocms/`, panel `https://example.com/modocms/admin/`. Nie trzeba nic zmieniać — `.htaccess` i runtime wykryją podkatalog automatycznie.

---

## 🛠️ Konfiguracja serwera WWW

### Apache

Plik `.htaccess` (dołączony do projektu) obsługuje przepisywanie ruchu na front controller (`index.php`) zarówno w root, jak i w subfolderze. Upewnij się, że vhost ma włączone nadpisywanie oraz że aktywne są moduły `mod_rewrite` i `mod_headers`:

```apache
<Directory /var/www/html>
    AllowOverride All
    Require all granted
</Directory>
```

```bash
# Debian/Ubuntu
sudo a2enmod rewrite headers
sudo systemctl restart apache2
```

### Nginx

Nginx **nie czyta** `.htaccess` — potrzebny jest odpowiedni blok `server`. Przykład dla instalacji w **katalogu głównym**:

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/html;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8-fpm.sock;   # dostosuj do swojej wersji PHP-FPM
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Zablokuj dostęp do katalogów wewnętrznych
    location ~* ^/(data|core)/ { deny all; }
}
```

Dla instalacji w **podkatalogu** zmień regułę routingu:

```nginx
    location /modocms/ {
        try_files $uri $uri/ /modocms/index.php?$query_string;
    }
```

### PHP – wbudowany serwer (tylko do developmentu)

Katalog główny:
```bash
php -S localhost:8000 -t /var/www/html
```

Podkatalog:
```bash
cd /var/www/html
php -S localhost:8000
# otwórz: http://localhost:8000/modocms/
```

> Wbudowany serwer PHP nie odczytuje `.htaccess`. Dla „czystych” URL-i (bez `index.php` w adresie) użyj Apache lub Nginx; w prostym dev-ie można też dołączyć własny router-skrypt (`php -S localhost:8000 router.php`).

---

## 🔐 Uprawnienia i bezpieczeństwo

* Katalogi `data/` oraz `uploads/` muszą być zapisywalne przez użytkownika serwera WWW (np. `www-data`).
* Pliki `.htaccess` w `data/` i `uploads/` blokują bezpośredni dostęp HTTP do bazy i plików wykonywalnych.
* Silnik blokuje (w `.htaccess`) bezpośredni dostęp do plików `.sqlite`, `.db`, `.log`, `.ini`, `.json` oraz do katalogów `data/` i `languages/`.

---

## 🎨 Theme Customizer

Panel w backendzie: **Wygląd → Customizer** (`/admin/customize.php`) służy **wyłącznie do zarządzania ustawieniami** (builder sekcji i pól zapisywany w bazie). Nie zawiera podglądu szablonu ani edycji wartości — te czynności wykonuje się na froncie.

Edycja wartości (z podglądem na żywo) dostępna jest bezpośrednio na froncie: link **„Edytuj ustawienia szablonu"** w pasku administratora otwiera wysuwaną z prawej strony sekcję z ustawieniami motywu na **żywej stronie** — bez `iframe`, podglądem jest sama witryna. Kontrolki powiązane ze zmienną CSS (`css_var`) aktualizują wygląd natychmiast, pozostałe odświeżają stronę z nałożonym szkicem. Zmiany zapisują się jako szkic, a przycisk **Opublikuj** utrwala je w bazie. Klasa `Core\CustomizerPanel` jest wpięta w `theme-header`/`theme-footer`, więc działa w każdym motywie.

### Typy pól

`text`, `textarea`, `code`, `number`, `range` (suwak), `checkbox`/`toggle`, `select`, `radio`, `color`, `image`, `url`, `email` oraz elementy układu `heading` i `hr`.

### Źródła definicji ustawień

Schemat jest scalany z trzech źródeł (późniejsze nadpisuje wcześniejsze przy tym samym `id`):

1. **Motyw** — blok `customize` w `themes/<motyw>/theme.json`.
2. **Wtyczki** — hook `modo_customize_register` (rejestracja przez `Customizer::registerSection()` / `registerControl()`), opcjonalnie filtr `modo_customize_schema`.
3. **Builder GUI** — sekcje/pola utworzone w zakładce „Zarządzaj ustawieniami" (tabela `customize_schema`).

#### Przykład w `theme.json`

```json
"customize": {
  "sections": [
    {
      "id": "branding",
      "title": "Branding",
      "priority": 10,
      "controls": [
        {
          "id": "modo_primary_color",
          "type": "color",
          "label": "Kolor akcentu",
          "default": "#0d6efd",
          "css_var": "modo-primary"
        },
        { "id": "modo_footer_text", "type": "text", "label": "Tekst w stopce", "default": "Modo CMS" }
      ]
    }
  ]
}
```

#### Przykład wtyczki

```php
use Core\Hooks;
use Core\Customizer;

Hooks::addAction('modo_customize_register', function () {
    Customizer::registerSection(['id' => 'plugin', 'title' => 'Wtyczka']);
    Customizer::registerControl([
        'id' => 'plugin_show_banner',
        'type' => 'checkbox',
        'label' => 'Pokaż banner',
        'section' => 'plugin',
        'default' => '1',
    ]);
});
```

### Odczyt wartości w motywie

```php
<?php the_theme_mod('modo_footer_text'); // wypisuje wartość z escapowaniem ?>
<?php $v = get_theme_mod('modo_footer_text', 'Domyślny tekst'); // zwraca wartość ?>
```

Pola z kluczem `css_var` są automatycznie wypisywane w `<head>` jako zmienne CSS `:root` (funkcja `customizer_css()`, wywoływana w `theme_head()`), co daje natychmiastowy podgląd zmian kolorów i rozmiarów bez przeładowania strony. Wartości publikowane są w tabeli `theme_mods`, a szkic podglądu trzymany jest w sesji zalogowanego administratora.

---

## 🧭 Frontend Admin Bar

Zalogowani użytkownicy widzą na stronie (frontend) pasek administratora analogiczny do WordPressa. Renderuje go klasa `Core\AdminBar` (wpięta w `theme-header`/`theme-footer`), więc działa automatycznie w każdym motywie, który wywołuje `theme_head()` i `theme_footer()`.

Skróty: **Dashboard**, **+ Nowy** *(manage_pages)*, **Edytuj** (bieżącą stronę/wpis), **Edytuj ustawienia szablonu** *(manage_settings)* — otwiera frontendowy sidebar Customizera na bieżącej stronie, profil użytkownika oraz **Wyloguj**. Pasek domyślnie wypycha treść strony (`margin-top`) i można go zwinąć (stan zapamiętywany w `localStorage`). Nie jest wyświetlany w panelu admina ani dla niezalogowanych/gości.

---

## 📝 Licencja

Projekt dystrybuowany na licencji zgodnej z repozytorium źródłowym.