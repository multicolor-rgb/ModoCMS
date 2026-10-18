# Installation

[Polski](../pl/instalacja.md) | **English**

This guide walks you through installing Modo CMS, from requirements to a working site. It works both in the **domain root** and in a **subdirectory**.

---

## 1. Requirements

| Item | Minimum |
|---|---|
| PHP | **8.0** or newer |
| PHP extensions | `pdo_sqlite`, `sqlite3`, `mbstring` |
| Web server | Apache (with `mod_rewrite`, `.htaccess`) or Nginx |
| Write access | `data/` and `uploads/` must be writable by the web server user |

Check your PHP version and extensions:

```bash
php -v
php -m | grep -E 'pdo_sqlite|sqlite3|mbstring'
```

---

## 2. Get the files

**Root installation** (site at `https://example.com/`):

```bash
cd /var/www/html
git clone <repository-url> .
```

**Subdirectory installation** (site at `https://example.com/modocms/`):

```bash
cd /var/www/html
git clone <repository-url> modocms
```

---

## 3. File permissions

The `data/` directory holds the SQLite database and the `uploads/` directory holds media. Both must be writable by the user running the web server (often `www-data`).

```bash
# root installation
chmod -R 775 data uploads

# subdirectory installation
chmod -R 775 modocms/data modocms/uploads
```

> 💡 The `.htaccess` files inside `data/` and `uploads/` already block direct HTTP access, so sensitive files cannot be downloaded.

---

## 4. Run the installer

Open the installer in your browser:

* Root: `https://example.com/install.php`
* Subdirectory: `https://example.com/modocms/install.php`

Follow the wizard (database creation, administrator account, basic site settings). **When it finishes, delete `install.php`.**

```bash
rm install.php
```

After installation:

* Frontend: `https://example.com/` (or `/modocms/`)
* Admin panel: `https://example.com/admin/` (or `/modocms/admin/`)

---

## 5. Web server configuration

### Apache

Modo CMS ships with an `.htaccess` file that rewrites all traffic to `index.php`. Make sure overrides are allowed and the required modules are enabled.

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

> 🔒 **Never add `RewriteBase /name/`** to `.htaccess`. The base path is detected automatically; adding `RewriteBase` breaks root installations.

### Nginx

Nginx ignores `.htaccess`, so add a `server` block. Root installation:

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
        fastcgi_pass unix:/run/php/php8-fpm.sock;   # adjust to your PHP-FPM version
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~* ^/(data|core)/ { deny all; }
}
```

Subdirectory installation — change the routing rule:

```nginx
    location /modocms/ {
        try_files $uri $uri/ /modocms/index.php?$query_string;
    }
```

### PHP built-in server (development only)

```bash
# root
php -S localhost:8000 -t /var/www/html

# subdirectory
cd /var/www/html
php -S localhost:8000
# open http://localhost:8000/modocms/
```

The built-in server does not read `.htaccess`. For clean URLs use Apache/Nginx, or supply a router script: `php -S localhost:8000 router.php`.

---

## 6. Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| Blank page / HTTP 500 | Check the PHP error log; make sure PHP ≥ 8.0 and `pdo_sqlite` is enabled. |
| Installer cannot write | `data/` and `uploads/` are not writable. Re-run `chmod` and check the owner. |
| “404” on every page except home | URL rewriting is off. Enable `mod_rewrite`/`AllowOverride All` (Apache) or add the Nginx block. |
| Styles or images 404 in a subfolder | Base path misdetected — make sure `.htaccess` has **no** `RewriteBase`. |
| Editor assets missing | Verify `admin/assets/vendor/` exists; all libraries are bundled locally (no CDN). |

---

## Next steps

* [Creating plugins (native Modo)](plugins.md)
* [Creating GetSimple-style plugins](getsimple-plugins.md)
* [Theme functions reference](theme-functions.md)
* [Hooks reference](hooks.md)
* [Examples](examples.md)
