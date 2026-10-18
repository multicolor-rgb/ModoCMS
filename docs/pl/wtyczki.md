# Tworzenie wtyczek w stylu GetSimple

**Polski** | [English](../en/getsimple-plugins.md)

Modo CMS zawiera **warstwę zgodności z GetSimple** (`core/GetSimpleCompat.php`). Dzięki niej wtyczki napisane dla **GetSimple CMS** działają na Modo niemal bez zmian, a jednocześnie udostępnia wygodne globalne helpery (`register_plugin`, `createSideMenu`, `i18n_merge`), z których korzystają też natywne pluginy.

Użyj tego stylu, gdy **przenosisz istniejącą wtyczkę GetSimple** lub wolisz klasyczne API proceduralne. Dla zupełnie nowych pluginów zalecany jest [natywny styl hooków](pluginy.md).

---

## 1. Co daje ta warstwa

| Obszar | Funkcje / stałe |
|---|---|
| Środowisko | `IN_GS`, `GSVERSION`, `GSPLUGINPATH`, `GSDATAOTHERPATH`, `GSDATAPAGESPATH`, `$GLOBALS['SITEURL']` |
| Rejestracja i UI panelu | `register_plugin()`, `createSideMenu()`, `create_side_menu()`, `createNavTab()`, `render_admin_plugin_navigation()`, `render_admin_sidebar_icon()` |
| Akcje i filtry | `add_action()`, `exec_action()`, `add_filter()`, `exec_filter()` |
| Lokalizacja | `i18n_merge()`, `i18n_r()`, `i18n()` |
| Ścieżki, URL-e i motyw | `get_site_url()`, `find_url()`, `get_header()`, `get_footer()`, `get_page_slug()`, `get_page_content()` |
| Dane i XML | `getXML()`, `XMLsave()` |

Wszystkie są zdefiniowane globalnie (bez namespace), więc wtyczka GetSimple wywołuje je bezpośrednio.

---

## 2. Stałe środowiska

| Stała | Znaczenie w Modo |
|---|---|
| `IN_GS` | `true` — wtyczki GetSimple sądzą, że działają w GetSimple. |
| `GSVERSION` | Zgłaszane jako `3.3.16`. |
| `GSPLUGINPATH` | Ścieżka absolutna do `plugins/`. |
| `GSDATAOTHERPATH` | `data/other/` (tworzone automatycznie). |
| `GSDATAPAGESPATH` | `data/pages/` (tworzone automatycznie). |
| `$GLOBALS['SITEURL']` | Bazowy URL strony z ukośnikiem na końcu, świadomy podkatalogu. |

Wtyczki sprawdzające na górze `if (!defined('IN_GS')) die();` zadziałają więc bez zmian.

---

## 3. Rejestracja wtyczki

`register_plugin()` zapisuje metadane (pokazywane na ekranie **Wtyczki**) i opcjonalnie podpina renderer strony ustawień:

```php
register_plugin(
    string $id,
    string $name,
    string $version,
    string $author,
    string $author_url,
    string $description,
    string $page_type = '',
    string $load_function = ''
): void;
```

Gdy `$load_function` jest callable, zostaje podpięty pod akcję `admin_plugin_view_<id>`. Akcję tę wywołuje `admin/plugins.php`, gdy panel otwarty jest z `?id=<id>`, więc funkcja staje się **stroną ustawień** wtyczki.

```php
register_plugin(
    'my-gs-plugin',            // id — musi zgadzać się z identyfikatorem folderu/pliku w plugins/
    'My GS Plugin',
    '1.0.0',
    'Twoje Imię',
    '',
    'Przykładowa wtyczka używająca API GetSimple.',
    'settings',
    'MyGsPlugin::settings'     // renderowana przy ?id=my-gs-plugin
);
```

---

<a id="4-referencja-helperow"></a>

## 4. Referencja helperów

### `createSideMenu()`

Dodaje pozycję do menu panelu.

```php
createSideMenu(
    string $id,          // id wtyczki
    string $title,       // etykieta (tłumaczalna przez i18n_r)
    string $action = '', // domyślnie $id
    string $icon = ''    // inline SVG, ścieżka "d" SVG albo '' dla domyślnej ikony
): void;
```

Alias: `create_side_menu()`. Dostępny jest też zgodny z GetSimple `createNavTab($name, $plugin, $label, $action, $icon)`, który przekazuje do `createSideMenu()`.

Samo menu renderuje `render_admin_plugin_navigation()` (wywoływane z `admin/views/header.php`), które dodatkowo odpala akcje `pages-sidebar`, `plugins-sidebar`, `settings-sidebar`, `theme-sidebar` i `nav-tab`, więc możesz wstrzykiwać własne pozycje.

### `render_admin_sidebar_icon()`

Buduje znacznik ikony. Przyjmuje pełny string `<svg>`, samą ścieżkę `d` SVG albo pusty string (domyślna ikona puzzla).

```php
render_admin_sidebar_icon('<svg …></svg>');   // własny SVG
render_admin_sidebar_icon('M12 4v16m8-8H4');  // tylko ścieżka
render_admin_sidebar_icon();                  // ikona domyślna
```

---

## 5. Akcje i filtry

Warstwa zgodności mapuje nazwy hooków GetSimple na hooki Modo.

### `add_action( $hook, $function, $args = [] )`

```php
add_action('theme-footer', 'my_plugin_footer');
```

| Hook GetSimple | Mapowany na (Modo) |
|---|---|
| `theme-header` | `theme_head` |
| `theme-footer` | `theme_footer` |
| `header` | `admin_head` (w panelu) / `theme_head` (frontend) |
| `footer` | `admin_footer` (w panelu) / `theme_footer` (frontend) |
| `index-pretemplate` | `plugins_loaded` |
| `edit-extras` | `admin_page_edit_bottom` |
| `content-top` | `before_the_content` |
| `content-bottom` | `after_the_content` |
| *cokolwiek innego* | ta sama nazwa (przekazywane dalej) |

Jeśli hook odpala się z argumentami, są one przekazywane do Twojej funkcji; w przeciwnym razie użyta jest podana tablica `$args`.

### `add_filter( $filter, $function, $args = [] )`

```php
add_filter('content', 'my_plugin_filter_content');

function my_plugin_filter_content($content) {
    return $content . '<p>Dopisane.</p>';
}
```

| Filtr GetSimple | Mapowany na (Modo) |
|---|---|
| `content` | `the_content` |
| `title` | `the_title` |
| `excerpt` | `the_excerpt` |
| *cokolwiek innego* | ta sama nazwa (przekazywane dalej) |

> ⚠️ Sygnatura filtra GetSimple to `function($value)` — jeden argument. Wrapper przekazuje wyłącznie wartość; dodatkowe argumenty, które mógłby podać filtr Modo, nie są tu przekazywane. Jeśli ich potrzebujesz, subskrybuj bezpośrednio przez `Hooks::addFilter()` (patrz [natywne pluginy](pluginy.md)).

### `exec_action()` / `exec_filter()`

Ręczne wywołanie hooka:

```php
exec_action('my_custom_event', $arg1, $arg2);
$value = exec_filter('my_custom_filter', $value);
```

---

## 6. Lokalizacja (i18n)

`i18n_merge()` ładuje plik językowy z folderu `lang/` wtyczki i rejestruje wszystkie wpisy `$i18n[...]`:

```php
i18n_merge('my-gs-plugin');          // lub i18n_merge('my-gs-plugin', 'en')
i18n_merge('my-gs-plugin', 'en_US'); // jawny język zapasowy
```

Szuka w:

```
plugins/my-gs-plugin/lang/
plugins/my-gs-plugin/my-gs-plugin/lang/
```

pliku `<aktualny-locale>.php`, potem `en_US.php`, potem `en.php`. Aktualny locale pochodzi z `Core\I18n::getLocale()`.

Przykładowy plik języka (`plugins/my-gs-plugin/lang/en_US.php`):

```php
<?php
$i18n = [
    'MYPLUGIN_TITLE' => 'My Plugin',
    'MYPLUGIN_SAVED' => 'Settings saved.',
];
```

Używaj tekstów z prefiksem wtyczki:

```php
echo i18n_r('my-gs-plugin/MYPLUGIN_TITLE');  // zwraca tekst
i18n('my-gs-plugin/MYPLUGIN_TITLE');         // wypisuje go, z escapowaniem HTML
```

`i18n_r()` najpierw zagląda do rejestru, potem odcina prefiks i schodzi do tłumaczenia `__()` Modo.

---

## 7. Helpery ścieżek, URL-i i motywu

```php
get_site_url(bool $echo = false): string          // bazowy URL z ukośnikiem na końcu
find_url(string $slug, string $parent = ''): string // buduje wewnętrzny URL
get_header(): void                                // wywołuje theme_head()
get_footer(): void                                // wywołuje theme_footer()
get_page_slug(bool $echo = false): string         // slug aktualnej strony
get_page_content(): string                        // treść aktualnej strony
```

---

## 8. Helpery danych

```php
$xml = getXML($file);            // ścieżka pliku -> SimpleXMLElement|null
XMLsave($xml, $file);            // zapisuje XML na dysk
```

Istnieją dla zgodności z wtyczkami przechowującymi dane jako XML (częsty wzorzec GetSimple).

---

## 9. Pełny przykład

`plugins/my-gs-plugin/my-gs-plugin.php`:

```php
<?php
/**
 * Plugin Name: My GS Plugin
 * Description: Wtyczka w stylu GetSimple dla Modo CMS.
 * Version: 1.0.0
 * Author: Twoje Imię
 *
 * @package MyGsPlugin
 */
declare(strict_types=1);

if (!defined('IN_GS')) { define('IN_GS', true); } // zwykle już zdefiniowane

class MyGsPlugin
{
    public static function init(): void
    {
        // Rejestracja metadanych + renderera ustawień.
        register_plugin(
            'my-gs-plugin',
            'My GS Plugin',
            '1.0.0',
            'Twoje Imię',
            '',
            'Dodaje notkę w stopce i stronę ustawień.',
            'settings',
            [self::class, 'settings']
        );

        // Pozycja w menu.
        createSideMenu('my-gs-plugin', 'My GS Plugin', 'my-gs-plugin');

        // Frontend: dopisz notkę w stopce.
        add_action('theme-footer', [self::class, 'renderFooter']);

        // Frontend: zmodyfikuj treść wpisu.
        add_filter('content', [self::class, 'filterContent']);

        // Wczytaj tłumaczenia, jeśli są.
        if (function_exists('i18n_merge')) {
            i18n_merge('my-gs-plugin', 'en_US');
        }
    }

    public static function renderFooter(): void
    {
        echo '<div class="my-gs-footer">Witaj z wtyczki w stylu GetSimple</div>';
    }

    public static function filterContent(string $content): string
    {
        return $content . '<p><small>Przetworzone przez My GS Plugin</small></p>';
    }

    public static function settings(): void
    {
        echo '<h2>' . htmlspecialchars((string) i18n_r('my-gs-plugin/MYPLUGIN_TITLE'), ENT_QUOTES, 'UTF-8') . '</h2>';
        echo '<p>Ten ekran renderuje się przy ?id=my-gs-plugin.</p>';
    }
}

MyGsPlugin::init();
```

Aktywuj **my-gs-plugin** w panelu **Wtyczki** i otwórz go z menu.

---

## 10. Różnice i ograniczenia

* Nie całe API GetSimple jest emulowane. Funkcje strony takie jak `get_page_content()`, `get_header()`/`get_footer()` są dostępne, ale gdy wtyczka wywoła brakującą funkcję, pojawi się fatalny błąd „undefined function” — dodaj brakującą funkcję w pluginie lub przenieś go na [natywne API](pluginy.md).
* `add_filter()` przekazuje tylko filtrowaną wartość, bez dodatkowych argumentów.
* Piszesz zupełnie nowy plugin? Wybierz natywne API `Core\Hooks` — daje pełny zestaw hooków, priorytety i dodatkowe argumenty.

---

## Dalej

* [Natywne pluginy](pluginy.md)
* [Hooki — referencja](hooki.md)
* [Przykłady](przyklady.md)
