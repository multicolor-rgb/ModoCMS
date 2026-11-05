# API treści

API jest dostępne pod `/api/content.php` i uwierzytelnia się osobistym tokenem:

```http
Authorization: Bearer cms_...
```

Token wygenerujesz lub odświeżysz w **Mój profil → Dostęp do REST API**. Surowy
token jest pokazywany tylko raz; w bazie zapisywany jest hash SHA-256. Token może
mieć termin ważności (Ustawienia → Bezpieczeństwo → *Ważność tokenu API*).

## Uprawnienia

Token dziedziczy rolę użytkownika, który go utworzył:

| Operacja | Wymagane uprawnienie |
|---|---|
| Lista / odczyt | `manage_pages` |
| Tworzenie | `create_pages` |
| Publikacja | `publish_pages` |
| Edycja własnych | `edit_own_pages` |
| Edycja cudzych | `edit_others_pages` |
| Usuwanie | `delete_pages` |

Autor widzi i edytuje tylko własne wpisy oraz nie może publikować.

## Endpointy

### Lista

```http
GET /api/content.php?type=post&status=published&lang=pl&per_page=20&page=1&q=witaj
```

Obsługiwane parametry: `type`, `status`, `lang`, `page_type`, `q`, `page`, `per_page`.

### Odczyt jednego wpisu

```http
GET /api/content.php?id=42
```

### Tworzenie

```http
POST /api/content.php
Content-Type: application/json

{
  "title": "Witaj",
  "content": "<p>Treść</p>",
  "type": "post",
  "status": "draft",
  "lang": "pl",
  "page_type": "artykul",
  "published_at": "2026-12-01 09:00:00",
  "meta": {
    "podtytul": "Krótki lead",
    "cena": { "type": "number", "value": "19.99" }
  }
}
```

### Aktualizacja

`PUT` lub `PATCH` z `id` w treści (albo `?id=`). Zmieniane są tylko pola obecne
w żądaniu; klucze `meta` są dodawane lub nadpisywane.

### Usuwanie

```http
DELETE /api/content.php?id=42
```

## Odpowiedzi

Sukces: `{"status":"success","data":...}`. Błąd:
`{"status":"error","message":"..."}` z kodem `400`, `401`, `403`, `404`, `405`,
`422`, `429` lub `500`.

Limit: 120 żądań na minutę na token. Po każdym zapisie cache i sitemapa są
odświeżane.
