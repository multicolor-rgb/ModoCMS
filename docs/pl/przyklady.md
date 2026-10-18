# Przykłady

**Polski** | [English](../en/examples.md)

Gotowe do skopiowania przykłady rozbudowy Modo CMS. Każdy to kompletny plik pluginu — wrzuć go do `plugins/`, aktywuj w **Wtyczki** i działa.

> Wszystkie przykłady zaczynają się od `declare(strict_types=1);` i nie używają zewnętrznych CDN, zgodnie z konwencjami projektu.

---

## 1. Hello World — dopisz notkę do każdego wpisu

`plugins/hello-world.php`

```php
<?php
/**
 * Plugin Name: Hello World Filter
 * Description: Dopisuje małą notkę do każdego wpisu na frontendzie.
 *
 * @package HelloWorld
 */
declare(strict_types=1);

use Core\Hooks;

Hooks::addFilter('the_content', function (string $content): string {
    if (defined('IN_ADMIN') && IN_ADMIN === true) {
        return $content;
    }
    return $content . '<p style="color:#64748b;font-style:italic;">Powered by Modo CMS</p>';
});
```

**Pojęcia:** filtry, `the_content`, ochrona przed panelem admina.

---

## 2. Widget na pulpicie

Pokazuje na pulpicie panelu ramkę z liczbą opublikowanych wpisów.

`plugins/admin-stats.php`

```php
<?php
/**
 * Plugin Name: Admin Stats
 * Description: Dodaje mały widget na pulpicie z licznikiem wpisów.
 *
 * @package AdminStats
 */
declare(strict_types=1);

use Core\Hooks;
use Core\Database;

Hooks::addAction('admin_dashboard_widgets', function (): void {
    try {
        $db  = Database::getConnection();
        $count = (int) $db->query("SELECT COUNT(*) FROM pages WHERE type = 'post' AND status = 'published'")->fetchColumn();
    } catch (\Throwable $e) {
        $count = 0;
    }

    echo '<div class="card" style="padding:16px;">';
    echo '<h3 style="margin:0 0 8px;">Opublikowane wpisy</h3>';
    echo '<p style="font-size:28px;font-weight:700;margin:0;">' . $count . '</p>';
    echo '</div>';
});
```

**Pojęcia:** akcje, `admin_dashboard_widgets`, bezpieczne czytanie z bazy.

---

## 3. Plugin z własnym ekranem i tabelą ustawień

Shortcode `[guestbook]` wyświetla wpisy; ekran w panelu pozwala je dodawać.

`plugins/guestbook/guestbook.php`

```php
<?php
/**
 * Plugin Name: Guestbook
 * Description: Księga gości z shortcode'em i własnym ekranem w panelu.
 * Version: 1.0.0
 * Author: Twoje Imię
 *
 * @package Guestbook
 */
declare(strict_types=1);

if (!class_exists('Guestbook')) {

    final class Guestbook
    {
        public const PLUGIN_ID = 'guestbook';

        public static function init(): void
        {
            self::ensureTable();

            if (defined('IN_ADMIN') && IN_ADMIN === true) {
                // Rejestracja pozycji w menu + renderera ekranu ustawień.
                if (function_exists('register_plugin') && function_exists('createSideMenu')) {
                    register_plugin(
                        self::PLUGIN_ID,
                        'Guestbook',
                        '1.0.0',
                        'Twoje Imię',
                        '',
                        'Księga gości z shortcode\'em i ekranem w panelu.',
                        'settings',
                        [self::class, 'renderAdmin']
                    );
                    createSideMenu(self::PLUGIN_ID, 'Guestbook', self::PLUGIN_ID);
                }
            } else {
                \Core\Hooks::addFilter('the_content', [self::class, 'shortcode'], 20);
            }
        }

        private static function db(): ?\PDO
        {
            try { return \Core\Database::getConnection(); } catch (\Throwable $e) { return null; }
        }

        private static function ensureTable(): void
        {
            $db = self::db();
            if (!$db) { return; }
            $db->exec("CREATE TABLE IF NOT EXISTS guestbook_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                message TEXT NOT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            )");
        }

        /** Zamienia [guestbook] na listę wpisów. */
        public static function shortcode(string $content): string
        {
            if (!str_contains($content, '[guestbook]')) {
                return $content;
            }

            $db = self::db();
            $rows = $db ? $db->query("SELECT message FROM guestbook_entries ORDER BY id DESC LIMIT 50")->fetchAll() : [];

            $html = '<ul class="guestbook">';
            foreach ($rows as $row) {
                $html .= '<li>' . htmlspecialchars((string) $row['message'], ENT_QUOTES, 'UTF-8') . '</li>';
            }
            $html .= '</ul>';

            return str_replace('[guestbook]', $html, $content);
        }

        /** Renderuje ekran ustawień (wywoływany przez admin_plugin_view_guestbook). */
        public static function renderAdmin(): void
        {
            $db = self::db();

            // Obsłuż POST zanim cokolwiek wypiszesz.
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['guestbook_message'])) {
                if (\Core\Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
                    $message = trim((string) $_POST['guestbook_message']);
                    if ($message !== '' && $db) {
                        $stmt = $db->prepare("INSERT INTO guestbook_entries (message) VALUES (:m)");
                        $stmt->execute([':m' => $message]);
                    }
                }
            }

            echo '<h2>Księga gości</h2>';
            echo '<form method="post" style="display:flex;gap:8px;margin-bottom:16px;">';
            echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(\Core\Security::generateCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
            echo '<input type="text" name="guestbook_message" class="form-control" placeholder="Nowy wpis" required>';
            echo '<button class="btn">Dodaj</button>';
            echo '</form>';

            $rows = $db ? $db->query("SELECT message, created_at FROM guestbook_entries ORDER BY id DESC")->fetchAll() : [];
            echo '<table class="pro-table"><thead><tr><th>Wpis</th><th>Data</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                echo '<tr><td>' . htmlspecialchars((string) $row['message'], ENT_QUOTES, 'UTF-8') . '</td>'
                   . '<td>' . htmlspecialchars((string) $row['created_at'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
            }
            echo '</tbody></table>';
        }
    }

    Guestbook::init();
}
```

**Pojęcia:** plugin folderowy, podział przez `IN_ADMIN`, własna tabela, shortcode przez `the_content`, `register_plugin()` + `createSideMenu()`, prepared statements, CSRF.

---

## 4. Kontrolka customizera

Dodaje do customizera na żywo przełącznik „Pokaż banner” i kolor akcentu.

`plugins/plugin-branding.php`

```php
<?php
/**
 * Plugin Name: Plugin Branding
 * Description: Dodaje kontrolki customizera z pluginu.
 *
 * @package PluginBranding
 */
declare(strict_types=1);

use Core\Hooks;
use Core\Customizer;

Hooks::addAction('modo_customize_register', function (): void {
    Customizer::registerSection([
        'id'       => 'plugin_branding',
        'title'    => 'Plugin Branding',
        'priority' => 100,
    ]);

    Customizer::registerControl([
        'id'      => 'pb_show_banner',
        'type'    => 'checkbox',
        'label'   => 'Pokaż banner',
        'section' => 'plugin_branding',
        'default' => '1',
    ]);

    Customizer::registerControl([
        'id'      => 'pb_banner_color',
        'type'    => 'color',
        'label'   => 'Kolor bannera',
        'section' => 'plugin_branding',
        'default' => '#2563eb',
        'css_var' => 'pb-banner',          // -> :root { --pb-banner: … }
    ]);
});

// Renderuj banner na frontendzie.
Hooks::addAction('theme-header', function (): void {
    if (defined('IN_ADMIN') && IN_ADMIN === true) {
        return;
    }
    if (get_theme_mod('pb_show_banner', '0') !== '1') {
        return;
    }
    echo '<div style="background:var(--pb-banner);color:#fff;padding:8px 16px;text-align:center;">'
       . 'Witaj z Plugin Branding</div>';
});
```

**Pojęcia:** `modo_customize_register`, `registerSection` / `registerControl`, `css_var`, odczyt przez `get_theme_mod()`.

---

## 5. Ramka shortcode (styl + treść)

`[box]…[/box]` renderuje ramkę z obramowaniem.

`plugins/shortcode-box.php`

```php
<?php
/**
 * Plugin Name: Shortcode Box
 * Description: Dodaje shortcode [box]...[/box].
 *
 * @package ShortcodeBox
 */
declare(strict_types=1);

use Core\Hooks;

Hooks::addAction('theme-footer', function (): void {
    if (defined('IN_ADMIN') && IN_ADMIN === true) {
        return;
    }
    echo '<style>.md-box{border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:16px;margin:1em 0;background:#f8fafc;}</style>';
});

Hooks::addFilter('the_content', function (string $content): string {
    return preg_replace_callback(
        '/\[box\](.*?)\[\/box\]/is',
        static fn (array $m): string => '<div class="md-box">' . $m[1] . '</div>',
        $content
    );
}, 20);
```

**Pojęcia:** shortcode'y, `preg_replace_callback`, wstrzykiwanie arkusza na frontendzie, wysoki priorytet.

---

## 6. Wtyczka w stylu GetSimple (pełna)

Klasyczna wtyczka używająca `register_plugin()`, `add_action()`, `add_filter()` i `i18n_merge()`.

`plugins/my-gs-plugin/my-gs-plugin.php`

```php
<?php
/**
 * Plugin Name: My GS Plugin
 * Description: Wtyczka w stylu GetSimple (patrz dokumentacja).
 * Version: 1.0.0
 * Author: Twoje Imię
 *
 * @package MyGsPlugin
 */
declare(strict_types=1);

class MyGsPlugin
{
    public static function init(): void
    {
        register_plugin(
            'my-gs-plugin', 'My GS Plugin', '1.0.0', 'Twoje Imię', '',
            'Przykład w stylu GetSimple.', 'settings', [self::class, 'settings']
        );

        createSideMenu('my-gs-plugin', 'My GS Plugin', 'my-gs-plugin');

        add_action('theme-footer', [self::class, 'footer']);
        add_filter('content', [self::class, 'content']);

        i18n_merge('my-gs-plugin', 'en_US');
    }

    public static function footer(): void
    {
        echo '<div style="text-align:center;padding:8px;">Witaj z wtyczki w stylu GetSimple</div>';
    }

    public static function content(string $content): string
    {
        return $content . '<p><small>Przetworzone przez My GS Plugin</small></p>';
    }

    public static function settings(): void
    {
        echo '<h2>' . htmlspecialchars((string) i18n_r('my-gs-plugin/MYPLUGIN_TITLE'), ENT_QUOTES, 'UTF-8') . '</h2>';
        echo '<p>Renderowane przy ?id=my-gs-plugin</p>';
    }
}

MyGsPlugin::init();
```

**Pojęcia:** warstwa zgodności — patrz [Wtyczki w stylu GetSimple](wtyczki.md).

---

## 7. Fragment motywu — własna karta archiwum

Wewnątrz szablonu motywu (`themes/<motyw>/archive.php`), z użyciem pętli:

```php
<?php while (have_posts()): the_post(); ?>
    <article class="post-card">
        <?php if (has_image()): ?>
            <img src="<?php page_image(); ?>" alt="<?php page_title(); ?>">
        <?php endif; ?>
        <h2><a href="<?php post_url(); ?>"><?php post_title(); ?></a></h2>
        <p><?php post_excerpt(140); ?></p>
        <?php if (has_categories()): ?>
            <div class="post-cats"><?php post_categories(); ?></div>
        <?php endif; ?>
    </article>
<?php endwhile; ?>
```

**Pojęcia:** pętla wpisów, `have_posts()` / `the_post()`, obrazek wyróżniający, wyciąg, kategorie. Patrz [Funkcje motywu](funkcje-motywu.md#3-petla-wpisow-archiwum).

---

## Dalej

* [Tworzenie pluginów](pluginy.md)
* [Wtyczki w stylu GetSimple](wtyczki.md)
* [Funkcje motywu](funkcje-motywu.md)
* [Hooki — referencja](hooki.md)
