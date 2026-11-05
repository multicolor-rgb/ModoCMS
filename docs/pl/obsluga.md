# Obsługa Modo CMS

[English](../en/usage.md) | **Polski**

Przewodnik administratora: logowanie, publikowanie treści, media, motywy,
użytkownicy, backupy i aktualizacje.

---

## 1. Logowanie

Otwórz `/admin/` (lub `/podkatalog/admin/`). Użyj nazwy użytkownika lub adresu
e-mail i hasła ustawionego podczas instalacji.

- **Nie pamiętasz hasła?** — podaj e-mail na stronie logowania. Otrzymasz link
  ważny godzinę. Reset hasła wylogowuje inne sesje i unieważnia token API.
- **2FA** — jeśli włączone, po haśle wpisz 6-cyfrowy kod z aplikacji
  uwierzytelniającej.
- **Blokada** — pięć nieudanych prób z jednego IP w 15 minut blokuje logowanie.
  Odczekaj lub użyj innej sieci.

W profilu (**awatar w prawym górnym rogu → Mój profil**) zmienisz e-mail, język
panelu, motyw panelu (`Automatyczny`, `Jasny`, `Ciemny`), hasło, 2FA i token API.

---

## 2. Dashboard

Dashboard pokazuje:

- ocenę **Site Health** z listą problemów do naprawy,
- liczbę stron, wpisów i użytkowników,
- ruch dzisiaj i w wybranym okresie,
- najczęściej odwiedzane strony,
- ostatnio zmienione dokumenty,
- formularz szybkiego szkicu,
- wersję PHP, bazę i aktywny motyw.

Szybki szkic tworzy nieopublikowany wpis blogowy. Edytujesz go później w
**Treść**.

---

## 3. Treść

**Treść → Cała treść** listuje strony i wpisy. Filtry pozwalają zawęzić listę po
typie, języku i typie strony. Pasek masowych operacji usuwa wpisy albo zmienia
status, autora i język.

### Strony i wpisy

| Pole | Znaczenie |
|---|---|
| Tytuł | Widoczny nagłówek i domyślne źródło sluga. |
| Slug | Segment URL. Zmiana tworzy automatyczny redirect 301. |
| Treść | Treść TinyMCE, media, gridy i snippety. |
| Status | Opublikowany lub szkic. |
| Data publikacji | Opcjonalna przyszła data — wpis jest ukryty do tego czasu. |
| Typ strony | Preset z domyślnymi polami niestandardowymi. |
| Strona nadrzędna | Buduje hierarchiczne adresy, np. `/o-nas/zespol`. |
| Obrazek wyróżniający | Używany przez motywy i karty społecznościowe. |
| Meta / opis | Opis SEO/OG oraz tagi i kategorie. |
| Pola niestandardowe | Dodatkowe wartości czytane przez `get_meta()` w motywie. |

### Rewizje i autosave

Edytor prowadzi historię wersji. Otwórz **Historia rewizji**, aby porównać,
przywrócić lub usunąć wersję. Autosave zapisuje szkic co skonfigurowany czas.

### Planowanie publikacji

Ustaw **Datę publikacji** na przyszłość i pozostaw status **Opublikowany**.
Wpis nie pojawi się na stronie, w sitemapie, archiwum ani wyszukiwaniu do tego
dnia. Cache strony jest odświeżany przy zapisie.

### Kosz i podgląd

Usunięcie wpisu przenosi go do **Kosza**; możesz go przywrócić lub usunąć trwale
z widoku Kosza. Przycisk **Podgląd** w edytorze tworzy podpisany link dla
klienta — działa bez konta i pokazuje zapisaną wersję.

### RSS

Strona udostępnia `/feed.xml` (oraz `/pl/feed.xml` w trybie wielojęzycznym).
Odpowiedni `<link rel="alternate">` dodaje automatycznie `theme_head()`.

---

## 4. Edytor

Dołączony TinyMCE obsługuje:

- nagłówki, pogrubienie/kursywę, listy, cytaty, linki i tabele,
- obrazy z biblioteki mediów lub bezpośredni upload,
- builder gridów (Bootstrap, Bulma, Pico, UIkit, Foundation, Tailwind,
  Modo Grid lub własny CSS),
- snippety przez shortcode `[snippet:nazwa]`,
- podgląd kodu i pełny ekran.

Do obrazów używaj przycisku **Media**. Wgrane zdjęcia otrzymują miniatury, a
przy włączonej opcji także kopię WebP.

---

## 5. Biblioteka mediów

**Media** obsługuje foldery, przeciąganie plików, wyszukiwanie/filtrowanie,
ALT i podpisy, masową edycję oraz obróbkę zdjęć (obrót, odbicie, rozmiar,
jakość).

- ALT jest ważny dla dostępności i SEO.
- **Wymuś regenerację miniatur** w Ustawienia → Wydajność odtwarza podglądy,
  rozmiary galerii i kopie WebP.
- Pliki trafiają do `uploads/`, metadane do bazy.
- Nie usuwaj plików bezpośrednio z dysku — rób to z biblioteki.

---

## 6. Menu

**Nawigacja** zarządza jednym lub kilkoma menu. Każde menu ma nazwę, slug i
drzewo linków. Link może prowadzić do strony, wpisu, archiwum kategorii/taga,
własnej ścieżki lub zewnętrznego URL. Menu nagłówka ustawisz w **Ustawienia →
Ogólne → Menu nagłówka**.

Motywy renderują menu przez `get_theme_menu()` lub `menu('slug')`.

---

## 7. Wygląd

### Motywy

**Wygląd → Motywy** listuje katalogi w `themes/`. Wybór motywu od razu zmienia
stronę publiczną. Customizer zawsze edytuje aktywny motyw.

### Theme Customizer

Otwórz **Wygląd → Customizer** albo panel **Edytuj ustawienia szablonu** na
stronie. Możesz:

- edytować pola motywu/pluginów oraz pola utworzone w builderze,
- używać rich text (`tinymce`), linków wewnętrznych (`pagelink`), menu i dat,
- podejrzeć zmiany na żywo, opublikować lub zresetować,
- tworzyć sekcje/pola w **Zarządzaj ustawieniami** (zapis w bazie).

Pełna dokumentacja: [Ustawienia theme customizera](customizer.md).

### Edytor motywu

**Wygląd → Edytor motywu** edytuje pliki PHP/CSS/JS/JSON/HTML aktywnego motywu.
Edytuj tylko to, co rozumiesz — błąd PHP może położyć stronę. Przed większymi
zmianami zrób backup.

---

## 8. Ustawienia

| Zakładka | Co ustawia |
|---|---|
| Ogólne | Tytuł, opis, domena, strona główna, motyw, języki, branding, menu nagłówka, domyślne ustawienia customizera. |
| Treść | Toolbar edytora, grid, rewizje, autosave. |
| SEO i social | Format tytułu, hreflang, domyślne Open Graph, skrypty head/footer. |
| Bezpieczeństwo | Limity brute-force, nagłówki/CSP, 2FA, timeout sesji, ważność tokenu API, zaufane hosty. |
| Wydajność | Cache pełnostronicowy i TTL, retencja statystyk/audytu, media/WebP/miniatury. |
| E-mail | `mail()` lub SMTP, dane nadawcy, wiadomość testowa. |
| Statystyki | Tryb liczenia, typ/kolor/okres wykresu, reset. |
| Narzędzia | Czyszczenie cache, redirecty, dziennik aktywności, migracja domeny. |
| System | Wymagania, uprawnienia, reguły `.htaccess`/nginx, aktualizacje, plik konfiguracyjny. |

Ustawienia zapisują się per sekcja; wybrana zakładka jest pamiętana w adresie
URL i w przeglądarce.

---

## 9. Użytkownicy i role

| Rola | Może |
|---|---|
| Administrator | Wszystko: ustawienia, pluginy, użytkownicy, treść, media, motywy. |
| Redaktor | Zarządza całą treścią, publikuje/usuwa, media, taksonomie, rewizje. |
| Autor | Tworzy i edytuje własną treść, używa mediów, widzi własne rewizje; nie publikuje. |

**Użytkownicy → Utwórz użytkownika** dodaje konto. Nie można usunąć własnego
konta. Używaj silnych haseł (min. 8 znaków) i włącz 2FA dla administratorów.

---

## 10. Backupy i aktualizacje

- **Backupy** tworzą snapshot bazy lub pełny pakiet (baza + uploads) w
  `data/backups/`. Pobieraj je regularnie i przechowuj poza serwerem.
- **System → Aktualizacje** sprawdza wydania GitHub, weryfikuje sumę kontrolną,
  robi backup podmienianych plików i wycofuje zmiany przy błędzie. Treść,
  uploads, `modoconfig.php` i własne motywy/pluginy nie są podmieniane.
- Po instalacji usuń lub zablokuj `install.php`.

---

## 11. Dostęp do API

Token wygenerujesz w **Mój profil → Dostęp do REST API**. Surowy token jest
pokazywany raz; w bazie jest tylko hash SHA-256. Użycie:

```http
Authorization: Bearer cms_...
```

Endpointy, uprawnienia, przykłady i kody błędów: [API treści](api.md).

---

## 12. Konserwacja i rozwiązywanie problemów

- **Tryb konserwacji** konfiguruje się w `modoconfig.php`; dozwolone IP i
  zalogowani administratorzy nadal widzą stronę.
- **Biała strona** — włącz `debug` lub `debug_log` w `modoconfig.php` i sprawdź
  `data/logs/app.log`.
- **Zmiany niewidoczne** — wyczyść cache w **Ustawienia → Narzędzia**.
- **Upload nie działa** — sprawdź uprawnienia `uploads/` i limity PHP.
- **Zepsute linki po przeniesieniu** — użyj **Ustawienia → Narzędzia →
  Migracja domeny**.
- **Utracone 2FA** — administrator może wyłączyć je w bazie
  (`totp_enabled = 0`, `totp_secret = NULL`) albo przywrócić backup.
- **Blokada logowania** — odczekaj okno brute-force lub wyczyść tabelę
  `login_attempts`.
- **Błąd motywu** — przełącz motyw w bazie (`active_theme`) lub przywróć
  katalog motywu z backupu.

Problemy serwerowe: [Instalacja](instalacja.md).

Zobacz [Automatyzacja i konserwacja](automatyzacja.md) — Site Health, kosz,
podglądy, RSS i cron.
