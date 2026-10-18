# Instalacja

**Polski** | [English](../en/installation.md)

Ten przewodnik przeprowadzi Cię przez instalację Modo CMS — od wymagań po działającą stronę. Działa zarówno w **katalogu głównym domeny**, jak i w **podkatalogu**.

---

## 1. Wymagania

| Element | Minimum |
|---|---|
| PHP | **8.0** lub nowszy |
| Rozszerzenia PHP | `pdo_sqlite`, `sqlite3`, `mbstring` |
| Serwer WWW | Apache (z `mod_rewrite`, `.htaccess`) lub Nginx |
| Prawa zapisu | katalogi `data/` i `uploads/` muszą być zapisywalne przez użytkownika serwera WWW |

Sprawdź wersję PHP i rozszerzenia:

```bash
php -v
php -m | grep -E 'pdo_sqlite|sqlite3|mbstring'
```

---

## 2. Pobranie plików

**Instalacja w katalogu głównym** (strona pod `https://example.com/`):

```bash
cd /var/www/html
git clone <adres-repozytorium> .
```

**Instalacja w podkatalogu** (strona pod `https://example.com/modocms/`):

```bash
cd /var/www/html
git clone <adres-repozytorium> modocms
```

---

## 3. Uprawnienia plików

Katalog `data/` przechowuje bazę SQLite, a `uploads/` — pliki multimedialne. Oba muszą być zapisywalne przez użytkownika serwera WWW (często `www-data`).

```bash
# instalacja w katalogu głównym
chmod -R 775 data uploads

# instalacja w podkatalogu
chmod -R 775 modocms/data modocms/uploads
```

> 💡 Pliki `.htaccess` w `data/` i `uploads/` blokują już bezpośredni dostęp HTTP, więc wrażliwe pliki nie zostaną pobrane.

---

## 4. Uruchomienie instalatora

Otwórz instalator w przeglądarce:

* Katalog główny: `https://example.com/install.php`
* Podkatalog: `https://example.com/modocms/install.php`

Przejdź przez kreatora (utworzenie bazy, konto administratora, podstawowe ustawienia). **Po zakończeniu usuń plik `install.php`.**

```bash
rm install.php
```

Po instalacji:

* Frontend: `https://example.com/` (lub `/modocms/`)
* Panel: `https://example.com/admin/` (lub `/modocms/admin/`)

---

## 5. Konfiguracja serwera WWW

### Apache

Modo CMS zawiera plik `.htaccess`, który przepisuje cały ruch na `index.php`. Upewnij się, że nadpisywanie jest dozwolone, a wymagane moduły włączone.

```apache
<Directory /var/www/html>
    AllowOverride All
    Require all granted
</Directory>
```

```bash
# Debian/Ubuntu
sudo a2enmod rewrite headers
sudo systemctl restart apache2
```

> 🔒 **Nigdy nie dodawaj `RewriteBase /nazwa/`** do `.htaccess`. Bazowa ścieżka wykrywa się automatycznie; dodanie `RewriteBase` psuje instalację w katalogu głównym.

### Nginx

Nginx ignoruje `.htaccess`, więc dodaj blok `server`. Instalacja w katalogu głównym:

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/html;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8-fpm.sock;   # dostosuj do swojej wersji PHP-FPM
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~* ^/(data|core)/ { deny all; }
}
```

Instalacja w podkatalogu — zmień regułę routingu:

```nginx
    location /modocms/ {
        try_files $uri $uri/ /modocms/index.php?$query_string;
    }
```

### Wbudowany serwer PHP (tylko do developmentu)

```bash
# katalog główny
php -S localhost:8000 -t /var/www/html

# podkatalog
cd /var/www/html
php -S localhost:8000
# otwórz http://localhost:8000/modocms/
```

Wbudowany serwer nie odczytuje `.htaccess`. Dla czystych URL-i użyj Apache/Nginx albo dołącz własny router-skrypt: `php -S localhost:8000 router.php`.

---

## 6. Rozwiązywanie problemów

| Objaw | Prawdopodobna przyczyna / rozwiązanie |
|---|---|
| Biała strona / HTTP 500 | Sprawdź log błędów PHP; upewnij się, że PHP ≥ 8.0 i `pdo_sqlite` jest włączone. |
| Instalator nie może zapisać | Katalogi `data/` i `uploads/` nie są zapisywalne. Powtórz `chmod` i sprawdź właściciela. |
| „404” na każdej stronie poza główną | Wyłączone przepisywanie URL. Włącz `mod_rewrite`/`AllowOverride All` (Apache) lub dodaj blok Nginx. |
| Style/obrazy 404 w podkatalogu | Źle wykryta baza — upewnij się, że `.htaccess` **nie** ma `RewriteBase`. |
| Brak zasobów edytora | Sprawdź, czy istnieje `admin/assets/vendor/`; wszystkie biblioteki są dołączone lokalnie (bez CDN). |

---

## Kolejne kroki

* [Tworzenie pluginów (natywne Modo)](pluginy.md)
* [Tworzenie wtyczek w stylu GetSimple](wtyczki.md)
* [Funkcje motywu — referencja](funkcje-motywu.md)
* [Hooki — referencja](hooki.md)
* [Przykłady](przyklady.md)
