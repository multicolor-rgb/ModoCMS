# Ustawienia theme customizera

[English](../en/customizer.md) | **Polski**

Theme Customizer to panel „Edytuj ustawienia szablonu” dostępny dla
administratorów na stronie. Łączy trzy źródła w jeden schemat:

1. blok `customize` w pliku `theme.json` aktywnego motywu (zalecane dla ustawień
   dostarczanych razem z motywem),
2. sekcje i kontrolki rejestrowane przez plugin przez
   `Customizer::registerSection()` / `Customizer::registerControl()`,
3. ustawienia utworzone w builderze GUI w **Wygląd → Customizer → Zarządzaj
   ustawieniami** (zapisywane w bazie).

Opublikowane wartości trafiają do tabeli `theme_mods`. Niezapisane wartości
podglądu są trzymane w sesji użytkownika, więc podgląd nie zmienia publicznej
strony do momentu kliknięcia **Opublikuj**.

---

## Struktura schematu

```json
{
  "customize": {
    "sections": [
      {
        "id": "hero",
        "title": "Hero",
        "description": "Ustawienia sekcji hero na stronie głównej",
        "priority": 10,
        "controls": [
          {
            "id": "hero_title",
            "type": "text",
            "label": "Tytuł hero",
            "default": "Witaj",
            "priority": 10
          }
        ]
      }
    ]
  }
}
```

| Klucz | Wymagany | Opis |
|---|---|---|
| `id` | tak | Unikalny identyfikator. Używany przez `get_theme_mod()` — nie zmieniaj go. |
| `type` | tak | Jeden z typów poniżej. |
| `label` | tak | Etykieta widoczna w panelu. |
| `description` | nie | Krótki tekst pomocniczy pod polem. |
| `default` | nie | Wartość przed pierwszą publikacją. |
| `section` | tak w hookach | Id sekcji; w `theme.json` sekcja jest nadrzędna. |
| `priority` | nie | Niższe liczby są wyżej. Domyślnie `100`. |
| `options` | select/radio | Obiekt `wartość => etykieta`. |
| `min`, `max`, `step` | number/range | Limity liczbowe. |
| `css_var` | nie | Tworzy zmienną CSS `:root` i natychmiastowy podgląd. |
| `css_unit` | nie | Jednostka dopisywana do wartości liczbowych, np. `px`. |
| `link_types` | pagelink | `page`, `post` lub `page,post` (domyślnie). |

---

## Typy kontrolek

| Typ | Zapisywana wartość | Wygląd |
|---|---|---|
| `text` | zwykły tekst | pole jednoliniowe |
| `textarea` | zwykły tekst | pole wieloliniowe |
| `number` | liczba | pole liczbowe |
| `range` | liczba | suwak |
| `checkbox` / `toggle` | `1` / `0` | przełącznik |
| `select` | wartość opcji | lista wyboru |
| `radio` | wartość opcji | przyciski radio |
| `color` | `#rrggbb`, `rgb()`, `hsl()` | wybór koloru + pole tekstowe |
| `image` | URL/ścieżka mediów | wybór z biblioteki mediów |
| `url` | ścieżka lub `http(s)`, `mailto:`, `tel:` | pole URL |
| `email` | poprawny e-mail lub pusto | pole e-mail |
| `code` | surowy tekst | pole kodu |
| `tinymce` | oczyszczony HTML | edytor TinyMCE |
| `pagelink` | ścieżka względna strony | lista opublikowanych stron/wpisy |
| `menu` | slug menu | lista menu |
| `date` | `YYYY-MM-DD` | pole daty |
| `heading` | — | nagłówek (bez wartości) |
| `hr` | — | separator (bez wartości) |

`heading` i `hr` tylko porządkują panel i nigdy nie są zapisywane.

### Rich text (`tinymce`)

```json
{
  "id": "hero_text",
  "type": "tinymce",
  "label": "Treść hero",
  "default": "<p>Witaj na stronie.</p>"
}
```

Edytor jest dołączony lokalnie (bez CDN). Treść jest czyszczona przy zapisie:
usuwane są skrypty, ramki, formularze, atrybuty `on*` i adresy `javascript:`.
W motywie odczytaj ją helperem HTML:

```php
<div class="hero-text"><?php the_theme_mod_html('hero_text'); ?></div>
```

`the_theme_mod()` escapuje HTML, więc używaj go tylko do zwykłego tekstu.
Wartości `tinymce` są przeznaczone dla administratorów — nie używaj ich jako
pola na niezaufane dane odwiedzających.

### Linki wewnętrzne (`pagelink`)

```json
{
  "id": "hero_cta",
  "type": "pagelink",
  "label": "Cel przycisku hero",
  "link_types": "page,post",
  "default": "/o-nas"
}
```

Panel pokazuje pogrupowaną listę opublikowanych stron statycznych i wpisów.
Zapisywana jest ścieżka względna (w trybie wielojęzycznym zawiera już prefiks
języka). W motywie użyj helpera linku, aby działała też instalacja w podkatalogu:

```php
<a href="<?php the_theme_mod_link('hero_cta', '/'); ?>">Czytaj więcej</a>
```

`get_theme_mod_link()` pozostawia bez zmian adresy absolutne, `mailto:`,
`tel:` i `#fragment`, a do ścieżek wewnętrznych dokłada podkatalog instalacji.
Jeśli strona zostanie usunięta lub zdepublikowana, stara wartość zostanie
odrzucona przy następnej publikacji; panel pokaże ją jako „Niedostępna strona
lub wpis” do czasu zmiany.

### Menu (`menu`)

```json
{
  "id": "footer_menu",
  "type": "menu",
  "label": "Menu w stopce",
  "default": "main-menu"
}
```

Zapisanego sluga użyj z helperem menu:

```php
<?php menu((string) get_theme_mod('footer_menu', 'main-menu'), 'footer-nav'); ?>
```

### Daty (`date`)

```json
{
  "id": "event_date",
  "type": "date",
  "label": "Data wydarzenia",
  "default": "2026-12-31"
}
```

Wartość jest zapisywana jako `YYYY-MM-DD`, a niepoprawne daty są odrzucane.

---

## Rejestracja kontrolek z pluginu

```php
use Core\Hooks;
use Core\Customizer;

Hooks::addAction('modo_customize_register', function (): void {
    Customizer::registerSection([
        'id'       => 'myplugin',
        'title'    => 'Mój plugin',
        'priority' => 100,
    ]);

    Customizer::registerControl([
        'id'         => 'myplugin_cta',
        'type'       => 'pagelink',
        'label'      => 'Cel przycisku CTA',
        'section'    => 'myplugin',
        'link_types' => 'page,post',
        'default'    => '/',
    ]);

    Customizer::registerControl([
        'id'      => 'myplugin_note',
        'type'    => 'tinymce',
        'label'   => 'Komunikat',
        'section' => 'myplugin',
        'default' => '',
    ]);
});
```

Id kontrolki musi być unikalne w obrębie motywu, pluginów i buildera GUI.
Sekcje pluginów są tylko do odczytu w builderze, ale pojawiają się w panelu
frontendowym jak każde inne pole.

---

## Odczyt wartości

```php
$title  = get_theme_mod('hero_title', 'Witaj');
$link   = get_theme_mod_link('hero_cta', '/');
$isOn   = get_theme_mod('hero_show', '0') === '1';
$html   = get_theme_mod_html('hero_text', '');
```

| Helper | Wynik |
|---|---|
| `get_theme_mod($key, $default)` | surowa wartość (escapuj samodzielnie, jeśli trzeba) |
| `the_theme_mod($key, $default)` | tekst escapowany dla HTML |
| `get_theme_mod_link($key, $default)` | gotowy URL do `href` |
| `the_theme_mod_link($key, $default)` | escapowany URL |
| `get_theme_mod_html($key, $default)` | surowy HTML administratora |
| `the_theme_mod_html($key, $default)` | surowy HTML wypisany bezpośrednio |

Wartości z pól tekstowych i URL zawsze escapuj przy wypisywaniu:

```php
<p><?php the_theme_mod('hero_title'); ?></p>
<a href="<?php the_theme_mod_link('hero_cta'); ?>">Czytaj więcej</a>
```

---

## Zmienne CSS i podgląd na żywo

Kontrolka z `css_var` jest podglądana natychmiast, bez przeładowania iframe:

```json
{
  "id": "accent",
  "type": "color",
  "label": "Kolor akcentu",
  "default": "#0d6efd",
  "css_var": "modo-accent"
}
```

`theme_head()` wypisuje wygenerowane zmienne `:root` przez `customizer_css()`.
Wartości są czyszczone przed zapisaniem do CSS.

Kontrolki bez `css_var` odświeżają tylko iframe podglądu po zakończeniu edycji,
więc panel zachowuje przewinięcie, otwarte sekcje i fokus. **Opublikuj** i
**Reset** przeładowują cały edytor, bo zmieniają wartości zapisane w bazie.

---

## Builder GUI

Otwórz **Wygląd → Customizer** (zakładka „Zarządzaj ustawieniami”), aby tworzyć
własne sekcje i pola bez edycji `theme.json`. Builder obsługuje te same typy,
`options`, `min/max/step`, zmienne CSS i `link_types`.

Ustawienia utworzone w builderze są zapisywane w bazie i można je tam edytować.
Ustawienia motywu i pluginów są w builderze tylko do odczytu, więc aktualizacja
motywu lub pluginu nie nadpisze własnych pól.

---

## Bezpieczeństwo i zgodność

- Tylko użytkownicy z `manage_settings` mogą otworzyć customizer i publikować.
- Pole `url` odrzuca niebezpieczne schematy (`javascript:`, `data:`,
  `vbscript:`).
- HTML z `tinymce` jest czyszczony przy zapisie, ale nadal traktuj go jako
  treść administratora.
- `pagelink` przyjmuje wyłącznie aktualnie opublikowane strony/wpisy. Po zmianie
  sluga automatyczny redirect 301 utrzymuje stare linki do następnej publikacji.
- Dodanie nowej kontrolki do `theme.json` jest bezpieczne: istniejące wartości
  pozostają w bazie i są używane, dopóki `id` się nie zmieni.
- Usunięcie kontrolki powoduje, że nie jest już renderowana ani publikowana;
  stara wartość może pozostać w `theme_mods`, ale jest ignorowana.
