# Styl motywu w edytorze treści

Modo CMS pozwala motywowi dostarczyć własną typografię wyłącznie dla kanwy edytora treści — bez wpływu na frontend i bez nowego ustawienia w panelu.

## Zasada działania

Funkcja jest **opcjonalna** i włącza się tylko wtedy, gdy istnieje plik:

```text
themes/<motyw>/assets/css/editor.css
```

- Brak pliku oznacza zero zmian: TinyMCE działa dokładnie tak jak wcześniej.
- Nie ma fallbacku na `style.css` ani na inne arkusze motywu.
- Nie ma nowego ustawienia w **Settings**.
- Styl jest wstrzykiwany wyłącznie do kanwy edytora, nie do frontendu.

Aktywny motyw jest odczytywany z `Router::getOption('active_theme', 'default')`, a ścieżka jest sanityzowana przez `basename()`.

## TinyMCE

Gdy `editor.css` istnieje, edytor otrzymuje:

- `content_css` — URL arkusza motywu z cache-bustingiem `?v=<filemtime>`,
- `content_style` — zmienne CSS Customizera w bloku `:root { ... }` oraz opcjonalny `editor.content_style`,
- `body_class` — opcjonalna klasa z `theme.json` (domyślnie pusta).

Kolejność arkuszy w `content_css` to: skórka TinyMCE → CSS siatki → CSS motywu. Dzięki temu typografia motywu może nadpisać ustawienia siatki.

Kanwa treści zawsze startuje od jasnego arkusza zawartości TinyMCE (`default`), niezależnie od ciemnego trybu panelu. Tryb ciemny zmienia wyłącznie interfejs edytora (skórka `oxide-dark`: pasek narzędzi i dialogi), więc treść jest podglądana na białym tle — tak jak na frontendzie. Motyw, który świadomie chce innego tła kanwy, nadal może je nadpisać w `editor.css`.

## Editor.js (usunięty)

Opcjonalny edytor blokowy Editor.js został usunięty. Stare dokumenty są automatycznie konwertowane do HTML, a `editor.css` jest teraz używany wyłącznie przez TinyMCE. Reguły zapisane dla starej kanwy inline nadal działają, ponieważ TinyMCE ładuje ten sam plik wewnątrz swojego iframe.

## Opcjonalny blok `editor` w `theme.json`

Motyw może dodać metadane edytora:

```json
{
    "editor": {
        "body_class": "prose",
        "content_style": ":root { --editor-max-width: 72ch; }"
    }
}
```

- `body_class` — klasa dodawana do `body` kanwy TinyMCE. Ustaw ją tylko wtedy, gdy motyw naprawdę scope'uje typografię pod tą klasą. Domyślnie jest pusta; Modo CMS **nie wymusza** klasy `prose`.
- `content_style` — dodatkowy CSS dla TinyMCE, dopisywany po zmiennych Customizera.

Oba pola są ignorowane, gdy `editor.css` nie istnieje.

## Przykładowy `editor.css`

```css
body {
    margin: 0;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    font-size: var(--modo-base-font-size, 16px);
    line-height: 1.65;
    color: #212529;
}

h1, h2, h3, h4, h5, h6 {
    margin: 0 0 .75em;
    font-weight: 700;
    line-height: 1.25;
}

a {
    color: var(--modo-primary, #0d6efd);
}

blockquote {
    margin: 1.5rem 0;
    padding: .35rem 1rem;
    border-left: 4px solid var(--modo-primary, #0d6efd);
}

img {
    max-width: 100%;
    height: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th, td {
    padding: .5rem .75rem;
    border: 1px solid #dee2e6;
}

.lead {
    font-size: 1.25rem;
    font-weight: 300;
}

.hl {
    padding: .1em .25em;
    background: #fff3cd;
    border-radius: .2em;
}
```

Jeśli motyw ma `themes/<motyw>/assets/fonts/fonts.css`, można go dołączyć na początku pliku:

```css
@import url("../fonts/fonts.css");
```

Ścieżka jest względna wobec `editor.css` i rozwiązywana względem katalogu motywu; TinyMCE ładuje ją wewnątrz własnego iframe.

## Wdrożenie w innym motywie

1. Utwórz katalog `themes/<motyw>/assets/css/`, jeśli jeszcze nie istnieje.
2. Dodaj plik `editor.css` z typografią treści.
3. Nie kopiuj do niego layoutu nagłówka, stopki, hero ani sekcji — tylko style treści.
4. Opcjonalnie dodaj blok `"editor"` w `theme.json`.
5. Sprawdź edytor przy wybranym motywie w **Settings → Content**.

## Zmienione pliki

- `core/ThemeEditorStyles.php` — klasa `Core\ThemeEditorStyles`,
- `core/Customizer.php` — metoda `cssVariables()`,
- `admin/page-edit.php` — payload i konfiguracja TinyMCE,
- `admin/snippets.php` — payload i konfiguracja edytora snippetów,
- `themes/default/assets/css/editor.css`,
- `themes/default2/assets/css/editor.css`,
- `themes/editorial/assets/css/editor.css`,
- dokumentacja PL/EN oraz linki w README.

## Test ręczny

1. **Bez `editor.css`** — otwórz edycję strony i snippet; wygląd edytora nie może się zmienić.
2. **Z `editor.css`** — typografia w TinyMCE powinna odpowiadać plikowi.
3. **`@import`** — importowany arkusz fontów musi wczytać się wewnątrz iframe TinyMCE.
4. **Customizer** — zmiana koloru lub rozmiaru czcionki w Customizerze musi nadpisać wartość domyślną z `editor.css`.
5. **Frontend** — strona publiczna nie może się zmienić.

## Rozwiązywanie problemów

- Styl się nie pojawia — sprawdź dokładną ścieżkę `themes/<motyw>/assets/css/editor.css` i czy plik jest czytelny.
- Klasa `body_class` nie działa — sprawdź, czy `theme.json` jest poprawnym JSON-em i czy plik `editor.css` istnieje.
- Złe ścieżki do fontów/obrazów — używaj ścieżek względnych wobec `editor.css`; Modo CMS zamieni je na URL-e motywu.
