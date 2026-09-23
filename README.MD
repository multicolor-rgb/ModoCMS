# Clean CMS

[Polski](#polski) | [English](#english)

---

<a name="polski"></a>
## 🇵🇱 Wersja Polska

**Clean CMS** to lekki, niezwykle szybki i modułowy system zarządzania treścią (CMS) oparty na **PHP 8.x** oraz bazie danych **SQLite3**. Stworzony z myślą o programistach i twórcach stron, którzy cenią minimalistyczną architekturę opartą na funkcjach proceduralnych, brak zbędnego narzutu obiektowego oraz pełną kontrolę nad kodem i wyglądem szablonów.

---

### 🚀 Główne cechy

* **Brak ciężkiego OOP:** Prosta, czytelna architektura oparta na proceduralnych funkcjach pomocniczych (`mod()`, `get_field()`, `title()`, `e()`).
* **SQLite3:** Szybka, bezserwerowa baza danych w jednym pliku – zerowa konfiguracja zewnętrznych serwerów baz, prosty backup i migracja.
* **Dynamiczny Theme Customizer:** Konfiguracja motywu definiowana przez plik `theme.json` w katalogu motywu, z wbudowanym podglądem na żywo (`iframe`) w panelu administracyjnym.
* **Wbudowane pola niestandardowe (Custom Fields):** Elastyczne parametry dla stron i wpisów (tekst, textarea, liczba, checkbox, kolor, obrazek) zintegrowane w edytorze pod przełącznikiem.
* **System Hooków (Akcje i Filtry):** Prosty i elastyczny system rozszerzeń (Plugins) oraz integracji z widokami panelu administratora i frontendu.
* **Bezpieczeństwo na pierwszym miejscu:** Pełna ochrona przed SQL Injection (Prepared Statements PDO), tokeny CSRF, walidacja sesji i uprawnień oraz blokady bezpośredniego wywoływania plików (`IN_CMS`).

---

### ⚙️ Wymagania systemowe

* PHP **8.0** lub nowszy
* Moduł PHP **pdo_sqlite** oraz **sqlite3**
* Serwer WWW: **Apache** (z włączonym modułem `mod_rewrite`) lub **Nginx**

---

### 📦 Instalacja krok po kroku

Instalacja systemu odbywa się automatycznie za pomocą wbudowanego kreatora `install.php`:

1. **Sklonuj lub wgraj pliki projektu** do katalogu serwera WWW:
   ```bash
   cd /var/www/html
   git clone git@github.com:multicolor-rgb/cleanCMS.git cleancms
   ```

2. **Ustaw uprawnienia zapisu** dla serwera WWW (np. użytkownika `www-data`):
   ```bash
   sudo chown -R www-data:www-data /var/www/html/cleancms
   sudo chmod -R 775 /var/www/html/cleancms
   ```

3. **Uruchom instalator w przeglądarce**:
   Otwórz przeglądarkę i przejdź pod adres:
   ```text
   http://twoja-domena.pl/install.php
   # lub lokalnie:
   http://localhost/cleancms/install.php
   ```

4. **Wypełnij formularz instalacyjny**:
   * Podaj tytuł witryny.
   * Ustaw login, adres e-mail oraz hasło administratora.
   * Kliknij przycisk instalacji – instalator utworzy strukturę bazy danych SQLite oraz domyślne ustawienia.

5. **Usuń skrypt instalacyjny ze względów bezpieczeństwa**:
   ```bash
   rm /var/www/html/cleancms/install.php
   ```

6. Przejdź do panelu administracyjnego pod adresem `/admin` i zaloguj się.

---

### 🎨 Tworzenie szablonów i Theme Customizer

Każdy motyw znajduje się w katalogu `/themes/{nazwa-motywu}/`. Aby dodać dynamiczne opcje customizera widoczne w panelu administratora, utwórz plik `theme.json`:

```json
{
    "name": "Mój Nowy Motyw",
    "slug": "moj-nowy-motyw",
    "version": "1.0.0",
    "customizer": [
        {
            "id": "accent_color",
            "label": "Główny kolor akcentu",
            "type": "color",
            "default": "#0d6efd"
        },
        {
            "id": "hero_title",
            "label": "Nagłówek sekcji Hero",
            "type": "text",
            "default": "Witaj w Clean CMS"
        },
        {
            "id": "enable_sidebar",
            "label": "Wyświetlaj panel boczny",
            "type": "checkbox",
            "default": "1"
        }
    ]
}
```

#### Szybkie funkcje pomocnicze w szablonie

* **Pobieranie opcji Customizera:**
  ```php
  <style>
      :root { --bs-primary: <?= mod('accent_color', '#0d6efd') ?>; }
  </style>
  ```
* **Warunkowe sprawdzanie przełącznika:**
  ```php
  <?php if (mod('enable_sidebar', '1') === '1'): ?>
      <aside class="sidebar"><?php /* ... */ ?></aside>
  <?php endif; ?>
  ```
* **Podstawowe dane strony:**
  ```php
  <h1><?= title() ?></h1>
  <p><?= desc() ?></p>
  <a href="<?= home_url() ?>">Strona główna</a>
  <link rel="stylesheet" href="<?= theme_url() ?>/style.css">
  ```
* **Pola niestandardowe (Custom Fields):**
  ```php
  <?php if ($podtytul = get_field('podtytul')): ?>
      <h2><?= e($podtytul) ?></h2>
  <?php endif; ?>

  <!-- Lub bezpośredni wypis: -->
  <span><?php the_field('cena'); ?></span>
  ```

---

### 🔌 Tworzenie wtyczek (Plugins)

Wtyczki umieszczane są w katalogu `/plugins/{nazwa-wtyczki}/{nazwa-wtyczki}.php`.

```php
<?php
if (!defined('IN_CMS')) {
    die();
}

// Wstrzyknięcie skryptu/stylu w sekcji <head> frontendu
Hooks::addAction('theme-header', function() {
    echo '<meta name="generator" content="Clean CMS Plugin">';
});

// Dodanie pozycji w menu panelu administracyjnego
Hooks::addAction('plugins-sidebar', function() {
    echo '<a href="plugins.php?id=moja-wtyczka" class="nav-link">Moja Wtyczka</a>';
});

// Podstrona konfiguracji wtyczki w panelu
Hooks::addAction('admin-plugin-page-moja-wtyczka', function() {
    echo '<div class="page-header"><h1 class="page-title">Ustawienia Wtyczki</h1></div>';
    echo '<div class="card p-3"><p>Zawartość panelu wtyczki...</p></div>';
});
```

---

<a name="english"></a>
## 🇬🇧 English Version

**Clean CMS** is a lightweight, ultra-fast, and modular Content Management System built with **PHP 8.x** and **SQLite3**. Designed for developers and site creators who prefer a simple, procedural helper-driven architecture without the unnecessary overhead of complex OOP frameworks.

---

### 🚀 Key Features

* **No Heavy OOP:** Minimalist, transparent architecture powered by procedural helpers (`mod()`, `get_field()`, `title()`, `e()`).
* **SQLite3 Database:** Fast, zero-setup flat-file database – easy to backup, clone, and migrate.
* **Dynamic Theme Customizer:** Theme options are defined via a simple `theme.json` file and rendered dynamically in the admin area with live `iframe` preview.
* **Built-in Custom Fields:** Flexible document meta fields (text, textarea, number, checkbox, color, image) available inside the page/post editor under a toggleable switch.
* **Hooks & Plugin Architecture:** Extensible action/filter system for both frontend rendering and backend integration.
* **Security Focused:** Prepared statements via PDO to prevent SQLi, CSRF tokens, strict authentication, and access guardrails (`IN_CMS`).

---

### ⚙️ Requirements

* PHP **8.0** or higher
* PHP extensions: **pdo_sqlite** and **sqlite3**
* Web server: **Apache** (with `mod_rewrite` enabled) or **Nginx**

---

### 📦 Installation Guide

Clean CMS includes a guided installer script (`install.php`):

1. **Clone or copy the project** into your web server directory:
   ```bash
   cd /var/www/html
   git clone git@github.com:multicolor-rgb/cleanCMS.git cleancms
   ```

2. **Set appropriate directory permissions** for the web server user (e.g., `www-data`):
   ```bash
   sudo chown -R www-data:www-data /var/www/html/cleancms
   sudo chmod -R 775 /var/www/html/cleancms
   ```

3. **Run the installer in your browser**:
   Navigate to:
   ```text
   http://your-domain.com/install.php
   # or locally:
   http://localhost/cleancms/install.php
   ```

4. **Complete the setup form**:
   * Enter site title.
   * Provide administrator credentials (username, email, password).
   * Submit the form to initialize the SQLite database schema and initial settings.

5. **Remove the installer script** for security reasons:
   ```bash
   rm /var/www/html/cleancms/install.php
   ```

6. Log in to the administration dashboard at `/admin`.

---

### 🎨 Themes & Customizer

Themes reside in `/themes/{theme-folder}/`. To declare customizable settings inside the admin dashboard, create a `theme.json` configuration file inside your theme:

```json
{
    "name": "My Custom Theme",
    "slug": "my-custom-theme",
    "version": "1.0.0",
    "customizer": [
        {
            "id": "accent_color",
            "label": "Primary Accent Color",
            "type": "color",
            "default": "#0d6efd"
        },
        {
            "id": "hero_title",
            "label": "Hero Headline",
            "type": "text",
            "default": "Welcome to Clean CMS"
        },
        {
            "id": "enable_sidebar",
            "label": "Enable Sidebar",
            "type": "checkbox",
            "default": "1"
        }
    ]
}
```

#### Template Tags

* **Fetch Customizer Option:**
  ```php
  <style>
      :root { --bs-primary: <?= mod('accent_color', '#0d6efd') ?>; }
  </style>
  ```
* **Toggle Condition:**
  ```php
  <?php if (mod('enable_sidebar', '1') === '1'): ?>
      <aside class="sidebar"><?php /* ... */ ?></aside>
  <?php endif; ?>
  ```
* **Site Metadata & Assets:**
  ```php
  <h1><?= title() ?></h1>
  <p><?= desc() ?></p>
  <a href="<?= home_url() ?>">Home</a>
  <link rel="stylesheet" href="<?= theme_url() ?>/style.css">
  ```
* **Custom Meta Fields:**
  ```php
  <?php if ($subtitle = get_field('subtitle')): ?>
      <h2><?= e($subtitle) ?></h2>
  <?php endif; ?>

  <!-- Direct output: -->
  <span><?php the_field('price'); ?></span>
  ```

---

### 🔌 Writing Plugins

Create a folder and PHP file in `/plugins/{plugin-name}/{plugin-name}.php`:

```php
<?php
if (!defined('IN_CMS')) {
    die();
}

// Inject tags into frontend <head>
Hooks::addAction('theme-header', function() {
    echo '<meta name="generator" content="Clean CMS Plugin">';
});

// Add a navigation item in admin sidebar
Hooks::addAction('plugins-sidebar', function() {
    echo '<a href="plugins.php?id=my-plugin" class="nav-link">My Plugin</a>';
});

// Render settings page in admin
Hooks::addAction('admin-plugin-page-my-plugin', function() {
    echo '<div class="page-header"><h1 class="page-title">Plugin Settings</h1></div>';
    echo '<div class="card p-3"><p>Plugin configuration controls go here...</p></div>';
});
```

---

## 📄 License

Clean CMS is open-source software licensed under the **MIT License**.
