# Automatyzacja, podglądy i konserwacja

[English](../en/automation.md) | **Polski**

Modo CMS zawiera kilka narzędzi, które utrzymują stronę w dobrej kondycji bez
ciągłej pracy ręcznej: Site Health, kosz, podpisane podglądy, RSS i zadania
cykliczne.

---

## 1. Site Health

Dashboard rozpoczyna się kartą **Site Health**. Pokazuje ocenę instalacji i
listę problemów do naprawy:

- installer wciąż obecny,
- brak prawa zapisu do `data/` lub `uploads/`,
- stare domyślne hasło administratora,
- włączony tryb debug,
- wyłączone nagłówki bezpieczeństwa lub cache,
- brak backupów,
- cron nie uruchamia się,
- stara wersja PHP,
- HTTP zamiast HTTPS.

Każdy problem prowadzi do miejsca, w którym można go naprawić. 100% oznacza, że
wszystkie testy przeszły.

---

## 2. Kosz i przywracanie

Usunięcie dokumentu z **Treści** przenosi go do **Kosza** zamiast trwałego
usunięcia. Kosz to przycisk obok Wszystkie / Wpisy / Strony.

- **Przywróć** przywraca dokument z poprzednim statusem i slugiem.
- **Usuń trwale** usuwa dokument oraz jego tagi/kategorie/pola własne.
- Scheduler usuwa dokumenty z kosza po czasie z
  **Ustawienia → Wydajność → Przechowuj kosz (dni)** (domyślnie 30 dni).

Usunięte dokumenty nie pojawiają się na stronie, w menu, wyszukiwaniu, sitemapie,
feeds i API. API może pokazać kosz przez `?trash=1`.

---

## 3. Podgląd szkiców linkiem

Otwórz zapisany dokument w edytorze i kliknij **Podgląd**. Wygenerowany link jest
podpisany i działa bez sesji administratora:

```text
https://example.com/preview?token=...
```

- Linki są podpisywane kluczem `data/preview.key` (tworzony automatycznie).
- Domyślny czas ważności to 24 godziny.
- Strony podglądu mają `noindex`, są prywatne i nigdy nie trafiają do cache.
- Link pokazuje **zapisaną** wersję, nie niezapisane zmiany w edytorze.

Wyślij link klientowi do akceptacji, a po zatwierdzeniu opublikuj dokument.

---

## 4. Kanał RSS

Każda strona udostępnia kanał RSS 2.0:

- `/feed.xml` — bieżący język,
- `/pl/feed.xml` — konkretny język przy włączonym trybie wielojęzycznym.

Kanał zawiera tylko opublikowane wpisy, respektuje planowanie publikacji i
zawiera `content:encoded`. `theme_head()` automatycznie dodaje
`<link rel="alternate" type="application/rss+xml">`.

---

## 5. Zadania cykliczne (cron)

Cron utrzymuje porządek i odświeża dane zależne od czasu:

- publikuje zaplanowane treści, czyszcząc cache po osiągnięciu daty,
- usuwa przeterminowany kosz,
- czyści `login_attempts`, `visits`, limity i dziennik audytu,
- regeneruje `sitemap.xml` i `robots.txt`,
- zapisuje czas ostatniego uruchomienia.

### Wiersz poleceń (zalecane)

```bash
php /var/www/html/tools/cron.php
```

Przykładowy crontab (co 15 minut):

```cron
0,15,30,45 * * * * php /var/www/html/tools/cron.php >> /var/log/modocms-cron.log 2>&1
```

### Fallback HTTP (shared hosting)

Użyj tokenu z `data/cron.key`:

```text
https://example.com/cron.php?token=...
```

Dokładny URL i token znajdziesz w **Ustawienia → Narzędzia → Zadania cykliczne**.
Ta sama karta ma przycisk **Uruchom teraz** i pokazuje ostatnie uruchomienie.

> Trzymaj `data/cron.key` w tajemnicy. Aby wygenerować nowy token, usuń plik —
> następne żądanie utworzy nowy.

---

## 6. Zalecany harmonogram

| Zadanie | Częstotliwość |
|---|---|
| Cron | co 15 minut |
| Backup bazy | codziennie lub co tydzień |
| Sprawdzanie aktualizacji | co tydzień |
| Czyszczenie kosza | automatycznie przez cron |

Do backupów używaj **Backupów** albo serwerowego `sqlite3 .backup`/`VACUUM INTO`
i kopiuj wynik poza serwer.
