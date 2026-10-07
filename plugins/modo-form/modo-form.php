<?php
/**
 * Plugin Name: Modo Form
 * Description: Kreator formularzy zapytań i ankiet. Osadzanie przez shortcode [form id="1"] lub bezpośrednio w PHP (the_modo_form). Archiwizacja zgłoszeń w bazie oraz obsługa hCaptcha, Google reCAPTCHA i własnego zabezpieczenia (honeypot + pułapka czasowa + podpisany token).
 * Version: 1.0.0
 * Author: Modo CMS
 *
 * @package ModoForm
 */

if (!class_exists('ModoForm')) {

    /**
     * Modo Form - builder formularzy zapytań i ankiet dla Modo CMS.
     */
    final class ModoForm
    {
        public const PLUGIN_ID  = 'modo-form';
        public const VERSION    = '1.0.0';
        public const TAG        = 'form';
        public const OPT_PREFIX = 'mf_';

        private static bool $booted  = false;
        private static bool $cssDone = false;
        private static bool $jsDone  = false;
        private static bool $used    = false;
        private static bool $needHcaptcha  = false;
        private static bool $needRecaptcha = false;

        /** Błędy walidacji bieżącego żądania (per formularz). @var array<int,array<string,string>> */
        private static array $errors = [];
        /** Zachowane wartości pól po nieudanej walidacji. @var array<int,array<string,mixed>> */
        private static array $old = [];

        /** @var array<string,string> Domyślne ustawienia globalne. */
        private static array $defaults = [
            'captcha_mode'      => 'own',   // none | own | hcaptcha | recaptcha
            'hcaptcha_site'     => '',
            'hcaptcha_secret'   => '',
            'recaptcha_site'    => '',
            'recaptcha_secret'  => '',
            'recaptcha_version' => 'v2',    // v2 | v3
            'recaptcha_score'   => '0.5',
            'honeypot_enabled'  => '1',
            'min_seconds'       => '3',
            'store_ip'          => '1',
            'store'             => '1',
            'notify'            => '0',
            'notify_email'      => '',
            'from_email'        => '',
        ];

        public static function init(): void
        {
            if (self::$booted) {
                return;
            }
            self::$booted = true;

            self::ensureTables();

            if (function_exists('register_plugin')) {
                register_plugin(
                    self::PLUGIN_ID,
                    'Modo Form',
                    self::VERSION,
                    'Modo CMS',
                    '',
                    'Kreator formularzy zapytań i ankiet z archiwizacją zgłoszeń oraz obsługą hCaptcha, reCAPTCHA i własnego zabezpieczenia.',
                    'settings',
                    'ModoForm::renderAdmin'
                );
            }
            if (function_exists('createSideMenu')) {
                createSideMenu(
                    self::PLUGIN_ID,
                    'Forms',
                    self::PLUGIN_ID,
                    '<svg class="nav-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>'
                );
            }

            if (defined('IN_ADMIN') && IN_ADMIN === true) {
                self::handleAdminPost();
                \Core\Hooks::addAction('admin-edit-form', [self::class, 'renderEditorInsertScript']);
            } else {
                \Core\Hooks::addFilter('the_content', [self::class, 'renderShortcode'], 20);
                \Core\Hooks::addAction('theme-header', [self::class, 'maybeHeadAssets']);
                \Core\Hooks::addAction('theme-footer', [self::class, 'maybeFooterAssets']);
                self::maybeHandleSubmission();
            }
        }

        // ---------------------------------------------------------------------
        // Sciezki / baza danych / ustawienia
        // ---------------------------------------------------------------------

        public static function assetsUrl(): string
        {
            $base = '';
            if (class_exists('\\Core\\Router')) {
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

        private static function ensureTables(): void
        {
            $db = self::db();
            if (!$db) {
                return;
            }
            try {
                $db->exec("CREATE TABLE IF NOT EXISTS modo_forms (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    slug TEXT UNIQUE NOT NULL,
                    status TEXT NOT NULL DEFAULT 'published',
                    fields TEXT NOT NULL DEFAULT '[]',
                    settings TEXT NOT NULL DEFAULT '{}',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )");
                $db->exec("CREATE TABLE IF NOT EXISTS modo_form_submissions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    form_id INTEGER NOT NULL,
                    data TEXT NOT NULL DEFAULT '{}',
                    ip_hash TEXT,
                    user_agent TEXT,
                    page_url TEXT,
                    referer TEXT,
                    status TEXT NOT NULL DEFAULT 'new',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )");
                $db->exec("CREATE INDEX IF NOT EXISTS idx_mf_subs_form ON modo_form_submissions(form_id)");
            } catch (\Throwable $e) {
                // ignore
            }
        }

        public static function opt(string $key, ?string $default = null): string
        {
            $fallback = $default ?? (self::$defaults[$key] ?? '');
            if (class_exists('\\Core\\Router')) {
                return (string)\Core\Router::getOption(self::OPT_PREFIX . $key, $fallback);
            }
            return $fallback;
        }

        public static function setOption(string $key, string $value): void
        {
            try {
                $db = self::db();
                if (!$db) {
                    return;
                }
                $stmt = $db->prepare("INSERT INTO settings (key, value) VALUES (:k, :v) ON CONFLICT(key) DO UPDATE SET value = :v");
                $stmt->execute([':k' => self::OPT_PREFIX . $key, ':v' => $value]);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        /** Sekret HMAC instalacji (generowany raz, trzymany w settings). */
        private static function secret(): string
        {
            $s = self::opt('secret');
            if ($s === '') {
                $s = function_exists('random_bytes') ? bin2hex(random_bytes(24)) : hash('sha256', (string)microtime(true) . (string)mt_rand());
                self::setOption('secret', $s);
            }
            return $s;
        }

        private static function slugify(string $text): string
        {
            $text = trim($text);
            if ($text === '') {
                return 'form';
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
            return $text !== '' ? $text : 'form';
        }

        private static function uniqueSlug(string $slug, int $ignoreId = 0): string
        {
            $db   = self::db();
            $base = self::slugify($slug);
            $candidate = $base;
            $i = 2;
            while ($db) {
                try {
                    $stmt = $db->prepare("SELECT id FROM modo_forms WHERE slug = :s AND id <> :id LIMIT 1");
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
        // Model danych (formularze / pola / opcje)
        // ---------------------------------------------------------------------

        /** @return array<int,string> */
        public static function fieldTypes(): array
        {
            return ['text', 'email', 'tel', 'url', 'number', 'date', 'textarea', 'select', 'radio', 'checkbox', 'checkboxes', 'consent', 'heading', 'hidden'];
        }

        /** @return array<string,string> Domyślne ustawienia per-formularz. */
        public static function formDefaults(): array
        {
            return [
                'submit_label' => '',
                'success'      => '',
                'redirect'     => '',
                'store'        => '1',
                'notify'       => '0',
                'notify_email' => '',
                'captcha'      => 'inherit', // inherit | none | own | hcaptcha | recaptcha
                'honeypot'     => '1',
            ];
        }

        /** @return array<int,array<string,mixed>> */
        public static function decodeFields(?string $json): array
        {
            $fields = json_decode((string)$json, true);
            if (!is_array($fields)) {
                return [];
            }
            $types = self::fieldTypes();
            $out = [];
            foreach ($fields as $f) {
                if (!is_array($f)) {
                    continue;
                }
                $type = (string)($f['type'] ?? 'text');
                if (!in_array($type, $types, true)) {
                    $type = 'text';
                }
                $opts = [];
                if (isset($f['options']) && is_array($f['options'])) {
                    foreach ($f['options'] as $o) {
                        if (is_scalar($o)) {
                            $opts[] = (string)$o;
                        }
                    }
                }
                $out[] = [
                    'type'        => $type,
                    'name'        => preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($f['name'] ?? '')) ?: ('field' . (count($out) + 1)),
                    'label'       => (string)($f['label'] ?? ''),
                    'placeholder' => (string)($f['placeholder'] ?? ''),
                    'required'    => !empty($f['required']),
                    'width'       => (($f['width'] ?? 'full') === 'half') ? 'half' : 'full',
                    'options'     => $opts,
                    'value'       => (string)($f['value'] ?? ''),
                ];
            }
            return $out;
        }

        /** @return array<string,string> */
        public static function decodeSettings(?string $json): array
        {
            $opts = json_decode((string)$json, true);
            if (!is_array($opts)) {
                $opts = [];
            }
            $out = self::formDefaults();
            foreach ($opts as $k => $v) {
                if (is_scalar($v)) {
                    $out[(string)$k] = (string)$v;
                }
            }
            return $out;
        }

        // ---------------------------------------------------------------------
        // Zapytania o formularze
        // ---------------------------------------------------------------------

        /** @return array<int,array<string,mixed>> */
        public static function allForms(): array
        {
            $db = self::db();
            if (!$db) {
                return [];
            }
            try {
                return $db->query("SELECT * FROM modo_forms ORDER BY updated_at DESC, id DESC")->fetchAll() ?: [];
            } catch (\Throwable $e) {
                return [];
            }
        }

        /** @return array<string,mixed>|null */
        public static function find(int $id): ?array
        {
            $db = self::db();
            if (!$db || $id <= 0) {
                return null;
            }
            try {
                $stmt = $db->prepare("SELECT * FROM modo_forms WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch();
                return $row ?: null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        /** @return array<string,mixed>|null */
        public static function findBySlug(string $slug): ?array
        {
            $db = self::db();
            $slug = self::slugify($slug);
            if (!$db || $slug === '') {
                return null;
            }
            try {
                $stmt = $db->prepare("SELECT * FROM modo_forms WHERE slug = :s LIMIT 1");
                $stmt->execute([':s' => $slug]);
                $row = $stmt->fetch();
                return $row ?: null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        /** @return array<string,mixed>|null */
        public static function resolve(int|string $idOrSlug): ?array
        {
            if (is_int($idOrSlug) || (is_string($idOrSlug) && ctype_digit($idOrSlug))) {
                return self::find((int)$idOrSlug);
            }
            return self::findBySlug((string)$idOrSlug);
        }

        public static function exists(int|string $idOrSlug): bool
        {
            return self::resolve($idOrSlug) !== null;
        }

        public static function countSubmissions(int $formId, ?string $status = null): int
        {
            $db = self::db();
            if (!$db) {
                return 0;
            }
            try {
                if ($status !== null) {
                    $stmt = $db->prepare("SELECT COUNT(*) FROM modo_form_submissions WHERE form_id = :f AND status = :s");
                    $stmt->execute([':f' => $formId, ':s' => $status]);
                } else {
                    $stmt = $db->prepare("SELECT COUNT(*) FROM modo_form_submissions WHERE form_id = :f");
                    $stmt->execute([':f' => $formId]);
                }
                return (int)$stmt->fetchColumn();
            } catch (\Throwable $e) {
                return 0;
            }
        }

        /** Efektywny tryb captcha dla formularza. */
        private static function captchaMode(array $settings): string
        {
            $mode = (string)($settings['captcha'] ?? 'inherit');
            if ($mode === 'inherit' || $mode === '') {
                $mode = self::opt('captcha_mode', 'own');
            }
            return in_array($mode, ['none', 'own', 'hcaptcha', 'recaptcha'], true) ? $mode : 'none';
        }

        private static function effectiveSetting(array $settings, string $key): string
        {
            $v = (string)($settings[$key] ?? '');
            if ($v === '' && array_key_exists($key, self::$defaults)) {
                return self::opt($key);
            }
            return $v;
        }

        // ---------------------------------------------------------------------
        // Frontend - shortcode
        // ---------------------------------------------------------------------

        public static function renderShortcode(string $content): string
        {
            if ($content === '' || !str_contains($content, '[' . self::TAG)) {
                return $content;
            }
            return (string)preg_replace_callback(
                '/\[form\b([^\]]*)\]/i',
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
                    if ($id <= 0 && $slug === '') {
                        return '';
                    }
                    $form = $id > 0 ? self::find($id) : self::findBySlug($slug);
                    return ($form && ($form['status'] ?? 'published') === 'published') ? self::renderOne($form) : '';
                },
                $content
            );
        }

        // ---------------------------------------------------------------------
        // Frontend - renderowanie formularza
        // ---------------------------------------------------------------------

        public static function renderOne(array $form, array $override = []): string
        {
            self::$used = true;

            $id       = (int)$form['id'];
            $settings = self::decodeSettings($form['settings'] ?? '{}');
            $fields   = self::decodeFields($form['fields'] ?? '[]');

            foreach ($override as $k => $v) {
                if (is_scalar($v)) {
                    $settings[(string)$k] = (string)$v;
                }
            }

            $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

            // Komunikat sukcesu (parametr URL - odporny na pelnostronicowy cache).
            $sent = (string)($_GET['mf_sent'] ?? '');
            if ($sent !== '' && ($sent === (string)$id || $sent === (string)($form['slug'] ?? ''))) {
                $msg = (string)$settings['success'] !== '' ? (string)$settings['success'] : __('mf_success_default');
                return '<div class="modo-form modo-form--done" id="modo-form-' . $id . '" role="status">'
                    . '<div class="modo-form__done-title">' . $esc((string)$form['name']) . '</div>'
                    . '<p class="modo-form__message">' . nl2br($esc($msg)) . '</p>'
                    . '</div>';
            }

            $errors = self::$errors[$id] ?? [];
            $old    = self::$old[$id] ?? [];
            $mode   = self::captchaMode($settings);
            $csrf   = \Core\Security::generateCsrfToken();
            $ts     = time();

            $html  = '';
            $html .= '<form class="modo-form modo-form--' . $esc($mode) . '" id="modo-form-' . $id . '" method="post" action="" novalidate data-mf="' . $id . '">';
            $html .= '<input type="hidden" name="mf_submit" value="1">';
            $html .= '<input type="hidden" name="mf_id" value="' . $id . '">';
            $html .= '<input type="hidden" name="mf_csrf" value="' . $esc($csrf) . '">';
            $html .= '<input type="hidden" name="mf_ts" value="' . $ts . '">';
            $html .= '<input type="hidden" name="mf_token" value="' . $esc(self::signToken($id, $ts)) . '">';

            // Honeypot - ukryte pole pulapka na boty.
            $html .= '<div class="modo-form__hp" aria-hidden="true" style="position:absolute!important;left:-9999px!important;width:1px!important;height:1px!important;overflow:hidden!important;">';
            $html .= '<label>Leave this field empty</label>';
            $html .= '<input type="text" name="mf_hp" value="" tabindex="-1" autocomplete="off">';
            $html .= '</div>';

            if ($errors !== []) {
                $html .= '<div class="modo-form__alert modo-form__alert--error" role="alert">' . $esc(__('mf_errors_intro')) . '<ul>';
                foreach ($errors as $e) {
                    $html .= '<li>' . $esc($e) . '</li>';
                }
                $html .= '</ul></div>';
            }

            $html .= '<div class="modo-form__fields">';
            foreach ($fields as $field) {
                $html .= self::renderField($field, $old, $errors, $esc);
            }
            $html .= '</div>';

            $html .= self::renderCaptcha($id, $mode, $ts, $esc);

            $submit = (string)$settings['submit_label'] !== '' ? (string)$settings['submit_label'] : __('mf_submit');
            $html .= '<div class="modo-form__actions"><button type="submit" class="modo-form__submit">' . $esc($submit) . '</button></div>';
            $html .= '</form>';

            return $html;
        }

        private static function signToken(int $formId, int $ts): string
        {
            return hash_hmac('sha256', $formId . '|' . $ts, self::secret());
        }

        private static function renderField(array $field, array $old, array $errors, callable $esc): string
        {
            $type  = (string)$field['type'];
            $name  = (string)$field['name'];
            $label = (string)$field['label'];
            $ph    = (string)$field['placeholder'];
            $req   = !empty($field['required']) && $type !== 'heading';
            $val   = $old[$name] ?? ($field['value'] ?? '');
            $err   = (string)($errors[$name] ?? '');
            $width = ($field['width'] ?? 'full') === 'half' ? 'half' : 'full';

            if ($type === 'heading') {
                return '<div class="modo-form__row modo-form__row--full"><h3 class="modo-form__heading">' . $esc($label) . '</h3></div>';
            }
            if ($type === 'hidden') {
                return '<input type="hidden" name="mf_f[' . $esc($name) . ']" value="' . $esc(is_array($val) ? '' : (string)$val) . '">';
            }

            $reqAttr = $req ? ' required' : '';
            $reqMark = $req ? ' <span class="modo-form__req">*</span>' : '';
            $input   = '';

            switch ($type) {
                case 'textarea':
                    $input = '<textarea class="modo-form__input" name="mf_f[' . $esc($name) . ']" placeholder="' . $esc($ph) . '"' . $reqAttr . '>' . $esc(is_array($val) ? '' : (string)$val) . '</textarea>';
                    break;
                case 'select':
                    $input = '<select class="modo-form__input" name="mf_f[' . $esc($name) . ']"' . $reqAttr . '>';
                    if ($ph !== '') {
                        $input .= '<option value="">' . $esc($ph) . '</option>';
                    }
                    foreach ($field['options'] as $o) {
                        $sel = ((string)$val === (string)$o) ? ' selected' : '';
                        $input .= '<option value="' . $esc((string)$o) . '"' . $sel . '>' . $esc((string)$o) . '</option>';
                    }
                    $input .= '</select>';
                    break;
                case 'radio':
                    $input = '<div class="modo-form__choices">';
                    foreach ($field['options'] as $o) {
                        $chk = ((string)$val === (string)$o) ? ' checked' : '';
                        $input .= '<label class="modo-form__choice"><input type="radio" name="mf_f[' . $esc($name) . ']" value="' . $esc((string)$o) . '"' . $chk . '> <span>' . $esc((string)$o) . '</span></label>';
                    }
                    $input .= '</div>';
                    break;
                case 'checkboxes':
                    $arr = is_array($val) ? array_map('strval', $val) : [];
                    $input = '<div class="modo-form__choices">';
                    foreach ($field['options'] as $o) {
                        $chk = in_array((string)$o, $arr, true) ? ' checked' : '';
                        $input .= '<label class="modo-form__choice"><input type="checkbox" name="mf_f[' . $esc($name) . '][]" value="' . $esc((string)$o) . '"' . $chk . '> <span>' . $esc((string)$o) . '</span></label>';
                    }
                    $input .= '</div>';
                    break;
                case 'checkbox':
                case 'consent':
                    $chk = !empty($val) ? ' checked' : '';
                    $input = '<label class="modo-form__choice"><input type="checkbox" name="mf_f[' . $esc($name) . ']" value="1"' . $chk . $reqAttr . '> <span>' . $esc($label) . $reqMark . '</span></label>';
                    return '<div class="modo-form__row modo-form__row--' . $width . ($err !== '' ? ' is-error' : '') . '">'
                        . $input
                        . ($err !== '' ? '<div class="modo-form__error">' . $esc($err) . '</div>' : '')
                        . '</div>';
                default:
                    $t = in_array($type, ['email', 'tel', 'url', 'number', 'date'], true) ? $type : 'text';
                    $input = '<input class="modo-form__input" type="' . $t . '" name="mf_f[' . $esc($name) . ']" value="' . $esc(is_array($val) ? '' : (string)$val) . '" placeholder="' . $esc($ph) . '"' . $reqAttr . '>';
            }

            return '<div class="modo-form__row modo-form__row--' . $width . ($err !== '' ? ' is-error' : '') . '">'
                . '<label class="modo-form__label">' . $esc($label) . $reqMark . '</label>'
                . $input
                . ($err !== '' ? '<div class="modo-form__error">' . $esc($err) . '</div>' : '')
                . '</div>';
        }

        private static function renderCaptcha(int $formId, string $mode, int $ts, callable $esc): string
        {
            if ($mode === 'hcaptcha') {
                $site = self::opt('hcaptcha_site');
                if ($site !== '') {
                    self::$needHcaptcha = true;
                    return '<div class="modo-form__captcha"><div class="h-captcha" data-sitekey="' . $esc($site) . '"></div></div>';
                }
            } elseif ($mode === 'recaptcha') {
                $site = self::opt('recaptcha_site');
                if ($site !== '') {
                    self::$needRecaptcha = true;
                    if (self::opt('recaptcha_version', 'v2') === 'v3') {
                        return '<div class="modo-form__captcha"><input type="hidden" name="mf_grecaptcha" value=""></div>';
                    }
                    return '<div class="modo-form__captcha"><div class="g-recaptcha" data-sitekey="' . $esc($site) . '"></div></div>';
                }
            } elseif ($mode === 'own') {
                $a   = random_int(1, 9);
                $b   = random_int(1, 9);
                $sum = $a + $b;
                $token = hash_hmac('sha256', 'own|' . $formId . '|' . $sum . '|' . $ts, self::secret());
                return '<div class="modo-form__captcha modo-form__captcha--own">'
                    . '<label class="modo-form__label">' . $esc(sprintf(__('mf_captcha_question'), $a, $b)) . ' <span class="modo-form__req">*</span></label>'
                    . '<input type="hidden" name="mf_own_token" value="' . $esc($token) . '">'
                    . '<input class="modo-form__input" type="text" name="mf_own_answer" value="" autocomplete="off" required style="max-width:140px;">'
                    . '</div>';
            }
            return '';
        }

        // ---------------------------------------------------------------------
        // Frontend - assety (CSS/JS) ladowane warunkowo
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
            echo '<link rel="stylesheet" href="' . htmlspecialchars($u . '/modo-form.css', ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
        }

        private static function emitJs(): void
        {
            if (self::$jsDone) {
                return;
            }
            self::$jsDone = true;
            $u = self::assetsUrl();

            if (self::$needHcaptcha) {
                echo '<script src="https://js.hcaptcha.com/1/api.js" async defer></script>' . PHP_EOL;
            }
            if (self::$needRecaptcha) {
                if (self::opt('recaptcha_version', 'v2') === 'v3') {
                    $site = self::opt('recaptcha_site');
                    echo '<script src="https://www.google.com/recaptcha/api.js?render=' . htmlspecialchars($site, ENT_QUOTES, 'UTF-8') . '"></script>' . PHP_EOL;
                    echo '<script>window.mfRecaptchaV3={siteKey:' . json_encode($site) . ',score:0};</script>' . PHP_EOL;
                } else {
                    echo '<script src="https://www.google.com/recaptcha/api.js" async defer></script>' . PHP_EOL;
                }
            }

            echo '<script src="' . htmlspecialchars($u . '/modo-form.js', ENT_QUOTES, 'UTF-8') . '" defer></script>' . PHP_EOL;
        }

        /** Emituje assety raz - przydatne przy osadzaniu w szablonie. */
        public static function enqueueAssets(): void
        {
            self::emitCss();
            self::emitJs();
        }

        // ---------------------------------------------------------------------
        // Frontend - obsluga zgloszenia (POST)
        // ---------------------------------------------------------------------

        private static function maybeHandleSubmission(): void
        {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !isset($_POST['mf_submit'])) {
                return;
            }

            $formId = (int)($_POST['mf_id'] ?? 0);
            $form   = self::find($formId);
            if (!$form || ($form['status'] ?? 'published') !== 'published') {
                self::redirectBack();
            }

            $settings = self::decodeSettings($form['settings'] ?? '{}');
            $fields   = self::decodeFields($form['fields'] ?? '[]');
            $mode     = self::captchaMode($settings);

            // 1. CSRF
            if (!class_exists('\\Core\\Security') || !\Core\Security::verifyCsrfToken($_POST['mf_csrf'] ?? null)) {
                self::$errors[$formId]['_form'] = __('mf_err_csrf');
                self::captureOld($formId, $fields);
                return;
            }

            // 2. Honeypot - wypelnione pole pulapka = bot (udawany sukces bez zapisu).
            if (self::effectiveSetting($settings, 'honeypot') === '1' && trim((string)($_POST['mf_hp'] ?? '')) !== '') {
                self::redirectSuccess($formId, $settings);
            }

            // 3. Podpisany token + pulapka czasowa.
            $ts    = (int)($_POST['mf_ts'] ?? 0);
            $token = (string)($_POST['mf_token'] ?? '');
            $min   = max(0, (int)self::opt('min_seconds', '3'));
            $age   = time() - $ts;
            if ($ts <= 0 || $age < $min || $age > 86400 || !hash_equals(self::signToken($formId, $ts), $token)) {
                self::$errors[$formId]['_form'] = __('mf_err_token');
                self::captureOld($formId, $fields);
                return;
            }

            // 4. Captcha (hCaptcha / reCAPTCHA / wlasne).
            $capError = self::verifyCaptcha($formId, $mode, $ts);
            if ($capError !== '') {
                self::$errors[$formId]['_form'] = $capError;
                self::captureOld($formId, $fields);
                return;
            }

            // 5. Walidacja pol.
            $collected = self::collectFields($fields, $formId);
            if (!empty(self::$errors[$formId])) {
                return;
            }

            // 6. Archiwizacja zgloszenia.
            if (self::effectiveSetting($settings, 'store') === '1') {
                self::storeSubmission($formId, $collected);
            }

            // 7. Powiadomienie e-mail.
            $notifyEmail = self::effectiveSetting($settings, 'notify_email');
            if ($notifyEmail === '') {
                $notifyEmail = self::opt('notify_email');
            }
            if (self::effectiveSetting($settings, 'notify') === '1' && $notifyEmail !== '') {
                self::notifyEmail($notifyEmail, $form, $collected);
            }

            if (class_exists('\\Core\\PageCache')) {
                \Core\PageCache::purge();
            }

            self::redirectSuccess($formId, $settings);
        }

        /** @return string Komunikat bledu lub '' gdy OK. */
        private static function verifyCaptcha(int $formId, string $mode, int $ts): string
        {
            if ($mode === 'none') {
                return '';
            }

            if ($mode === 'own') {
                $ans = trim((string)($_POST['mf_own_answer'] ?? ''));
                if ($ans === '' || !ctype_digit($ans)) {
                    return __('mf_err_captcha');
                }
                $expected = hash_hmac('sha256', 'own|' . $formId . '|' . (int)$ans . '|' . $ts, self::secret());
                if (!hash_equals($expected, (string)($_POST['mf_own_token'] ?? ''))) {
                    return __('mf_err_captcha');
                }
                return '';
            }

            $ip = class_exists('\\Core\\Security') ? \Core\Security::clientIp() : '';

            if ($mode === 'hcaptcha') {
                $secret = self::opt('hcaptcha_secret');
                if ($secret === '') {
                    return '';
                }
                $resp = trim((string)($_POST['h-captcha-response'] ?? ''));
                if ($resp === '') {
                    return __('mf_err_captcha');
                }
                $res = self::httpPost('https://hcaptcha.com/siteverify', ['secret' => $secret, 'response' => $resp, 'remoteip' => $ip]);
                return (is_array($res) && !empty($res['success'])) ? '' : __('mf_err_captcha');
            }

            if ($mode === 'recaptcha') {
                $secret = self::opt('recaptcha_secret');
                if ($secret === '') {
                    return '';
                }
                $resp = trim((string)($_POST['g-recaptcha-response'] ?? $_POST['mf_grecaptcha'] ?? ''));
                if ($resp === '') {
                    return __('mf_err_captcha');
                }
                $res = self::httpPost('https://www.google.com/recaptcha/api/siteverify', ['secret' => $secret, 'response' => $resp, 'remoteip' => $ip]);
                if (!is_array($res) || empty($res['success'])) {
                    return __('mf_err_captcha');
                }
                if (self::opt('recaptcha_version', 'v2') === 'v3' && (float)($res['score'] ?? 0) < (float)self::opt('recaptcha_score', '0.5')) {
                    return __('mf_err_captcha');
                }
                return '';
            }

            return '';
        }

        /** @return array<string,mixed>|null */
        private static function httpPost(string $url, array $data): ?array
        {
            $body = http_build_query($data);
            $raw  = null;

            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $body,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 8,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $raw = curl_exec($ch);
                if ($raw === false) {
                    $raw = null;
                }
                curl_close($ch);
            } elseif (ini_get('allow_url_fopen')) {
                $ctx = stream_context_create([
                    'http' => [
                        'method'  => 'POST',
                        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                        'content' => $body,
                        'timeout' => 8,
                    ],
                ]);
                $raw = @file_get_contents($url, false, $ctx);
            }

            if (!is_string($raw) || $raw === '') {
                return null;
            }
            $json = json_decode($raw, true);
            return is_array($json) ? $json : null;
        }

        /**
         * Waliduje i zbiera wartosci pol. Bledy trafiaja do self::$errors[$formId].
         *
         * @param array<int,array<string,mixed>> $fields
         * @return array<string,mixed> Wartosci kluczowane etykieta pola (do archiwizacji/CSV).
         */
        private static function collectFields(array $fields, int $formId): array
        {
            $data   = [];
            $posted = $_POST['mf_f'] ?? [];
            if (!is_array($posted)) {
                $posted = [];
            }

            foreach ($fields as $field) {
                $type  = (string)$field['type'];
                if ($type === 'heading') {
                    continue;
                }
                $name  = (string)$field['name'];
                $label = (string)$field['label'] !== '' ? (string)$field['label'] : $name;
                $raw   = $posted[$name] ?? '';

                if ($type === 'checkboxes') {
                    $arr = is_array($raw) ? array_map(static fn($x) => trim(strip_tags((string)$x)), $raw) : [];
                    $arr = array_values(array_filter($arr, static fn($x) => $x !== ''));
                    self::$old[$formId][$name] = $arr;
                    if (!empty($field['required']) && $arr === []) {
                        self::$errors[$formId][$name] = sprintf(__('mf_err_required'), $label);
                    }
                    $data[$label] = implode(', ', $arr);
                    continue;
                }

                $val = is_array($raw) ? '' : trim(strip_tags((string)$raw));

                if ($type === 'checkbox' || $type === 'consent') {
                    $checked = $val !== '';
                    self::$old[$formId][$name] = $checked;
                    if (!empty($field['required']) && !$checked) {
                        self::$errors[$formId][$name] = sprintf(__('mf_err_consent'), $label);
                    }
                    $data[$label] = $checked ? __('mf_yes') : __('mf_no');
                    continue;
                }

                $val = function_exists('mb_substr') ? mb_substr($val, 0, 5000) : substr($val, 0, 5000);
                self::$old[$formId][$name] = $val;

                if (!empty($field['required']) && $val === '') {
                    self::$errors[$formId][$name] = sprintf(__('mf_err_required'), $label);
                    continue;
                }
                if ($val !== '' && $type === 'email' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                    self::$errors[$formId][$name] = __('mf_err_email');
                    continue;
                }
                if ($val !== '' && $type === 'url' && !filter_var($val, FILTER_VALIDATE_URL)) {
                    self::$errors[$formId][$name] = __('mf_err_url');
                    continue;
                }
                if ($val !== '' && $type === 'number' && !is_numeric($val)) {
                    self::$errors[$formId][$name] = __('mf_err_number');
                    continue;
                }
                $data[$label] = $val;
            }

            return $data;
        }

        /** Zachowuje wartosci POST do ponownego wypelnienia po wczesniejszym bledzie. */
        private static function captureOld(int $formId, array $fields): void
        {
            $posted = $_POST['mf_f'] ?? [];
            if (!is_array($posted)) {
                return;
            }
            foreach ($fields as $field) {
                $name = (string)$field['name'];
                if (array_key_exists($name, $posted)) {
                    $v = $posted[$name];
                    self::$old[$formId][$name] = is_array($v)
                        ? array_map(static fn($x) => trim((string)$x), $v)
                        : trim((string)$v);
                }
            }
        }

        private static function storeSubmission(int $formId, array $data): void
        {
            $db = self::db();
            if (!$db) {
                return;
            }
            try {
                $ipHash = self::opt('store_ip', '1') === '1'
                    ? hash('sha256', (\Core\Security::clientIp() ?? '') . date('Y-m-d'))
                    : '';
                $stmt = $db->prepare("INSERT INTO modo_form_submissions (form_id, data, ip_hash, user_agent, page_url, referer, status)
                    VALUES (:f, :d, :ip, :ua, :pu, :rf, 'new')");
                $stmt->execute([
                    ':f'  => $formId,
                    ':d'  => json_encode($data, JSON_UNESCAPED_UNICODE),
                    ':ip' => $ipHash,
                    ':ua' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 190),
                    ':pu' => substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 255),
                    ':rf' => substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 255),
                ]);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        private static function notifyEmail(string $to, array $form, array $data): void
        {
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            $subject = sprintf(__('mf_email_subject'), (string)$form['name']);
            $lines = [];
            foreach ($data as $k => $v) {
                $lines[] = $k . ': ' . (is_array($v) ? implode(', ', $v) : (string)$v);
            }
            $body = implode("\n", $lines);

            // Per-form sender override; when empty the site-wide sender is used.
            $options = [];
            $from = trim(self::opt('from_email'));
            if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
                $options['from_email'] = $from;
            }

            // Delegate to the central mailer so the site-wide transport
            // (PHP mail() or SMTP via PHPMailer) and sender identity are respected.
            if (class_exists('\\Core\\Mailer')) {
                \Core\Mailer::send($to, $subject, $body, $options);
                return;
            }

            // Fallback for an environment without the Core\Mailer class.
            $headers = 'Content-Type: text/plain; charset=UTF-8';
            if (isset($options['from_email'])) {
                $headers .= "\r\nFrom: " . $options['from_email'];
            }
            @mail($to, $subject, $body, $headers);
        }

        private static function currentPath(): string
        {
            $uri  = (string)($_SERVER['REQUEST_URI'] ?? '/');
            $path = parse_url($uri, PHP_URL_PATH);
            return (is_string($path) && $path !== '') ? $path : '/';
        }

        private static function redirectSuccess(int $formId, array $settings): void
        {
            $url = trim((string)($settings['redirect'] ?? ''));
            if ($url === '') {
                $url = self::currentPath();
                $sep = str_contains($url, '?') ? '&' : '?';
                $url .= $sep . 'mf_sent=' . $formId . '#modo-form-' . $formId;
            }
            if (!headers_sent()) {
                header('Location: ' . $url);
            }
            exit;
        }

        private static function redirectBack(): void
        {
            if (!headers_sent()) {
                header('Location: ' . self::currentPath());
            }
            exit;
        }

        // ---------------------------------------------------------------------
        // Admin - akcje (zapis/usuwanie/status/eksport)
        // ---------------------------------------------------------------------

        private static function handleAdminPost(): void
        {
            if (!class_exists('\\Core\\Security')) {
                return;
            }
            $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

            // Zapis formularza.
            if ($isPost && isset($_POST['mf_save'])) {
                if (!\Core\Security::verifyCsrfToken($_POST['mf_csrf'] ?? '')) {
                    header('Location: plugins.php?id=' . self::PLUGIN_ID);
                    exit;
                }
                $id = self::saveForm();
                header('Location: plugins.php?id=' . self::PLUGIN_ID . '&mf_view=edit&fid=' . $id . '&mf_saved=1');
                exit;
            }

            // Zapis ustawien globalnych.
            if ($isPost && isset($_POST['mf_settings'])) {
                if (\Core\Security::verifyCsrfToken($_POST['mf_csrf'] ?? '')
                    && (!class_exists('\\Core\\Auth') || \Core\Auth::can('manage_settings'))) {
                    self::saveSettings();
                }
                header('Location: plugins.php?id=' . self::PLUGIN_ID . '&mf_view=settings&mf_saved=1');
                exit;
            }

            // Usuniecie formularza.
            if (isset($_GET['mf_delete'], $_GET['csrf'])) {
                $id = (int)$_GET['mf_delete'];
                if (\Core\Security::verifyCsrfToken((string)$_GET['csrf']) && $id > 0) {
                    self::deleteForm($id);
                }
                header('Location: plugins.php?id=' . self::PLUGIN_ID);
                exit;
            }

            // Zmiana statusu zgloszenia.
            if (isset($_GET['mf_status'], $_GET['sid'], $_GET['csrf'])) {
                $sid    = (int)$_GET['sid'];
                $status = in_array($_GET['mf_status'], ['new', 'read', 'spam'], true) ? (string)$_GET['mf_status'] : 'new';
                if (\Core\Security::verifyCsrfToken((string)$_GET['csrf']) && $sid > 0) {
                    self::setSubmissionStatus($sid, $status);
                }
                header('Location: plugins.php?id=' . self::PLUGIN_ID . '&mf_view=subs&fid=' . (int)($_GET['fid'] ?? 0));
                exit;
            }

            // Usuniecie zgloszenia.
            if (isset($_GET['mf_delsub'], $_GET['csrf'])) {
                $sid = (int)$_GET['mf_delsub'];
                if (\Core\Security::verifyCsrfToken((string)$_GET['csrf']) && $sid > 0) {
                    self::deleteSubmission($sid);
                }
                header('Location: plugins.php?id=' . self::PLUGIN_ID . '&mf_view=subs&fid=' . (int)($_GET['fid'] ?? 0));
                exit;
            }

            // Eksport CSV.
            if (isset($_GET['mf_export'], $_GET['csrf'])) {
                $fid = (int)$_GET['mf_export'];
                if (\Core\Security::verifyCsrfToken((string)$_GET['csrf']) && $fid > 0) {
                    self::exportCsv($fid);
                }
                header('Location: plugins.php?id=' . self::PLUGIN_ID);
                exit;
            }
        }

        private static function saveForm(): int
        {
            $db   = self::db();
            $id   = (int)($_POST['mf_id'] ?? 0);
            $name = trim((string)($_POST['mf_name'] ?? ''));
            if ($name === '') {
                $name = 'Form';
            }
            $slug   = self::uniqueSlug((string)($_POST['mf_slug'] ?? $name), $id);
            $status = (($_POST['mf_status'] ?? 'published') === 'draft') ? 'draft' : 'published';

            $types  = $_POST['mf_field_type'] ?? [];
            $labels = $_POST['mf_field_label'] ?? [];
            $names  = $_POST['mf_field_name'] ?? [];
            $phs    = $_POST['mf_field_placeholder'] ?? [];
            $reqs   = $_POST['mf_field_required'] ?? [];
            $widths = $_POST['mf_field_width'] ?? [];
            $opts   = $_POST['mf_field_options'] ?? [];

            $allowed = self::fieldTypes();
            $fields  = [];
            if (is_array($types)) {
                foreach ($types as $i => $rawType) {
                    $t = (string)$rawType;
                    if (!in_array($t, $allowed, true)) {
                        $t = 'text';
                    }
                    $label = trim((string)($labels[$i] ?? ''));
                    $fname = (string)preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($names[$i] ?? ''));
                    if ($fname === '') {
                        $fname = 'field' . ($i + 1);
                    }
                    if ($label === '' && $t !== 'heading') {
                        $label = $fname;
                    }
                    $fieldOpts = [];
                    if (in_array($t, ['select', 'radio', 'checkboxes'], true)) {
                        foreach (preg_split('/\r\n|\r|\n/', (string)($opts[$i] ?? '')) as $line) {
                            $line = trim($line);
                            if ($line !== '') {
                                $fieldOpts[] = $line;
                            }
                        }
                    }
                    $fields[] = [
                        'type'        => $t,
                        'name'        => $fname,
                        'label'       => $label,
                        'placeholder' => trim((string)($phs[$i] ?? '')),
                        'required'    => !empty($reqs[$i]),
                        'width'       => ((string)($widths[$i] ?? 'full') === 'half') ? 'half' : 'full',
                        'options'     => $fieldOpts,
                        'value'       => '',
                    ];
                }
            }

            $capMode = (string)($_POST['mf_set_captcha'] ?? 'inherit');
            $settings = [
                'submit_label' => trim((string)($_POST['mf_set_submit'] ?? '')),
                'success'      => trim((string)($_POST['mf_set_success'] ?? '')),
                'redirect'     => trim((string)($_POST['mf_set_redirect'] ?? '')),
                'store'        => isset($_POST['mf_set_store']) ? '1' : '0',
                'notify'       => isset($_POST['mf_set_notify']) ? '1' : '0',
                'notify_email' => trim((string)($_POST['mf_set_notify_email'] ?? '')),
                'captcha'      => in_array($capMode, ['inherit', 'none', 'own', 'hcaptcha', 'recaptcha'], true) ? $capMode : 'inherit',
                'honeypot'     => isset($_POST['mf_set_honeypot']) ? '1' : '0',
            ];

            $fieldsJson   = json_encode($fields, JSON_UNESCAPED_UNICODE);
            $settingsJson = json_encode($settings, JSON_UNESCAPED_UNICODE);

            if (!$db) {
                return $id;
            }
            try {
                if ($id > 0) {
                    $stmt = $db->prepare("UPDATE modo_forms SET name=:n, slug=:s, status=:st, fields=:f, settings=:se, updated_at=CURRENT_TIMESTAMP WHERE id=:id");
                    $stmt->execute([':n' => $name, ':s' => $slug, ':st' => $status, ':f' => $fieldsJson, ':se' => $settingsJson, ':id' => $id]);
                } else {
                    $stmt = $db->prepare("INSERT INTO modo_forms (name, slug, status, fields, settings) VALUES (:n, :s, :st, :f, :se)");
                    $stmt->execute([':n' => $name, ':s' => $slug, ':st' => $status, ':f' => $fieldsJson, ':se' => $settingsJson]);
                    $id = (int)$db->lastInsertId();
                }
            } catch (\Throwable $e) {
                // ignore
            }
            return $id;
        }

        private static function saveSettings(): void
        {
            foreach (['hcaptcha_site', 'hcaptcha_secret', 'recaptcha_site', 'recaptcha_secret', 'notify_email', 'from_email'] as $k) {
                if (array_key_exists('mf_' . $k, $_POST)) {
                    self::setOption($k, trim((string)$_POST['mf_' . $k]));
                }
            }
            if (isset($_POST['mf_captcha_mode']) && in_array($_POST['mf_captcha_mode'], ['none', 'own', 'hcaptcha', 'recaptcha'], true)) {
                self::setOption('captcha_mode', (string)$_POST['mf_captcha_mode']);
            }
            if (isset($_POST['mf_recaptcha_version']) && in_array($_POST['mf_recaptcha_version'], ['v2', 'v3'], true)) {
                self::setOption('recaptcha_version', (string)$_POST['mf_recaptcha_version']);
            }
            if (array_key_exists('mf_recaptcha_score', $_POST)) {
                self::setOption('recaptcha_score', (string)max(0.0, min(1.0, (float)$_POST['mf_recaptcha_score'])));
            }
            if (array_key_exists('mf_min_seconds', $_POST)) {
                self::setOption('min_seconds', (string)max(0, (int)$_POST['mf_min_seconds']));
            }
            foreach (['honeypot_enabled', 'store_ip', 'store', 'notify'] as $k) {
                self::setOption($k, isset($_POST['mf_' . $k]) ? '1' : '0');
            }
            if (class_exists('\\Core\\PageCache')) {
                \Core\PageCache::purge();
            }
        }

        private static function deleteForm(int $id): void
        {
            $db = self::db();
            if (!$db) {
                return;
            }
            try {
                $db->prepare("DELETE FROM modo_forms WHERE id=:id")->execute([':id' => $id]);
                $db->prepare("DELETE FROM modo_form_submissions WHERE form_id=:id")->execute([':id' => $id]);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        /** @return array<int,array<string,mixed>> */
        private static function submissions(int $formId, int $limit = 200, int $offset = 0): array
        {
            $db = self::db();
            if (!$db) {
                return [];
            }
            try {
                $stmt = $db->prepare("SELECT * FROM modo_form_submissions WHERE form_id=:f ORDER BY id DESC LIMIT :l OFFSET :o");
                $stmt->bindValue(':f', $formId, \PDO::PARAM_INT);
                $stmt->bindValue(':l', $limit, \PDO::PARAM_INT);
                $stmt->bindValue(':o', $offset, \PDO::PARAM_INT);
                $stmt->execute();
                return $stmt->fetchAll() ?: [];
            } catch (\Throwable $e) {
                return [];
            }
        }

        private static function setSubmissionStatus(int $sid, string $status): void
        {
            $db = self::db();
            if (!$db) {
                return;
            }
            try {
                $stmt = $db->prepare("UPDATE modo_form_submissions SET status=:s WHERE id=:id");
                $stmt->execute([':s' => $status, ':id' => $sid]);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        private static function deleteSubmission(int $sid): void
        {
            $db = self::db();
            if (!$db) {
                return;
            }
            try {
                $db->prepare("DELETE FROM modo_form_submissions WHERE id=:id")->execute([':id' => $sid]);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        private static function exportCsv(int $formId): void
        {
            $form = self::find($formId);
            $rows = self::submissions($formId, 10000, 0);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="submissions-' . $formId . '-' . date('Ymd-His') . '.csv"');

            $out = fopen('php://output', 'w');
            // Naglowek dynamiczny z etykiet pol.
            $labels = [];
            foreach (self::decodeFields($form['fields'] ?? '[]') as $field) {
                if ($field['type'] === 'heading') {
                    continue;
                }
                $labels[] = $field['label'] !== '' ? $field['label'] : $field['name'];
            }
            fputcsv($out, array_merge(['id', 'created_at', 'status'], $labels));
            foreach ($rows as $r) {
                $data = json_decode((string)($r['data'] ?? '{}'), true);
                if (!is_array($data)) {
                    $data = [];
                }
                $line = [(string)($r['id'] ?? ''), (string)($r['created_at'] ?? ''), (string)($r['status'] ?? '')];
                foreach ($labels as $lbl) {
                    $v = $data[$lbl] ?? '';
                    $line[] = is_array($v) ? implode(', ', $v) : $v;
                }
                fputcsv($out, $line);
            }
            fclose($out);
            exit;
        }

        // ---------------------------------------------------------------------
        // Admin - panel glowny (renderowany przez admin_plugin_view_*)
        // ---------------------------------------------------------------------

        public static function renderAdmin(): void
        {
            if (!class_exists('\\Core\\Security')) {
                return;
            }
            $view = (string)($_GET['mf_view'] ?? 'list');
            $csrf = \Core\Security::generateCsrfToken();
            $esc  = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

            echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap;">';
            echo '<div><h2 style="margin:0;font-size:18px;">' . $esc(__('mf_plugin_name')) . '</h2>';
            echo '<div style="font-size:12px;color:var(--text-muted);margin-top:4px;font-family:monospace;">' . $esc('[form id="1"]  |  &lt;?php the_modo_form(1); ?&gt;') . '</div></div>';
            echo '</div>';

            echo '<div class="mf-tabs">';
            echo '<a class="mf-tab' . ($view === 'list' ? ' active' : '') . '" href="plugins.php?id=' . self::PLUGIN_ID . '&mf_view=list">' . $esc(__('mf_tab_list')) . '</a>';
            echo '<a class="mf-tab' . ($view === 'settings' ? ' active' : '') . '" href="plugins.php?id=' . self::PLUGIN_ID . '&mf_view=settings">' . $esc(__('mf_tab_settings')) . '</a>';
            if ($view === 'edit') {
                echo '<a class="mf-tab active" href="#">' . $esc(__('mf_tab_edit')) . '</a>';
            } elseif ($view === 'subs') {
                echo '<a class="mf-tab active" href="#">' . $esc(__('mf_tab_subs')) . '</a>';
            }
            echo '</div>';

            echo '<style>' . self::adminStyles() . '</style>';

            switch ($view) {
                case 'edit':
                    self::renderEditor($csrf, $esc);
                    break;
                case 'subs':
                    self::renderSubs($csrf, $esc);
                    break;
                case 'settings':
                    self::renderSettings($csrf, $esc);
                    break;
                default:
                    self::renderList($csrf, $esc);
            }
        }

        private static function adminStyles(): string
        {
            return '
            .mf-tabs{display:flex;gap:4px;border-bottom:1px solid var(--border-subtle);margin-bottom:18px;flex-wrap:wrap;}
            .mf-tab{padding:9px 16px;font-size:13px;font-weight:600;color:var(--text-muted);text-decoration:none;border:1px solid transparent;border-bottom:none;border-radius:8px 8px 0 0;}
            .mf-tab.active{color:var(--primary,#3b82f6);background:var(--bg-surface,#fff);border-color:var(--border-subtle);}
            .mf-grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px;}
            .mf-field{display:grid;grid-template-columns:24px 150px minmax(0,1fr);gap:10px;align-items:start;padding:12px;border:1px solid var(--border-subtle);border-radius:10px;background:var(--bg-surface,#fff);margin-bottom:10px;}
            .mf-field .mf-drag{cursor:grab;color:var(--text-muted);text-align:center;font-size:18px;user-select:none;padding-top:4px;}
            .mf-field .mf-body{display:grid;grid-template-columns:1fr 1fr;gap:8px;}
            .mf-field .mf-body .mf-full{grid-column:1 / -1;}
            .mf-btn-x{border:none;background:transparent;color:#dc2626;font-size:20px;line-height:1;cursor:pointer;padding:0 4px;justify-self:end;}
            .mf-row-actions{display:flex;justify-content:flex-end;gap:8px;grid-column:1 / -1;}
            .mf-empty{padding:24px;color:var(--text-muted);text-align:center;}
            .mf-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;}
            .mf-badge--new{background:#dbeafe;color:#1d4ed8;}
            .mf-badge--read{background:#dcfce7;color:#166534;}
            .mf-badge--spam{background:#fee2e2;color:#991b1b;}
            .mf-badge--draft{background:#f1f5f9;color:#64748b;}
            .mf-badge--published{background:#dcfce7;color:#166534;}
            ';
        }

        private static function renderList(string $csrf, callable $esc): void
        {
            $forms = self::allForms();

            echo '<div style="margin-bottom:16px;">';
            echo '<a class="btn btn-primary" style="font-size:12px;" href="plugins.php?id=' . self::PLUGIN_ID . '&mf_view=edit">+ ' . $esc(__('mf_new_form')) . '</a>';
            echo '</div>';

            if (empty($forms)) {
                echo '<div class="card mf-empty">' . $esc(__('mf_no_forms')) . '</div>';
                return;
            }

            echo '<div class="table-container"><table class="pro-table"><thead><tr>';
            echo '<th>' . $esc(__('mf_name')) . '</th>';
            echo '<th style="width:120px;">' . $esc(__('mf_status')) . '</th>';
            echo '<th style="width:130px;">' . $esc(__('mf_submissions')) . '</th>';
            echo '<th>' . $esc(__('mf_shortcode')) . '</th>';
            echo '<th style="text-align:right;width:250px;">' . $esc(__('mf_actions')) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($forms as $f) {
                $id     = (int)$f['id'];
                $status = (string)($f['status'] ?? 'published');
                $sc     = '[form id="' . $id . '"]';
                $cnt    = self::countSubmissions($id);
                $newCnt = self::countSubmissions($id, 'new');

                echo '<tr>';
                echo '<td><strong>' . $esc((string)$f['name']) . '</strong><div style="font-size:11px;color:var(--text-muted);font-family:monospace;">' . $esc((string)$f['slug']) . '</div></td>';
                echo '<td><span class="mf-badge mf-badge--' . $esc($status) . '">' . $esc($status === 'draft' ? __('mf_draft') : __('mf_published')) . '</span></td>';
                echo '<td><a href="plugins.php?id=' . self::PLUGIN_ID . '&mf_view=subs&fid=' . $id . '">' . $cnt . '</a>' . ($newCnt > 0 ? ' <span class="mf-badge mf-badge--new">+' . $newCnt . '</span>' : '') . '</td>';
                echo '<td><div style="display:flex;align-items:center;gap:6px;">'
                    . '<code style="font-size:12px;">' . $esc($sc) . '</code>'
                    . '<button type="button" class="btn btn-secondary mf-copy" data-shortcode="' . $esc($sc) . '" style="padding:2px 8px;font-size:11px;">' . $esc(__('mf_copy')) . '</button>'
                    . '</div></td>';
                echo '<td style="text-align:right;white-space:nowrap;">'
                    . '<a class="btn btn-secondary" style="padding:4px 10px;font-size:12px;" href="plugins.php?id=' . self::PLUGIN_ID . '&mf_view=subs&fid=' . $id . '">' . $esc(__('mf_view_subs')) . '</a> '
                    . '<a class="btn btn-secondary" style="padding:4px 10px;font-size:12px;" href="plugins.php?id=' . self::PLUGIN_ID . '&mf_view=edit&fid=' . $id . '">' . $esc(__('mf_edit')) . '</a> '
                    . '<a class="btn btn-danger-ghost mf-confirm" href="plugins.php?id=' . self::PLUGIN_ID . '&mf_delete=' . $id . '&csrf=' . $esc($csrf) . '" data-confirm="' . $esc(__('mf_confirm_delete')) . '" style="padding:4px 10px;font-size:12px;">' . $esc(__('mf_delete')) . '</a>'
                    . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';

            echo self::listScript($esc(__('mf_copied')));
        }

        private static function listScript(string $copied): string
        {
            return '<script>
            (function(){
                document.querySelectorAll(".mf-copy").forEach(function(b){
                    b.addEventListener("click", function(){
                        var t = b.getAttribute("data-shortcode") || "";
                        if(navigator.clipboard){ navigator.clipboard.writeText(t); }
                        var o = b.textContent; b.textContent = ' . json_encode($copied) . ';
                        setTimeout(function(){ b.textContent = o; }, 1500);
                    });
                });
                document.querySelectorAll(".mf-confirm").forEach(function(a){
                    a.addEventListener("click", function(e){
                        if(!window.confirm(a.getAttribute("data-confirm") || "Are you sure?")){ e.preventDefault(); }
                    });
                });
            })();
            </script>';
        }

        private static function renderEditor(string $csrf, callable $esc): void
        {
            $id       = (int)($_GET['fid'] ?? 0);
            $form     = $id > 0 ? self::find($id) : null;
            $fields   = $form ? self::decodeFields($form['fields'] ?? '[]') : [];
            $settings = $form ? self::decodeSettings($form['settings'] ?? '{}') : self::formDefaults();

            echo '<div style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">';
            echo '<a class="btn btn-secondary" style="font-size:12px;" href="plugins.php?id=' . self::PLUGIN_ID . '">&larr; ' . $esc(__('mf_back')) . '</a>';
            if ($form) {
                $sc = '[form id="' . $id . '"]';
                echo '<div style="display:flex;align-items:center;gap:6px;"><code style="font-size:12px;">' . $esc($sc) . '</code>'
                    . '<button type="button" class="btn btn-secondary mf-copy" data-shortcode="' . $esc($sc) . '" style="padding:2px 8px;font-size:11px;">' . $esc(__('mf_copy')) . '</button></div>';
            }
            echo '</div>';

            if (isset($_GET['mf_saved'])) {
                echo '<div class="card" style="padding:12px 16px;margin-bottom:14px;border-left:4px solid #16a34a;background:#f0fdf4;color:#166534;">' . $esc(__('mf_saved')) . '</div>';
            }

            echo '<form method="post" action="plugins.php?id=' . self::PLUGIN_ID . '&mf_view=edit' . ($id > 0 ? '&fid=' . $id : '') . '">';
            echo '<input type="hidden" name="mf_csrf" value="' . $esc($csrf) . '">';
            echo '<input type="hidden" name="mf_id" value="' . $id . '">';

            echo '<div class="card" style="margin-bottom:16px;"><div class="mf-grid2">';
            echo '<div class="form-group"><label class="form-label">' . $esc(__('mf_name')) . '</label><input class="form-control" type="text" name="mf_name" value="' . $esc((string)($form['name'] ?? '')) . '" required></div>';
            echo '<div class="form-group"><label class="form-label">' . $esc(__('mf_slug')) . '</label><input class="form-control" type="text" name="mf_slug" value="' . $esc((string)($form['slug'] ?? '')) . '"></div>';
            echo '<div class="form-group"><label class="form-label">' . $esc(__('mf_status')) . '</label><select class="form-control" name="mf_status">';
            echo '<option value="published"' . ((($form['status'] ?? 'published') === 'published') ? ' selected' : '') . '>' . $esc(__('mf_published')) . '</option>';
            echo '<option value="draft"' . ((($form['status'] ?? '') === 'draft') ? ' selected' : '') . '>' . $esc(__('mf_draft')) . '</option>';
            echo '</select></div>';
            echo '</div></div>';

            echo '<div class="card" style="margin-bottom:16px;">';
            echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:10px;flex-wrap:wrap;"><h3 style="margin:0;font-size:15px;">' . $esc(__('mf_fields')) . '</h3>';
            echo '<button type="button" class="btn btn-secondary" id="mf-add" style="font-size:12px;">+ ' . $esc(__('mf_add_field')) . '</button></div>';
            echo '<div id="mf-fields">';
            if (empty($fields)) {
                echo '<div class="mf-empty" id="mf-empty">' . $esc(__('mf_no_fields')) . '</div>';
            } else {
                foreach ($fields as $i => $field) {
                    echo self::fieldRow($field, (string)$i, $esc);
                }
            }
            echo '</div></div>';

            echo self::editorSettings($settings, $esc);

            echo '<div style="display:flex;gap:10px;margin-bottom:20px;">';
            echo '<button type="submit" name="mf_save" value="1" class="btn btn-primary">' . $esc(__('mf_save')) . '</button>';
            echo '<a class="btn btn-secondary" href="plugins.php?id=' . self::PLUGIN_ID . '">' . $esc(__('mf_cancel')) . '</a>';
            echo '</div>';
            echo '</form>';

            echo self::editorScript($esc);
            echo self::listScript($esc(__('mf_copied')));
        }

        private static function fieldRow(array $field, string $i, callable $esc): string
        {
            $type  = (string)($field['type'] ?? 'text');
            $name  = (string)($field['name'] ?? '');
            $label = (string)($field['label'] ?? '');
            $ph    = (string)($field['placeholder'] ?? '');
            $req   = !empty($field['required']);
            $width = (string)($field['width'] ?? 'full');
            $opts  = implode("\n", $field['options'] ?? []);
            $ix    = '[' . $i . ']';

            $h  = '<div class="mf-field" data-mf-field>';
            $h .= '<div class="mf-drag" draggable="true" title="' . $esc(__('mf_drag')) . '">&#8942;&#8942;</div>';
            $h .= '<div>';
            $h .= '<select class="form-control mf-type" name="mf_field_type' . $ix . '">';
            foreach (self::fieldTypes() as $t) {
                $h .= '<option value="' . $t . '"' . ($t === $type ? ' selected' : '') . '>' . $esc(__('mf_ftype_' . $t)) . '</option>';
            }
            $h .= '</select>';
            $h .= '<label style="display:flex;align-items:center;gap:6px;margin-top:8px;font-size:12px;"><input type="checkbox" name="mf_field_required' . $ix . '" value="1"' . ($req ? ' checked' : '') . '> ' . $esc(__('mf_required')) . '</label>';
            $h .= '</div>';
            $h .= '<div class="mf-body">';
            $h .= '<input class="form-control mf-full" type="text" name="mf_field_label' . $ix . '" value="' . $esc($label) . '" placeholder="' . $esc(__('mf_field_label')) . '">';
            $h .= '<input class="form-control" type="text" name="mf_field_name' . $ix . '" value="' . $esc($name) . '" placeholder="' . $esc(__('mf_field_name')) . '">';
            $h .= '<input class="form-control" type="text" name="mf_field_placeholder' . $ix . '" value="' . $esc($ph) . '" placeholder="' . $esc(__('mf_field_placeholder')) . '">';
            $h .= '<div class="mf-full"><textarea class="form-control mf-options" name="mf_field_options' . $ix . '" rows="2" placeholder="' . $esc(__('mf_field_options')) . '">' . $esc($opts) . '</textarea>';
            $h .= '<select class="form-control" name="mf_field_width' . $ix . '" style="margin-top:6px;">';
            $h .= '<option value="full"' . ($width === 'full' ? ' selected' : '') . '>' . $esc(__('mf_width_full')) . '</option>';
            $h .= '<option value="half"' . ($width === 'half' ? ' selected' : '') . '>' . $esc(__('mf_width_half')) . '</option>';
            $h .= '</select></div>';
            $h .= '<div class="mf-row-actions"><button type="button" class="btn btn-danger-ghost mf-remove" style="padding:4px 10px;font-size:12px;">' . $esc(__('mf_remove')) . '</button></div>';
            $h .= '</div>';
            $h .= '</div>';
            return $h;
        }

        private static function editorSettings(array $settings, callable $esc): string
        {
            $h  = '<div class="card" style="margin-bottom:16px;">';
            $h .= '<h3 style="margin:0 0 12px;font-size:15px;">' . $esc(__('mf_form_settings')) . '</h3>';
            $h .= '<div class="mf-grid2">';

            $h .= '<div class="form-group"><label class="form-label">' . $esc(__('mf_submit_label')) . '</label>'
                . '<input class="form-control" type="text" name="mf_set_submit" value="' . $esc((string)($settings['submit_label'] ?? '')) . '" placeholder="' . $esc(__('mf_submit')) . '"></div>';

            $h .= '<div class="form-group"><label class="form-label">' . $esc(__('mf_notify_email')) . '</label>'
                . '<input class="form-control" type="text" name="mf_set_notify_email" value="' . $esc((string)($settings['notify_email'] ?? '')) . '"></div>';

            $h .= '<div class="form-group" style="grid-column:1/-1;"><label class="form-label">' . $esc(__('mf_success_message')) . '</label>'
                . '<textarea class="form-control" name="mf_set_success" rows="2">' . $esc((string)($settings['success'] ?? '')) . '</textarea></div>';

            $h .= '<div class="form-group" style="grid-column:1/-1;"><label class="form-label">' . $esc(__('mf_redirect')) . '</label>'
                . '<input class="form-control" type="text" name="mf_set_redirect" value="' . $esc((string)($settings['redirect'] ?? '')) . '"></div>';

            $h .= '<div class="form-group"><label class="form-label">' . $esc(__('mf_captcha')) . '</label><select class="form-control" name="mf_set_captcha">';
            foreach (['inherit' => __('mf_captcha_inherit'), 'none' => __('mf_captcha_none'), 'own' => __('mf_captcha_own'), 'hcaptcha' => 'hCaptcha', 'recaptcha' => 'Google reCAPTCHA'] as $val => $lbl) {
                $h .= '<option value="' . $val . '"' . (((string)($settings['captcha'] ?? 'inherit') === $val) ? ' selected' : '') . '>' . $esc($lbl) . '</option>';
            }
            $h .= '</select></div>';
            $h .= '</div>';

            $h .= '<div style="display:grid;gap:8px;margin-top:10px;">';
            $h .= '<label style="display:flex;align-items:center;gap:10px;"><input type="checkbox" name="mf_set_store" value="1"' . (!empty($settings['store']) ? ' checked' : '') . '> ' . $esc(__('mf_store')) . '</label>';
            $h .= '<label style="display:flex;align-items:center;gap:10px;"><input type="checkbox" name="mf_set_notify" value="1"' . (!empty($settings['notify']) ? ' checked' : '') . '> ' . $esc(__('mf_notify')) . '</label>';
            $h .= '<label style="display:flex;align-items:center;gap:10px;"><input type="checkbox" name="mf_set_honeypot" value="1"' . (!empty($settings['honeypot']) ? ' checked' : '') . '> ' . $esc(__('mf_honeypot')) . '</label>';
            $h .= '</div></div>';

            return $h;
        }

        private static function renderSubs(string $csrf, callable $esc): void
        {
            $forms = self::allForms();
            $fid   = (int)($_GET['fid'] ?? 0);
            if ($fid <= 0 && !empty($forms)) {
                $fid = (int)$forms[0]['id'];
            }
            $form = $fid > 0 ? self::find($fid) : null;

            echo '<div style="margin-bottom:14px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">';
            echo '<a class="btn btn-secondary" style="font-size:12px;" href="plugins.php?id=' . self::PLUGIN_ID . '">&larr; ' . $esc(__('mf_back')) . '</a>';
            if ($form) {
                echo '<a class="btn btn-primary" style="font-size:12px;" href="plugins.php?id=' . self::PLUGIN_ID . '&mf_export=' . $fid . '&csrf=' . $esc($csrf) . '">' . $esc(__('mf_export_csv')) . '</a>';
            }
            echo '</div>';

            if (empty($forms)) {
                echo '<div class="card mf-empty">' . $esc(__('mf_no_forms')) . '</div>';
                return;
            }

            echo '<div class="card" style="margin-bottom:14px;"><form method="get" action="plugins.php">';
            echo '<input type="hidden" name="id" value="' . self::PLUGIN_ID . '"><input type="hidden" name="mf_view" value="subs">';
            echo '<label class="form-label">' . $esc(__('mf_form')) . '</label>';
            echo '<select class="form-control" name="fid" onchange="this.form.submit()" style="max-width:360px;">';
            foreach ($forms as $f) {
                $sel = ((int)$f['id'] === $fid) ? ' selected' : '';
                echo '<option value="' . (int)$f['id'] . '"' . $sel . '>' . $esc((string)$f['name']) . ' (' . self::countSubmissions((int)$f['id']) . ')</option>';
            }
            echo '</select></form></div>';

            if (!$form) {
                return;
            }

            $rows = self::submissions($fid);
            if (empty($rows)) {
                echo '<div class="card mf-empty">' . $esc(__('mf_no_submissions')) . '</div>';
                return;
            }

            echo '<div class="table-container"><table class="pro-table"><thead><tr>';
            echo '<th style="width:150px;">' . $esc(__('mf_date')) . '</th>';
            echo '<th style="width:90px;">' . $esc(__('mf_status')) . '</th>';
            echo '<th>' . $esc(__('mf_content')) . '</th>';
            echo '<th style="text-align:right;width:240px;">' . $esc(__('mf_actions')) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($rows as $r) {
                $sid    = (int)$r['id'];
                $status = (string)($r['status'] ?? 'new');
                $data   = json_decode((string)($r['data'] ?? '{}'), true);
                if (!is_array($data)) {
                    $data = [];
                }
                $summary = [];
                foreach ($data as $k => $v) {
                    $summary[] = $k . ': ' . (is_array($v) ? implode(', ', $v) : (string)$v);
                }
                $text = implode(' · ', $summary);
                $text = function_exists('mb_substr') ? mb_substr($text, 0, 240) : substr($text, 0, 240);
                $base = 'plugins.php?id=' . self::PLUGIN_ID . '&mf_view=subs&fid=' . $fid;

                echo '<tr>';
                echo '<td>' . $esc((string)$r['created_at']) . '</td>';
                echo '<td><span class="mf-badge mf-badge--' . $esc($status) . '">' . $esc(__('mf_st_' . $status)) . '</span></td>';
                echo '<td style="font-size:13px;color:var(--text-muted);max-width:520px;">' . $esc($text) . '</td>';
                echo '<td style="text-align:right;white-space:nowrap;">';
                if ($status !== 'read') {
                    echo '<a class="btn btn-secondary" style="padding:4px 8px;font-size:11px;" href="' . $base . '&sid=' . $sid . '&mf_status=read&csrf=' . $esc($csrf) . '">' . $esc(__('mf_mark_read')) . '</a> ';
                }
                if ($status !== 'spam') {
                    echo '<a class="btn btn-secondary" style="padding:4px 8px;font-size:11px;" href="' . $base . '&sid=' . $sid . '&mf_status=spam&csrf=' . $esc($csrf) . '">' . $esc(__('mf_mark_spam')) . '</a> ';
                }
                echo '<a class="btn btn-danger-ghost mf-confirm" style="padding:4px 8px;font-size:11px;" data-confirm="' . $esc(__('mf_confirm_delete')) . '" href="' . $base . '&mf_delsub=' . $sid . '&csrf=' . $esc($csrf) . '">' . $esc(__('mf_delete')) . '</a>';
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';

            echo self::listScript($esc(__('mf_copied')));
        }

        private static function renderSettings(string $csrf, callable $esc): void
        {
            echo '<form method="post" action="plugins.php?id=' . self::PLUGIN_ID . '&mf_view=settings">';
            echo '<input type="hidden" name="mf_csrf" value="' . $esc($csrf) . '">';
            echo '<input type="hidden" name="mf_settings" value="1">';

            if (isset($_GET['mf_saved'])) {
                echo '<div class="card" style="padding:12px 16px;margin-bottom:14px;border-left:4px solid #16a34a;background:#f0fdf4;color:#166534;">' . $esc(__('mf_saved')) . '</div>';
            }

            echo '<div class="card" style="margin-bottom:16px;"><h3 style="margin:0 0 12px;font-size:15px;">' . $esc(__('mf_captcha')) . '</h3>';
            $mode = self::opt('captcha_mode', 'own');
            echo '<div class="form-group"><label class="form-label">' . $esc(__('mf_captcha_mode')) . '</label><select class="form-control" name="mf_captcha_mode" style="max-width:320px;">';
            foreach (['none' => __('mf_captcha_none'), 'own' => __('mf_captcha_own'), 'hcaptcha' => 'hCaptcha', 'recaptcha' => 'Google reCAPTCHA'] as $val => $lbl) {
                echo '<option value="' . $val . '"' . ($mode === $val ? ' selected' : '') . '>' . $esc($lbl) . '</option>';
            }
            echo '</select></div>';

            echo '<div class="mf-grid2">';
            echo '<div class="form-group"><label class="form-label">hCaptcha Site Key</label><input class="form-control" type="text" name="mf_hcaptcha_site" value="' . $esc(self::opt('hcaptcha_site')) . '"></div>';
            echo '<div class="form-group"><label class="form-label">hCaptcha Secret</label><input class="form-control" type="text" name="mf_hcaptcha_secret" value="' . $esc(self::opt('hcaptcha_secret')) . '"></div>';
            echo '<div class="form-group"><label class="form-label">reCAPTCHA Site Key</label><input class="form-control" type="text" name="mf_recaptcha_site" value="' . $esc(self::opt('recaptcha_site')) . '"></div>';
            echo '<div class="form-group"><label class="form-label">reCAPTCHA Secret</label><input class="form-control" type="text" name="mf_recaptcha_secret" value="' . $esc(self::opt('recaptcha_secret')) . '"></div>';
            echo '<div class="form-group"><label class="form-label">reCAPTCHA ' . $esc(__('mf_version')) . '</label><select class="form-control" name="mf_recaptcha_version">';
            foreach (['v2', 'v3'] as $v) {
                echo '<option value="' . $v . '"' . (self::opt('recaptcha_version', 'v2') === $v ? ' selected' : '') . '>' . $v . '</option>';
            }
            echo '</select></div>';
            echo '<div class="form-group"><label class="form-label">reCAPTCHA ' . $esc(__('mf_score')) . '</label><input class="form-control" type="number" step="0.1" min="0" max="1" name="mf_recaptcha_score" value="' . $esc(self::opt('recaptcha_score')) . '"></div>';
            echo '</div></div>';

            echo '<div class="card" style="margin-bottom:16px;"><h3 style="margin:0 0 12px;font-size:15px;">' . $esc(__('mf_own_protection')) . '</h3>';
            echo '<div class="form-group" style="max-width:320px;"><label class="form-label">' . $esc(__('mf_min_seconds')) . '</label><input class="form-control" type="number" min="0" name="mf_min_seconds" value="' . $esc(self::opt('min_seconds')) . '"></div>';
            echo '<div style="display:grid;gap:8px;margin-top:10px;">';
            echo '<label style="display:flex;align-items:center;gap:10px;"><input type="checkbox" name="mf_honeypot_enabled" value="1"' . (self::opt('honeypot_enabled') === '1' ? ' checked' : '') . '> ' . $esc(__('mf_honeypot')) . '</label>';
            echo '<label style="display:flex;align-items:center;gap:10px;"><input type="checkbox" name="mf_store_ip" value="1"' . (self::opt('store_ip') === '1' ? ' checked' : '') . '> ' . $esc(__('mf_store_ip')) . '</label>';
            echo '</div></div>';

            echo '<div class="card" style="margin-bottom:16px;"><h3 style="margin:0 0 12px;font-size:15px;">' . $esc(__('mf_notifications')) . '</h3><div class="mf-grid2">';
            echo '<div class="form-group"><label class="form-label">' . $esc(__('mf_notify_email')) . '</label><input class="form-control" type="text" name="mf_notify_email" value="' . $esc(self::opt('notify_email')) . '"></div>';
            echo '<div class="form-group"><label class="form-label">' . $esc(__('mf_from_email')) . '</label><input class="form-control" type="text" name="mf_from_email" value="' . $esc(self::opt('from_email')) . '"></div>';
            echo '</div>';
            echo '<small style="font-size:11px;color:var(--text-muted);display:block;line-height:1.4;">' . $esc(__('mf_mail_hint')) . '</small>';
            echo '</div>';

            echo '<button type="submit" class="btn btn-primary" style="margin-bottom:20px;">' . $esc(__('mf_save')) . '</button>';
            echo '</form>';
        }

        private static function editorScript(callable $esc): string
        {
            $tpl = self::fieldRow(['type' => 'text', 'name' => '', 'label' => '', 'placeholder' => '', 'required' => false, 'width' => 'full', 'options' => []], '__IDX__', $esc);

            return '<template id="mf-tpl">' . $tpl . '</template>
            <script>
            (function(){
                var box=document.getElementById("mf-fields");
                var tplEl=document.getElementById("mf-tpl");
                if(!box||!tplEl) return;
                var dragEl=null;

                function nextIdx(){ return Date.now() + Math.floor(Math.random()*1000); }

                function addField(){
                    var empty=document.getElementById("mf-empty"); if(empty) empty.remove();
                    var html=tplEl.innerHTML.split("__IDX__").join(String(nextIdx()));
                    var wrap=document.createElement("div");
                    wrap.innerHTML=html.trim();
                    if(wrap.firstElementChild){ box.appendChild(wrap.firstElementChild); }
                }

                var addBtn=document.getElementById("mf-add");
                if(addBtn){ addBtn.addEventListener("click", addField); }

                box.addEventListener("click", function(e){
                    var x=e.target.closest(".mf-remove");
                    if(x){ var it=x.closest(".mf-field"); if(it) it.remove(); }
                });

                box.addEventListener("dragstart", function(e){
                    var h=e.target.closest(".mf-drag");
                    if(!h) return;
                    dragEl=h.closest(".mf-field");
                    e.dataTransfer.effectAllowed="move";
                    setTimeout(function(){ if(dragEl) dragEl.classList.add("mf-ghost"); },0);
                });
                box.addEventListener("dragend", function(){ if(dragEl){ dragEl.classList.remove("mf-ghost"); dragEl=null; } });
                box.addEventListener("dragover", function(e){
                    e.preventDefault();
                    if(!dragEl) return;
                    var it=e.target.closest(".mf-field");
                    if(!it||it===dragEl) return;
                    var rect=it.getBoundingClientRect();
                    var after=(e.clientY-rect.top)/rect.height>0.5;
                    box.insertBefore(dragEl, after? it.nextSibling : it);
                });
            })();
            </script>';
        }

        // ---------------------------------------------------------------------
        // Admin - przycisk wstawiania w edytorze TinyMCE
        // ---------------------------------------------------------------------

        public static function renderEditorInsertScript(): void
        {
            $items = [];
            foreach (self::allForms() as $f) {
                $items[] = ['id' => (int)$f['id'], 'name' => (string)$f['name']];
            }
            $payload = [
                'items'  => $items,
                'labels' => [
                    'title' => __('mf_insert_form'),
                    'none'  => __('mf_no_forms'),
                    'label' => __('mf_form'),
                ],
            ];
            ?>
            <script type="application/json" id="mf-editor-data"><?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
            <script>
            (function(){
                var data=document.getElementById("mf-editor-data");
                if(!data) return;
                var cfg=JSON.parse(data.textContent||"{}");
                function patch(){
                    if(!window.tinymce||typeof window.tinymce.init!=="function"||window.tinymce.__modoFormPatched) return !!window.tinymce;
                    window.tinymce.__modoFormPatched=true;
                    var orig=window.tinymce.init;
                    window.tinymce.init=function(c){
                        var conf=c||{};
                        if(typeof conf.toolbar==="string" && conf.toolbar.indexOf("modo_form")===-1){
                            conf.toolbar=conf.toolbar+" | modo_form";
                        }
                        var os=conf.setup;
                        conf.setup=function(editor){
                            if(typeof os==="function"){ os(editor); }
                            editor.ui.registry.addButton("modo_form",{
                                icon:"browse",
                                tooltip:cfg.labels.title,
                                onAction:function(){
                                    var list=cfg.items||[];
                                    if(!list.length){ editor.notificationManager.open({text:cfg.labels.none,type:"info"}); return; }
                                    editor.windowManager.open({
                                        title:cfg.labels.title,
                                        body:{type:"panel",items:[{type:"select",name:"fid",label:cfg.labels.label,
                                            items:list.map(function(g){return {text:g.name+" (#"+g.id+")",value:String(g.id)};})}]},
                                        onSubmit:function(api){
                                            var d=api.getData();
                                            editor.insertContent('[form id="'+d.fid+'"]');
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

    }
}

// Uruchom plugin zaraz po zaladowaniu przez plugin loader.
ModoForm::init();

/*
 * Template tags (do uzycia w plikach motywu PHP):
 *   the_modo_form(1);                 // wypisuje formularz o id = 1
 *   the_modo_form('kontakt');          // wypisuje formularz po slug
 *   echo modo_form(1);                // zwraca HTML (string)
 *   echo modo_form('kontakt', ['submit_label' => 'Wyslij']);
 *   if (has_modo_form(1)) { ... }
 */
if (!function_exists('modo_form')) {
    function modo_form(string|int $idOrSlug = 0, array $args = []): string
    {
        $f = ModoForm::resolve($idOrSlug);
        return $f ? ModoForm::renderOne($f, $args) : '';
    }
}
if (!function_exists('get_modo_form')) {
    function get_modo_form(string|int $idOrSlug = 0, array $args = []): string
    {
        $f = ModoForm::resolve($idOrSlug);
        return $f ? ModoForm::renderOne($f, $args) : '';
    }
}
if (!function_exists('the_modo_form')) {
    function the_modo_form(string|int $idOrSlug = 0, array $args = []): void
    {
        $f = ModoForm::resolve($idOrSlug);
        if ($f) {
            ModoForm::enqueueAssets();
            echo ModoForm::renderOne($f, $args);
        }
    }
}
if (!function_exists('has_modo_form')) {
    function has_modo_form(string|int $idOrSlug = 0): bool
    {
        return ModoForm::exists($idOrSlug);
    }
}

