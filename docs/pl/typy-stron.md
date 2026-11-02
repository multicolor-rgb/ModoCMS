# Typy stron

Typy stron to gotowe szablony treści: nazwa, typ bazowy (`Strona statyczna` albo `Wpis bloga`) oraz zestaw domyślnych pól niestandardowych. Dzięki nim redaktor tworzy „Stronę produktu”, „Stronę usługi” czy „Odcinek podcastu” bez ręcznego odtwarzania tych samych pól za każdym razem.

Typy stron zarządzasz w **Panel → Typy stron** (`admin/page-types.php`).

## Tworzenie typu strony

1. Otwórz **Typy stron** i kliknij **Nowy typ strony**.
2. Wpisz nazwę, a opcjonalnie slug. Slug jest generowany z nazwy, gdy pozostawisz go pustego, i jest używany przez motywy oraz API.
3. Wybierz **Typ bazowy**:
   - **Strona statyczna** — dokument zachowuje hierarchię stron, wybór strony nadrzędnej i adres strony.
   - **Wpis bloga** — dokument zachowuje adres bloga, tagi i kategorie.
4. Dodaj domyślne pola niestandardowe. Każde pole ma:
   - **Klucz pola** — używany w motywie, np. `get_meta('price')`.
   - **Etykietę** — czytelną nazwę widoczną w edytorze.
   - **Typ** — text, textarea, rich text, galeria, obraz, checkbox, liczba, HTML, PHP, JavaScript, CSS, JSON lub code.
   - **Wartość domyślną** — wstępnie wypełnianą dla nowych dokumentów.
   - **Wymagane** — flaga wyłącznie informacyjna; nie blokuje zapisu.
5. Zapisz typ strony.

Dla pól galerii wartość domyślna może być obiektem JSON:

```json
{"items":[],"options":{"columns":"3","gap":"14","ratio":"1","lightbox":"1","captions":"1"}}
```

## Tworzenie treści z typu strony

Użyj **Nowy z typu…** na liście treści, na pulpicie albo otwórz bezpośrednio:

```text
/admin/page-edit.php?type=page&page_type=strona-produktu
/admin/page-edit.php?type=post&page_type=podcast
```

Pola szablonu zostaną dodane automatycznie wraz z wartościami domyślnymi. Jeśli typ wybierzesz później podczas edycji istniejącego dokumentu, użyj przycisku **Zastosuj domyślne pola typu**. Dodawane są tylko brakujące pola; istniejące pola i wartości nigdy nie są nadpisywane.

Selektor **Typ strony** w edytorze jest filtrowany po aktualnym typie bazowym, więc szablony wpisów są dostępne tylko dla wpisów, a szablony stron tylko dla stron.

## Odczyt typu strony w motywie

```php
get_page_type(?int $pageId = null): ?array
page_type_slug(?int $pageId = null): string
is_page_type(string $slug, ?int $pageId = null): bool
```

Przykład:

```php
<?php if (is_page_type('strona-produktu')): ?>
    <div class="price"><?= get_meta('price') ?></div>
    <p><?= get_meta('short_description') ?></p>
    <?php the_meta_gallery('gallery'); ?>
<?php endif; ?>
```

`get_page_type()` zwraca wiersz typu (wraz z definicjami pól w `fields`) albo `null` dla klasycznych stron i wpisów.

## Usuwanie typu strony

Usunięcie typu **nie usuwa dokumentów**. Ich treść i pola niestandardowe pozostają nienaruszone; czyszczone jest tylko powiązanie `page_type_id`. Dlatego motyw powinien sprawdzać `is_page_type()` lub `get_page_type()` i mieć sensowny wariant zapasowy.

## API

`api/content.php` przyjmuje `page_type` (slug) albo `page_type_id`:

```json
{
  "title": "Nowy produkt",
  "type": "page",
  "page_type": "strona-produktu"
}
```

Odpowiedź zawiera `page_type_id` oraz `page_type`.
