<?php
/**
 * Plugin Name: Modo Gallery
 * Description: Responsive image galleries (grid, masonry, justified) with a bundled GLightbox lightbox, drag & drop ordering and a media-library picker. Advanced per-gallery options. No CDN - all assets ship locally.
 * Version: 1.0.0
 * Author: Modo CMS
 *
 * @package ModoGallery
 */

if (!class_exists('ModoGallery')) {

    /**
     * Modo Gallery - galleries manager for Modo CMS.
     */
    final class ModoGallery
    {
        public const PLUGIN_ID = 'modo-gallery';
        public const VERSION   = '1.0.0';
        public const TAG       = 'modo_gallery';

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
                    'Modo Gallery',
                    self::VERSION,
                    'Modo CMS',
                    '',
                    'Responsive image galleries with layout & lightbox options and a bundled lightbox (no CDN).',
                    'settings',
                    'ModoGallery::renderAdmin'
                );
            }
            if (function_exists('createSideMenu')) {
                createSideMenu(
                    self::PLUGIN_ID,
                    'Gallery',
                    self::PLUGIN_ID,
                    '<svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>'
                );
            }

            if (defined('IN_ADMIN') && IN_ADMIN === true) {
                self::handleAdminPost();
                \Core\Hooks::addAction('admin_head', [self::class, 'renderAdminHeadAssets']);
                \Core\Hooks::addAction('admin-edit-form', [self::class, 'renderEditorInsertScript']);
            } else {
                \Core\Hooks::addFilter('the_content', [self::class, 'renderShortcode'], 20);
                \Core\Hooks::addAction('theme-header', [self::class, 'maybeHeadAssets']);
                \Core\Hooks::addAction('theme-footer', [self::class, 'maybeFooterAssets']);
            }
        }

        // ---------------------------------------------------------------------
        // Sciezki / baza danych
        // ---------------------------------------------------------------------

        public static function assetsUrl(): string
        {
            $base = '';
            if (class_exists('\Core\Router')) {
                $base = rtrim(\Core\Router::getBaseSubdirectory(), '/');
            }
            return $base . '/plugins/' . self::PLUGIN_ID . '/assets';
        }

        /**
         * Loads the admin-side styles used by the gallery picker modal.
         */
        public static function renderAdminHeadAssets(): void
        {
            $path = __DIR__ . '/assets/admin.css';
            if (!is_file($path)) {
                return;
            }
            $url = self::assetsUrl() . '/admin.css?v=' . (int)filemtime($path);
            echo '<link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
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
                $db->exec("CREATE TABLE IF NOT EXISTS modo_galleries (
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
                'layout'   => 'grid',   // grid | masonry | justified
                'columns'  => '3',
                'gap'      => '14',
                'radius'   => '10',
                'ratio'    => '1',      // 1 | 0.75 | 1.3333 | 0.5625 | auto
                'hover'    => 'zoom',   // zoom | fade | overlay | none
                'captions' => '1',
                'lightbox' => '1',
                'theme'    => 'auto',   // auto | light | dark
                'lbEffect' => 'zoom',   // zoom | fade
                'lbLoop'   => '1',
                'lbZoom'   => '1',
            ];
        }

        /** @return array<int,array<string,mixed>> */
        public static function allGalleries(): array
        {
            $db = self::db();
            if (!$db) {
                return [];
            }
            try {
                $rows = $db->query("SELECT * FROM modo_galleries ORDER BY id DESC")->fetchAll(\PDO::FETCH_ASSOC);
                return $rows ?: [];
            } catch (\Throwable $e) {
                return [];
            }
        }

        /** @return array<string,mixed>|null */
        public static function findGallery(int $id): ?array
        {
            if ($id <= 0) {
                return null;
            }
            $db = self::db();
            if (!$db) {
                return null;
            }
            try {
                $stmt = $db->prepare("SELECT * FROM modo_galleries WHERE id = :id LIMIT 1");
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
                $stmt = $db->prepare("SELECT * FROM modo_galleries WHERE slug = :s LIMIT 1");
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
                    'caption' => (string)($it['caption'] ?? ''),
                    'link'    => (string)($it['link'] ?? ''),
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
                return 'gallery';
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
            return $text !== '' ? $text : 'gallery';
        }

        private static function uniqueSlug(string $slug, int $ignoreId = 0): string
        {
            $db = self::db();
            $base = self::slugify($slug);
            $candidate = $base;
            $i = 2;
            while ($db) {
                try {
                    $stmt = $db->prepare("SELECT id FROM modo_galleries WHERE slug = :s AND id <> :id LIMIT 1");
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
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['mg_save'])) {
                if (!\Core\Security::verifyCsrfToken($_POST['mg_csrf'] ?? '')) {
                    header('Location: plugins.php?id=' . self::PLUGIN_ID);
                    exit;
                }
                self::saveGallery();
            }

            if (isset($_GET['mg_delete'], $_GET['csrf'])) {
                $id = (int)$_GET['mg_delete'];
                if (\Core\Security::verifyCsrfToken((string)$_GET['csrf']) && $id > 0) {
                    self::deleteGallery($id);
                }
                header('Location: plugins.php?id=' . self::PLUGIN_ID);
                exit;
            }
        }

        private static function saveGallery(): void
        {
            $db = self::db();
            if (!$db) {
                return;
            }

            $id      = (int)($_POST['mg_id'] ?? 0);
            $name    = trim((string)($_POST['mg_name'] ?? ''));
            if ($name === '') {
                $name = 'Gallery';
            }
            $slug    = self::uniqueSlug((string)($_POST['mg_slug'] ?? $name), $id);

            $defaults = self::defaultOptions();
            $options  = [];
            foreach ($defaults as $key => $def) {
                $field = 'mg_opt_' . $key;
                if (in_array($key, ['captions', 'lightbox', 'lbLoop', 'lbZoom'], true)) {
                    $options[$key] = isset($_POST[$field]) ? '1' : '0';
                } else {
                    $options[$key] = trim((string)($_POST[$field] ?? $def));
                }
            }

            $images   = (array)($_POST['mg_item_image'] ?? []);
            $alts     = (array)($_POST['mg_item_alt'] ?? []);
            $captions = (array)($_POST['mg_item_caption'] ?? []);
            $links    = (array)($_POST['mg_item_link'] ?? []);
            $items = [];
            foreach (array_values($images) as $i => $img) {
                $img = trim((string)$img);
                if ($img === '') {
                    continue;
                }
                $items[] = [
                    'image'   => $img,
                    'alt'     => trim((string)($alts[$i] ?? '')),
                    'caption' => trim((string)($captions[$i] ?? '')),
                    'link'    => trim((string)($links[$i] ?? '')),
                ];
            }

            $itemsJson   = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $optionsJson = json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            try {
                if ($id > 0) {
                    $stmt = $db->prepare("UPDATE modo_galleries
                        SET name = :n, slug = :s, items = :i, options = :o, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id");
                    $stmt->execute([':n' => $name, ':s' => $slug, ':i' => $itemsJson, ':o' => $optionsJson, ':id' => $id]);
                } else {
                    $stmt = $db->prepare("INSERT INTO modo_galleries (name, slug, items, options)
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

            $savedId = $id > 0 ? $id : (int)($_POST['mg_id'] ?? 0);
            $target = 'plugins.php?id=' . self::PLUGIN_ID . '&mg_view=edit&mg_saved=1';
            if ($savedId > 0) {
                $target .= '&gid=' . $savedId;
            }
            if (!headers_sent()) {
                header('Location: ' . $target);
                exit;
            }
        }

        private static function deleteGallery(int $id): void
        {
            $db = self::db();
            if (!$db) {
                return;
            }
            try {
                $stmt = $db->prepare("DELETE FROM modo_galleries WHERE id = :id");
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
            $view = (string)($_GET['mg_view'] ?? 'list');
            $csrf = \Core\Security::generateCsrfToken();
            $esc  = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

            echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">';
            echo '<div><h2 style="margin:0;font-size:18px;">' . $esc(__('mg_plugin_name')) . '</h2>';
            echo '<div style="font-size:12px;color:var(--text-muted);margin-top:4px;font-family:monospace;">' . $esc('[modo_gallery id="1"]  |  &lt;?php the_modo_gallery(1); ?&gt;') . '</div></div>';
            if ($view === 'edit') {
                echo '<a class="btn btn-secondary" style="font-size:12px;" href="plugins.php?id=' . self::PLUGIN_ID . '">&larr; ' . $esc(__('mg_back')) . '</a>';
            } else {
                echo '<a class="btn btn-primary" style="font-size:12px;" href="plugins.php?id=' . self::PLUGIN_ID . '&mg_view=edit">+ ' . $esc(__('mg_new_gallery')) . '</a>';
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
            .mg-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;}
            .mg-items{display:flex;flex-direction:column;gap:10px;margin:8px 0 4px;}
            .mg-item{display:grid;grid-template-columns:28px 84px minmax(0,1fr) 32px;gap:10px;align-items:center;padding:10px;border:1px solid var(--border-subtle);border-radius:10px;background:var(--bg-surface,#fff);}
            .mg-item img{width:84px;height:60px;object-fit:cover;border-radius:6px;background:rgba(0,0,0,.05);}
            .mg-item .mg-fields{display:grid;grid-template-columns:1fr 1fr;gap:6px;}
            .mg-item .mg-fields .mg-full{grid-column:1 / -1;}
            .mg-drag{cursor:grab;color:var(--text-muted);text-align:center;font-size:18px;user-select:none;}
            .mg-btn-x{border:none;background:transparent;color:#dc2626;font-size:20px;line-height:1;cursor:pointer;padding:0 6px;}
            .mg-picker{position:fixed;inset:0;background:rgba(11,15,25,.8);z-index:99999;display:none;align-items:center;justify-content:center;backdrop-filter:blur(6px);}
            .mg-picker.open{display:flex;}
            .mg-picker-inner{width:92%;max-width:900px;max-height:86vh;display:flex;flex-direction:column;background:var(--bg-card,#fff);border:1px solid var(--border-subtle);border-radius:14px;overflow:hidden;}
            .mg-picker-head{display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--border-subtle);font-weight:700;}
            .mg-picker-body{padding:18px;overflow-y:auto;display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px;}
            .mg-pick-thumb{border:2px solid transparent;border-radius:8px;overflow:hidden;cursor:pointer;background:rgba(0,0,0,.05);aspect-ratio:1;display:flex;align-items:center;justify-content:center;}
            .mg-pick-thumb.sel{border-color:var(--primary,#3b82f6);}
            .mg-pick-thumb img{width:100%;height:100%;object-fit:cover;}
            .mg-picker-foot{padding:14px 20px;border-top:1px solid var(--border-subtle);display:flex;gap:10px;justify-content:flex-end;}
            .mg-empty{padding:20px;color:var(--text-muted);text-align:center;grid-column:1/-1;}
            ';
        }

        private static function renderList(string $csrf, callable $esc): void
        {
            $galleries = self::allGalleries();
            if (empty($galleries)) {
                echo '<div class="card" style="padding:30px;text-align:center;color:var(--text-muted);">' . $esc(__('mg_no_galleries')) . '</div>';
                return;
            }

            echo '<div class="table-container"><table class="pro-table"><thead><tr>';
            echo '<th>' . $esc(__('mg_name')) . '</th>';
            echo '<th style="width:90px;">' . $esc(__('mg_items')) . '</th>';
            echo '<th>' . $esc(__('mg_shortcode')) . '</th>';
            echo '<th style="text-align:right;width:180px;">' . $esc(__('mg_actions')) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($galleries as $g) {
                $id    = (int)$g['id'];
                $items = self::decodeItems($g['items'] ?? '[]');
                $sc    = '[' . self::TAG . ' id="' . $id . '"]';
                echo '<tr>';
                echo '<td><strong>' . $esc((string)$g['name']) . '</strong><div style="font-size:11px;color:var(--text-muted);font-family:monospace;">' . $esc((string)$g['slug']) . '</div></td>';
                echo '<td>' . count($items) . '</td>';
                echo '<td><div style="display:flex;align-items:center;gap:6px;">'
                    . '<code style="font-size:12px;">' . $esc($sc) . '</code>'
                    . '<button type="button" class="btn btn-secondary mg-copy" data-shortcode="' . $esc($sc) . '" style="padding:2px 8px;font-size:11px;">' . $esc(__('mg_copy')) . '</button>'
                    . '</div></td>';
                echo '<td style="text-align:right;white-space:nowrap;">'
                    . '<a class="btn btn-secondary" style="padding:4px 10px;font-size:12px;" href="plugins.php?id=' . self::PLUGIN_ID . '&mg_view=edit&gid=' . $id . '">' . $esc(__('mg_edit')) . '</a> '
                    . '<a class="btn btn-danger-ghost mg-confirm" href="plugins.php?id=' . self::PLUGIN_ID . '&mg_delete=' . $id . '&csrf=' . $esc($csrf) . '" data-confirm="' . $esc(__('mg_confirm_delete')) . '" style="padding:4px 10px;font-size:12px;">' . $esc(__('mg_delete')) . '</a>'
                    . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            echo self::listScript($esc(__('mg_copied')));
        }

        private static function listScript(string $copied): string
        {
            return '<script>
            (function(){
                document.querySelectorAll(".mg-copy").forEach(function(b){
                    b.addEventListener("click", function(){
                        var t = b.getAttribute("data-shortcode") || "";
                        if(navigator.clipboard){ navigator.clipboard.writeText(t); }
                        var o = b.textContent; b.textContent = ' . json_encode($copied) . ';
                        setTimeout(function(){ b.textContent = o; }, 1500);
                    });
                });
                document.querySelectorAll(".mg-confirm").forEach(function(a){
                    a.addEventListener("click", function(e){
                        if(!window.confirm(a.getAttribute("data-confirm") || "Are you sure?")){ e.preventDefault(); }
                    });
                });
            })();
            </script>';
        }

        // ---------------------------------------------------------------------
        // Edytor galerii
        // ---------------------------------------------------------------------

        /** @return array<int,array<string,string>> */
        private static function mediaItems(): array
        {
            if (!class_exists('\Core\MediaLibrary')) {
                return [];
            }
            return \Core\MediaLibrary::listImages();
        }

        private static function itemRow(array $it, callable $esc): string
        {
            $img = (string)($it['image'] ?? '');
            return '<div class="mg-item">'
                . '<div class="mg-drag" draggable="true" title="' . $esc(__('mg_drag')) . '">&#9776;</div>'
                . '<img src="' . $esc($img) . '" alt="" loading="lazy" decoding="async">'
                . '<div class="mg-fields">'
                . '<input type="hidden" name="mg_item_image[]" value="' . $esc($img) . '">'
                . '<button type="button" class="btn btn-secondary mg-choose" style="font-size:11px;padding:4px 8px;">' . $esc(__('mg_choose')) . '</button>'
                . '<input class="form-control" type="text" name="mg_item_alt[]" placeholder="' . $esc(__('mg_alt')) . '" value="' . $esc((string)($it['alt'] ?? '')) . '" style="font-size:12px;">'
                . '<input class="form-control mg-full" type="text" name="mg_item_caption[]" placeholder="' . $esc(__('mg_caption')) . '" value="' . $esc((string)($it['caption'] ?? '')) . '" style="font-size:12px;">'
                . '<input class="form-control mg-full" type="text" name="mg_item_link[]" placeholder="' . $esc(__('mg_link')) . '" value="' . $esc((string)($it['link'] ?? '')) . '" style="font-size:12px;">'
                . '</div>'
                . '<button type="button" class="mg-btn-x" title="' . $esc(__('mg_remove')) . '">&times;</button>'
                . '</div>';
        }

        private static function renderEditor(string $csrf, callable $esc): void
        {
            $gid      = (int)($_GET['gid'] ?? 0);
            $g        = $gid > 0 ? self::findGallery($gid) : null;
            $defaults = self::defaultOptions();
            $opts     = $g ? self::decodeOptions($g['options'] ?? '{}', $defaults) : $defaults;
            $items    = $g ? self::decodeItems($g['items'] ?? '[]') : [];

            $name  = $g ? (string)$g['name'] : '';
            $slug  = $g ? (string)$g['slug'] : '';
            $idVal = $g ? (int)$g['id'] : 0;

            $sel = static fn(string $k, string $val): string => ($opts[$k] ?? '') === $val ? ' selected' : '';
            $chk = static fn(string $k): string => ($opts[$k] ?? '') === '1' ? ' checked' : '';
            $v   = static fn(string $k): string => (string)($opts[$k] ?? '');

            if (isset($_GET['mg_saved'])) {
                echo '<div class="card" style="padding:14px 18px;margin-bottom:16px;border-left:4px solid #16a34a;background:#f0fdf4;color:#166534;">' . $esc(__('mg_saved')) . '</div>';
            }

            echo '<form method="post" action="plugins.php?id=' . self::PLUGIN_ID . '">';
            echo '<input type="hidden" name="mg_save" value="1">';
            echo '<input type="hidden" name="mg_csrf" value="' . $esc($csrf) . '">';
            echo '<input type="hidden" name="mg_id" value="' . $idVal . '">';

            echo '<div class="card" style="padding:20px;margin-bottom:16px;"><div class="mg-grid">';
            echo '<div class="form-group"><label class="form-label">' . $esc(__('mg_name')) . '</label>'
                . '<input class="form-control" type="text" name="mg_name" value="' . $esc($name) . '" placeholder="' . $esc(__('mg_gallery')) . '" required></div>';
            echo '<div class="form-group"><label class="form-label">' . $esc(__('mg_slug')) . '</label>'
                . '<input class="form-control" type="text" name="mg_slug" value="' . $esc($slug) . '" placeholder="' . $esc(__('mg_slug_hint')) . '"></div>';
            echo '</div></div>';

            echo '<div class="card" style="padding:20px;margin-bottom:16px;">';
            echo '<h3 style="margin:0 0 14px;font-size:15px;">' . $esc(__('mg_options')) . '</h3>';
            echo '<div class="mg-grid">';

            echo '<div class="form-group"><label class="form-label">' . $esc(__('mg_layout')) . '</label><select class="form-control" name="mg_opt_layout">'
                . '<option value="grid"' . $sel('layout', 'grid') . '>' . $esc(__('mg_layout_grid')) . '</option>'
                . '<option value="masonry"' . $sel('layout', 'masonry') . '>' . $esc(__('mg_layout_masonry')) . '</option>'
                . '<option value="justified"' . $sel('layout', 'justified') . '>' . $esc(__('mg_layout_justified')) . '</option>'
                . '</select></div>';

            echo '<div class="form-group"><label class="form-label">' . $esc(__('mg_columns')) . '</label><select class="form-control" name="mg_opt_columns">';
            foreach (['1', '2', '3', '4', '5', '6'] as $c) {
                echo '<option value="' . $c . '"' . $sel('columns', $c) . '>' . $c . '</option>';
            }
            echo '</select></div>';

            echo '<div class="form-group"><label class="form-label">' . $esc(__('mg_gap')) . ' (px)</label><input class="form-control" type="number" min="0" max="60" name="mg_opt_gap" value="' . $esc($v('gap')) . '"></div>';
            echo '<div class="form-group"><label class="form-label">' . $esc(__('mg_radius')) . ' (px)</label><input class="form-control" type="number" min="0" max="40" name="mg_opt_radius" value="' . $esc($v('radius')) . '"></div>';
            echo '<div class="form-group"><label class="form-label">' . $esc(__('mg_ratio')) . '</label><select class="form-control" name="mg_opt_ratio">'
                . '<option value="1"' . $sel('ratio', '1') . '>1:1</option>'
                . '<option value="0.75"' . $sel('ratio', '0.75') . '>4:3</option>'
                . '<option value="1.3333"' . $sel('ratio', '1.3333') . '>3:2</option>'
                . '<option value="0.5625"' . $sel('ratio', '0.5625') . '>16:9</option>'
                . '<option value="auto"' . $sel('ratio', 'auto') . '>' . $esc(__('mg_ratio_auto')) . '</option>'
                . '</select></div>';

            echo '<div class="form-group"><label class="form-label">' . $esc(__('mg_hover')) . '</label><select class="form-control" name="mg_opt_hover">'
                . '<option value="zoom"' . $sel('hover', 'zoom') . '>' . $esc(__('mg_hover_zoom')) . '</option>'
                . '<option value="fade"' . $sel('hover', 'fade') . '>' . $esc(__('mg_hover_fade')) . '</option>'
                . '<option value="overlay"' . $sel('hover', 'overlay') . '>' . $esc(__('mg_hover_overlay')) . '</option>'
                . '<option value="none"' . $sel('hover', 'none') . '>' . $esc(__('mg_hover_none')) . '</option>'
                . '</select></div>';

            echo '<div class="form-group"><label class="form-label">' . $esc(__('mg_theme')) . '</label><select class="form-control" name="mg_opt_theme">'
                . '<option value="auto"' . $sel('theme', 'auto') . '>' . $esc(__('mg_theme_auto')) . '</option>'
                . '<option value="light"' . $sel('theme', 'light') . '>' . $esc(__('mg_theme_light')) . '</option>'
                . '<option value="dark"' . $sel('theme', 'dark') . '>' . $esc(__('mg_theme_dark')) . '</option>'
                . '</select></div>';

            echo '<div class="form-group"><label class="form-label">' . $esc(__('mg_lb_effect')) . '</label><select class="form-control" name="mg_opt_lbEffect">'
                . '<option value="zoom"' . $sel('lbEffect', 'zoom') . '>' . $esc(__('mg_lb_zoom')) . '</option>'
                . '<option value="fade"' . $sel('lbEffect', 'fade') . '>' . $esc(__('mg_lb_fade')) . '</option>'
                . '</select></div>';

            echo '</div><!-- /mg-grid -->';

            echo '<div style="display:flex;flex-wrap:wrap;gap:18px;margin-top:14px;">';
            echo '<label style="display:flex;align-items:center;gap:8px;font-weight:600;"><input type="checkbox" name="mg_opt_captions" value="1"' . $chk('captions') . '> ' . $esc(__('mg_captions')) . '</label>';
            echo '<label style="display:flex;align-items:center;gap:8px;font-weight:600;"><input type="checkbox" name="mg_opt_lightbox" value="1"' . $chk('lightbox') . '> ' . $esc(__('mg_lightbox')) . '</label>';
            echo '<label style="display:flex;align-items:center;gap:8px;font-weight:600;"><input type="checkbox" name="mg_opt_lbLoop" value="1"' . $chk('lbLoop') . '> ' . $esc(__('mg_lb_loop')) . '</label>';
            echo '<label style="display:flex;align-items:center;gap:8px;font-weight:600;"><input type="checkbox" name="mg_opt_lbZoom" value="1"' . $chk('lbZoom') . '> ' . $esc(__('mg_lb_zoomable')) . '</label>';
            echo '</div>';

            echo '</div><!-- /card options -->';

            // Items
            echo '<div class="card" style="padding:20px;margin-bottom:16px;">';
            echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">';
            echo '<h3 style="margin:0;font-size:15px;">' . $esc(__('mg_images')) . '</h3>';
            echo '<button type="button" class="btn btn-primary" id="mg-add" style="font-size:12px;padding:6px 12px;">+ ' . $esc(__('mg_add_images')) . '</button>';
            echo '</div>';
            echo '<div class="mg-items" id="mg-items">';
            if (empty($items)) {
                echo '<div class="mg-empty" id="mg-empty">' . $esc(__('mg_no_images')) . '</div>';
            } else {
                foreach ($items as $it) {
                    echo self::itemRow($it, $esc);
                }
            }
            echo '</div>';
            echo '</div><!-- /card items -->';

            echo '<div style="display:flex;gap:10px;margin-bottom:20px;">';
            echo '<button type="submit" class="btn btn-primary">' . $esc(__('mg_save')) . '</button>';
            echo '<a class="btn btn-secondary" href="plugins.php?id=' . self::PLUGIN_ID . '">' . $esc(__('mg_cancel')) . '</a>';
            echo '</div>';
            echo '</form>';

            // Media picker
            $base  = class_exists('\Core\Router') ? rtrim(\Core\Router::getBaseSubdirectory(), '/') : '';
            $media = self::mediaItems();
            $folders = class_exists('\Core\MediaLibrary') ? \Core\MediaLibrary::folders() : [];
            echo '<div class="mg-picker" id="mg-picker"><div class="mg-picker-inner">';
            echo '<div class="mg-picker-head"><span>' . $esc(__('mg_choose_media')) . '</span><button type="button" class="mg-btn-x" id="mg-picker-close">&times;</button></div>';
            if (!empty($media)) {
                echo '<div style="display:flex;align-items:center;gap:10px;padding:8px 14px;border-bottom:1px solid rgba(148,163,184,.25);">';
                echo '<label for="mg-folder-filter" style="font-size:12px;opacity:.8;">' . $esc(__('Folder')) . '</label>';
                echo '<select id="mg-folder-filter" class="form-control" style="max-width:220px;padding:5px 8px;font-size:12px;">';
                echo '<option value="__all__">' . $esc(__('All files')) . '</option>';
                echo '<option value="">' . $esc(__('Root')) . '</option>';
                foreach ($folders as $folder) {
                    echo '<option value="' . $esc($folder['name']) . '">' . $esc($folder['name']) . ' (' . (int)$folder['count'] . ')</option>';
                }
                echo '</select></div>';
            }
            echo '<div class="mg-picker-body" id="mg-picker-body">';
            if (empty($media)) {
                echo '<div class="mg-empty">' . $esc(__('mg_no_media')) . '</div>';
            } else {
                foreach ($media as $m) {
                    $thumb = \Core\MediaLibrary::thumbnailUrl((string) ($m['path'] ?? $m['url']), 160);
                    $thumb2x = \Core\MediaLibrary::thumbnailUrl((string) ($m['path'] ?? $m['url']), 240);
                    echo '<div class="mg-pick-thumb" data-url="' . $esc($m['url']) . '" data-folder="' . $esc((string)($m['folder'] ?? '')) . '"><img src="' . $esc($thumb) . '" srcset="' . $esc($thumb) . ' 1x, ' . $esc($thumb2x) . ' 2x" alt="' . $esc($m['name']) . '" loading="lazy" decoding="async"></div>';
                }
            }
            echo '</div>';
            echo '<div class="mg-picker-foot"><button type="button" class="btn btn-primary" id="mg-picker-add">' . $esc(__('mg_insert')) . '</button></div>';
            echo '</div></div>';

            echo self::editorScript($base);
        }

        private static function editorScript(string $base): string
        {
            $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
            $tpl = self::itemRow(['image' => '', 'alt' => '', 'caption' => '', 'link' => ''], $esc);

            return '<template id="mg-tpl">' . $tpl . '</template>
            <script>
            (function(){
                var picker=document.getElementById("mg-picker");
                var itemsBox=document.getElementById("mg-items");
                var tplEl=document.getElementById("mg-tpl");
                if(!picker||!itemsBox||!tplEl) return;
                var mode="add", targetRow=null, dragEl=null;
                var folderFilter=document.getElementById("mg-folder-filter");

                function applyFolderFilter(){
                    if(!folderFilter) return;
                    var value=folderFilter.value;
                    picker.querySelectorAll(".mg-pick-thumb").forEach(function(t){
                        t.style.display=(value==="__all__"||(t.getAttribute("data-folder")||"")===value)?"":"none";
                    });
                }
                if(folderFilter){ folderFilter.addEventListener("change",function(){ clearSel(); applyFolderFilter(); }); }

                function clearSel(){ picker.querySelectorAll(".mg-pick-thumb.sel").forEach(function(t){t.classList.remove("sel");}); }
                function open(m,t){
                    mode=m; targetRow=t||null; clearSel();
                    if(folderFilter){ folderFilter.value="__all__"; applyFolderFilter(); }
                    picker.classList.add("open");
                }
                function close(){ picker.classList.remove("open"); }

                function addRow(url){
                    var node=tplEl.content.firstElementChild.cloneNode(true);
                    node.querySelector("img").src=url;
                    node.querySelector("input[name=\"mg_item_image[]\"]").value=url;
                    var e=document.getElementById("mg-empty"); if(e) e.remove();
                    itemsBox.appendChild(node);
                    return node;
                }

                var addBtn=document.getElementById("mg-add");
                if(addBtn){ addBtn.addEventListener("click",function(){ open("add",null); }); }
                var closeBtn=document.getElementById("mg-picker-close");
                if(closeBtn){ closeBtn.addEventListener("click",close); }
                picker.addEventListener("click",function(e){ if(e.target===picker) close(); });

                picker.querySelectorAll(".mg-pick-thumb").forEach(function(t){
                    t.addEventListener("click",function(){
                        if(mode==="replace"){ clearSel(); t.classList.add("sel"); }
                        else { t.classList.toggle("sel"); }
                    });
                });

                var insertBtn=document.getElementById("mg-picker-add");
                if(insertBtn){ insertBtn.addEventListener("click",function(){
                    var sel=[].slice.call(picker.querySelectorAll(".mg-pick-thumb.sel")).map(function(t){return t.getAttribute("data-url");});
                    if(mode==="replace"){
                        if(sel.length && targetRow){
                            targetRow.querySelector("img").src=sel[0];
                            targetRow.querySelector("input[name=\"mg_item_image[]\"]").value=sel[0];
                        }
                    } else {
                        sel.forEach(function(u){ addRow(u); });
                    }
                    close();
                }); }

                itemsBox.addEventListener("click",function(e){
                    var ch=e.target.closest(".mg-choose");
                    if(ch){ open("replace", ch.closest(".mg-item")); return; }
                    var x=e.target.closest(".mg-btn-x");
                    if(x){ var it=x.closest(".mg-item"); if(it) it.remove(); }
                });

                itemsBox.addEventListener("dragstart",function(e){
                    var h=e.target.closest(".mg-drag");
                    if(!h) return;
                    dragEl=h.closest(".mg-item");
                    e.dataTransfer.effectAllowed="move";
                    setTimeout(function(){ if(dragEl) dragEl.classList.add("mg-ghost"); },0);
                });
                itemsBox.addEventListener("dragend",function(){ if(dragEl){ dragEl.classList.remove("mg-ghost"); dragEl=null; } });
                itemsBox.addEventListener("dragover",function(e){
                    e.preventDefault();
                    if(!dragEl) return;
                    var it=e.target.closest(".mg-item");
                    if(!it||it===dragEl) return;
                    var rect=it.getBoundingClientRect();
                    var after=(e.clientY-rect.top)/rect.height>0.5;
                    itemsBox.insertBefore(dragEl, after? it.nextSibling : it);
                });
            })();
            </script>';
        }

        // ---------------------------------------------------------------------
        // Wybor galerii w edytorze strony (TinyMCE / Editor.js)
        // ---------------------------------------------------------------------

        /**
         * Data used by the gallery picker modal.
         *
         * @return array<int,array<string,mixed>>
         */
        private static function galleryPickerItems(): array
        {
            $defaults = self::defaultOptions();
            $out      = [];

            foreach (self::allGalleries() as $g) {
                $galleryItems = self::decodeItems($g['items'] ?? '[]');
                $cover        = '';
                foreach ($galleryItems as $it) {
                    if ($it['image'] !== '') {
                        $cover = self::mediaUrl($it['image']);
                        break;
                    }
                }

                $opts   = self::decodeOptions($g['options'] ?? '{}', $defaults);
                $layout = in_array($opts['layout'], ['grid', 'masonry', 'justified'], true) ? $opts['layout'] : 'grid';

                $out[] = [
                    'id'     => (int)$g['id'],
                    'name'   => (string)$g['name'],
                    'slug'   => (string)$g['slug'],
                    'count'  => count($galleryItems),
                    'cover'  => $cover,
                    'layout' => $layout,
                ];
            }

            return $out;
        }

        public static function renderEditorInsertScript(): void
        {
            $payload = [
                'items'  => self::galleryPickerItems(),
                'manage' => 'plugins.php?id=' . self::PLUGIN_ID . '&mg_view=edit',
                'labels' => [
                    'title'     => __('mg_pick_title'),
                    'subtitle'  => __('mg_pick_subtitle'),
                    'button'    => __('mg_gallery'),
                    'insert'    => __('mg_insert_gallery'),
                    'cancel'    => __('mg_cancel'),
                    'empty'     => __('mg_pick_empty'),
                    'manage'    => __('mg_pick_manage'),
                    'images'    => __('mg_items'),
                    'grid'      => __('mg_layout_grid'),
                    'masonry'   => __('mg_layout_masonry'),
                    'justified' => __('mg_layout_justified'),
                ],
            ];

            // HEX flags keep a gallery name from closing the JSON script tag.
            $json = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            );
            ?>
            <script type="application/json" id="mg-editor-data"><?= $json ?: '{}' ?></script>
            <script>
            (function(){
                "use strict";

                var data=document.getElementById("mg-editor-data");
                if(!data) return;

                var cfg;
                try { cfg=JSON.parse(data.textContent||"{}")||{}; } catch(e){ return; }

                var labels=cfg.labels||{};
                var galleries=Array.isArray(cfg.items)?cfg.items:[];
                var picker=null, selectedId=0, pickCallback=null, lastFocus=null, cards=[];

                function el(tag,cls,text){
                    var node=document.createElement(tag);
                    if(cls){ node.className=cls; }
                    if(text!==undefined && text!==null){ node.textContent=String(text); }
                    return node;
                }

                function iconSvg(){
                    return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">'
                        +'<rect x="3" y="3" width="18" height="18" rx="2"/><path d="m21 15-5-5L5 21"/><circle cx="8.5" cy="8.5" r="1.5"/></svg>';
                }

                function layoutLabel(layout){
                    if(layout==="masonry"){ return labels.masonry||"Masonry"; }
                    if(layout==="justified"){ return labels.justified||"Justified"; }
                    return labels.grid||"Grid";
                }

                function placeholder(){
                    var box=el("span","mg-gcard__placeholder");
                    box.innerHTML=iconSvg();
                    return box;
                }

                function buildCard(g){
                    var card=el("button","mg-gcard");
                    card.type="button";
                    card.setAttribute("data-id",String(g.id));
                    card.setAttribute("aria-pressed","false");

                    var thumb=el("span","mg-gcard__thumb");
                    if(g.cover){
                        var img=el("img");
                        img.src=String(g.cover);
                        img.alt="";
                        img.loading="lazy";
                        img.addEventListener("error",function(){
                            img.remove();
                            thumb.appendChild(placeholder());
                        });
                        thumb.appendChild(img);
                    } else {
                        thumb.appendChild(placeholder());
                    }

                    var meta=el("span","mg-gcard__meta");
                    meta.appendChild(el("span","mg-gcard__name",g.name||("#"+g.id)));
                    var bits=["#"+g.id,(labels.images||"Images")+": "+(parseInt(g.count,10)||0),layoutLabel(g.layout)];
                    meta.appendChild(el("span","mg-gcard__sub",bits.join(" \u00b7 ")));

                    card.appendChild(thumb);
                    card.appendChild(meta);
                    card.addEventListener("click",function(){ select(g.id); });
                    card.addEventListener("dblclick",function(){ select(g.id); submit(); });
                    return card;
                }

                function buildPicker(){
                    if(picker){ return picker; }

                    var overlay=el("div","mg-gmodal");
                    var box=el("div","mg-gmodal__box");
                    box.setAttribute("role","dialog");
                    box.setAttribute("aria-modal","true");
                    box.setAttribute("aria-labelledby","mg-gmodal-title");

                    var head=el("div","mg-gmodal__head");
                    var titleWrap=el("div","mg-gmodal__title-wrap");
                    var icon=el("span","mg-gmodal__icon");
                    icon.innerHTML=iconSvg();
                    var title=el("h3","mg-gmodal__title",labels.title||labels.button||"Insert gallery");
                    title.id="mg-gmodal-title";
                    titleWrap.appendChild(icon);
                    titleWrap.appendChild(title);

                    var closeBtn=el("button","mg-gmodal__close","\u00d7");
                    closeBtn.type="button";
                    closeBtn.setAttribute("aria-label",labels.cancel||"Cancel");
                    closeBtn.addEventListener("click",close);

                    head.appendChild(titleWrap);
                    head.appendChild(closeBtn);

                    var body=el("div","mg-gmodal__body");
                    if(labels.subtitle){ body.appendChild(el("p","mg-gmodal__subtitle",labels.subtitle)); }

                    var grid=el("div","mg-gmodal__grid");
                    if(!galleries.length){
                        var empty=el("div","mg-gmodal__empty");
                        empty.appendChild(el("p",null,labels.empty||"No galleries yet."));
                        if(labels.manage){
                            var manage=el("a","btn btn-primary",labels.manage);
                            manage.href=cfg.manage||"plugins.php?id=modo-gallery&mg_view=edit";
                            empty.appendChild(manage);
                        }
                        grid.appendChild(empty);
                    } else {
                        galleries.forEach(function(g){
                            var card=buildCard(g);
                            cards.push(card);
                            grid.appendChild(card);
                        });
                    }
                    body.appendChild(grid);

                    var foot=el("div","mg-gmodal__foot");
                    var preview=el("div","mg-gmodal__preview");
                    var actions=el("div","mg-gmodal__actions");

                    var cancel=el("button","btn btn-secondary",labels.cancel||"Cancel");
                    cancel.type="button";
                    cancel.addEventListener("click",close);

                    var insert=el("button","btn btn-primary",labels.insert||"Insert");
                    insert.type="button";
                    insert.disabled=true;
                    insert.addEventListener("click",submit);

                    actions.appendChild(cancel);
                    actions.appendChild(insert);
                    foot.appendChild(preview);
                    foot.appendChild(actions);

                    box.appendChild(head);
                    box.appendChild(body);
                    box.appendChild(foot);
                    overlay.appendChild(box);
                    overlay.addEventListener("mousedown",function(e){ if(e.target===overlay){ close(); } });
                    document.body.appendChild(overlay);

                    picker={overlay:overlay,insert:insert,preview:preview,closeBtn:closeBtn,firstCard:cards[0]||null};
                    return picker;
                }

                function select(id){
                    selectedId=parseInt(id,10)||0;
                    cards.forEach(function(card){
                        var on=card.getAttribute("data-id")===String(selectedId);
                        card.classList.toggle("is-selected",on);
                        card.setAttribute("aria-pressed",on?"true":"false");
                    });
                    if(picker){
                        picker.insert.disabled=!selectedId;
                        picker.preview.textContent=selectedId?('[modo_gallery id="'+selectedId+'"]'):"";
                    }
                }

                function submit(){
                    if(!selectedId||typeof pickCallback!=="function"){ return; }
                    var id=selectedId;
                    var cb=pickCallback;
                    close(false);
                    try { cb(id); } catch(e){
                        if(window.console&&console.error){ console.error("[ModoGallery] insert failed:",e); }
                    }
                }

                function close(restoreFocus){
                    if(!picker){ return; }
                    picker.overlay.classList.remove("is-open");
                    document.documentElement.classList.remove("mg-modal-open");
                    pickCallback=null;
                    if(restoreFocus!==false&&lastFocus&&typeof lastFocus.focus==="function"){
                        try { lastFocus.focus(); } catch(e){}
                    }
                    lastFocus=null;
                }

                function onKeydown(e){
                    if(!picker||!picker.overlay.classList.contains("is-open")){ return; }
                    if(e.key==="Escape"){ e.preventDefault(); close(); }
                }
                document.addEventListener("keydown",onKeydown,true);

                function open(callback){
                    picker=buildPicker();
                    pickCallback=typeof callback==="function"?callback:null;
                    lastFocus=document.activeElement;
                    select(0);
                    picker.overlay.classList.add("is-open");
                    document.documentElement.classList.add("mg-modal-open");
                    var focusTarget=picker.firstCard||picker.closeBtn;
                    if(focusTarget){
                        setTimeout(function(){ try { focusTarget.focus(); } catch(e){} },0);
                    }
                }

                window.ModoGalleryPicker={open:open,close:close};

                // TinyMCE: toolbar button opening the shared picker modal.
                function patchTinyMce(){
                    if(!window.tinymce||typeof window.tinymce.init!=="function"||window.tinymce.__modoGalleryPatched){
                        return !!window.tinymce;
                    }
                    window.tinymce.__modoGalleryPatched=true;
                    var orig=window.tinymce.init;
                    window.tinymce.init=function(c){
                        var conf=c||{};
                        if(typeof conf.toolbar==="string"){
                            if(conf.toolbar.indexOf("modo_gallery")===-1){
                                conf.toolbar=conf.toolbar.trim()?conf.toolbar+" | modo_gallery":"modo_gallery";
                            }
                        } else if(Array.isArray(conf.toolbar)&&conf.toolbar.indexOf("modo_gallery")===-1){
                            conf.toolbar=conf.toolbar.concat("modo_gallery");
                        }
                        var setup=conf.setup;
                        conf.setup=function(editor){
                            if(typeof setup==="function"){ setup(editor); }
                            editor.ui.registry.addButton("modo_gallery",{
                                icon:"gallery",
                                tooltip:labels.button||labels.title||"Gallery",
                                onAction:function(){
                                    window.ModoGalleryPicker.open(function(id){
                                        editor.insertContent('[modo_gallery id="'+id+'"]');
                                    });
                                }
                            });
                        };
                        return orig.apply(this,arguments);
                    };
                    return true;
                }

                if(!patchTinyMce()){
                    var tries=0;
                    var iv=setInterval(function(){
                        tries++;
                        if(patchTinyMce()||tries>60){ clearInterval(iv); }
                    },100);
                }

                // Editor.js: the same picker through the editor bridge.
                function registerEditorJs(){
                    if(!window.ModoEditor||typeof window.ModoEditor.registerButton!=="function"){ return false; }
                    window.ModoEditor.registerButton({
                        id:"modo_gallery",
                        label:labels.button||labels.title||"Gallery",
                        icon:'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="m21 15-5-5L5 21"/><circle cx="8.5" cy="8.5" r="1.5"/></svg>',
                        insert:function(api){
                            window.ModoGalleryPicker.open(function(id){
                                api.insertShortcode('[modo_gallery id="'+id+'"]');
                            });
                        }
                    });
                    return true;
                }

                function whenReady(fn){
                    if(document.readyState==="loading"){
                        document.addEventListener("DOMContentLoaded",fn,{once:true});
                    } else {
                        fn();
                    }
                }

                whenReady(function(){
                    if(registerEditorJs()){ return; }
                    var tries=0;
                    var iv=setInterval(function(){
                        tries++;
                        if(registerEditorJs()||tries>60){ clearInterval(iv); }
                    },100);
                });
            })();
            </script>
            <?php
        }

        /**
         * Renders a gallery from inline custom-field data (page_meta "gallery").
         *
         * @param array<int,array<string,mixed>> $items
         * @param array<string,mixed>            $options
         */
        public static function renderInlineGallery(array $items, array $options = [], string $uid = ''): string
        {
            $normalized = self::decodeItems((string) json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if (empty($normalized)) {
                return '';
            }

            $defaults = self::defaultOptions();
            $resolved = $defaults;
            foreach ($options as $key => $value) {
                if (array_key_exists((string) $key, $defaults) && is_scalar($value)) {
                    $resolved[(string) $key] = (string) $value;
                }
            }

            $uid = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $uid), '-');
            if ($uid === '') {
                $uid = 'cf-' . substr(md5((string) json_encode($normalized)), 0, 10);
            }

            $gallery = [
                'id'      => $uid,
                'name'    => '',
                'items'   => (string) json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'options' => (string) json_encode($resolved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];

            $html = self::renderOne($gallery);
            if ($html !== '') {
                self::enqueueAssets();
            }

            return $html;
        }

        // ---------------------------------------------------------------------
        // Frontend – renderowanie shortcode
        // ---------------------------------------------------------------------

        public static function renderShortcode(string $content): string
        {
            if ($content === '' || stripos($content, '[' . self::TAG) === false) {
                return $content;
            }
            return (string)preg_replace_callback(
                '/\[modo_gallery\b([^\]]*)\]/i',
                static function (array $m): string {
                    $attrs = self::normalizeAttrs((string)($m[1] ?? ''));
                    $id = 0;
                    $slug = '';
                    if (preg_match('/id\s*=\s*["\']?\s*(\d+)/i', $attrs, $mm)) {
                        $id = (int)$mm[1];
                    }
                    if (preg_match('/slug\s*=\s*["\']?\s*([a-z0-9\-_]+)/i', $attrs, $mm)) {
                        $slug = $mm[1];
                    }
                    $g = $id > 0 ? self::findGallery($id) : ($slug !== '' ? self::findBySlug($slug) : null);
                    return $g ? self::renderOne($g) : '';
                },
                $content
            );
        }

        /**
         * Normalizes shortcode attributes so encoded or typographic quotes
         * (e.g. &quot;, &#34;, &apos;, curly quotes) parse like plain quotes.
         */
        private static function normalizeAttrs(string $attrs): string
        {
            $attrs = html_entity_decode($attrs, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return str_replace(
                ["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"], // " " ' '
                ['"', '"', "'", "'"],
                $attrs
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

        private static function renderOne(array $g, array $override = []): string
        {
            self::$used = true;

            $defaults = self::defaultOptions();
            $opts     = self::decodeOptions($g['options'] ?? '{}', $defaults);

            // Per-call overrides (used by template tags).
            foreach ($override as $okey => $oval) {
                $okey = (string)$okey;
                if (array_key_exists($okey, $defaults) && is_scalar($oval)) {
                    $opts[$okey] = (string)$oval;
                }
            }

            $items    = self::decodeItems($g['items'] ?? '[]');
            if (empty($items)) {
                return '';
            }

            $id       = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($g['id'] ?? '0')), '-');
            if ($id === '') {
                $id = '0';
            }
            $layout   = in_array($opts['layout'], ['grid', 'masonry', 'justified'], true) ? $opts['layout'] : 'grid';
            $columns  = max(1, min(6, (int)$opts['columns']));
            $gap      = max(0, min(60, (int)$opts['gap']));
            $radius   = max(0, min(40, (int)$opts['radius']));
            $ratio    = (string)$opts['ratio'];
            $autoRatio = $ratio === 'auto';
            $hover    = in_array($opts['hover'], ['zoom', 'fade', 'overlay', 'none'], true) ? $opts['hover'] : 'zoom';
            $theme    = in_array($opts['theme'], ['auto', 'light', 'dark'], true) ? $opts['theme'] : 'auto';
            $showCaps = $opts['captions'] === '1';
            $lightbox = $opts['lightbox'] === '1';

            $style = '--mg-cols:' . $columns . ';--mg-gap:' . $gap . 'px;--mg-radius:' . $radius . 'px;';
            if ($ratio !== 'auto' && (float)$ratio > 0) {
                $style .= '--mg-ratio:' . (float)$ratio . ';';
            }

            $lbOpts = [
                'openEffect'  => $opts['lbEffect'] === 'fade' ? 'fade' : 'zoom',
                'closeEffect' => $opts['lbEffect'] === 'fade' ? 'fade' : 'zoom',
                'loop'        => $opts['lbLoop'] === '1',
                'zoomable'    => $opts['lbZoom'] === '1',
            ];

            $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

            $html = '<div class="modo-gallery modo-gallery--' . $layout . ' modo-gallery--' . $theme
                . ' modo-gallery--hover-' . $hover . ($autoRatio ? ' modo-gallery--ratio-auto' : '')
                . ($showCaps ? '' : ' modo-gallery--nocaps') . '"'
                . ' id="modo-gallery-' . $id . '" data-lightbox="' . ($lightbox ? '1' : '0') . '"'
                . ' data-lb="' . $esc((string)json_encode($lbOpts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"'
                . ' style="' . $style . '">';

            $gName = (string)($g['name'] ?? '');
            foreach ($items as $it) {
                $img = (string)$it['image'];
                if ($img === '') {
                    continue;
                }
                $src  = self::mediaUrl($img);
                $alt  = $it['alt'] !== '' ? $it['alt'] : $gName;
                $cap  = $it['caption'];
                $link = $it['link'];

                $inner = '<img src="' . $esc($src) . '" alt="' . $esc($alt) . '" loading="lazy" decoding="async">';
                if ($showCaps && $cap !== '') {
                    $inner .= '<span class="modo-gallery__caption">' . $esc($cap) . '</span>';
                }

                if ($lightbox) {
                    $html .= '<a class="modo-gallery__item glightbox" href="' . $esc($src) . '"'
                        . ' data-gallery="mg-' . $id . '"'
                        . ' data-title="' . $esc($cap !== '' ? $cap : $alt) . '"'
                        . ' data-description="' . $esc($cap) . '">' . $inner . '</a>';
                } elseif ($link !== '') {
                    $html .= '<a class="modo-gallery__item" href="' . $esc($link) . '">' . $inner . '</a>';
                } else {
                    $html .= '<span class="modo-gallery__item">' . $inner . '</span>';
                }
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
            return $content !== '' && stripos($content, '[' . self::TAG) !== false;
        }

        private static function emitCss(): void
        {
            if (self::$cssDone) {
                return;
            }
            self::$cssDone = true;
            $u = self::assetsUrl();
            echo '<link rel="stylesheet" href="' . htmlspecialchars($u . '/vendor/glightbox/glightbox.min.css', ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
            echo '<link rel="stylesheet" href="' . htmlspecialchars($u . '/gallery.css', ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
        }

        private static function emitJs(): void
        {
            if (self::$jsDone) {
                return;
            }
            self::$jsDone = true;
            $u = self::assetsUrl();
            echo '<script src="' . htmlspecialchars($u . '/vendor/glightbox/glightbox.min.js', ENT_QUOTES, 'UTF-8') . '"></script>' . PHP_EOL;
            echo '<script src="' . htmlspecialchars($u . '/gallery.js', ENT_QUOTES, 'UTF-8') . '"></script>' . PHP_EOL;
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
                return self::findGallery((int)$idOrSlug);
            }
            return self::findBySlug((string)$idOrSlug);
        }

        public static function exists(string|int $idOrSlug): bool
        {
            return self::resolve($idOrSlug) !== null;
        }

        /**
         * Zwraca HTML galerii (do uzycia w kodzie szablonu). $args nadpisuje opcje.
         *
         * @param array<string,mixed> $args
         */
        public static function renderTpl(string|int $idOrSlug, array $args = []): string
        {
            $g = self::resolve($idOrSlug);
            return $g ? self::renderOne($g, $args) : '';
        }

        /**
         * Wypisuje HTML galerii i jednorazowo doladowuje assety.
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
ModoGallery::init();

/*
 * Template tags (do uzycia w plikach motywu):
 *   the_modo_gallery(1);                 // wypisuje galerie o id = 1
 *   the_modo_gallery('moja-galeria');    // wypisuje galerie po slug
 *   echo modo_gallery(1);                // zwraca HTML (string)
 *   echo modo_gallery(1, ['columns' => 4, 'gap' => 20, 'lightbox' => 0]);
 *   if (has_modo_gallery(1)) { ... }
 */
if (!function_exists('modo_gallery')) {
    function modo_gallery(string|int $idOrSlug = 0, array $args = []): string
    {
        return ModoGallery::renderTpl($idOrSlug, $args);
    }
}
if (!function_exists('get_modo_gallery')) {
    function get_modo_gallery(string|int $idOrSlug = 0, array $args = []): string
    {
        return ModoGallery::renderTpl($idOrSlug, $args);
    }
}
if (!function_exists('the_modo_gallery')) {
    function the_modo_gallery(string|int $idOrSlug = 0, array $args = []): void
    {
        ModoGallery::renderTplEcho($idOrSlug, $args);
    }
}
if (!function_exists('has_modo_gallery')) {
    function has_modo_gallery(string|int $idOrSlug = 0): bool
    {
        return ModoGallery::exists($idOrSlug);
    }
}
