<?php
/**
 * Plugin Name: Cookie Consent & Consent Manager
 * Description: Blokuje skrypty analityczne/marketingowe do momentu wyrażenia zgody. Baner zgody zgodny z RODO (GDPR), ePrivacy, CCPA/CPRA i Google Consent Mode v2.
 * Version: 1.0.0
 * Author: Modo CMS
 *
 * @package ModoCookieConsent
 */

if (!class_exists('ModoCookieConsent')) {

    /**
     * RODO / Cookies consent manager plugin for Modo CMS.
     */
    final class ModoCookieConsent
    {
        public const PLUGIN_ID = 'cookie-consent';
        public const VERSION   = '1.0.0';
        public const OPT_PREFIX = 'cc_';

        /** @var array<string,string> Domyślne wartości ustawień. */
        private static array $defaults = [
            'enabled'        => '1',
            'layout'         => 'bar',       // bar | card | modal
            'position'       => 'bottom',    // bottom | top | bottom-left | bottom-right
            'theme'          => 'light',     // light | dark | glass | minimal | gradient
            'accent'         => '#2563eb',
            'bg'             => '',
            'text_color'     => '',
            'radius'         => '14',
            'zindex'         => '999999',
            'title'          => 'Szanujemy Twoją prywatność',
            'message'        => 'Używamy plików cookies, aby zapewnić działanie strony, analizować ruch oraz personalizować treści. Możesz zaakceptować wszystkie, odrzucić opcjonalne lub dostosować ustawienia.',
            'btn_accept'     => 'Akceptuję wszystkie',
            'btn_reject'     => 'Odrzuć opcjonalne',
            'btn_settings'   => 'Dostosuj',
            'btn_save'       => 'Zapisz wybór',
            'btn_revoke'     => 'Zmień zgodę',
            'policy_url'     => '',
            'policy_label'   => 'Polityka prywatności',
            'cookie_url'     => '',
            'cookie_label'   => 'Polityka cookies',

            'cat_pref_enabled'    => '1',
            'cat_pref_name'       => 'Funkcjonalne',
            'cat_pref_desc'       => 'Umożliwiają zapamiętanie preferencji (język, motyw, region).',

            'cat_analytics_enabled' => '1',
            'cat_analytics_name'    => 'Analityczne',
            'cat_analytics_desc'    => 'Pomagają zrozumieć, jak odwiedzający korzystają ze strony (statystyki ruchu).',

            'cat_marketing_enabled' => '1',
            'cat_marketing_name'    => 'Marketingowe',
            'cat_marketing_desc'    => 'Służą do wyświetlania dopasowanych reklam i mierzenia ich skuteczności.',

            'scripts_analytics' => '',
            'scripts_marketing' => '',
            'scripts_pref'      => '',

            'region_mode'    => 'all',   // all | eu
            'respect_gpc'    => '1',
            'respect_dnt'    => '0',
            'consent_mode'   => '1',     // Google Consent Mode v2
            'expiry_days'    => '180',
            'policy_version' => '1',
            'show_revoke'    => '1',
            'revoke_position'=> 'right',
            'log_enabled'    => '1',
        ];

        private static bool $booted = false;

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
                    'Cookie Consent (RODO / GDPR / CCPA)',
                    self::VERSION,
                    'Modo CMS',
                    '',
                    'Baner zgody na cookies z blokowaniem skryptów analitycznych do czasu wyrażenia zgody.',
                    'settings',
                    'ModoCookieConsent::renderAdmin'
                );
            }
            if (function_exists('createSideMenu')) {
                createSideMenu(self::PLUGIN_ID, 'Cookie Consent', self::PLUGIN_ID);
            }

            if (defined('IN_ADMIN') && IN_ADMIN === true) {
                self::handleExport();
                self::maybeSaveSettings();
            }

            self::maybeHandleConsentEndpoint();

            if (!defined('IN_ADMIN') || IN_ADMIN !== true) {
                self::registerFrontend();
            }
        }

        // ---------------------------------------------------------------------
        // Ustawienia
        // ---------------------------------------------------------------------

        public static function opt(string $key, ?string $default = null): string
        {
            $fallback = $default ?? (self::$defaults[$key] ?? '');
            if (class_exists('\Core\Router')) {
                return (string)\Core\Router::getOption(self::OPT_PREFIX . $key, $fallback);
            }
            return $fallback;
        }

        public static function default(string $key): string
        {
            return self::$defaults[$key] ?? '';
        }

        /** @return array<string,string> */
        public static function allOptions(): array
        {
            $out = [];
            foreach (self::$defaults as $k => $v) {
                $out[$k] = self::opt($k);
            }
            return $out;
        }

        public static function setOption(string $key, string $value): void
        {
            try {
                $db = \Core\Database::getConnection();
                $stmt = $db->prepare("INSERT INTO settings (key, value) VALUES (:k, :v) ON CONFLICT(key) DO UPDATE SET value = :v");
                $stmt->execute([':k' => self::OPT_PREFIX . $key, ':v' => $value]);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // ---------------------------------------------------------------------
        // Baza danych (log zgód)
        // ---------------------------------------------------------------------

        private static function ensureTable(): void
        {
            try {
                $db = \Core\Database::getConnection();
                $db->exec("CREATE TABLE IF NOT EXISTS cookie_consent_log (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    consent_id TEXT,
                    action TEXT,
                    categories TEXT,
                    policy_version TEXT,
                    ip_hash TEXT,
                    user_agent TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )");
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // ---------------------------------------------------------------------
        // Frontend
        // ---------------------------------------------------------------------

        private static function registerFrontend(): void
        {
            if (self::opt('enabled') !== '1') {
                return;
            }
            if (self::opt('region_mode') === 'eu' && !self::isEuVisitor()) {
                return;
            }

            \Core\Hooks::addAction('theme-header', [self::class, 'renderHead']);
            \Core\Hooks::addAction('theme-footer', [self::class, 'renderFooter']);
        }

        public static function assetsUrl(): string
        {
            $base = '';
            if (class_exists('\Core\Router')) {
                $base = rtrim(\Core\Router::getBaseSubdirectory(), '/');
            }
            return $base . '/plugins/' . self::PLUGIN_ID . '/assets';
        }

        public static function renderHead(): void
        {
            $cfg = self::frontConfig();
            $css = self::assetsUrl() . '/cookie-consent.css';
            $js  = self::assetsUrl() . '/cookie-consent.js';

            echo "\n<!-- Cookie Consent (Modo CMS) -->\n";
            echo '<link rel="stylesheet" href="' . htmlspecialchars($css, ENT_QUOTES, 'UTF-8') . '">' . "\n";

            if (self::opt('consent_mode') === '1') {
                echo self::consentModeDefaultScript();
            }

            echo self::renderBlockedScripts('analytics', self::opt('scripts_analytics'));
            echo self::renderBlockedScripts('marketing', self::opt('scripts_marketing'));
            echo self::renderBlockedScripts('pref', self::opt('scripts_pref'));

            echo '<script id="cc-config" type="application/json">'
                . json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
            echo '<script defer src="' . htmlspecialchars($js, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
        }

        public static function renderFooter(): void
        {
            $cfg = self::frontConfig();
            echo "\n" . self::bannerHtml($cfg);
        }

        /**
         * Buduje konfigurację przekazywaną do frontowego JS.
         *
         * @return array<string,mixed>
         */
        public static function frontConfig(): array
        {
            $cats = [];
            foreach (['pref', 'analytics', 'marketing'] as $cat) {
                if (self::opt('cat_' . $cat . '_enabled') !== '1') {
                    continue;
                }
                $cats[] = [
                    'id'   => $cat,
                    'name' => self::opt('cat_' . $cat . '_name'),
                    'desc' => self::opt('cat_' . $cat . '_desc'),
                ];
            }

            return [
                'layout'        => self::opt('layout'),
                'position'      => self::opt('position'),
                'theme'         => self::opt('theme'),
                'zindex'        => (int)self::opt('zindex'),
                'radius'        => (int)self::opt('radius'),
                'accent'        => self::opt('accent'),
                'bg'            => self::opt('bg'),
                'text_color'    => self::opt('text_color'),
                'expiryDays'    => max(1, (int)self::opt('expiry_days')),
                'policyVersion' => self::opt('policy_version'),
                'consentMode'   => self::opt('consent_mode') === '1',
                'showRevoke'    => self::opt('show_revoke') === '1',
                'revokePosition'=> self::opt('revoke_position'),
                'categories'    => $cats,
                'labels'        => [
                    'title'      => self::opt('title'),
                    'message'    => self::opt('message'),
                    'accept'     => self::opt('btn_accept'),
                    'reject'     => self::opt('btn_reject'),
                    'settings'   => self::opt('btn_settings'),
                    'save'       => self::opt('btn_save'),
                    'revoke'     => self::opt('btn_revoke'),
                    'necessary'  => 'Niezbędne',
                    'alwaysOn'   => 'Zawsze aktywne',
                    'policyLabel'=> self::opt('policy_label'),
                    'cookieLabel'=> self::opt('cookie_label'),
                ],
                'policyUrl'     => self::opt('policy_url'),
                'cookieUrl'     => self::opt('cookie_url'),
                'endpoint'      => self::endpointUrl(),
                'server'        => [
                    'gpc' => self::hasGpc(),
                    'dnt' => self::hasDnt(),
                ],
            ];
        }

        public static function endpointUrl(): string
        {
            $base = '';
            if (class_exists('\Core\Router')) {
                $base = rtrim(\Core\Router::getBaseSubdirectory(), '/');
            }
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
            return $path . (str_contains($path, '?') ? '&' : '?') . 'cc_consent=1';
        }

        /**
         * Owija wklejone skrypty w <script type="text/plain"> (uśpione) z kategorią.
         */
        public static function renderBlockedScripts(string $category, string $raw): string
        {
            $raw = trim($raw);
            if ($raw === '') {
                return '';
            }
            $cat = htmlspecialchars($category, ENT_QUOTES, 'UTF-8');
            return '<script type="text/plain" data-cookieconsent="' . $cat . '">' . "\n"
                . $raw . "\n" . '</script>' . "\n";
        }

        public static function consentModeDefaultScript(): string
        {
            return '<script data-cc="consent-mode">' . "\n"
                . 'window.dataLayer = window.dataLayer || [];' . "\n"
                . 'function gtag(){dataLayer.push(arguments);}' . "\n"
                . "gtag('consent', 'default', {" . "\n"
                . "  'ad_storage': 'denied'," . "\n"
                . "  'ad_user_data': 'denied'," . "\n"
                . "  'ad_personalization': 'denied'," . "\n"
                . "  'analytics_storage': 'denied'," . "\n"
                . "  'functionality_storage': 'granted'," . "\n"
                . "  'security_storage': 'granted'," . "\n"
                . "  'wait_for_update': 500" . "\n"
                . '});' . "\n"
                . '</script>' . "\n";
        }

        /**
         * Renderuje kontener banera (wypełniany przez JS).
         *
         * @param array<string,mixed> $cfg
         */
        public static function bannerHtml(array $cfg): string
        {
            $esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

            $theme    = $esc($cfg['theme']);
            $layout   = $esc($cfg['layout']);
            $position = $esc($cfg['position']);
            $style    = 'z-index:' . (int)$cfg['zindex'] . ';';
            if ($cfg['accent'] !== '') {
                $style .= '--cc-accent:' . $esc($cfg['accent']) . ';';
            }
            if ($cfg['bg'] !== '') {
                $style .= '--cc-bg:' . $esc($cfg['bg']) . ';';
            }
            if ($cfg['text_color'] !== '') {
                $style .= '--cc-text:' . $esc($cfg['text_color']) . ';';
            }
            $style .= '--cc-radius:' . (int)$cfg['radius'] . 'px;';

            $html  = '<div id="cc-root" class="cc cc-theme-' . $theme . ' cc-layout-' . $layout . ' cc-pos-' . $position . '" style="' . $style . '" hidden>' . "\n";
            $html .= '  <div class="cc-panel" role="dialog" aria-modal="false" aria-labelledby="cc-title" aria-describedby="cc-message">' . "\n";
            $html .= '    <div class="cc-body">' . "\n";
            $html .= '      <h2 id="cc-title" class="cc-title">' . $esc($cfg['labels']['title']) . '</h2>' . "\n";
            $html .= '      <p id="cc-message" class="cc-message">' . nl2br($esc($cfg['labels']['message'])) . '</p>' . "\n";

            $links = [];
            if (!empty($cfg['policyUrl'])) {
                $links[] = '<a href="' . $esc($cfg['policyUrl']) . '" target="_blank" rel="noopener">' . $esc($cfg['labels']['policyLabel']) . '</a>';
            }
            if (!empty($cfg['cookieUrl'])) {
                $links[] = '<a href="' . $esc($cfg['cookieUrl']) . '" target="_blank" rel="noopener">' . $esc($cfg['labels']['cookieLabel']) . '</a>';
            }
            if ($links) {
                $html .= '      <p class="cc-links">' . implode(' &middot; ', $links) . '</p>' . "\n";
            }

            $html .= '    </div>' . "\n";

            $html .= '    <div class="cc-categories" hidden>' . "\n";
            $html .= '      <div class="cc-cat cc-cat-necessary">' . "\n";
            $html .= '        <div class="cc-cat-head"><span class="cc-cat-name">' . $esc($cfg['labels']['necessary']) . '</span>';
            $html .= '<span class="cc-badge">' . $esc($cfg['labels']['alwaysOn']) . '</span></div>' . "\n";
            $html .= '      </div>' . "\n";

            foreach ($cfg['categories'] as $cat) {
                $id = $esc($cat['id']);
                $html .= '      <label class="cc-cat" for="cc-cat-' . $id . '">' . "\n";
                $html .= '        <span class="cc-cat-head"><span class="cc-cat-name">' . $esc($cat['name']) . '</span>' . "\n";
                $html .= '          <span class="cc-switch"><input type="checkbox" id="cc-cat-' . $id . '" data-cat="' . $id . '"><span class="cc-slider"></span></span>' . "\n";
                $html .= '        </span>' . "\n";
                $html .= '        <span class="cc-cat-desc">' . $esc($cat['desc']) . '</span>' . "\n";
                $html .= '      </label>' . "\n";
            }
            $html .= '    </div>' . "\n";

            $html .= '    <div class="cc-actions">' . "\n";
            $html .= '      <button type="button" class="cc-btn cc-btn-ghost" data-cc="settings">' . $esc($cfg['labels']['settings']) . '</button>' . "\n";
            $html .= '      <button type="button" class="cc-btn cc-btn-ghost" data-cc="reject">' . $esc($cfg['labels']['reject']) . '</button>' . "\n";
            $html .= '      <button type="button" class="cc-btn cc-btn-primary" data-cc="accept">' . $esc($cfg['labels']['accept']) . '</button>' . "\n";
            $html .= '      <button type="button" class="cc-btn cc-btn-primary cc-btn-save" data-cc="save" hidden>' . $esc($cfg['labels']['save']) . '</button>' . "\n";
            $html .= '    </div>' . "\n";
            $html .= '  </div>' . "\n";
            $html .= '</div>' . "\n";

            if ($cfg['showRevoke']) {
                $pos = $esc($cfg['revokePosition']);
                $html .= '<button type="button" id="cc-revoke" class="cc cc-revoke cc-revoke-' . $pos . ' cc-theme-' . $theme . '" style="' . $style . '" hidden aria-label="' . $esc($cfg['labels']['revoke']) . '">'
                    . '<span class="cc-revoke-icon">&#127850;</span><span class="cc-revoke-text">' . $esc($cfg['labels']['revoke']) . '</span></button>' . "\n";
            }

            return $html;
        }

        // ---------------------------------------------------------------------
        // Detekcja regionu / sygnałów prywatności
        // ---------------------------------------------------------------------

        public static function hasGpc(): bool
        {
            return self::opt('respect_gpc') === '1'
                && (($_SERVER['HTTP_SEC_GPC'] ?? '') === '1');
        }

        public static function hasDnt(): bool
        {
            return self::opt('respect_dnt') === '1'
                && (($_SERVER['HTTP_DNT'] ?? '') === '1');
        }

        /**
         * Prosta heurystyka wykrywania odwiedzającego z UE/EOG.
         */
        public static function isEuVisitor(): bool
        {
            $country = strtoupper((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? $_SERVER['HTTP_X_COUNTRY_CODE'] ?? ''));
            $eu = ['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE','IS','LI','NO','CH','GB'];
            if ($country !== '' && strlen($country) === 2) {
                return in_array($country, $eu, true);
            }

            $accept = strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
            $euLangs = ['pl','de','fr','es','it','nl','pt','sv','da','fi','cs','sk','hu','ro','bg','el','hr','sl','et','lv','lt','ga','mt','is','no','en-gb'];
            foreach ($euLangs as $lang) {
                if (str_contains($accept, $lang)) {
                    return true;
                }
            }
            return false;
        }

        // ---------------------------------------------------------------------
        // Endpoint zapisu zgody (front, AJAX)
        // ---------------------------------------------------------------------

        private static function maybeHandleConsentEndpoint(): void
        {
            if (!isset($_GET['cc_consent'])) {
                return;
            }

            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');

            $raw  = file_get_contents('php://input') ?: '';
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                $data = $_POST;
            }

            $allowedCats = ['pref', 'analytics', 'marketing'];
            $cats = [];
            foreach ($allowedCats as $c) {
                $cats[$c] = !empty($data['categories'][$c]);
            }
            $action = in_array(($data['action'] ?? ''), ['accept_all', 'reject_all', 'save', 'revoke'], true)
                ? (string)$data['action'] : 'save';
            $consentId = preg_replace('/[^a-zA-Z0-9\-]/', '', (string)($data['consent_id'] ?? '')) ?: bin2hex(random_bytes(8));

            if (self::opt('log_enabled') === '1') {
                try {
                    $db = \Core\Database::getConnection();
                    $stmt = $db->prepare("INSERT INTO cookie_consent_log
                        (consent_id, action, categories, policy_version, ip_hash, user_agent)
                        VALUES (:cid, :action, :cats, :pv, :ip, :ua)");
                    $stmt->execute([
                        ':cid'    => $consentId,
                        ':action' => $action,
                        ':cats'   => json_encode($cats, JSON_UNESCAPED_UNICODE),
                        ':pv'     => self::opt('policy_version'),
                        ':ip'     => hash('sha256', (\Core\Security::clientIp() ?? '') . date('Y-m-d')),
                        ':ua'     => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 190),
                    ]);
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            echo json_encode(['ok' => true, 'consent_id' => $consentId, 'categories' => $cats], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ---------------------------------------------------------------------
        // Admin – zapis ustawień
        // ---------------------------------------------------------------------

        private static function maybeSaveSettings(): void
        {
            if (!isset($_POST['cc_save'])) {
                return;
            }
            if (!class_exists('\Core\Security') || !\Core\Security::verifyCsrfToken($_POST['cc_csrf'] ?? null)) {
                return;
            }
            if (class_exists('\Core\Auth') && !\Core\Auth::can('manage_settings')) {
                return;
            }

            $textKeys = [
                'layout', 'position', 'theme', 'accent', 'bg', 'text_color', 'radius', 'zindex',
                'title', 'message', 'btn_accept', 'btn_reject', 'btn_settings', 'btn_save', 'btn_revoke',
                'policy_url', 'policy_label', 'cookie_url', 'cookie_label',
                'cat_pref_name', 'cat_pref_desc', 'cat_analytics_name', 'cat_analytics_desc',
                'cat_marketing_name', 'cat_marketing_desc',
                'scripts_analytics', 'scripts_marketing', 'scripts_pref',
                'region_mode', 'expiry_days', 'policy_version', 'revoke_position',
            ];
            foreach ($textKeys as $key) {
                if (array_key_exists('cc_' . $key, $_POST)) {
                    self::setOption($key, trim((string)$_POST['cc_' . $key]));
                }
            }

            $boolKeys = [
                'enabled', 'cat_pref_enabled', 'cat_analytics_enabled', 'cat_marketing_enabled',
                'respect_gpc', 'respect_dnt', 'consent_mode', 'show_revoke', 'log_enabled',
            ];
            foreach ($boolKeys as $key) {
                self::setOption($key, isset($_POST['cc_' . $key]) ? '1' : '0');
            }

            if (class_exists('\Core\PageCache')) {
                \Core\PageCache::purge();
            }

            $target = 'plugins.php?id=' . self::PLUGIN_ID . '&cc_saved=1';
            if (!headers_sent()) {
                header('Location: ' . $target);
                exit;
            }
        }

        // ---------------------------------------------------------------------
        // Admin – panel ustawień (renderowany przez admin_plugin_view_*)
        // ---------------------------------------------------------------------

        public static function renderAdmin(): void
        {
            if (!class_exists('\Core\Security')) {
                return;
            }
            $csrf = \Core\Security::generateCsrfToken();
            $v = static fn(string $k): string => htmlspecialchars(self::opt($k), ENT_QUOTES, 'UTF-8');
            $sel = static function (string $k, string $val): string {
                return self::opt($k) === $val ? ' selected' : '';
            };
            $chk = static function (string $k): string {
                return self::opt($k) === '1' ? ' checked' : '';
            };
            ?>
            <?php if (isset($_GET['cc_saved'])): ?>
                <div class="card" style="padding:14px 18px; margin-bottom:16px; border-left:4px solid #16a34a; background:#f0fdf4; color:#166534;">
                    Ustawienia zapisane.
                </div>
            <?php endif; ?>

            <form method="post" action="plugins.php?id=<?= self::PLUGIN_ID ?>">
                <input type="hidden" name="cc_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

                <div class="card" style="padding:20px; margin-bottom:16px;">
                    <h2 style="margin:0 0 4px; font-size:18px;">Cookie Consent &amp; RODO / GDPR / CCPA</h2>
                    <p style="margin:0 0 16px; color:var(--text-muted); font-size:13px;">
                        Blokuje skrypty analityczne i marketingowe do momentu wyrażenia zgody. Zgodne z RODO (GDPR),
                        ePrivacy, CCPA/CPRA oraz Google Consent Mode v2.
                    </p>
                    <label style="display:flex; align-items:center; gap:10px; font-weight:600;">
                        <input type="checkbox" name="cc_enabled" value="1"<?= $chk('enabled') ?>>
                        Włącz baner zgody na stronie
                    </label>
                </div>

                <div class="card" style="padding:20px; margin-bottom:16px;">
                    <h3 style="margin:0 0 14px; font-size:15px;">Wygląd</h3>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px;">
                        <div class="form-group">
                            <label class="form-label">Układ</label>
                            <select class="form-control" name="cc_layout">
                                <option value="bar"<?= $sel('layout','bar') ?>>Pasek (bar)</option>
                                <option value="card"<?= $sel('layout','card') ?>>Karta (card)</option>
                                <option value="modal"<?= $sel('layout','modal') ?>>Modal (nakładka)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Pozycja</label>
                            <select class="form-control" name="cc_position">
                                <option value="bottom"<?= $sel('position','bottom') ?>>Dół</option>
                                <option value="top"<?= $sel('position','top') ?>>Góra</option>
                                <option value="bottom-left"<?= $sel('position','bottom-left') ?>>Dół – lewo</option>
                                <option value="bottom-right"<?= $sel('position','bottom-right') ?>>Dół – prawo</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Styl / motyw</label>
                            <select class="form-control" name="cc_theme">
                                <option value="light"<?= $sel('theme','light') ?>>Jasny</option>
                                <option value="dark"<?= $sel('theme','dark') ?>>Ciemny</option>
                                <option value="glass"<?= $sel('theme','glass') ?>>Glass (szkło)</option>
                                <option value="minimal"<?= $sel('theme','minimal') ?>>Minimal</option>
                                <option value="gradient"<?= $sel('theme','gradient') ?>>Gradient</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Kolor akcentu</label>
                            <input class="form-control" type="color" name="cc_accent" value="<?= $v('accent') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tło (opcjonalnie)</label>
                            <input class="form-control" type="text" name="cc_bg" value="<?= $v('bg') ?>" placeholder="#ffffff / rgba(...)">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Kolor tekstu (opcjonalnie)</label>
                            <input class="form-control" type="text" name="cc_text_color" value="<?= $v('text_color') ?>" placeholder="#111827">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Zaokrąglenie (px)</label>
                            <input class="form-control" type="number" name="cc_radius" value="<?= $v('radius') ?>" min="0" max="40">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Z-index</label>
                            <input class="form-control" type="number" name="cc_zindex" value="<?= $v('zindex') ?>">
                        </div>
                    </div>
                </div>

                <div class="card" style="padding:20px; margin-bottom:16px;">
                    <h3 style="margin:0 0 14px; font-size:15px;">Treści i przyciski</h3>
                    <div class="form-group">
                        <label class="form-label">Nagłówek</label>
                        <input class="form-control" type="text" name="cc_title" value="<?= $v('title') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Opis</label>
                        <textarea class="form-control" name="cc_message" rows="4"><?= $v('message') ?></textarea>
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px;">
                        <div class="form-group">
                            <label class="form-label">Przycisk: akceptuj wszystkie</label>
                            <input class="form-control" type="text" name="cc_btn_accept" value="<?= $v('btn_accept') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Przycisk: odrzuć opcjonalne</label>
                            <input class="form-control" type="text" name="cc_btn_reject" value="<?= $v('btn_reject') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Przycisk: dostosuj</label>
                            <input class="form-control" type="text" name="cc_btn_settings" value="<?= $v('btn_settings') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Przycisk: zapisz wybór</label>
                            <input class="form-control" type="text" name="cc_btn_save" value="<?= $v('btn_save') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Przycisk: zmień zgodę</label>
                            <input class="form-control" type="text" name="cc_btn_revoke" value="<?= $v('btn_revoke') ?>">
                        </div>
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:14px;">
                        <div class="form-group">
                            <label class="form-label">URL polityki prywatności</label>
                            <input class="form-control" type="text" name="cc_policy_url" value="<?= $v('policy_url') ?>" placeholder="/polityka-prywatnosci">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Etykieta polityki prywatności</label>
                            <input class="form-control" type="text" name="cc_policy_label" value="<?= $v('policy_label') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">URL polityki cookies</label>
                            <input class="form-control" type="text" name="cc_cookie_url" value="<?= $v('cookie_url') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Etykieta polityki cookies</label>
                            <input class="form-control" type="text" name="cc_cookie_label" value="<?= $v('cookie_label') ?>">
                        </div>
                    </div>
                </div>

                <div class="card" style="padding:20px; margin-bottom:16px;">
                    <h3 style="margin:0 0 6px; font-size:15px;">Kategorie zgody</h3>
                    <p style="margin:0 0 14px; color:var(--text-muted); font-size:12px;">„Niezbędne" są zawsze aktywne. Pozostałe kategorie można włączyć/wyłączyć.</p>

                    <?php
                    $catDefs = [
                        'pref'      => ['Funkcjonalne', 'cat_pref'],
                        'analytics' => ['Analityczne', 'cat_analytics'],
                        'marketing' => ['Marketingowe', 'cat_marketing'],
                    ];
                    foreach ($catDefs as $cid => $info):
                        [$label, $prefix] = $info;
                    ?>
                        <div style="border:1px solid var(--border-color,#e5e7eb); border-radius:10px; padding:14px; margin-bottom:12px;">
                            <label style="display:flex; align-items:center; gap:10px; font-weight:600; margin-bottom:10px;">
                                <input type="checkbox" name="cc_<?= $prefix ?>_enabled" value="1"<?= $chk($prefix . '_enabled') ?>>
                                <?= $label ?>
                            </label>
                            <div class="form-group">
                                <label class="form-label">Nazwa wyświetlana</label>
                                <input class="form-control" type="text" name="cc_<?= $prefix ?>_name" value="<?= $v($prefix . '_name') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Opis</label>
                                <textarea class="form-control" name="cc_<?= $prefix ?>_desc" rows="2"><?= $v($prefix . '_desc') ?></textarea>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="card" style="padding:20px; margin-bottom:16px;">
                    <h3 style="margin:0 0 6px; font-size:15px;">Blokowane skrypty</h3>
                    <p style="margin:0 0 14px; color:var(--text-muted); font-size:12px;">
                        Wklej tutaj kod skryptów analitycznych / marketingowych. Zostaną one wstrzymane
                        (jako <code>type="text/plain"</code>) i uruchomione dopiero po wyrażeniu odpowiedniej zgody.
                        Możesz też ręcznie oznaczać własne znaczniki atrybutem
                        <code>data-cookieconsent="analytics|marketing|pref"</code>.
                    </p>
                    <div class="form-group">
                        <label class="form-label">Skrypty analityczne (np. Google Analytics, Matomo)</label>
                        <textarea class="form-control" name="cc_scripts_analytics" rows="5" style="font-family:monospace; font-size:12px;"><?= $v('scripts_analytics') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Skrypty marketingowe (np. Meta Pixel, Google Ads)</label>
                        <textarea class="form-control" name="cc_scripts_marketing" rows="5" style="font-family:monospace; font-size:12px;"><?= $v('scripts_marketing') ?></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Skrypty funkcjonalne</label>
                        <textarea class="form-control" name="cc_scripts_pref" rows="4" style="font-family:monospace; font-size:12px;"><?= $v('scripts_pref') ?></textarea>
                    </div>
                </div>

                <div class="card" style="padding:20px; margin-bottom:16px;">
                    <h3 style="margin:0 0 14px; font-size:15px;">Zgodność i zaawansowane</h3>
                    <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px;">
                        <div class="form-group">
                            <label class="form-label">Wyświetlanie według regionu</label>
                            <select class="form-control" name="cc_region_mode">
                                <option value="all"<?= $sel('region_mode','all') ?>>Wszystkim odwiedzającym</option>
                                <option value="eu"<?= $sel('region_mode','eu') ?>>Tylko UE/EOG (RODO)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Ważność zgody (dni)</label>
                            <input class="form-control" type="number" name="cc_expiry_days" value="<?= $v('expiry_days') ?>" min="1">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Wersja polityki (zmiana wymusza ponowną zgodę)</label>
                            <input class="form-control" type="text" name="cc_policy_version" value="<?= $v('policy_version') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Pozycja przycisku „Zmień zgodę"</label>
                            <select class="form-control" name="cc_revoke_position">
                                <option value="right"<?= $sel('revoke_position','right') ?>>Prawy dolny róg</option>
                                <option value="left"<?= $sel('revoke_position','left') ?>>Lewy dolny róg</option>
                            </select>
                        </div>
                    </div>
                    <div style="display:grid; gap:8px; margin-top:6px;">
                        <label style="display:flex; align-items:center; gap:10px;">
                            <input type="checkbox" name="cc_consent_mode" value="1"<?= $chk('consent_mode') ?>>
                            Google Consent Mode v2 (domyślnie „denied", aktualizacja po zgodzie)
                        </label>
                        <label style="display:flex; align-items:center; gap:10px;">
                            <input type="checkbox" name="cc_respect_gpc" value="1"<?= $chk('respect_gpc') ?>>
                            Respektuj Global Privacy Control / Do Not Sell (CCPA/CPRA)
                        </label>
                        <label style="display:flex; align-items:center; gap:10px;">
                            <input type="checkbox" name="cc_respect_dnt" value="1"<?= $chk('respect_dnt') ?>>
                            Respektuj nagłówek Do Not Track (DNT)
                        </label>
                        <label style="display:flex; align-items:center; gap:10px;">
                            <input type="checkbox" name="cc_show_revoke" value="1"<?= $chk('show_revoke') ?>>
                            Pokaż pływający przycisk „Zmień zgodę" (wycofanie/zmiana zgody)
                        </label>
                        <label style="display:flex; align-items:center; gap:10px;">
                            <input type="checkbox" name="cc_log_enabled" value="1"<?= $chk('log_enabled') ?>>
                            Zapisuj dowody zgody w dzienniku (wymóg rozliczalności RODO)
                        </label>
                    </div>
                </div>

                <div style="position:sticky; bottom:0; padding:14px 0; background:var(--bg-body,#fff);">
                    <button type="submit" name="cc_save" value="1" class="btn btn-primary">Zapisz ustawienia</button>
                </div>
            </form>
            <?php
            self::renderLogTable();
        }

        // ---------------------------------------------------------------------
        // Admin – dziennik zgód
        // ---------------------------------------------------------------------

        public static function renderLogTable(): void
        {
            $rows = [];
            try {
                $db = \Core\Database::getConnection();
                $rows = $db->query("SELECT * FROM cookie_consent_log ORDER BY id DESC LIMIT 50")->fetchAll() ?: [];
            } catch (\Throwable $e) {
                $rows = [];
            }

            $esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
            $csrf = \Core\Security::generateCsrfToken();
            ?>
            <div class="card" style="padding:20px; margin-top:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <h3 style="margin:0; font-size:15px;">Dziennik zgód (ostatnie 50)</h3>
                    <a class="btn btn-secondary" style="font-size:12px; padding:4px 10px;"
                       href="plugins.php?id=<?= self::PLUGIN_ID ?>&cc_export=1&csrf=<?= $esc($csrf) ?>">Eksport CSV</a>
                </div>
                <div style="overflow-x:auto;">
                    <table class="pro-table" style="width:100%;">
                        <thead>
                            <tr>
                                <th>Data</th><th>Akcja</th><th>Kategorie</th><th>Wersja</th><th>ID zgody</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="5" style="text-align:center; padding:18px; color:var(--text-muted);">Brak zapisanych zgód.</td></tr>
                        <?php else: foreach ($rows as $r): ?>
                            <tr>
                                <td><?= $esc($r['created_at'] ?? '') ?></td>
                                <td><?= $esc($r['action'] ?? '') ?></td>
                                <td><code style="font-size:11px;"><?= $esc($r['categories'] ?? '') ?></code></td>
                                <td><?= $esc($r['policy_version'] ?? '') ?></td>
                                <td><code style="font-size:11px;"><?= $esc($r['consent_id'] ?? '') ?></code></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php
        }

        /**
         * Eksport dziennika zgód do CSV (obsługa żądania z panelu admina).
         */
        public static function handleExport(): void
        {
            if (!isset($_GET['cc_export'])) {
                return;
            }
            if (!class_exists('\Core\Security') || !\Core\Security::verifyCsrfToken($_GET['csrf'] ?? null)) {
                return;
            }
            if (class_exists('\Core\Auth') && !\Core\Auth::can('manage_settings')) {
                return;
            }

            $rows = [];
            try {
                $db = \Core\Database::getConnection();
                $rows = $db->query("SELECT * FROM cookie_consent_log ORDER BY id DESC")->fetchAll() ?: [];
            } catch (\Throwable $e) {
                $rows = [];
            }

            if (!headers_sent()) {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="cookie-consent-log.csv"');
            }
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'created_at', 'action', 'categories', 'policy_version', 'consent_id', 'user_agent']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['id'] ?? '', $r['created_at'] ?? '', $r['action'] ?? '',
                    $r['categories'] ?? '', $r['policy_version'] ?? '',
                    $r['consent_id'] ?? '', $r['user_agent'] ?? '',
                ]);
            }
            fclose($out);
            exit;
        }

        }
}

// Boot the plugin as soon as it is loaded by the plugin loader.
ModoCookieConsent::init();