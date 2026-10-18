# Tworzenie pluginów (natywne Modo)

**Polski** | [English](../en/plugins.md)

To przystępny przewodnik po pisaniu **natywnych pluginów Modo** z użyciem systemu hooków. Jeśli przenosisz istniejącą wtyczkę GetSimple, zobacz [Wtyczki w stylu GetSimple](wtyczki.md).

---

## 1. Czym jest plugin?

Plugin to mały plik PHP (lub folder), który **dodaje nowe zachowanie do Modo CMS bez modyfikowania rdzenia**. Wrzucasz go do katalogu `plugins/`, włączasz w panelu i zaczyna działać.

Wyobraź sobie CMS jako dom z **gniazdkami** (zwanymi *hookami*) w ścianach:

* Część gniazdek to gniazdka **„zrób coś”** (*akcje*). Gdy strona dochodzi do tego miejsca, CMS woła „hej, czy ktoś chce tu coś zrobić?” i każdy plugin, który słucha, reaguje.
* Inne gniazdka to gniazdka **„przekaż mi dane”** (*filtry*). CMS podaje wartość (np. HTML strony) każdemu słuchaczowi, a każdy może ją zmienić i przekazać dalej.

To cała idea: Twój plugin nie edytuje plików rdzenia — on **wpina się w gniazdko**.

---

## 2. Gdzie leży plugin?

`Core\PluginLoader` przeszukuje katalog `plugins/` i ładuje plik wejściowy każdego **aktywnego** pluginu. Plugin można zorganizować na trzy sposoby:

| Układ | Plik wejściowy, który zostanie załadowany |
|---|---|
| Pojedynczy plik | `plugins/hello-world.php` |
| Folder o nazwie pluginu | `plugins/modo-form/modo-form.php` |
| Folder z ogólnym plikiem wejściowym | `plugins/modo-gallery/plugin.php` lub `plugins/modo-gallery/index.php` |

Loader sprawdza w dokładnie tej kolejności: `plugins/<nazwa>.php`, potem `plugins/<nazwa>/<nazwa>.php`, potem `plugins/<nazwa>/plugin.php`, potem `plugins/<nazwa>/index.php`.

> **Identyfikatorem** pluginu jest nazwa pliku bez `.php` (`hello-world`) albo nazwa folderu (`modo-form`). Ten identyfikator będzie potrzebny do aktywacji.

Plugin folderowy zwykle trzyma własne zasoby obok pliku wejściowego:

```
plugins/
└── my-plugin/
    ├── my-plugin.php        ← plik wejściowy (ładowany automatycznie)
    └── assets/
        ├── css/style.css
        └── js/script.js
```

---

## 3. Aktywacja pluginu

1. Umieść plik/folder w `plugins/`.
2. W panelu otwórz **Wtyczki** (`/admin/plugins.php`).
3. Kliknij przełącznik obok swojego pluginu. Ustawi to `is_active = 1` w tabeli `plugins`.
4. Odśwież frontend — plugin działa.

Lista aktywnych to po prostu flaga w bazie; sam kod jest ładowany przy każdym żądaniu przez `PluginLoader::loadActivePlugins()`, który następnie wywołuje akcję `plugins_loaded`.

---

<a id="4-system-hookow-corehooks"></a>

## 4. System hooków (`Core\Hooks`)

Wszystkie hooki żyją w klasie statycznej `Core\Hooks`. Do subskrypcji używasz dwóch metod:

```php
<?php
use Core\Hooks;

// Akcje: „gdy X się zdarzy, uruchom moją funkcję”
Hooks::addAction(string $hook, callable $callback, int $priority = 10): void;

// Filtry: „gdy powstaje wartość X, pozwól mi ją zmienić”
Hooks::addFilter(string $filter, callable $callback, int $priority = 10): void;
```

### Akcja vs. filtr — różnica

* **Akcja** — Twoja funkcja działa *dla efektu ubocznego* (wypisuje HTML, wysyła e-mail, zapisuje dane). **Nie** zwracasz wartości. Użyj `Hooks::addAction()`.
* **Filtr** — Twoja funkcja dostaje wartość, opcjonalnie ją zmienia i **musi ją zwrócić**. Użyj `Hooks::addFilter()`.

```php
// AKCJA: wstaw coś do <head>. Nic nie zwraca.
Hooks::addAction('theme-header', function () {
    echo '<link rel="stylesheet" href="' . plugin_url('my-plugin') . '/assets/css/style.css">';
});

// FILTR: dopisz podpis do każdego wpisu. Musi zwrócić string.
Hooks::addFilter('the_content', function (string $content): string {
    return $content . '<p>— Dziękujemy za lekturę!</p>';
});
```

### Priorytety

Trzeci argument to **priorytet** (domyślnie `10`). Niższe liczby wykonują się pierwsze.

| Priorytet | Typowe użycie |
|---|---|
| `1` | Bardzo wcześnie (np. konwersja formatu treści) |
| `5` | Wczesne przetwarzanie |
| `10` | Domyślnie |
| `20` | Późno — po innych pluginach, np. renderowanie shortcode'ów |

Przykład: `Core\EditorJs` zamienia dokumenty blokowe na HTML z priorytetem `1`, zanim rozwiną się snippety (`5`) i shortcode'y wtyczek (`20`).

### Pełne API `Hooks`

```php
Hooks::addAction($hook, $callback, $priority = 10);   // subskrybuj akcję
Hooks::hasAction($hook);                               // bool — czy ktoś słucha?
Hooks::doAction($hook, ...$args);                      // wywołaj akcję (rdzeń/zaawansowane)

Hooks::addFilter($filter, $callback, $priority = 10);  // subskrybuj filtr
Hooks::hasFilter($filter);                             // bool
Hooks::applyFilters($filter, $value, ...$args);        // przepuść wartość przez filtry (rdzeń/zaawansowane)
```

W pluginie używasz zwykle tylko `addAction()` i `addFilter()`. Metody `do*`/`has*` są dla rdzenia lub bardzo zaawansowanych rozszerzeń.

Callback filtra dostaje wartość **oraz dodatkowe argumenty**, które przekazał rdzeń:

```php
Hooks::addFilter('clean_menu_raw_items', function (array $items, string $slug): array {
    // $slug to dodatkowy argument przekazany przez renderowanie menu
    return $items;
});
```

---

## 5. Twój pierwszy plugin — „Hello World”

Utwórz `plugins/hello-world.php`:

```php
<?php
/**
 * Plugin Name: Hello World Filter
 * Description: Demonstruje hooki i filtrowanie treści w Modo CMS.
 *
 * @package HelloWorld
 */
declare(strict_types=1);

use Core\Hooks;

Hooks::addFilter('the_content', function (string $content): string {
    // Nie ruszaj panelu admina.
    if (defined('IN_ADMIN') && IN_ADMIN === true) {
        return $content;
    }
    return $content . '<p style="color:#64748b;font-style:italic;">Powered by Modo CMS</p>';
});
```

Aktywuj go w **Wtyczki** i każdy wpis będzie się kończył tym wersem.

> 💡 `IN_ADMIN` jest ustawione na `true` w każdym pliku panelu (np. `admin/plugins.php`). Warunek tym chroni plugin przed działaniem w panelu.

---

## 6. Zalecana struktura pluginu

Dla czegokolwiek większego niż fragment użyj folderu i klasy zabezpieczonej przed podwójnym załadowaniem:

```php
<?php
/**
 * Plugin Name: My Plugin
 * Description: Co robi w jednym zdaniu.
 * Version: 1.0.0
 * Author: Twoje Imię
 *
 * @package MyPlugin
 */
declare(strict_types=1);

if (!class_exists('MyPlugin')) {

    final class MyPlugin
    {
        public const PLUGIN_ID = 'my-plugin';
        public const VERSION   = '1.0.0';

        private static bool $booted = false;

        public static function init(): void
        {
            if (self::$booted) { return; }
            self::$booted = true;

            if (defined('IN_ADMIN') && IN_ADMIN === true) {
                self::bootAdmin();
            } else {
                self::bootFrontend();
            }
        }

        private static function bootFrontend(): void
        {
            \Core\Hooks::addFilter('the_content', [self::class, 'renderShortcode'], 20);
            \Core\Hooks::addAction('theme-header', [self::class, 'headAssets']);
        }

        private static function bootAdmin(): void
        {
            \Core\Hooks::addAction('admin_head', [self::class, 'adminAssets']);
        }

        public static function renderShortcode(string $content): string { return $content; }
        public static function headAssets(): void {}
        public static function adminAssets(): void {}
    }

    MyPlugin::init();
}
```

Dobre nawyki:

* **`declare(strict_types=1);`** na górze każdego pliku.
* Owijaj klasę w **`if (!class_exists(...))`**, żeby plik można było bezpiecznie dołączyć dwukrotnie.
* **Rozdziel ścieżki** panelu i frontendu przez `IN_ADMIN`.
* Zrób `init()` **idempotentne** przez `private static bool $booted`.
* Używaj callable tablicowych `[self::class, 'method']` — są leniwe i czytelne.

---

## 7. Budowanie URL-i zasobów

Nigdy nie wpisuj na sztywno `/plugins/...`. Skorzystaj z helpera bazowego podkatalogu routera, żeby plugin działał też w podkatalogu:

```php
public static function assetsUrl(): string
{
    $base = class_exists('\Core\Router') ? rtrim(\Core\Router::getBaseSubdirectory(), '/') : '';
    return $base . '/plugins/' . self::PLUGIN_ID . '/assets';
}
```

Następnie wstrzykuj skrypty/style na hookach frontendu:

```php
\Core\Hooks::addAction('theme-footer', function () {
    echo '<script src="' . MyPlugin::assetsUrl() . '/js/script.js" defer></script>';
});
```

Punkty wstrzyknięcia na frontendzie to `theme-header` (w `<head>`) i `theme-footer` (przed `</body>`), wywoływane przez `theme_head()` / `theme_footer()`.

### Mały helper URL

Jeśli wolisz krótką funkcję globalną, zdefiniuj ją raz w swoim pluginie:

```php
if (!function_exists('plugin_url')) {
    function plugin_url(string $id): string {
        $base = class_exists('\Core\Router') ? rtrim(\Core\Router::getBaseSubdirectory(), '/') : '';
        return $base . '/plugins/' . $id;
    }
}
```

---

## 8. Shortcode'y przez `the_content`

Najczęstsza funkcja frontendu to shortcode, np. `[my_box]Cześć[/my_box]`. Zarejestruj go jako filtr `the_content`:

```php
\Core\Hooks::addFilter('the_content', function (string $content): string {
    return preg_replace_callback(
        '/\[my_box\](.*?)\[\/my_box\]/is',
        static fn (array $m): string => '<div class="my-box">' . $m[1] . '</div>',
        $content
    );
}, 20);
```

Użyj **wysokiego priorytetu (20)**, żeby zadziałało po wbudowanym rozwijaniu snippetów (`render_snippets`, priorytet 5) i po konwersji edytora blokowego (priorytet 1).

---

## 9. Dodanie strony pluginu do menu panelu

Jeśli plugin potrzebuje własnego ekranu w panelu, zarejestruj go dwoma helperami z warstwy zgodności GetSimple (`core/GetSimpleCompat.php`), dostępnymi globalnie:

```php
\Core\Hooks::addAction('plugins_loaded', function () {
    if (!function_exists('register_plugin') || !function_exists('createSideMenu')) {
        return;
    }

    register_plugin(
        'my-plugin',                       // id (musi zgadzać się z identyfikatorem folderu/pliku)
        'My Plugin',                       // nazwa czytelna dla człowieka
        '1.0.0',                           // wersja
        'Twoje Imię',                      // autor
        '',                                // URL autora
        'Krótki opis.',                    // opis
        'settings',                        // typ strony (dowolna etykieta)
        'MyPlugin::renderAdmin'            // callback renderujący ekran ustawień
    );

    createSideMenu(
        'my-plugin',                       // id
        'My Plugin',                       // etykieta w menu
        'my-plugin',                       // akcja
        '<svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>'
    );
});
```

* `register_plugin(...)` zapisuje metadane pokazywane na ekranie **Wtyczki**; ostatni argument jest podpięty pod akcję `admin_plugin_view_<id>`.
* `createSideMenu(...)` dodaje link do menu panelu (ostatni argument to inline SVG; bez niego użyta zostanie domyślna ikona pluginu).
* Renderer zaimplementuj jako `public static function renderAdmin(): void`, który wypisuje Twój HTML (i obsługuje wysyłkę formularza przed jakimkolwiek wyjściem).

Pełny opis tych helperów znajduje się w [Wtyczki w stylu GetSimple](wtyczki.md#4-referencja-helperow).

---

## 10. Przechowywanie ustawień

### Ponowne użycie globalnej tabeli ustawień

Dla kilku opcji `Router::getOption()` czyta z tabeli `settings`:

```php
use Core\Router;

$enabled = Router::getOption('myplugin_enabled', '1') === '1';
```

Zapis opcji rób przez PDO z prepared statement (INSERT/UPDATE tabeli `settings`). Dla czegoś poważniejszego wybierz własną tabelę:

```php
use Core\Database;

$db = Database::getConnection();
$db->exec("CREATE TABLE IF NOT EXISTS myplugin_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
)");

$stmt = $db->prepare("INSERT INTO myplugin_items (title) VALUES (:t)");
$stmt->execute([':t' => $title]);
```

Tabele twórz zawsze przez `CREATE TABLE IF NOT EXISTS` przy starcie — pluginy są samodzielne i mogą działać, zanim administrator wejdzie na Twój ekran.

---

## 11. Integracja z Theme Customizerem

Możesz dodać własne kontrolki do customizera na żywo przez akcję `modo_customize_register`:

```php
use Core\Hooks;
use Core\Customizer;

Hooks::addAction('modo_customize_register', function () {
    Customizer::registerSection(['id' => 'myplugin', 'title' => 'My Plugin', 'priority' => 100]);

    Customizer::registerControl([
        'id'      => 'myplugin_show_banner',
        'type'    => 'checkbox',
        'label'   => 'Pokaż banner',
        'section' => 'myplugin',
        'default' => '1',
    ]);

    Customizer::registerControl([
        'id'      => 'myplugin_accent',
        'type'    => 'color',
        'label'   => 'Kolor akcentu',
        'section' => 'myplugin',
        'default' => '#0d6efd',
        'css_var' => 'myplugin-accent',   // wypisane jako zmienna CSS :root
    ]);
});
```

Odczytaj wartość w motywie lub pluginie przez `get_theme_mod()`:

```php
$show = get_theme_mod('myplugin_show_banner', '0') === '1';
```

Opisy `get_theme_mod`, `the_theme_mod` i `customizer_css` znajdziesz w [Funkcje motywu](funkcje-motywu.md#10-helpery-customizera).

---

## 12. Tłumaczenie tekstów

Teksty widoczne dla użytkownika opakuj w globalne helpery `__()` / `_e()` i dodaj klucze do `languages/*.json` (klucz = angielski tekst źródłowy):

```php
echo '<h2>' . __('My Plugin') . '</h2>';
_e('Save changes');   // wypisuje z escapowaniem
```

---

## 13. Checklista przed publikacją

* [ ] Nazwa pliku/folderu wejściowego zgadza się z identyfikatorem aktywowanym w panelu.
* [ ] `declare(strict_types=1);` i guard `class_exists` (dla pluginów klasowych).
* [ ] Ścieżki admina i frontendu rozdzielone przez `IN_ADMIN`.
* [ ] Wszystkie URL-e budowane helperem bazowego podkatalogu (działa w subfolderze).
* [ ] Całe wyjście escapowane przez `htmlspecialchars($v, ENT_QUOTES, 'UTF-8')`.
* [ ] Cały SQL przez prepared statements.
* [ ] Teksty tłumaczalne.
* [ ] Bez zasobów z CDN.
* [ ] Brak zapisu do plików rdzenia — tylko hooki.

---

## Dalej

* [Wtyczki w stylu GetSimple](wtyczki.md)
* [Hooki — referencja](hooki.md)
* [Przykłady](przyklady.md)
