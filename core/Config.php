<?php
declare(strict_types=1);

namespace Core;

/**
 * Class Config
 *
 * File-based advanced configuration - the ModoCMS equivalent of GetSimple's
 * gsconfig.php.
 *
 * Values stored in modoconfig.php (project root) WIN over the matching database
 * setting and over the built-in default, because this file is loaded before the
 * database connection is opened. Deleting a key from the file makes the value
 * coming from the Settings screen (or the built-in default) apply again.
 *
 * The file is blocked from HTTP access by the shipped .htaccess rules and must
 * stay blocked: it can contain environment-specific configuration.
 */
final class Config {
    /** Advanced configuration file (project root). */
    public const FILENAME = 'modoconfig.php';

    /** Documented template that is shipped with every installation. */
    public const SAMPLE_FILENAME = 'modoconfig.sample.php';

    /** @var array<string,mixed>|null Loaded overrides. */
    private static ?array $values = null;

    private static bool $loaded = false;

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $definitionCache = null;

    public static function rootPath(): string {
        return dirname(__DIR__);
    }

    public static function path(): string {
        return self::rootPath() . '/' . self::FILENAME;
    }

    public static function samplePath(): string {
        return self::rootPath() . '/' . self::SAMPLE_FILENAME;
    }

    public static function exists(): bool {
        return is_file(self::path());
    }

    public static function isWritable(): bool {
        $path = self::path();
        if (is_file($path)) {
            return is_writable($path);
        }

        return is_writable(self::rootPath());
    }

    /** Whether the current request already arrived over HTTPS (proxy aware). */
    public static function requestIsHttps(): bool {
        $https = $_SERVER['HTTPS'] ?? '';
        if ($https !== '' && strtolower((string)$https) !== 'off') {
            return true;
        }

        if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        $proto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($proto !== '') {
            // Several proxies may be chained: "https, http" - the first entry wins.
            return in_array('https', array_map('trim', explode(',', $proto)), true);
        }

        return strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? ''))) === 'on';
    }

    /**
     * Host of the current request, or an empty string when it cannot be trusted.
     * Used to build redirects without ever honouring a spoofed Host header.
     */
    public static function requestHost(): string {
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '') {
            $host = trim((string)($_SERVER['SERVER_NAME'] ?? ''));
        }

        if (preg_match('/^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*)(:(\d{1,5}))?$/', $host) !== 1) {
            return '';
        }

        return $host;
    }

    /**
     * Loads the overrides. A missing, unreadable or syntactically broken file is
     * silently ignored: a bad config file must never take the site down.
     */
    public static function load(bool $reload = false): void {
        if (self::$loaded && !$reload) {
            return;
        }

        self::$loaded = true;
        self::$values = [];

        $path = self::path();
        if (!is_readable($path)) {
            return;
        }

        try {
            $data = require $path;
        } catch (\Throwable $e) {
            return;
        }

        if (!is_array($data)) {
            return;
        }

        foreach ($data as $key => $value) {
            self::$values[(string)$key] = $value;
        }
    }

    /** Whether the key is explicitly present in modoconfig.php. */
    public static function has(string $key): bool {
        self::load();
        return self::$values !== null && array_key_exists($key, self::$values);
    }

    /** Raw file value, or null when the key is absent. */
    public static function raw(string $key): mixed {
        self::load();
        return self::$values[$key] ?? null;
    }

    /**
     * Returns the file override, or the database setting it overrides, or the
     * given default - in that order.
     */
    public static function get(string $key, mixed $default = null): mixed {
        $raw = self::raw($key);
        if ($raw !== null) {
            return $raw;
        }

        $definition = self::definitions()[$key] ?? null;
        $overrides = $definition['overrides'] ?? null;
        if (is_string($overrides) && $overrides !== '' && class_exists(Router::class)) {
            $setting = Router::getOption($overrides, "\0");
            if ($setting !== "\0" && $setting !== '') {
                return $setting;
            }
        }

        if ($default !== null) {
            return $default;
        }

        return $definition['default'] ?? null;
    }

    public static function getBool(string $key, bool $default = false): bool {
        $value = self::get($key, null);
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }

    public static function getInt(string $key, int $default = 0): int {
        $value = self::get($key, null);
        if ($value === null || $value === '') {
            return $default;
        }

        return (int)$value;
    }

    public static function getString(string $key, string $default = ''): string {
        $value = self::get($key, null);
        if ($value === null) {
            return $default;
        }
        if (is_array($value)) {
            return implode(',', array_map('strval', $value));
        }

        return trim((string)$value);
    }

    /** Returns a comma / newline separated value as a list of trimmed tokens. */
    public static function getList(string $key, array $default = []): array {
        $value = self::get($key, null);
        if ($value === null || $value === '') {
            return $default;
        }

        $tokens = is_array($value) ? $value : preg_split('/[\s,;]+/', (string)$value);

        return array_values(array_filter(array_map('trim', array_map('strval', (array)$tokens)), static fn($v) => $v !== ''));
    }

    /** Every override currently stored in the file. */
    public static function all(): array {
        self::load();
        return self::$values ?? [];
    }

    /**
     * Persists the given overrides. A null value removes the key (the database
     * setting / built-in default applies again). Keys that are not part of the
     * definitions - e.g. manually added or plugin-provided ones - are preserved.
     *
     * @return array{ok:bool,message:string}
     */
    public static function save(array $values): array {
        $path = self::path();

        if (!self::isWritable()) {
            return [
                'ok'      => false,
                'message' => 'The configuration file cannot be written. Make the project root writable, e.g.: chmod 755 . && chmod 640 ' . self::FILENAME,
            ];
        }

        $merged = self::all();
        foreach ($values as $key => $value) {
            $key = (string)$key;
            if ($value === null) {
                unset($merged[$key]);
            } else {
                $merged[$key] = $value;
            }
        }
        ksort($merged);

        $temporaryPath = $path . '.tmp';
        if (@file_put_contents($temporaryPath, self::exportSource($merged), LOCK_EX) === false) {
            return ['ok' => false, 'message' => 'Could not write the temporary configuration file.'];
        }

        @chmod($temporaryPath, 0640);

        if (!@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            return ['ok' => false, 'message' => 'Could not replace the configuration file (check directory permissions).'];
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        self::load(true);

        return ['ok' => true, 'message' => 'Advanced configuration saved to ' . self::FILENAME . '.'];
    }

    /**
     * Removes the configuration file so every value falls back to the database.
     *
     * @return array{ok:bool,message:string}
     */
    public static function delete(): array {
        $path = self::path();
        if (!is_file($path)) {
            return ['ok' => true, 'message' => 'No advanced configuration file present.'];
        }
        if (!is_writable($path)) {
            return ['ok' => false, 'message' => 'The configuration file is not writable, delete ' . self::FILENAME . ' manually.'];
        }
        if (!@unlink($path)) {
            return ['ok' => false, 'message' => 'Could not delete ' . self::FILENAME . '.'];
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }

        self::load(true);

        return ['ok' => true, 'message' => 'Advanced configuration removed - every option now follows the database settings.'];
    }

    /** Renders the PHP source of the configuration file. */
    public static function exportSource(array $values): string {
        $source  = "<?php\n";
        $source .= "declare(strict_types=1);\n\n";
        $source .= "/**\n";
        $source .= " * ModoCMS advanced configuration - the equivalent of GetSimple's gsconfig.php.\n";
        $source .= " *\n";
        $source .= " * Values listed here OVERRIDE the Settings screen and the built-in defaults,\n";
        $source .= " * because this file is read before the database connection is opened.\n";
        $source .= " * Delete a line to fall back to the value stored in the database.\n";
        $source .= " *\n";
        $source .= " * Generated by Settings -> System on " . date('Y-m-d H:i:s') . ".\n";
        $source .= " * Blocked from HTTP access by .htaccess - keep it blocked.\n";
        $source .= " */\n\n";
        $source .= "return [\n";

        foreach ($values as $key => $value) {
            $source .= '    ' . var_export((string)$key, true) . ' => ' . var_export($value, true) . ",\n";
        }

        if ($values === []) {
            $source .= "    // No overrides - every option follows the Settings screen.\n";
        }

        $source .= "];\n";

        return $source;
    }

    /** Renders the documented sample file (every supported option, commented out). */
    public static function sampleSource(): string {
        $source  = "<?php\n";
        $source .= "declare(strict_types=1);\n\n";
        $source .= "/**\n";
        $source .= " * ModoCMS advanced configuration - SAMPLE FILE.\n";
        $source .= " *\n";
        $source .= " * Copy this file to modoconfig.php (or let Settings -> System write it for you)\n";
        $source .= " * and uncomment the lines you need. Values set here override the matching option\n";
        $source .= " * from the Settings screen.\n";
        $source .= " */\n\n";
        $source .= "return [\n";

        $currentGroup = '';
        foreach (self::definitions() as $key => $definition) {
            $group = (string)($definition['group'] ?? 'misc');
            if ($group !== $currentGroup) {
                $currentGroup = $group;
                $source .= "\n    // " . str_repeat('-', 70) . "\n";
                $source .= "    // " . self::groupLabel($group) . "\n";
                $source .= "    // " . str_repeat('-', 70) . "\n";
            }

            foreach (self::commentLines((string)($definition['help'] ?? '')) as $line) {
                $source .= "    // " . $line . "\n";
            }
            if (!empty($definition['overrides'])) {
                $source .= "    // Falls back to the \"" . $definition['overrides'] . "\" setting when omitted.\n";
            }

            $source .= '    // ' . var_export($key, true) . ' => ' . var_export($definition['default'] ?? null, true) . ",\n";
        }

        $source .= "];\n";

        return $source;
    }

    /** Human readable heading for an option group. */
    public static function groupLabel(string $group): string {
        return match ($group) {
            'runtime'      => 'Debugging and error handling',
            'localization' => 'Localization',
            'urls'         => 'URLs',
            'availability' => 'Availability (maintenance / HTTPS / analytics)',
            'uploads'      => 'Uploads',
            'security'     => 'Security',
            default        => ucfirst($group),
        };
    }

    /** Wraps a help text into comment lines of at most 76 characters. */
    private static function commentLines(string $text): array {
        if (trim($text) === '') {
            return [];
        }

        return explode("\n", wordwrap(trim($text), 76, "\n", false));
    }

    /**
     * Drops the cached option table so filters registered later in the request
     * (plugins are loaded after the configuration) still apply.
     */
    public static function flushDefinitions(): void {
        self::$definitionCache = null;
    }

    /** Re-reads modoconfig.php from disk (used after an external edit). */
    public static function reload(): void {
        self::load(true);
    }

    /** Writes the documented sample file next to the configuration file. */
    public static function writeSample(): bool {
        $path = self::samplePath();
        if (!is_writable($path) && !is_writable(self::rootPath())) {
            return false;
        }

        return @file_put_contents($path, self::sampleSource(), LOCK_EX) !== false;
    }

    /**
     * The complete Advanced option table: file key => definition.
     *
     * 'overrides' names the database setting (settings table) that the file
     * value takes precedence over, so an option can be managed either from the
     * configuration file or from the Settings screen.
     *
     * Plugins may append their own entries through the "modo_advanced_options"
     * filter.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function definitions(): array {
        if (self::$definitionCache !== null) {
            return self::$definitionCache;
        }

        $timezones = ['' => 'Use the server default'];
        foreach (\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC) as $identifier) {
            $timezones[$identifier] = $identifier;
        }

        $languages = ['' => 'Use Settings -> General'];
        foreach (glob(self::rootPath() . '/languages/*.json') ?: [] as $file) {
            $code = strtolower(pathinfo($file, PATHINFO_FILENAME));
            $languages[$code] = strtoupper($code);
        }

        $definitions = [
            'debug' => [
                'type'    => 'bool',
                'default' => false,
                'group'   => 'runtime',
                'label'   => 'Enable debug mode',
                'help'    => 'Shows PHP notices, warnings and errors on screen and disables asset minification. Never enable this on a live site: error output can expose filesystem paths and configuration details.',
            ],
            'debug_log' => [
                'type'    => 'bool',
                'default' => false,
                'group'   => 'runtime',
                'label'   => 'Write errors to a log file',
                'help'    => 'Writes PHP errors to data/logs/error.log instead of displaying them. Keep it enabled on production sites so problems stay traceable. Requires a writable data/logs directory.',
            ],
            'timezone' => [
                'type'    => 'select',
                'default' => '',
                'group'   => 'localization',
                'label'   => 'PHP timezone',
                'help'    => 'Timezone used by PHP for dates and times (date_default_timezone_set). Leave empty to keep the value configured in php.ini.',
                'options' => $timezones,
            ],
            'language' => [
                'type'      => 'select',
                'default'   => '',
                'group'     => 'localization',
                'label'     => 'Frontend default language',
                'help'      => 'Default frontend language code, used when the visitor has not chosen a language yet.',
                'options'   => $languages,
                'overrides' => 'default_language',
            ],
            'siteurl' => [
                'type'      => 'text',
                'default'   => '',
                'group'     => 'urls',
                'label'     => 'Site URL override',
                'help'      => 'Absolute base URL of the installation including the subfolder, e.g. https://example.com or https://example.com/modocms. Feeds canonical links, OpenGraph tags, the sitemap and robots.txt.',
                'overrides' => 'site_url',
                'placeholder' => 'https://example.com/modocms',
            ],
            'robots_txt' => [
                'type'        => 'textarea',
                'default'     => '',
                'group'       => 'urls',
                'label'       => 'Custom robots.txt',
                'help'        => 'Replaces the rules generated for /robots.txt. Leave it empty to let the CMS build the file (it then blocks /admin/, /core/ and /uploads/cache/ and links the sitemap). Useful to keep a staging copy out of the search index.',
                'placeholder' => "User-agent: *\nDisallow: /",
            ],
            'maintenance_mode' => [
                'type'    => 'bool',
                'default' => false,
                'group'   => 'availability',
                'label'   => 'Maintenance mode (frontend answers 503)',
                'help'    => 'Locks the public site: visitors get a 503 Service Unavailable page with a Retry-After header instead of the content, while the admin area and the IP addresses listed below keep working. Search engines mark the outage as temporary instead of dropping the pages.',
            ],
            'maintenance_allowed_ips' => [
                'type'        => 'text',
                'default'     => '',
                'group'       => 'availability',
                'label'       => 'IP addresses allowed during maintenance',
                'help'        => 'Comma separated IP addresses, address prefixes or CIDR ranges (203.0.113.7, 198.51.100. or 203.0.113.0/24) that may browse the site while maintenance mode is active. The admin area stays reachable for everybody who can log in.',
                'placeholder' => '203.0.113.10, 198.51.100.',
            ],
            'maintenance_message' => [
                'type'        => 'text',
                'default'     => '',
                'group'       => 'availability',
                'label'       => 'Maintenance notice text',
                'help'        => 'Message shown to visitors while maintenance mode is active. Leave it empty for the built-in wording. A theme can replace the whole page by shipping maintenance.php.',
                'placeholder' => 'We are performing scheduled maintenance - the site is back in a few minutes.',
            ],
            'maintenance_retry_after' => [
                'type'    => 'int',
                'default' => 3600,
                'group'   => 'availability',
                'label'   => 'Maintenance Retry-After (seconds)',
                'help'    => 'Value of the Retry-After header sent with the 503 response. It tells browsers and search engines when to come back (3600 = one hour).',
                'min'     => 60,
                'max'     => 86400,
            ],
            'require_https' => [
                'type'    => 'bool',
                'default' => false,
                'group'   => 'availability',
                'label'   => 'Force HTTPS on the frontend',
                'help'    => 'Redirects every frontend request made over plain HTTP to the same URL over HTTPS (301). Enable it only once a valid TLS certificate is installed, otherwise the site becomes unreachable.',
            ],
            'analytics_enabled' => [
                'type'    => 'bool',
                'default' => true,
                'group'   => 'availability',
                'label'   => 'Collect internal visit statistics',
                'help'    => 'Stores anonymous, daily rotating IP hashes for visited pages. Disabling it stops all writes to the statistics table, which removes the Dashboard traffic report.',
            ],
            'upload_max_size' => [
                'type'    => 'int',
                'default' => 0,
                'group'   => 'uploads',
                'label'   => 'Upload size limit (MB)',
                'help'    => 'Rejects larger files in the media library and the editor. The effective limit is never higher than PHP upload_max_filesize / post_max_size. Use 0 to follow the PHP configuration only.',
                'min'     => 0,
                'max'     => 2048,
            ],
            'session_cookie_secure' => [
                'type'    => 'select',
                'default' => 'auto',
                'group'   => 'security',
                'label'   => 'Secure session cookie',
                'help'    => 'automatic: mark the session cookie as Secure whenever the request arrives over HTTPS (recommended). always: mark it Secure even over HTTP, suitable for HTTPS-only sites. never: required when TLS is terminated by a reverse proxy that does not forward the HTTPS flag.',
                'options' => [
                    'auto'   => 'Automatic (recommended)',
                    'always' => 'Always (HTTPS-only site)',
                    'never'  => 'Never',
                ],
            ],
            'plugins_php_deny' => [
                'type'    => 'bool',
                'default' => true,
                'group'   => 'security',
                'label'   => 'Block direct PHP access to plugins/',
                'help'    => 'Keeps a plugins/.htaccess that stops browsers from executing plugin entry points (they are included by the core, never requested directly). Disable it only if a third-party plugin ships its own web endpoint.',
            ],
        ];

        if (class_exists(Hooks::class)) {
            $filtered = Hooks::applyFilters('modo_advanced_options', $definitions);
            if (is_array($filtered)) {
                $definitions = $filtered;
            }
        }

        self::$definitionCache = $definitions;

        return self::$definitionCache;
    }
}
