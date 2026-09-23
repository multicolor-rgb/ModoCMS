cat << 'EOF' > README.md
# Clean CMS

[Polski](#polski) | [English](#english)

---

<a name="polski"></a>
# Polski

**Clean CMS** to lekki, szybki i modułowy system zarządzania treścią (CMS) oparty na **PHP 8.x** oraz bazie danych **SQLite3**. Zaprojektowany z myślą o programistach ceniących czytelną architekturę opartą na prostych funkcjach proceduralnych, brak zbędnego narzutu obiektowego oraz pełną kontrolę nad kodem szablonów i wtyczek.

---

## 🚀 Główne cechy

* **Prosta architektura:** Minimalistyczne, łatwe do zapamiętania funkcje szablonu (`mod()`, `get_field()`, `title()`, `e()`).
* **Baza SQLite3:** Brak konieczności konfiguracji zewnętrznych serwerów bazodanowych – baza w jednym pliku, łatwa do migracji i kopii zapasowych.
* **Dynamiczny Theme Customizer:** Konfiguracja wyglądu napędzana plikiem `theme.json` z podglądem na żywo (`iframe`).
* **Wbudowane pola niestandardowe (Custom Fields):** Obsługa różnych typów pól (tekst, textarea, liczba, checkbox, kolor, obrazek) aktywowana przełącznikiem w edytorze stron.
* **Architektura wtyczek (Hooks):** System akcji i filtrów pozwalający na łatwą rozbudowę panelu admina oraz frontendu.
* **Bezpieczeństwo:** Pełna ochrona przed SQL Injection (PDO Prepared Statements), CSRF, weryfikacja uprawnień i sanityzacja danych wyjściowych.

---

## ⚙️ Wymagania systemowe

* PHP **8.0** lub nowszy
* Rozszerzenia PHP: **pdo_sqlite**, **sqlite3**, **mbstring**
* Serwer WWW (Apache z włączonym `mod_rewrite` i obsługą `.htaccess` lub Nginx)

---

## 📦 Instalacja w głównym katalogu serwera (Root)

Poniższa instrukcja dotyczy instalacji bezpośrednio w głównym katalogu serwera (np. `/var/www/html` dla domeny głównej `http://localhost` lub `https://twojadomena.pl`).

### 1. Umieszczenie plików
Sklonuj lub skopiuj pliki projektu bezpośrednio do głównego katalogu serwera WWW:
```bash
cd /var/www/html
git clone git@github.com:multicolor-rgb/cleanCMS.git .
