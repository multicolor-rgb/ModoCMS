# API treści

[English](../en/api.md) | **Polski**

API treści pozwala zewnętrznym narzędziom tworzyć, czytać, aktualizować i
usuwać strony oraz wpisy. To małe API JSON wbudowane w CMS — nie wymaga
dodatkowych pakietów.

---

## 1. Uwierzytelnianie

Token wygenerujesz w **Mój profil → Dostęp do REST API → Wygeneruj token API**.

- Surowy token (`cms_...`) jest pokazywany **tylko raz**.
- W bazie zapisywany jest wyłącznie hash SHA-256.
- Ponowne wygenerowanie natychmiast unieważnia poprzedni token.
- Administrator może ustawić termin ważności w **Ustawienia → Bezpieczeństwo →
  Ważność tokenu API** (`0` = bez limitu).
- Token dziedziczy rolę użytkownika, który go utworzył.

Wysyłaj token w każdym żądaniu:

```http
Authorization: Bearer cms_...
Content-Type: application/json
```

Tokeny są osobiste. Nie umieszczaj ich w publicznym JavaScripcie ani w
repozytorium.

---

## 2. Uprawnienia

| Operacja | Wymagane uprawnienie |
|---|---|
| Lista / odczyt | `manage_pages` |
| Tworzenie | `create_pages` |
| Publikacja (`status: published`) | `publish_pages` |
| Edycja własnych | `edit_own_pages` |
| Edycja cudzych | `edit_others_pages` |
| Usuwanie | `delete_pages` |

Token **autora** widzi i edytuje tylko własne szkice oraz nie może publikować.
Token **redaktora** lub **administratora** zarządza całą treścią.

---

## 3. Lista wpisów

```bash
curl -sS \
  -H "Authorization: Bearer $MODO_TOKEN" \
  "https://example.com/api/content.php?type=post&status=published&lang=pl&per_page=20&page=1"
```

| Parametr | Domyślnie | Opis |
|---|---|---|
| `type` | wszystkie | `page` lub `post`. |
| `status` | wszystkie | `published` lub `draft`. |
| `lang` | wszystkie | Kod języka, np. `pl`. |
| `page_type` | wszystkie | Slug typu strony, np. `artykul`. |
| `q` | — | Szukanie w tytule i treści. |
| `page` | `1` | Numer strony. |
| `per_page` | `20` | 1–100 wpisów. |

Odpowiedź:

```json
{
  "status": "success",
  "data": [
    {
      "id": 42,
      "title": "Witaj",
      "slug": "witaj",
      "content": "<p>Treść</p>",
      "type": "post",
      "status": "published",
      "lang": "pl",
      "page_type_id": null,
      "parent_id": 0,
      "translation_group": "…",
      "featured_image": "",
      "meta_title": "",
      "meta_description": "",
      "published_at": "2026-12-01 09:00:00",
      "created_at": "2026-11-05 10:00:00",
      "updated_at": "2026-11-05 10:00:00",
      "author_id": 1,
      "meta": {},
      "author": "admin",
      "page_type": ""
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 20,
    "total": 1,
    "pages": 1
  }
}
```

`per_page` i `page` są ograniczane do bezpiecznych wartości. Autor widzi tylko
własne wpisy; redaktor i administrator widzą wszystkie.

---

## 4. Odczyt jednego wpisu

```bash
curl -sS \
  -H "Authorization: Bearer $MODO_TOKEN" \
  "https://example.com/api/content.php?id=42"
```

`meta` zawiera pola niestandardowe jako `{ "value": ..., "type": "..." }`.

---

## 5. Tworzenie wpisu

```bash
curl -sS -X POST \
  -H "Authorization: Bearer $MODO_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Witaj",
    "slug": "witaj",
    "content": "<p>Treść</p>",
    "type": "post",
    "status": "draft",
    "lang": "pl",
    "page_type": "artykul",
    "featured_image": "/uploads/zdjecie.webp",
    "meta_title": "Witaj",
    "meta_description": "Krótkie podsumowanie",
    "meta": {
      "podtytul": "Krótki lead",
      "cena": { "type": "number", "value": "19.99" },
      "galeria": { "type": "gallery", "value": { "items": [] } }
    }
  }' \
  "https://example.com/api/content.php"
```

Pola:

| Pole | Wymagane | Uwagi |
|---|---|---|
| `title` | tak | Niepuste. |
| `slug` | nie | Generowany z tytułu; unikalny w obrębie języka. |
| `content` | nie | Treść HTML. |
| `type` | nie | `post` (domyślnie) lub `page`. |
| `status` | nie | `published` (domyślnie) lub `draft`. Publikacja wymaga `publish_pages`. |
| `lang` | nie | Musi być dostępnym językiem; domyślnie język strony. |
| `page_type` / `page_type_id` | nie | Slug lub id presetu. |
| `parent_id` | nie | Strona nadrzędna dla hierarchii. |
| `translation_group` | nie | Grupuje tłumaczenia; generowany, gdy pusty. |
| `featured_image` | nie | Ścieżka mediów lub URL. |
| `meta_title`, `meta_description` | nie | Pola SEO. |
| `published_at` | nie | `YYYY-MM-DD HH:MM:SS`; przyszła data planuje publikację. |
| `meta` | nie | Pola niestandardowe. Wartość może być skalarem lub `{ "type": …, "value": … }`. |

Dozwolone typy pól: `text`, `textarea`, `number`, `checkbox`, `boolean`,
`color`, `image`, `gallery`, `json`, `richtext`.

Utworzenie zwraca `201` z wpisem i `data.url`.

---

## 6. Aktualizacja wpisu

Użyj `PUT` (lub `PATCH`) z `id` w treści albo w query stringu. Zmieniane są
tylko pola obecne w żądaniu; brakujące zachowują obecną wartość. Klucze `meta`
są dodawane lub nadpisywane.

```bash
curl -sS -X PATCH \
  -H "Authorization: Bearer $MODO_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "id": 42,
    "status": "published",
    "meta": { "podtytul": "Nowy lead" }
  }' \
  "https://example.com/api/content.php"
```

Zmiana statusu z `draft` na `published` wymaga `publish_pages`. Zmiana sluga lub
języka automatycznie zapewnia unikalność. Autor może edytować tylko własne
wpisy.

---

## 7. Usuwanie wpisu

```bash
curl -sS -X DELETE \
  -H "Authorization: Bearer $MODO_TOKEN" \
  "https://example.com/api/content.php?id=42"
```

Usunięcie usuwa dokument oraz jego tagi, kategorie i pola niestandardowe.
Rewizje pozostają do celów audytowych.

---

## 8. Błędy i limity

Sukces:

```json
{ "status": "success", "data": { } }
```

Błąd:

```json
{ "status": "error", "message": "Entry not found." }
```

| Kod | Znaczenie |
|---|---|
| `400` | Niepoprawne żądanie. |
| `401` | Brak, niepoprawny lub wygasły token. |
| `403` | Rola tokenu nie ma wymaganego uprawnienia. |
| `404` | Nie znaleziono wpisu. |
| `405` | Nieobsługiwana metoda HTTP. |
| `422` | Błąd walidacji (np. brak tytułu, zła data). |
| `429` | Przekroczony limit (120 żądań/min na token). |
| `500` | Błąd wewnętrzny; szczegóły trafiają do logu serwera. |

Po każdym udanym zapisie cache strony jest czyszczony, a `sitemap.xml`
regenerowany. Jeśli frontend nadal pokazuje stare treści, wyczyść cache w
**Ustawienia → Narzędzia**.

Opis OpenAPI: [`docs/openapi.yaml`](https://github.com/multicolor-rgb/ModoCMS/blob/main/docs/openapi.yaml).

---

## 9. Szybkie rozwiązywanie problemów

- **401** — sprawdź prefiks `Bearer`, termin ważności i czy użytkownik jest
  aktywny.
- **403** — rola nie może publikować/edytować cudzych; użyj tokenu redaktora
  albo utwórz wpis jako szkic.
- **422** — tytuł jest wymagany, a daty muszą mieć format
  `YYYY-MM-DD HH:MM:SS`.
- **429** — odczekaj minutę lub zmniejsz częstotliwość żądań.
- **CORS** — endpoint wysyła `Access-Control-Allow-Origin: *`; własne nagłówki
  muszą być dozwolone przez klienta/proxy.
