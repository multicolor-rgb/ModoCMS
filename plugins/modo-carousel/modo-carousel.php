<?php
/**
 * Plugin Name: Modo Carousel
 * Description: Responsive slider/carousel powered by a bundled Swiper build (no CDN). Slides with image, heading, text and CTA, plus advanced options (autoplay, effects, arrows, pagination, breakpoints), drag & drop ordering and a media-library picker.
 * Version: 1.0.0
 * Author: Modo CMS
 *
 * @package ModoCarousel
 */

if (!class_exists('ModoCarousel')) {

    /**
     * Modo Carousel - sliders manager for Modo CMS.
     */
    final class ModoCarousel
    {
        public const PLUGIN_ID = 'modo-carousel';
        public const VERSION   = '1.0.0';
        public const TAG       = 'modo_carousel';

        private static bool $booted  = false;
        private static bool $cssDone = false;
        private static bool $jsDone  = false;
        private static bool $used    = false;

        public static function init(): void
        {
            if (self::$booted) {
                return;
            }
            self::$booted = true;

            self::ensureTable();

            if (function_exists('register_plugin')) {
                register_plugin(
                    self::PLUGIN_ID,
                    'Modo Carousel',
                    self::VERSION,
                    'Modo CMS',
                    '',
                    'Responsive image/content slider built on a bundled Swiper (no CDN).',
                    'settings',
                    'ModoCarousel::renderAdmin'
                );
            }
            if (function_exists('createSideMenu')) {
                createSideMenu(
                    self::PLUGIN_ID,
                    'Carousel',
                    self::PLUGIN_ID,
                    '<svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v16m16-16v16M8 8h8v8H8z"/></svg>'
                );
            }

            if (defined('IN_ADMIN') && IN_ADMIN === true) {
                self::handleAdminPost();
                \Core\Hooks::addAction('admin-edit-form', [self::class, 'renderEditorInsertScript']);
            } else {
                \Core\Hooks::addFilter('the_content', [self::class, 'renderShortcode'], 20);
                \Core\Hooks::addAction('theme-header', [self::class, 'maybeHeadAssets']);
                \Core\Hooks::addAction('theme-footer', [self::class, 'maybeFooterAssets']);
            }
        }

        public static function assetsUrl(): string
        {
            $base = '';
            if (class_exists('\Core\Router')) {
                $base = rtrim(\Core\Router::getBaseSubdirectory(), '/');
            }
            return $base . '/plugins/' . self::PLUGIN_ID . '/assets';
        }

        private static function db(): ?\PDO
        {
            try {
                return \Core\Database::getConnection();
            } catch (\Throwable $e) {
                return null;
            }
        }

        private static function ensureTable(): void
        {
            $db = self::db();
            if (!$db) {
                return;
            }
            try {
                $db->exec("CREATE TABLE IF NOT EXISTS modo_carousels (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    slug TEXT UNIQUE NOT NULL,
                    items TEXT NOT NULL DEFAULT '[]',
                    options TEXT NOT NULL DEFAULT '{}',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )");
            } catch (\Throwable $e) {
                // ignore
            }
        }

        /** @return array<string,string> */
        public static function defaultOptions(): array
        {
            return [
                'effect'      => 'slide',   // slide | fade | coverflow | cube | flip
                'speed'       => '600',
                'autoplay'    => '1',
                'delay'       => '5000',
                'loop'        => '1',
                'arrows'      => '1',
                'dots'        => '1',
                'pagType'     => 'bullets', // bullets | fraction | progressbar
                'pauseHover'  => '1',
                'perView'     => '1',
                'spaceBetween'=> '0',
                'centered'    => '0',
                'rtl'         => '0',
                'keyboard'    => '1',
                'mousewheel'  => '0',
                'grab'        => '1',
                'height'      => '460',
                'overlay'     => 'gradient', // none | dark | gradient
                'textAlign'   => 'left',     // left | center | right
                'captionPos'  => 'bottom',   // bottom | center | top
                'fullBleed'   => '0',
            ];
        }

        /** @return array<int,array<string,mixed>> */
        public static function allCarousels(): array
        {
            $db = self::db();
            if (!$db) {
                return [];
            }
            try {
                $rows = $db->query("SELECT * FROM modo_carousels ORDER BY id DESC")->fetchAll(\PDO::FETCH_ASSOC);
                return $rows ?: [];
            } catch (\Throwable $e) {
                return [];
            }
        }

        /** @return array<string,mixed>|null */
        public static function findCarousel(int $id): ?array
        {
            if ($id <= 0) {
                return null;
            }
            $db = self::db();
            if (!$db) {
                return null;
            }
            try {
                $stmt = $db->prepare("SELECT * FROM modo_carousels WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                return $row ?: null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        /** @return array<string,mixed>|null */
        public static function findBySlug(string $slug): ?array
        {
            $slug = trim($slug);
            if ($slug === '') {
                return null;
            }
            $db = self::db();
            if (!$db) {
                return null;
            }
            try {
                $stmt = $db->prepare("SELECT * FROM modo_carousels WHERE slug = :s LIMIT 1");
                $stmt->execute([':s' => $slug]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                return $row ?: null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        /** @return array<int,array<string,string>> */
        public static function decodeItems(?string $json): array
        {
            $items = json_decode((string)$json, true);
            if (!is_array($items)) {
                return [];
            }
            $out = [];
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $out[] = [
                    'image'   => (string)($it['image'] ?? ''),
                    'alt'     => (string)($it['alt'] ?? ''),
                    'title'   => (string)($it['title'] ?? ''),
                    'text'    => (string)($it['text'] ?? ''),
                    'link'    => (string)($it['link'] ?? ''),
                    'btntext' => (string)($it['btntext'] ?? ''),
                ];
            }
            return $out;
        }

        /** @return array<string,string> */
        public static function decodeOptions(?string $json, array $defaults): array
        {
            $opts = json_decode((string)$json, true);
            if (!is_array($opts)) {
                $opts = [];
            }
            $out = $defaults;
            foreach ($opts as $k => $v) {
                if (is_scalar($v)) {
                    $out[(string)$k] = (string)$v;
                }
            }
            return $out;
        }

        private static function slugify(string $text): string
        {
            $text = trim($text);
            if ($text === '') {
                return 'carousel';
            }
            if (function_exists('iconv')) {
                $conv = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
                if ($conv !== false) {
                    $text = $conv;
                }
            }
            $text = strtolower($text);
            $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
            $text = trim($text, '-');
            return $text !== '' ? $text : 'carousel';
        }

        private static function uniqueSlug(string $slug, int $ignoreId = 0): string
        {
            $db = self::db();
            $base = self::slugify($slug);
            $candidate = $base;
            $i = 2;
            while ($db) {
                try {
                    $stmt = $db->prepare("SELECT id FROM modo_carousels WHERE slug = :s AND id <> :id LIMIT 1");
                    $stmt->execute([':s' => $candidate, ':id' => $ignoreId]);
                    if ($stmt->fetchColumn() === false) {
                        break;
                    }
                } catch (\Throwable $e) {
                    break;
                }
                $candidate = $base . '-' . $i;
                $i++;
            }
            return $candidate;
        }

        // ---------------------------------------------------------------------
        // Zapis / usuwanie (admin)
        // ---------------------------------------------------------------------

        private static function handleAdminPost(): void
        {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['mc_save'])) {
                if (!\Core\Security::verifyCsrfToken($_POST['mc_csrf'] ?? '')) {
                    header('Location: plugins.php?id=' . self::PLUGIN_ID);
                    exit;
                }
                self::saveCarousel();
            }

            if (isset($_GET['mc_delete'], $_GET['csrf'])) {
                $id = (int)$_GET['mc_delete'];
                if (\Core\Security::verifyCsrfToken((string)$_GET['csrf']) && $id > 0) {
                    self::deleteCarousel($id);
                }
                header('Location: plugins.php?id=' . self::PLUGIN_ID);
                exit;
            }
        }

        private static function saveCarousel(): void
        {
            $db = self::db();
            if (!$db) {
                return;
            }

            $id = (int)($_POST['mc_id'] ?? 0);
            $name = trim((string)($_POST['mc_name'] ?? ''));
            if ($name === '') {
                $name = 'Carousel';
            }
            $slug = self::uniqueSlug((string)($_POST['mc_slug'] ?? $name), $id);

            $defaults = self::defaultOptions();
            $bools = ['autoplay', 'loop', 'arrows', 'dots', 'pauseHover', 'centered', 'rtl', 'keyboard', 'mousewheel', 'grab', 'fullBleed'];
            $options = [];
            foreach ($defaults as $key => $def) {
                $field = 'mc_opt_' . $key;
                if (in_array($key, $bools, true)) {
                    $options[$key] = isset($_POST[$field]) ? '1' : '0';
                } else {
                    $options[$key] = trim((string)($_POST[$field] ?? $def));
                }
            }

            $images = (array)($_POST['mc_item_image'] ?? []);
            $alts   = (array)($_POST['mc_item_alt'] ?? []);
            $titles = (array)($_POST['mc_item_title'] ?? []);
            $texts  = (array)($_POST['mc_item_text'] ?? []);
            $links  = (array)($_POST['mc_item_link'] ?? []);
            $btns   = (array)($_POST['mc_item_btntext'] ?? []);

            $items = [];
            foreach (array_values($images) as $i => $img) {
                $img = trim((string)$img);
                if ($img === '') {
                    continue;
                }
                $items[] = [
                    'image'   => $img,
                    'alt'     => trim((string)($alts[$i] ?? '')),
                    'title'   => trim((string)($titles[$i] ?? '')),
                    'text'    => trim((string)($texts[$i] ?? '')),
                    'link'    => trim((string)($links[$i] ?? '')),
                    'btntext' => trim((string)($btns[$i] ?? '')),
                ];
            }

            $itemsJson   = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $optionsJson = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            try {
                if ($id > 0) {
                    $stmt = $db->prepare("UPDATE modo_carousels
                        SET name = :n, slug = :s, items = :i, options = :o, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id");
                    $stmt->execute([':n' => $name, ':s' => $slug, ':i' => $itemsJson, ':o' => $optionsJson, ':id' => $id]);
                } else {
                    $stmt = $db->prepare("INSERT INTO modo_carousels (name, slug, items, options)
                        VALUES (:n, :s, :i, :o)");
                    $stmt->execute([':n' => $name, ':s' => $slug, ':i' => $itemsJson, ':o' => $optionsJson]);
                    $id = (int)$db->lastInsertId();
                }
            } catch (\Throwable $e) {
                // ignore
            }

            if (class_exists('\Core\PageCache')) {
                \Core\PageCache::purge();
            }

            $savedId = $id > 0 ? $id : (int)($_POST['mc_id'] ?? 0);
            $target = 'plugins.php?id=' . self::PLUGIN_ID . '&mc_view=edit&mc_saved=1';
            if ($savedId > 0) {
                $target .= '&cid=' . $savedId;
            }
            if (!headers_sent()) {
                header('Location: ' . $target);
                exit;
            }
        }

        private static function deleteCarousel(int $id): void
        {
            $db = self::db();
            if (!$db) {
                return;
            }
            try {
                $stmt = $db->prepare("DELETE FROM modo_carousels WHERE id = :id");
                $stmt->execute([':id' => $id]);
            } catch (\Throwable $e) {
                // ignore
            }
            if (class_exists('\Core\PageCache')) {
                \Core\PageCache::purge();
            }
        }

        // ---------------------------------------------------------------------
        // Panel administracyjny
        // ---------------------------------------------------------------------

        public static function renderAdmin(): void
        {
            if (!class_exists('\Core\Security')) {
                return;
            }
            $view = (string)($_GET['mc_view'] ?? 'list');
            $csrf = \Core\Security::generateCsrfToken();
            $esc  = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

            echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">';
            echo '<div><h2 style="margin:0;font-size:18px;">' . $esc(__('mc_plugin_name')) . '</h2>';
            echo '<div style="font-size:12px;color:var(--text-muted);margin-top:4px;font-family:monospace;">' . $esc('[modo_carousel id="1"]  |  &lt;?php the_modo_carousel(1); ?&gt;') . '</div></div>';
            if ($view === 'edit') {
                echo '<a class="btn btn-secondary" style="font-size:12px;" href="plugins.php?id=' . self::PLUGIN_ID . '">&larr; ' . $esc(__('mc_back')) . '</a>';
            } else {
                echo '<a class="btn btn-primary" style="font-size:12px;" href="plugins.php?id=' . self::PLUGIN_ID . '&mc_view=edit">+ ' . $esc(__('mc_new_carousel')) . '</a>';
            }
            echo '</div>';

            echo '<style>' . self::adminStyles() . '</style>';

            if ($view === 'edit') {
                self::renderEditor($csrf, $esc);
            } else {
                self::renderList($csrf, $esc);
            }
        }

        private static function adminStyles(): string
        {
            return '
            .mc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;}
            .mc-items{display:flex;flex-direction:column;gap:10px;margin:8px 0 4px;}
            .mc-item{display:grid;grid-template-columns:28px 96px minmax(0,1fr) 32px;gap:10px;align-items:start;padding:10px;border:1px solid var(--border-subtle);border-radius:10px;background:var(--bg-surface,#fff);}
            .mc-item img{width:96px;height:64px;object-fit:cover;border-radius:6px;background:rgba(0,0,0,.05);}
            .mc-item .mc-fields{display:grid;grid-template-columns:1fr 1fr;gap:6px;}
            .mc-item .mc-fields .mc-full{grid-column:1 / -1;}
            .mc-drag{cursor:grab;color:var(--text-muted);text-align:center;font-size:18px;user-select:none;padding-top:20px;}
            .mc-item.mc-ghost{opacity:.4;border-style:dashed;}
            .mc-btn-x{border:none;background:transparent;color:#dc2626;font-size:20px;line-height:1;cursor:pointer;padding:0 6px;}
            .mc-picker{position:fixed;inset:0;background:rgba(11,15,25,.8);z-index:99999;display:none;align-items:center;justify-content:center;backdrop-filter:blur(6px);}
            .mc-picker.open{display:flex;}
            .mc-picker-inner{width:92%;max-width:900px;max-height:86vh;display:flex;flex-direction:column;background:var(--bg-card,#fff);border:1px solid var(--border-subtle);border-radius:14px;overflow:hidden;}
            .mc-picker-head{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border-subtle);font-weight:700;}
            .mc-picker-body{padding:18px;overflow-y:auto;display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;}
            .mc-pick-thumb{border:2px solid transparent;border-radius:8px;overflow:hidden;cursor:pointer;background:rgba(0,0,0,.05);aspect-ratio:1;display:flex;align-items:center;justify-content:center;}
            .mc-pick-thumb.sel{border-color:var(--primary,#3b82f6);}
            .mc-pick-thumb img{width:100%;height:100%;object-fit:cover;}
            .mc-picker-foot{padding:14px 20px;border-top:1px solid var(--border-subtle);display:flex;gap:10px;justify-content:flex-end;}
            .mc-empty{padding:20px;color:var(--text-muted);text-align:center;grid-column:1/-1;}
            .mc-win{display:flex;align-items:center;gap:8px;font-weight:600;}
            ';
        }

        private static function renderList(string $csrf, callable $esc): void
        {
            $carousels = self::allCarousels();
            if (empty($carousels)) {
                echo '<div class="card" style="padding:30px;text-align:center;color:var(--text-muted);">' . $esc(__('mc_no_carousels')) . '</div>';
                return;
            }

            echo '<div class="table-container"><table class="pro-table"><thead><tr>';
            echo '<th>' . $esc(__('mc_name')) . '</th>';
            echo '<th style="width:90px;">' . $esc(__('mc_slides')) . '</th>';
            echo '<th>' . $esc(__('mc_shortcode')) . '</th>';
            echo '<th style="text-align:right;width:180px;">' . $esc(__('mc_actions')) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($carousels as $c) {
                $id    = (int)$c['id'];
                $items = self::decodeItems($c['items'] ?? '[]');
                $sc    = '[' . self::TAG . ' id="' . $id . '"]';
                echo '<tr>';
                echo '<td><strong>' . $esc((string)$c['name']) . '</strong><div style="font-size:11px;color:var(--text-muted);font-family:monospace;">' . $esc((string)$c['slug']) . '</div></td>';
                echo '<td>' . count($items) . '</td>';
                echo '<td><div style="display:flex;align-items:center;gap:6px;">'
                    . '<code style="font-size:12px;">' . $esc($sc) . '</code>'
                    . '<button type="button" class="btn btn-secondary mc-copy" data-shortcode="' . $esc($sc) . '" style="padding:2px 8px;font-size:11px;">' . $esc(__('mc_copy')) . '</button>'
                    . '</div></td>';
                echo '<td style="text-align:right;white-space:nowrap;">'
                    . '<a class="btn btn-secondary" style="padding:4px 10px;font-size:12px;" href="plugins.php?id=' . self::PLUGIN_ID . '&mc_view=edit&cid=' . $id . '">' . $esc(__('mc_edit')) . '</a> '
                    . '<a class="btn btn-danger-ghost mc-confirm" href="plugins.php?id=' . self::PLUGIN_ID . '&mc_delete=' . $id . '&csrf=' . $esc($csrf) . '" data-confirm="' . $esc(__('mc_confirm_delete')) . '" style="padding:4px 10px;font-size:12px;">' . $esc(__('mc_delete')) . '</a>'
                    . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            echo self::listScript($esc(__('mc_copied')));
        }

        private static function listScript(string $copied): string
        {
            return '<script>
            (function(){
                document.querySelectorAll(".mc-copy").forEach(function(b){
                    b.addEventListener("click", function(){
                        var t = b.getAttribute("data-shortcode") || "";
                        if(navigator.clipboard){ navigator.clipboard.writeText(t); }
                        var o = b.textContent; b.textContent = ' . json_encode($copied) . ';
                        setTimeout(function(){ b.textContent = o; }, 1500);
                    });
                });
                document.querySelectorAll(".mc-confirm").forEach(function(a){
                    a.addEventListener("click", function(e){
                        if(!window.confirm(a.getAttribute("data-confirm") || "Are you sure?")){ e.preventDefault(); }
                    });
                });
            })();
            </script>';
        }

        // ---------------------------------------------------------------------
        // Edytor carousela
        // ---------------------------------------------------------------------

        /** @return array<int,array<string,string>> */
        private static function mediaItems(): array
        {
            $dir  = dirname(__DIR__, 2) . '/uploads/';
            $base = class_exists('\Core\Router') ? rtrim(\Core\Router::getBaseSubdirectory(), '/') : '';
            $out  = [];
            if (!is_dir($dir)) {
                return $out;
            }
            $files = scandir($dir, SCANDIR_SORT_DESCENDING) ?: [];
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                if (!is_file($dir . $file)) {
                    continue;
                }
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'], true)) {
                    $out[] = ['name' => $file, 'url' => $base . '/uploads/' . $file];
                }
            }
            return $out;
        }

        private static function itemRow(array $it, callable $esc): string
        {
            $img = (string)($it['image'] ?? '');
            return '<div class="mc-item">'
                . '<div class="mc-drag" draggable="true" title="' . $esc(__('mc_drag')) . '">&#9776;</div>'
                . '<img src="' . $esc($img) . '" alt="">'
                . '<div class="mc-fields">'
                . '<input type="hidden" name="mc_item_image[]" value="' . $esc($img) . '">'
                . '<button type="button" class="btn btn-secondary mc-choose" style="font-size:11px;padding:4px 8px;">' . $esc(__('mc_choose')) . '</button>'
                . '<input class="form-control" type="text" name="mc_item_alt[]" placeholder="' . $esc(__('mc_alt')) . '" value="' . $esc((string)($it['alt'] ?? '')) . '" style="font-size:12px;">'
                . '<input class="form-control mc-full" type="text" name="mc_item_title[]" placeholder="' . $esc(__('mc_slide_title')) . '" value="' . $esc((string)($it['title'] ?? '')) . '" style="font-size:12px;">'
                . '<textarea class="form-control mc-full" name="mc_item_text[]" placeholder="' . $esc(__('mc_slide_text')) . '" rows="2" style="font-size:12px;">' . $esc((string)($it['text'] ?? '')) . '</textarea>'
                . '<input class="form-control" type="text" name="mc_item_link[]" placeholder="' . $esc(__('mc_slide_link')) . '" value="' . $esc((string)($it['link'] ?? '')) . '" style="font-size:12px;">'
                . '<input class="form-control" type="text" name="mc_item_btntext[]" placeholder="' . $esc(__('mc_slide_btn')) . '" value="' . $esc((string)($it['btntext'] ?? '')) . '" style="font-size:12px;">'
                . '</div>'
                . '<button type="button" class="mc-btn-x" title="' . $esc(__('mc_remove')) . '">&times;</button>'
                . '</div>';
        }

        private static function renderEditor(string $csrf, callable $esc): void
        {
            $cid      = (int)($_GET['cid'] ?? 0);
            $c        = $cid > 0 ? self::findCarousel($cid) : null;
            $defaults = self::defaultOptions();
            $opts     = $c ? self::decodeOptions($c['options'] ?? '{}', $defaults) : $defaults;
            $items    = $c ? self::decodeItems($c['items'] ?? '[]') : [];

            $name  = $c ? (string)$c['name'] : '';
            $slug  = $c ? (string)$c['slug'] : '';
            $idVal = $c ? (int)$c['id'] : 0;

            $sel = static fn(string $k, string $val): string => ($opts[$k] ?? '') === $val ? ' selected' : '';
            $chk = static fn(string $k): string => ($opts[$k] ?? '') === '1' ? ' checked' : '';
            $v   = static fn(string $k): string => (string)($opts[$k] ?? '');

            if (isset($_GET['mc_saved'])) {
                echo '<div class="card" style="padding:14px 18px;margin-bottom:16px;border-left:4px solid #16a34a;background:#f0fdf4;color:#166534;">' . $esc(__('mc_saved')) . '</div>';
            }

            echo '<form method="post" action="plugins.php?id=' . self::PLUGIN_ID . '">';
            echo '<input type="hidden" name="mc_save" value="1">';
            echo '<input type="hidden" name="mc_csrf" value="' . $esc($csrf) . '">';
            echo '<input type="hidden" name="mc_id" value="' . $idVal . '">';

            echo '<div class="card" style="padding:20px;margin-bottom:16px;"><div class="mc-grid">';
            echo '<div class="form-group"><label class="form-label">' . $esc(__('mc_name')) . '</label>'
                . '<input class="form-control" type="text" name="mc_name" value="' . $esc($name) . '" placeholder="' . $esc(__('mc_carousel')) . '" required></div>';
            echo '<div class="form-group"><label class="form-label">' . $esc(__('mc_slug')) . '</label>'
                . '<input class="form-control" type="text" name="mc_slug" value="' . $esc($slug) . '" placeholder="' . $esc(__('mc_slug_hint')) . '"></div>';
            echo '</div></div>';

            echo '<div class="card" style="padding:20px;margin-bottom:16px;">';
            echo '<h3 style="margin:0 0 14px;font-size:15px;">' . $esc(__('mc_options')) . '</h3>';
            echo '<div class="mc-grid">';

            echo '<div class="form-group"><label class="form-label">' . $esc(__('mc_effect')) . '</label><select class="form-control" name="mc_opt_effect">'
                . '<option value="slide"' . $sel('effect', 'slide') . '>' . $esc(__('mc_effect_slide')) . '</option>'
                . '<option value="fade"' . $sel('effect', 'fade') . '>' . $esc(__('mc_effect_fade')) . '</option>'
                . '<option value="coverflow"' . $sel('effect', 'coverflow') . '>' . $esc(__('mc_effect_coverflow')) . '</option>'
                . '<option value="cube"' . $sel('effect', 'cube') . '>' . $esc(__('mc_effect_cube')) . '</option>'
                . '<option value="flip"' . $sel('effect', 'flip') . '>' . $esc(__('mc_effect_flip')) . '</option>'
                . '</select></div>';

            echo '<div class="form-group"><label class="form-label">' . $esc(__('mc_per_view')) . '</label><select class="form-control" name="mc_opt_perView">';
            foreach (['1', '2', '3', '4'] as $pv) {
                echo '<option value="' . $pv . '"' . $sel('perView', $pv) . '>' . $pv . '</option>';
            }
            echo '</select></div>';

            echo '<div class="form-group"><label class="form-label">' . $esc(__('mc_speed')) . ' (ms)</label><input class="form-control" type="number" min="100" max="3000" step="50" name="mc_opt_speed" value="' . $esc($v('speed')) . '"></div>';
            echo '<div class="form-group"><label class="form-label">' . $esc(__('mc_delay')) . ' (ms)</label><input class="form-control" type="number" min="500" max="20000" step="100" name="mc_opt_delay" value="' . $esc($v('delay')) . '"></div>';
            echo '<div class="form-group"><label class="form-label">' . $esc(__('mc_space')) . ' (px)</label><input class="form-control" type="number" min="0" max="80" name="mc_opt_spaceBetween" value="' . $esc($v('spaceBetween')) . '"></div>';
            echo '<div class="form-group"><label class="form-label">' . $esc(__('mc_height')) . ' (px)</label><input class="form-control" type="number" min="180" max="900" name="mc_opt_height" value="' . $esc($v('height')) . '"></div>';
            echo '<div class="form-group"><label class="form-label">' . $esc(__('mc_overlay')) . '</label><select class="form-control" name="mc_opt_overlay">'
                . '<option value="none"' . $sel('overlay', 'none') . '>' . $esc(__('mc_overlay_none')) . '</option>'
                . '<option value="dark"' . $sel('overlay', 'dark') . '>' . $esc(__('mc_overlay_dark')) . '</option>'
                . '<option value="gradient"' . $sel('overlay', 'gradient') . '>' . $esc(__('mc_overlay_gradient')) . '</option>'
                . '</select></div>';

            echo '<div class="form-group"><label class="form-label">' . $esc(__('mc_text_align')) . '</label><select class="form-control" name="mc_opt_textAlign">'
                . '<option value="left"' . $sel('textAlign', 'left') . '>' . $esc(__('mc_align_left')) . '</option>'
                . '<option value="center"' . $sel('textAlign', 'center') . '>' . $esc(__('mc_align_center')) . '</option>'
                . '<option value="right"' . $sel('textAlign', 'right') . '>' . $esc(__('mc_align_right')) . '</option>'
                . '</select></div>';

            echo '<div class="form-group"><label class="form-label">' . $esc(__('mc_caption_pos')) . '</label><select class="form-control" name="mc_opt_captionPos">'
                . '<option value="bottom"' . $sel('captionPos', 'bottom') . '>' . $esc(__('mc_pos_bottom')) . '</option>'
                . '<option value="center"' . $sel('captionPos', 'center') . '>' . $esc(__('mc_pos_center')) . '</option>'
                . '<option value="top"' . $sel('captionPos', 'top') . '>' . $esc(__('mc_pos_top')) . '</option>'
                . '</select></div>';

            echo '<div class="form-group"><label class="form-label">' . $esc(__('mc_pagination')) . '</label><select class="form-control" name="mc_opt_pagType">'
                . '<option value="bullets"' . $sel('pagType', 'bullets') . '>' . $esc(__('mc_pag_bullets')) . '</option>'
                . '<option value="fraction"' . $sel('pagType', 'fraction') . '>' . $esc(__('mc_pag_fraction')) . '</option>'
                . '<option value="progressbar"' . $sel('pagType', 'progressbar') . '>' . $esc(__('mc_pag_progress')) . '</option>'
                . '</select></div>';

            echo '</div><!-- /mc-grid -->';

            echo '<div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:14px;">';
            $boxes = [
                'autoplay'   => __('mc_autoplay'),
                'loop'       => __('mc_loop'),
                'arrows'     => __('mc_arrows'),
                'dots'       => __('mc_dots'),
                'pauseHover' => __('mc_pause_hover'),
                'centered'   => __('mc_centered'),
                'grab'       => __('mc_grab'),
                'keyboard'   => __('mc_keyboard'),
                'mousewheel' => __('mc_mousewheel'),
                'rtl'        => __('mc_rtl'),
                'fullBleed'  => __('mc_full_bleed'),
            ];
            foreach ($boxes as $key => $label) {
                echo '<label class="mc-win"><input type="checkbox" name="mc_opt_' . $key . '" value="1"' . $chk($key) . '> ' . $esc((string)$label) . '</label>';
            }
            echo '</div>';

            echo '</div><!-- /card options -->';

            // Slides
            echo '<div class="card" style="padding:20px;margin-bottom:16px;">';
            echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">';
            echo '<h3 style="margin:0;font-size:15px;">' . $esc(__('mc_slides')) . '</h3>';
            echo '<button type="button" class="btn btn-primary" id="mc-add" style="font-size:12px;padding:6px 12px;">+ ' . $esc(__('mc_add_slides')) . '</button>';
            echo '</div>';
            echo '<div class="mc-items" id="mc-items">';
            if (empty($items)) {
                echo '<div class="mc-empty" id="mc-empty">' . $esc(__('mc_no_slides')) . '</div>';
            } else {
                foreach ($items as $it) {
                    echo self::itemRow($it, $esc);
                }
            }
            echo '</div>';
            echo '</div><!-- /card slides -->';

            echo '<div style="display:flex;gap:10px;margin-bottom:20px;">';
            echo '<button type="submit" class="btn btn-primary">' . $esc(__('mc_save')) . '</button>';
            echo '<a class="btn btn-secondary" href="plugins.php?id=' . self::PLUGIN_ID . '">' . $esc(__('mc_cancel')) . '</a>';
            echo '</div>';
            echo '</form>';

            // Media picker
            $base  = class_exists('\Core\Router') ? rtrim(\Core\Router::getBaseSubdirectory(), '/') : '';
            $media = self::mediaItems();
            echo '<div class="mc-picker" id="mc-picker"><div class="mc-picker-inner">';
            echo '<div class="mc-picker-head"><span>' . $esc(__('mc_choose_media')) . '</span><button type="button" class="mc-btn-x" id="mc-picker-close">&times;</button></div>';
            echo '<div class="mc-picker-body" id="mc-picker-body">';
            if (empty($media)) {
                echo '<div class="mc-empty">' . $esc(__('mc_no_media')) . '</div>';
            } else {
                foreach ($media as $m) {
                    echo '<div class="mc-pick-thumb" data-url="' . $esc($m['url']) . '"><img src="' . $esc($m['url']) . '" alt="' . $esc($m['name']) . '" loading="lazy"></div>';
                }
            }
            echo '</div>';
            echo '<div class="mc-picker-foot"><button type="button" class="btn btn-primary" id="mc-picker-add">' . $esc(__('mc_insert')) . '</button></div>';
            echo '</div></div>';

            echo self::editorScript();
        }

        private static function editorScript(): string
        {
            $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
            $tpl = self::itemRow(['image' => '', 'alt' => '', 'title' => '', 'text' => '', 'link' => '', 'btntext' => ''], $esc);

            return '<template id="mc-tpl">' . $tpl . '</template>
            <script>
            (function(){
                var picker=document.getElementById("mc-picker");
                var itemsBox=document.getElementById("mc-items");
                var tplEl=document.getElementById("mc-tpl");
                if(!picker||!itemsBox||!tplEl) return;
                var mode="add", targetRow=null, dragEl=null;

                function clearSel(){ picker.querySelectorAll(".mc-pick-thumb.sel").forEach(function(t){t.classList.remove("sel");}); }
                function open(m,t){ mode=m; targetRow=t||null; clearSel(); picker.classList.add("open"); }
                function close(){ picker.classList.remove("open"); }

                function addRow(url){
                    var node=tplEl.content.firstElementChild.cloneNode(true);
                    node.querySelector("img").src=url;
                    node.querySelector("input[name=\"mc_item_image[]\"]").value=url;
                    var e=document.getElementById("mc-empty"); if(e) e.remove();
                    itemsBox.appendChild(node);
                    return node;
                }

                var addBtn=document.getElementById("mc-add");
                if(addBtn){ addBtn.addEventListener("click",function(){ open("add",null); }); }
                var closeBtn=document.getElementById("mc-picker-close");
                if(closeBtn){ closeBtn.addEventListener("click",close); }
                picker.addEventListener("click",function(e){ if(e.target===picker) close(); });

                picker.querySelectorAll(".mc-pick-thumb").forEach(function(t){
                    t.addEventListener("click",function(){
                        if(mode==="replace"){ clearSel(); t.classList.add("sel"); }
                        else { t.classList.toggle("sel"); }
                    });
                });

                var insertBtn=document.getElementById("mc-picker-add");
                if(insertBtn){ insertBtn.addEventListener("click",function(){
                    var sel=[].slice.call(picker.querySelectorAll(".mc-pick-thumb.sel")).map(function(t){return t.getAttribute("data-url");});
                    if(mode==="replace"){
                        if(sel.length && targetRow){
                            targetRow.querySelector("img").src=sel[0];
                            targetRow.querySelector("input[name=\"mc_item_image[]\"]").value=sel[0];
                        }
                    } else {
                        sel.forEach(function(u){ addRow(u); });
                    }
                    close();
                }); }

                itemsBox.addEventListener("click",function(e){
                    var ch=e.target.closest(".mc-choose");
                    if(ch){ open("replace", ch.closest(".mc-item")); return; }
                    var x=e.target.closest(".mc-btn-x");
                    if(x){ var it=x.closest(".mc-item"); if(it) it.remove(); }
                });

                itemsBox.addEventListener("dragstart",function(e){
                    var h=e.target.closest(".mc-drag");
                    if(!h) return;
                    dragEl=h.closest(".mc-item");
                    e.dataTransfer.effectAllowed="move";
                    setTimeout(function(){ if(dragEl) dragEl.classList.add("mc-ghost"); },0);
                });
                itemsBox.addEventListener("dragend",function(){ if(dragEl){ dragEl.classList.remove("mc-ghost"); dragEl=null; } });
                itemsBox.addEventListener("dragover",function(e){
                    e.preventDefault();
                    if(!dragEl) return;
                    var it=e.target.closest(".mc-item");
                    if(!it||it===dragEl) return;
                    var rect=it.getBoundingClientRect();
                    var after=(e.clientY-rect.top)/rect.height>0.5;
                    itemsBox.insertBefore(dragEl, after? it.nextSibling : it);
                });
            })();
            </script>';
        }

        // ---------------------------------------------------------------------
        // Przycisk wstawiania w edytorze TinyMCE (hook admin-edit-form)
        // ---------------------------------------------------------------------

        public static function renderEditorInsertScript(): void
        {
            $items = [];
            foreach (self::allCarousels() as $c) {
                $items[] = ['id' => (int)$c['id'], 'name' => (string)$c['name']];
            }
            $payload = [
                'items'  => $items,
                'labels' => [
                    'title' => __('mc_insert_carousel'),
                    'none'  => __('mc_no_carousels'),
                    'label' => __('mc_carousel'),
                ],
            ];
            ?>
            <script type="application/json" id="mc-editor-data"><?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
            <script>
            (function(){
                var data=document.getElementById("mc-editor-data");
                if(!data) return;
                var cfg=JSON.parse(data.textContent||"{}");
                function patch(){
                    if(!window.tinymce||typeof window.tinymce.init!=="function"||window.tinymce.__modoCarouselPatched) return !!window.tinymce;
                    window.tinymce.__modoCarouselPatched=true;
                    var orig=window.tinymce.init;
                    window.tinymce.init=function(c){
                        var conf=c||{};
                        if(typeof conf.toolbar==="string" && conf.toolbar.indexOf("modo_carousel")===-1){
                            conf.toolbar=conf.toolbar+" | modo_carousel";
                        }
                        var os=conf.setup;
                        conf.setup=function(editor){
                            if(typeof os==="function"){ os(editor); }
                            editor.ui.registry.addButton("modo_carousel",{
                                icon:"gallery",
                                tooltip:cfg.labels.title,
                                onAction:function(){
                                    var list=cfg.items||[];
                                    if(!list.length){ editor.notificationManager.open({text:cfg.labels.none,type:"info"}); return; }
                                    editor.windowManager.open({
                                        title:cfg.labels.title,
                                        body:{type:"panel",items:[{type:"select",name:"cid",label:cfg.labels.label,
                                            items:list.map(function(g){return {text:g.name+" (#"+g.id+")",value:String(g.id)};})}]},
                                        onSubmit:function(api){
                                            var d=api.getData();
                                            editor.insertContent('[modo_carousel id="'+d.cid+'"]');
                                            api.close();
                                        }
                                    });
                                }
                            });
                        };
                        return orig.apply(this,arguments);
                    };
                    return true;
                }
                if(!patch()){
                    var tries=0;
                    var iv=setInterval(function(){ tries++; if(patch()||tries>60){ clearInterval(iv); } },100);
                }
            })();
            </script>
            <?php
        }

        // ---------------------------------------------------------------------
        // Frontend – renderowanie shortcode
        // ---------------------------------------------------------------------

        public static function renderShortcode(string $content): string
        {
            if ($content === '' || !str_contains($content, '[' . self::TAG)) {
                return $content;
            }
            return (string)preg_replace_callback(
                '/\[modo_carousel\b([^\]]*)\]/i',
                static function (array $m): string {
                    $attrs = (string)($m[1] ?? '');
                    $id = 0;
                    $slug = '';
                    if (preg_match('/id\s*=\s*["\']?(\d+)["\']?/i', $attrs, $mm)) {
                        $id = (int)$mm[1];
                    }
                    if (preg_match('/slug\s*=\s*["\']?([a-z0-9\-_]+)["\']?/i', $attrs, $mm)) {
                        $slug = $mm[1];
                    }
                    $c = $id > 0 ? self::findCarousel($id) : ($slug !== '' ? self::findBySlug($slug) : null);
                    return $c ? self::renderOne($c) : '';
                },
                $content
            );
        }

        private static function mediaUrl(string $path): string
        {
            if ($path === '' || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                return $path;
            }
            if (function_exists('resolve_media_url')) {
                return resolve_media_url($path);
            }
            $base = class_exists('\Core\Router') ? rtrim(\Core\Router::getBaseSubdirectory(), '/') : '';
            return $base . '/' . ltrim($path, '/');
        }

        private static function renderOne(array $c, array $override = []): string
        {
            self::$used = true;

            $defaults = self::defaultOptions();
            $opts     = self::decodeOptions($c['options'] ?? '{}', $defaults);

            // Per-call overrides (used by template tags).
            foreach ($override as $okey => $oval) {
                $okey = (string)$okey;
                if (array_key_exists($okey, $defaults) && is_scalar($oval)) {
                    $opts[$okey] = (string)$oval;
                }
            }

            $items    = self::decodeItems($c['items'] ?? '[]');
            if (empty($items)) {
                return '';
            }

            $id = (int)$c['id'];

            $effect  = in_array($opts['effect'], ['slide', 'fade', 'coverflow', 'cube', 'flip'], true) ? $opts['effect'] : 'slide';
            $perView = max(1, min(4, (int)$opts['perView']));
            $space   = max(0, min(80, (int)$opts['spaceBetween']));
            $height  = max(180, min(900, (int)$opts['height']));
            $speed   = max(100, min(3000, (int)$opts['speed']));
            $delay   = max(500, min(20000, (int)$opts['delay']));
            $overlay = in_array($opts['overlay'], ['none', 'dark', 'gradient'], true) ? $opts['overlay'] : 'gradient';
            $align   = in_array($opts['textAlign'], ['left', 'center', 'right'], true) ? $opts['textAlign'] : 'left';
            $pos     = in_array($opts['captionPos'], ['bottom', 'center', 'top'], true) ? $opts['captionPos'] : 'bottom';
            $pagType = in_array($opts['pagType'], ['bullets', 'fraction', 'progressbar'], true) ? $opts['pagType'] : 'bullets';

            $bool = static fn(string $k): bool => ($opts[$k] ?? '0') === '1';

            $swiper = [
                'effect'         => $effect,
                'speed'          => $speed,
                'loop'           => $bool('loop'),
                'slidesPerView'  => $perView,
                'spaceBetween'   => $space,
                'centeredSlides' => $bool('centered'),
                'grabCursor'     => $bool('grab'),
                'rtl'            => $bool('rtl'),
                'allowTouchMove' => true,
            ];
            if ($effect === 'fade') {
                $swiper['fadeEffect'] = ['crossFade' => true];
            } elseif ($effect === 'coverflow') {
                $swiper['coverflowEffect'] = ['rotate' => 30, 'stretch' => 0, 'depth' => 120, 'modifier' => 1, 'slideShadows' => true];
            } elseif ($effect === 'cube') {
                $swiper['cubeEffect'] = ['shadow' => true, 'slideShadows' => true, 'shadowOffset' => 20, 'shadowScale' => 0.94];
            } elseif ($effect === 'flip') {
                $swiper['flipEffect'] = ['slideShadows' => true, 'limitRotation' => true];
            }
            $swiper['autoplay'] = $bool('autoplay')
                ? ['delay' => $delay, 'disableOnInteraction' => false, 'pauseOnMouseEnter' => $bool('pauseHover')]
                : false;
            $swiper['navigation'] = $bool('arrows')
                ? ['nextEl' => '#modo-carousel-' . $id . ' .swiper-button-next', 'prevEl' => '#modo-carousel-' . $id . ' .swiper-button-prev']
                : false;
            $swiper['pagination'] = $bool('dots')
                ? ['el' => '#modo-carousel-' . $id . ' .swiper-pagination', 'type' => $pagType, 'clickable' => true]
                : false;
            $swiper['keyboard'] = $bool('keyboard') ? ['enabled' => true, 'onlyInViewport' => true] : false;
            $swiper['mousewheel'] = $bool('mousewheel') ? ['forceToAxis' => true] : false;

            $esc  = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
            $json = $esc((string)json_encode($swiper, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $classes = 'modo-carousel swiper modo-carousel--' . $overlay . ' modo-carousel--align-' . $align
                . ' modo-carousel--pos-' . $pos . ($bool('fullBleed') ? ' modo-carousel--full' : '');

            $html = '<div class="' . $classes . '" id="modo-carousel-' . $id . '"'
                . ' data-swiper="' . $json . '" role="region" aria-roledescription="carousel"'
                . ' style="--mc-height:' . $height . 'px;">';
            $html .= '<div class="swiper-wrapper">';

            $name = (string)($c['name'] ?? '');
            foreach ($items as $it) {
                $img = (string)$it['image'];
                if ($img === '') {
                    continue;
                }
                $src  = self::mediaUrl($img);
                $alt  = $it['alt'] !== '' ? $it['alt'] : $name;
                $ti   = $it['title'];
                $tx   = $it['text'];
                $link = $it['link'];
                $btn  = $it['btntext'];

                $html .= '<div class="swiper-slide">';
                $html .= '<div class="modo-carousel__media"><img src="' . $esc($src) . '" alt="' . $esc($alt) . '" loading="lazy" decoding="async"></div>';
                if ($ti !== '' || $tx !== '' || $link !== '') {
                    $html .= '<div class="modo-carousel__body">';
                    if ($ti !== '') {
                        $html .= '<h2 class="modo-carousel__title">' . $esc($ti) . '</h2>';
                    }
                    if ($tx !== '') {
                        $html .= '<div class="modo-carousel__text">' . nl2br($esc($tx)) . '</div>';
                    }
                    if ($link !== '') {
                        $html .= '<a class="modo-carousel__btn" href="' . $esc($link) . '">' . $esc($btn !== '' ? $btn : $ti) . '</a>';
                    }
                    $html .= '</div>';
                }
                $html .= '</div>';
            }

            $html .= '</div>'; // .swiper-wrapper
            if ($bool('arrows')) {
                $html .= '<div class="swiper-button-prev" aria-label="Previous"></div><div class="swiper-button-next" aria-label="Next"></div>';
            }
            if ($bool('dots')) {
                $html .= '<div class="swiper-pagination"></div>';
            }
            $html .= '</div>';

            return $html;
        }

        // ---------------------------------------------------------------------
        // Frontend – assety (CSS/JS) ladowane warunkowo
        // ---------------------------------------------------------------------

        public static function maybeHeadAssets(): void
        {
            if (self::pageMayContain()) {
                self::emitCss();
            }
        }

        public static function maybeFooterAssets(): void
        {
            if (self::pageMayContain() || self::$used) {
                self::emitCss();
                self::emitJs();
            }
        }

        private static function pageMayContain(): bool
        {
            $content = '';
            if (class_exists('ThemeState')) {
                $content = (string)(\ThemeState::$currentPage['content'] ?? '');
            }
            return $content !== '' && str_contains($content, '[' . self::TAG);
        }

        private static function emitCss(): void
        {
            if (self::$cssDone) {
                return;
            }
            self::$cssDone = true;
            $u = self::assetsUrl();
            echo '<link rel="stylesheet" href="' . htmlspecialchars($u . '/vendor/swiper/swiper-bundle.min.css', ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
            echo '<link rel="stylesheet" href="' . htmlspecialchars($u . '/carousel.css', ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
        }

        private static function emitJs(): void
        {
            if (self::$jsDone) {
                return;
            }
            self::$jsDone = true;
            $u = self::assetsUrl();
            echo '<script src="' . htmlspecialchars($u . '/vendor/swiper/swiper-bundle.min.js', ENT_QUOTES, 'UTF-8') . '"></script>' . PHP_EOL;
            echo '<script src="' . htmlspecialchars($u . '/carousel.js', ENT_QUOTES, 'UTF-8') . '"></script>' . PHP_EOL;
        }

        // ---------------------------------------------------------------------
        // API dla szablonow motywu (osadzanie w kodzie PHP)
        // ---------------------------------------------------------------------

        /** Emituje assety (CSS/JS) raz - przydatne przy osadzaniu w szablonie. */
        public static function enqueueAssets(): void
        {
            self::emitCss();
            self::emitJs();
        }

        /** @return array<string,mixed>|null */
        public static function resolve(string|int $idOrSlug): ?array
        {
            if (is_int($idOrSlug) || (is_string($idOrSlug) && ctype_digit($idOrSlug))) {
                return self::findCarousel((int)$idOrSlug);
            }
            return self::findBySlug((string)$idOrSlug);
        }

        public static function exists(string|int $idOrSlug): bool
        {
            return self::resolve($idOrSlug) !== null;
        }

        /**
         * Zwraca HTML carousela (do uzycia w kodzie szablonu). $args nadpisuje opcje.
         *
         * @param array<string,mixed> $args
         */
        public static function renderTpl(string|int $idOrSlug, array $args = []): string
        {
            $c = self::resolve($idOrSlug);
            return $c ? self::renderOne($c, $args) : '';
        }

        /**
         * Wypisuje HTML carousela i jednorazowo doladowuje assety.
         *
         * @param array<string,mixed> $args
         */
        public static function renderTplEcho(string|int $idOrSlug, array $args = []): void
        {
            $html = self::renderTpl($idOrSlug, $args);
            if ($html === '') {
                return;
            }
            self::enqueueAssets();
            echo $html;
        }
    }
}

// Boot the plugin as soon as it is loaded by the plugin loader.
ModoCarousel::init();

/*
 * Template tags (do uzycia w plikach motywu):
 *   the_modo_carousel(1);                // wypisuje carousel o id = 1
 *   the_modo_carousel('moj-slider');     // wypisuje carousel po slug
 *   echo modo_carousel(1);               // zwraca HTML (string)
 *   echo modo_carousel(1, ['height' => 620, 'autoplay' => 0, 'arrows' => 1]);
 *   if (has_modo_carousel(1)) { ... }
 */
if (!function_exists('modo_carousel')) {
    function modo_carousel(string|int $idOrSlug = 0, array $args = []): string
    {
        return ModoCarousel::renderTpl($idOrSlug, $args);
    }
}
if (!function_exists('get_modo_carousel')) {
    function get_modo_carousel(string|int $idOrSlug = 0, array $args = []): string
    {
        return ModoCarousel::renderTpl($idOrSlug, $args);
    }
}
if (!function_exists('the_modo_carousel')) {
    function the_modo_carousel(string|int $idOrSlug = 0, array $args = []): void
    {
        ModoCarousel::renderTplEcho($idOrSlug, $args);
    }
}
if (!function_exists('has_modo_carousel')) {
    function has_modo_carousel(string|int $idOrSlug = 0): bool
    {
        return ModoCarousel::exists($idOrSlug);
    }
}
