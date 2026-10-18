# Hooki — referencja (akcje i filtry)

**Polski** | [English](../en/hooks.md)

**Hook** to nazwany punkt w kodzie, w który mogą wpinać się pluginy. Modo CMS ma dwa rodzaje:

* **Akcje** — coś się dzieje; Twoja funkcja działa dla efektu ubocznego. Subskrybuj przez `Hooks::addAction($hook, $callback, $priority = 10)`.
* **Filtry** — powstaje wartość; Twoja funkcja ją dostaje i **zwraca** (ewentualnie zmienioną). Subskrybuj przez `Hooks::addFilter($filter, $callback, $priority = 10)`.

Łagodne wprowadzenie znajdziesz w [Tworzenie pluginów](pluginy.md#4-system-hookow-corehooks). Ta strona to **pełna lista** hooków dostarczanych przez rdzeń.

> Pierwsza kolumna tabeli to dokładna nazwa hooka, którą podajesz do `addAction()` / `addFilter()`.

---

## Akcje

| Hook | Odpala się w | Typowe użycie |
|---|---|---|
| `theme_head` | `theme_head()` (`core/frontend.php`) | Wstrzyknięcie zasobów do `<head>`. `Core\CssFramework` używa go z priorytetem `5`. |
| `theme-header` | `theme_head()` oraz `get_header()` | Wstrzyknięcie CSS/meta do `<head>` (główny punkt wejścia pluginów na zasoby head). |
| `theme_footer` | `theme_footer()` | Wstrzyknięcie skryptów przed `</body>`. |
| `theme-footer` | `theme_footer()` / `get_footer()` | Główny punkt wejścia pluginów na skrypty stopki. |
| `plugins_loaded` | `PluginLoader::loadActivePlugins()` | Odpala się raz, po załadowaniu wszystkich aktywnych pluginów — dobre miejsce na rejestrację stron/menu panelu. |
| `before_the_content` | Renderowanie treści (mapowane z GetSimple `content-top`) | Wyjście tuż przed treścią strony. |
| `after_the_content` | Renderowanie treści (mapowane z GetSimple `content-bottom`) | Wyjście tuż po treści strony. |
| `before_render_menu` | `menu()` (`core/frontend.php`) | Przed renderowaniem menu (dostaje slug menu). |
| `after_render_menu` | `menu()` | Po renderowaniu menu (dostaje slug menu). |
| `admin_head` | `admin/views/header.php` | Wstrzyknięcie CSS/JS do `<head>` panelu. |
| `admin_footer` | `admin/views/footer.php` | Wstrzyknięcie skryptów na końcu stron panelu. |
| `admin_dashboard_widgets` | `admin/index.php` | Dodanie widgetów do pulpitu. |
| `admin_dashboard_sidebar` | `admin/index.php` | Dodanie treści do sidebaru pulpitu. |
| `admin-edit-form` | `admin/page-edit.php`, `admin/snippets.php` | Dodanie pól/UI do edytora strony/wpisu/snippetu (dostaje id rekordu). |
| `admin-edit-form-content` | `admin/page-edit.php` | Dodanie UI do zakładki treści edytora (dostaje id). |
| `admin-save-page` | `admin/page-edit.php`, `core/Revisions.php` | Reakcja po zapisaniu strony (np. zapis pól niestandardowych). |
| `admin_page_edit_bottom` | Mapowane z GetSimple `edit-extras` | Dół strony edycji (zgodność). |
| `admin_plugin_view_<id>` | `admin/plugins.php` | Renderowanie własnego ekranu ustawień wtyczki (`?id=<id>`). Podpinane automatycznie przez `register_plugin()`. |
| `pages-sidebar` / `plugins-sidebar` / `settings-sidebar` / `theme-sidebar` | `render_admin_plugin_navigation()` | Dodanie własnych pozycji do odpowiednich sekcji menu panelu. |
| `nav-tab` | `render_admin_plugin_navigation()` | Dodanie zakładki nawigacji w panelu. |
| `modo_customize_register` | `Customizer::loadCodeSources()` | Rejestracja sekcji/kontrolek customizera (patrz niżej). |

### Rejestracja kontrolek customizera

`modo_customize_register` to akcja, ale używa się jej zwykle razem z `Customizer::registerSection()` / `registerControl()`:

```php
use Core\Hooks;
use Core\Customizer;

Hooks::addAction('modo_customize_register', function () {
    Customizer::registerSection(['id' => 'plugin', 'title' => 'Wtyczka']);
    Customizer::registerControl([
        'id'      => 'plugin_show_banner',
        'type'    => 'checkbox',
        'label'   => 'Pokaż banner',
        'section' => 'plugin',
        'default' => '1',
    ]);
});
```

---

## Filtry

| Filtr | Dostaje | Dodatkowe argumenty | Odpala się w |
|---|---|---|---|
| `the_content` | HTML wpisu/strony (string) | — | `page_content()` (`core/frontend.php`). Rdzeń słucha z priorytetem `1` (`EditorJs`) i `5` (`render_snippets`). |
| `the_title` | Tytuł (string) | — | `page_title()` |
| `the_excerpt` | Wyciąg (string) | — | `post_excerpt()` |
| `page_date` | Sformatowana data (string) | — | `page_date()` |
| `page_author` | Nazwa autora (string) | — | `page_author()` |
| `featured_image_url` | URL obrazka (string) | — | `page_image()` |
| `site_title` | Tytuł strony (string) | — | `site_title()` |
| `site_description` | Opis strony (string) | — | `site_desc()` |
| `clean_menu_raw_items` | Wiersze elementów menu (array) | slug menu (string) | `menu()`, `get_theme_menu_items()` |
| `modo_customize_schema` | Całe drzewo schematu `['sections' => [...]]` | — | `Customizer::loadCodeSources()` — wstrzyknij cały schemat zamiast pojedynczych kontrolek |
| `modo_advanced_options` | Definicje opcji zaawansowanych (array) | — | `core/Config.php` — rozbudowa strony ustawień zaawansowanych |
| `theme_mod_<key>` | Pojedyncza wartość customizera | — | `Customizer::getMod()` — dynamiczne filtrowanie jednego ustawienia, np. `theme_mod_modo_footer_text` |

### Przykład — modyfikacja tytułu

```php
\Core\Hooks::addFilter('the_title', function (string $title): string {
    return '★ ' . $title;
}, 20);
```

### Przykład — odebranie dodatkowego argumentu menu

```php
\Core\Hooks::addFilter('clean_menu_raw_items', function (array $items, string $slug): array {
    if ($slug === 'main-menu') {
        // zmiana kolejności / nazw elementów…
    }
    return $items;
});
```

---

## Tworzenie własnych hooków (zaawansowane)

Pluginy i motywy mogą definiować **własne** hooki, żeby inny kod mógł je rozbudować, używając `Hooks::doAction()` / `Hooks::applyFilters()`:

```php
use Core\Hooks;

// Gdzieś w Twoim pluginie:
Hooks::doAction('myplugin_after_save', $itemId);

$html = Hooks::applyFilters('myplugin_button_html', $html, $itemId);
// …dalej użyj $html
```

Inny kod subskrybuje wtedy przez `addAction('myplugin_after_save', …)` / `addFilter('myplugin_button_html', …)`.

> Trzymaj konwencję nazw: prefiks z id pluginu (np. `myplugin_`), żeby uniknąć kolizji.

---

## Mostek GetSimple (zgodność)

Jeśli piszesz w stylu GetSimple, `add_action()` / `add_filter()` tłumaczą klasyczne nazwy na powyższe hooki. Pełne mapowanie jest w [Wtyczki w stylu GetSimple](wtyczki.md#5-akcje-i-filtry). Kluczowe mapowania:

| Nazwa GetSimple | Hook Modo |
|---|---|
| `theme-header` | `theme_head` |
| `theme-footer` | `theme_footer` |
| `header` | `admin_head` (w panelu) / `theme_head` |
| `footer` | `admin_footer` (w panelu) / `theme_footer` |
| `index-pretemplate` | `plugins_loaded` |
| `edit-extras` | `admin_page_edit_bottom` |
| `content-top` | `before_the_content` |
| `content-bottom` | `after_the_content` |
| `content` (filtr) | `the_content` |
| `title` (filtr) | `the_title` |
| `excerpt` (filtr) | `the_excerpt` |

---

## Ściągawka priorytetów

| Priorytet | Kiedy się wykonuje |
|---|---|
| `1` | Najpierw (konwersja formatu rdzenia) |
| `5` | Wcześnie (rozwijanie snippetów) |
| `10` | Domyślnie |
| `20` | Późno (shortcode'y pluginów) |
| `100+` | Bardzo późno / porządki |

---

## Dalej

* [Przykłady](przyklady.md)
* [Tworzenie pluginów](pluginy.md)
