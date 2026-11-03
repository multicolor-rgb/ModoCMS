# Styl motywu w edytorze treści

Modo CMS pozwala motywowi dostarczyć własną typografię wyłącznie dla kanwy edytora treści — bez wpływu na frontend i bez nowego ustawienia w panelu.

## Zasada działania

Funkcja jest **opcjonalna** i włącza się tylko wtedy, gdy istnieje plik:

```text
themes/<motyw>/assets/css/editor.css
```

- Brak pliku oznacza zero zmian: TinyMCE i Editor.js działają dokładnie tak jak wcześniej.
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

## Editor.js

Editor.js renderuje się bezpośrednio w panelu, więc CSS motywu jest scopowany do kanwy:

- `#editorjs` na stronie edycji treści,
- `#snippet-editorjs` na stronie snippetów.

Selektory `:root`, `html` i `body` są mapowane na sam scope, a `:root X`, `html X` i `body X` na `<scope> X`. Reguły `@media` są przetwarzane rekurencyjnie.

Względne adresy `url(...)` oraz `@import` są zamieniane na bezwzględne URL-e motywu, ponieważ CSS jest wklejany inline do strony administracyjnej. Reguły `@import` trafiają na początek bloku `<style>`, przed CSS siatki.

Zmienne Customizera są dodawane **na końcu** zascopowanego CSS, aby nadpisały wartości domyślne zadeklarowane w `editor.css`.

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

Ścieżka jest względna wobec `editor.css` i zostanie automatycznie zamieniona na bezwzględny URL motywu w Editor.js.

## Wdrożenie w innym motywie

1. Utwórz katalog `themes/<motyw>/assets/css/`, jeśli jeszcze nie istnieje.
2. Dodaj plik `editor.css` z typografią treści.
3. Nie kopiuj do niego layoutu nagłówka, stopki, hero ani sekcji — tylko style treści.
4. Opcjonalnie dodaj blok `"editor"` w `theme.json`.
5. Sprawdź edytor przy wybranym motywie w **Settings → Content** dla TinyMCE i Editor.js.

## Zmienione pliki

- `core/ThemeEditorStyles.php` — nowa klasa `Core\ThemeEditorStyles`,
- `core/Customizer.php` — nowa metoda `cssVariables()`,
- `core/CssFramework.php` — nowa metoda `scopeCss()`,
- `admin/page-edit.php` — payload i konfiguracja obu edytorów,
- `admin/snippets.php` — payload i konfiguracja edytora snippetów,
- `themes/default/assets/css/editor.css`,
- `themes/default2/assets/css/editor.css`,
- `themes/editorial/assets/css/editor.css`,
- dokumentacja PL/EN oraz linki w README.

## Test ręczny

1. **Bez `editor.css`** — otwórz edycję strony i snippet; wygląd edytora nie może się zmienić.
2. **Z `editor.css`** — typografia w TinyMCE powinna odpowiadać plikowi.
3. **Editor.js** — styl ma działać tylko w `#editorjs` / `#snippet-editorjs`, bez wycieku do panelu.
4. **`@import`** — w źródle strony reguła `@import` musi być pierwszą regułą w bloku `<style>`, przed CSS siatki.
5. **Customizer** — zmiana koloru lub rozmiaru czcionki w Customizerze musi nadpisać wartość domyślną z `editor.css`.
6. **Frontend** — strona publiczna nie może się zmienić.

## Rozwiązywanie problemów

- Styl się nie pojawia — sprawdź dokładną ścieżkę `themes/<motyw>/assets/css/editor.css` i czy plik jest czytelny.
- Styl pojawia się tylko w TinyMCE — upewnij się, że w Editor.js wybrany jest właściwy motyw i odśwież cache przeglądarki.
- Klasa `body_class` nie działa — sprawdź, czy `theme.json` jest poprawnym JSON-em i czy plik `editor.css` istnieje.
- Złe ścieżki do fontów/obrazów — używaj ścieżek względnych wobec `editor.css`; Modo CMS zamieni je na URL-e motywu.
