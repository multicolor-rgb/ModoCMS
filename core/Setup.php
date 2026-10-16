<?php
declare(strict_types=1);

namespace Core;

/**
 * Class Setup
 *
 * Installation helper: server requirement checks, filesystem permission checks
 * and the deployment / self-healing of every .htaccess file that hardens an
 * installation.
 *
 * The templates live in core/setup/*.txt and are the single source of truth:
 * the .htaccess files shipped with the repository are byte-identical copies of
 * those templates, so an existing installation can be audited and repaired from
 * Settings -> System.
 */
final class Setup {
    /** Version of the shipped .htaccess rule set (matches the marker line). */
    public const VERSION = '3';

    /** Marker that identifies a file as managed by ModoCMS. */
    public const MARKER_PATTERN = '/#\s*ModoCMS\s+\.htaccess\s+v([0-9]+(?:\.[0-9]+)?)/i';

    /** Directory roots that are never allowed to be served directly. */
    public const DENIED_DIRECTORIES = ['data', 'languages', 'core', 'uploads/cache'];

    public static function rootPath(): string {
        return dirname(__DIR__);
    }

    public static function templatePath(string $name): string {
        return __DIR__ . '/setup/' . $name;
    }

    /** Raw template contents, normalised to a single trailing newline. */
    public static function template(string $name): ?string {
        $path = self::templatePath($name);
        if (!is_readable($path)) {
            return null;
        }

        return self::normalize((string)file_get_contents($path));
    }

    /** Normalises line endings and guarantees exactly one trailing newline. */
    public static function normalize(string $content): string {
        return rtrim(str_replace(["\r\n", "\r"], "\n", $content)) . "\n";
    }

    /** Version of the marker line found in the given file content. */
    public static function markerVersion(string $content): ?string {
        if ($content === '' || !preg_match(self::MARKER_PATTERN, $content, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /** Whether the file hardcodes RewriteBase (breaks portable installations). */
    public static function hasHardcodedRewriteBase(string $content): bool {
        return (bool)preg_match('/^\s*RewriteBase\s+\S+/mi', $content);
    }

    /**
     * Every .htaccess file the CMS maintains.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function htaccessTargets(): array {
        $root = self::rootPath();

        $targets = [
            [
                'id'       => 'root',
                'label'    => 'Website root',
                'path'     => $root . '/.htaccess',
                'template' => 'htaccess-root.txt',
                'kind'     => 'root',
                'purpose'  => 'Front controller rewrites, security headers and blocking of sensitive files.',
            ],
            [
                'id'       => 'data',
                'label'    => 'data/',
                'path'     => $root . '/data/.htaccess',
                'template' => 'htaccess-deny-all.txt',
                'kind'     => 'deny',
                'purpose'  => 'The database and the site configuration must not be downloadable.',
            ],
            [
                'id'       => 'data-backups',
                'label'    => 'data/backups/',
                'path'     => $root . '/data/backups/.htaccess',
                'template' => 'htaccess-deny-all.txt',
                'kind'     => 'deny',
                'purpose'  => 'Content and database backups must not be downloadable.',
            ],
            [
                'id'       => 'data-cache',
                'label'    => 'data/cache/',
                'path'     => $root . '/data/cache/.htaccess',
                'template' => 'htaccess-deny-all.txt',
                'kind'     => 'deny',
                'purpose'  => 'Generated frontend HTML must not be downloadable.',
            ],
            [
                'id'       => 'data-logs',
                'label'    => 'data/logs/',
                'path'     => $root . '/data/logs/.htaccess',
                'template' => 'htaccess-deny-all.txt',
                'kind'     => 'deny',
                'purpose'  => 'Error logs can expose paths and configuration details.',
            ],
            [
                'id'       => 'core',
                'label'    => 'core/',
                'path'     => $root . '/core/.htaccess',
                'template' => 'htaccess-deny-all.txt',
                'kind'     => 'deny',
                'purpose'  => 'Framework code is never requested directly.',
            ],
            [
                'id'       => 'languages',
                'label'    => 'languages/',
                'path'     => $root . '/languages/.htaccess',
                'template' => 'htaccess-deny-all.txt',
                'kind'     => 'deny',
                'purpose'  => 'Translation packs are served through the router only.',
            ],
            [
                'id'       => 'uploads',
                'label'    => 'uploads/',
                'path'     => $root . '/uploads/.htaccess',
                'template' => 'htaccess-uploads.txt',
                'kind'     => 'uploads',
                'purpose'  => 'Uploaded files are served as static content and never executed.',
            ],
            [
                'id'       => 'uploads-cache',
                'label'    => 'uploads/cache/',
                'path'     => $root . '/uploads/cache/.htaccess',
                'template' => 'htaccess-deny-all.txt',
                'kind'     => 'deny',
                'purpose'  => 'Reserved cache directory for derived images; never served statically.',
            ],
            [
                'id'       => 'plugins',
                'label'    => 'plugins/',
                'path'     => $root . '/plugins/.htaccess',
                'template' => 'htaccess-plugins.txt',
                'kind'     => 'plugins',
                'purpose'  => 'Plugin entry points are included by the core, never executed directly.',
            ],
        ];

        return $targets;
    }

    /**
     * Audits every managed .htaccess file without touching the filesystem.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function audit(): array {
        $pluginsDenied = Config::getBool('plugins_php_deny', true);
        $rows = [];

        foreach (self::htaccessTargets() as $target) {
            $rows[] = self::inspectTarget($target, $pluginsDenied);
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $target
     * @return array<string,mixed>
     */
    private static function inspectTarget(array $target, bool $pluginsDenied): array {
        $path = (string)$target['path'];
        $directory = dirname($path);
        $exists = is_file($path);
        $content = $exists ? (string)@file_get_contents($path) : '';
        $version = $exists ? self::markerVersion($content) : null;
        $expected = self::template((string)$target['template']);
        $matchesTemplate = $exists && $expected !== null && self::normalize($content) === $expected;
        $directoryExists = is_dir($directory);
        $writable = $exists
            ? is_writable($path)
            : is_writable($directoryExists ? $directory : dirname($directory));

        if ($target['kind'] === 'plugins' && !$pluginsDenied) {
            $state = 'disabled';
        } elseif (!$exists) {
            $state = 'missing';
        } elseif ($version === null) {
            $state = ($target['kind'] === 'root' && self::hasHardcodedRewriteBase($content))
                ? 'outdated'
                : 'unmanaged';
        } elseif (version_compare($version, self::VERSION, '<') || !$matchesTemplate) {
            $state = 'outdated';
        } else {
            $state = 'ok';
        }

        if (!in_array($state, ['ok', 'disabled'], true) && !$writable) {
            $state = 'not-writable';
        }

        return array_merge($target, [
            'exists'           => $exists,
            'directory_exists' => $directoryExists,
            'writable'         => $writable,
            'version'          => $version,
            'version_ok'       => $version !== null && version_compare($version, self::VERSION, '>='),
            'matches_template' => $matchesTemplate,
            'state'            => $state,
            'managed'          => $version !== null,
        ]);
    }

    /** Localised label and severity for an audit state. */
    public static function stateMeta(string $state): array {
        return match ($state) {
            'ok'            => ['label' => 'Up to date', 'severity' => 'success'],
            'outdated'      => ['label' => 'Outdated - repair recommended', 'severity' => 'warning'],
            'missing'       => ['label' => 'Missing', 'severity' => 'warning'],
            'unmanaged'     => ['label' => 'Custom file (not managed)', 'severity' => 'info'],
            'not-writable'  => ['label' => 'Not writable', 'severity' => 'danger'],
            'disabled'      => ['label' => 'Disabled by configuration', 'severity' => 'muted'],
            default         => ['label' => ucfirst($state), 'severity' => 'muted'],
        };
    }

    /** Counts used by the admin badges (ok / problem / total). */
    public static function auditSummary(array $rows): array {
        $summary = ['ok' => 0, 'problem' => 0, 'disabled' => 0, 'total' => count($rows)];
        foreach ($rows as $row) {
            $state = (string)($row['state'] ?? '');
            if ($state === 'ok') {
                $summary['ok']++;
            } elseif ($state === 'disabled') {
                $summary['disabled']++;
            } else {
                $summary['problem']++;
            }
        }

        return $summary;
    }

    /**
     * Creates / repairs every managed .htaccess file.
     *
     * Files carrying our marker are refreshed to the current version; files the
     * user customised (no marker) are only replaced when $force is true and are
     * always backed up into data/backups/ first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function deployHtaccess(bool $force = false): array {
        $pluginsDenied = Config::getBool('plugins_php_deny', true);
        $results = [];

        foreach (self::htaccessTargets() as $target) {
            $row = self::inspectTarget($target, $pluginsDenied);
            $path = (string)$row['path'];
            $directory = dirname($path);
            $result = ['id' => $row['id'], 'label' => $row['label'], 'path' => $path, 'backup' => ''];

            if ($row['state'] === 'disabled') {
                if ($row['managed']) {
                    $result['backup'] = self::backup($path, (string)$row['id']);
                    $result['status'] = @unlink($path) ? 'removed' : 'error';
                    $result['message'] = $result['status'] === 'removed'
                        ? 'Direct PHP access to plugins/ is allowed again - our file was deleted.'
                        : 'Could not delete plugins/.htaccess.';
                } else {
                    $result['status'] = 'skipped';
                    $result['message'] = 'Direct PHP access to plugins/ stays allowed - the existing file was left untouched.';
                }

                $results[] = $result;
                continue;
            }

            if (!$row['directory_exists'] && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                $result['status'] = 'error';
                $result['message'] = 'Directory ' . $directory . ' could not be created.';
                $results[] = $result;
                continue;
            }

            switch ($row['state']) {
                case 'ok':
                    $result['status'] = 'up-to-date';
                    $result['message'] = 'Already up to date.';
                    $results[] = $result;
                    continue 2;
                case 'not-writable':
                    $result['status'] = 'error';
                    $result['message'] = 'Not writable - check the permissions of ' . ($row['exists'] ? $path : $directory) . '.';
                    $results[] = $result;
                    continue 2;
                case 'unmanaged':
                    if (!$force) {
                        $result['status'] = 'skipped';
                        $result['message'] = 'Existing file is not managed by ModoCMS and was left untouched. Tick "Replace custom files" to overwrite it (a backup is created first).';
                        $results[] = $result;
                        continue 2;
                    }
                    break;
                default:
                    break;
            }

            if ($row['exists']) {
                $result['backup'] = self::backup($path, (string)$row['id']);
            }

            $content = self::template((string)$target['template']);
            if ($content === null) {
                $result['status'] = 'error';
                $result['message'] = 'Rule template ' . $target['template'] . ' is missing.';
                $results[] = $result;
                continue;
            }

            if (@file_put_contents($path, $content, LOCK_EX) === false) {
                $result['status'] = 'error';
                $result['message'] = 'Could not write ' . $path . '.';
                $results[] = $result;
                continue;
            }
            @chmod($path, 0644);

            $result['status'] = $row['exists'] ? 'updated' : 'written';
            $result['message'] = $row['exists']
                ? 'Refreshed to rule version ' . self::VERSION . '.'
                : 'File created.';
            $results[] = $result;
        }

        return $results;
    }

    /** Copies a managed file to data/backups before it is replaced. */
    private static function backup(string $path, string $id): string {
        if (!is_file($path)) {
            return '';
        }

        $directory = self::rootPath() . '/data/backups';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return '';
        }

        $safeId = preg_replace('/[^a-z0-9_-]/i', '', $id) ?: 'htaccess';
        $destination = $directory . '/htaccess-' . $safeId . '-' . date('Ymd-His') . '.bak';

        return @copy($path, $destination) ? $destination : '';
    }

    /**
     * Server requirements (PHP version, extensions, Apache modules, php.ini).
     *
     * Rows with severity "error" block the installation; "warning" rows only
     * reduce functionality; "info" rows document the environment.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function requirements(): array {
        $rows = [];

        $rows[] = [
            'id'       => 'php_version',
            'label'    => 'PHP version',
            'value'    => PHP_VERSION,
            'ok'       => version_compare(PHP_VERSION, '8.0.0', '>='),
            'severity' => 'error',
            'kind'     => 'requirement',
            'hint'     => 'ModoCMS requires PHP 8.0 or newer (8.2+ recommended).',
        ];

        $requiredExtensions = [
            'pdo'        => 'Database access for pages, settings and plugins.',
            'pdo_sqlite' => 'SQLite driver - all content lives in data/cms.sqlite.',
            'sqlite3'    => 'SQLite helper used by the backup and migration tools.',
            'mbstring'   => 'UTF-8 aware string handling (titles, excerpts, slugs).',
            'json'       => 'Translation packs and JSON endpoints.',
            'session'    => 'Admin login and CSRF protection.',
            'fileinfo'   => 'MIME detection for every uploaded file.',
        ];
        foreach ($requiredExtensions as $extension => $hint) {
            $loaded = extension_loaded($extension);
            $rows[] = [
                'id'       => 'ext_' . $extension,
                'label'    => 'Extension: ' . $extension,
                'value'    => $loaded ? 'loaded' : 'missing',
                'ok'       => $loaded,
                'severity' => 'error',
                'kind'     => 'requirement',
                'hint'     => $hint . ' Enable the extension in php.ini or install the matching PHP package.',
            ];
        }

        $optionalExtensions = [
            'gd'      => 'Thumbnail generation and WebP conversion (Core\\ImageOptimizer).',
            'exif'    => 'Image orientation correction for photos.',
            'zip'     => 'Installing plugins and themes from a ZIP archive.',
            'openssl' => 'TLS for SMTP mail and reliable HTTPS detection.',
            'curl'    => 'Outgoing HTTP requests (update checks, external APIs).',
        ];
        foreach ($optionalExtensions as $extension => $hint) {
            $loaded = extension_loaded($extension);
            $rows[] = [
                'id'       => 'ext_' . $extension,
                'label'    => 'Extension: ' . $extension,
                'value'    => $loaded ? 'loaded' : 'not loaded',
                'ok'       => $loaded,
                'severity' => 'warning',
                'kind'     => 'requirement',
                'hint'     => $hint . ' Optional: only the listed feature stops working.',
            ];
        }

        $software = self::serverSoftware();
        $rows[] = [
            'id'       => 'server_software',
            'label'    => 'Web server',
            'value'    => $software,
            'ok'       => true,
            'severity' => 'info',
            'kind'     => 'info',
            'hint'     => 'The hardening rules ship as .htaccess files (Apache / LiteSpeed). nginx ignores them - copy the ready-made server block from Settings -> System.',
        ];

        if (function_exists('apache_get_modules')) {
            $loadedModules = array_map(static fn($module) => strtolower((string)$module), (array)apache_get_modules());
            $apacheModules = [
                'mod_rewrite'    => 'Pretty permalinks and the front controller.',
                'mod_headers'    => 'HTTP security headers (HSTS, Permissions-Policy).',
                'mod_deflate'    => 'Gzip compression of HTML, CSS and JS.',
                'mod_authz_core' => 'Apache 2.4 syntax used by the "Require all denied" rules.',
            ];
            foreach ($apacheModules as $module => $hint) {
                $enabled = in_array($module, $loadedModules, true) || in_array($module . '.c', $loadedModules, true);
                $rows[] = [
                    'id'       => 'module_' . $module,
                    'label'    => 'Apache module: ' . $module,
                    'value'    => $enabled ? 'enabled' : 'not detected',
                    'ok'       => $enabled,
                    'severity' => $module === 'mod_rewrite' ? 'error' : 'warning',
                    'kind'     => 'requirement',
                    'hint'     => $hint . ' Enable it with: a2enmod ' . str_replace('mod_', '', $module) . ' && systemctl reload apache2',
                ];
            }
        } elseif (stripos($software, 'apache') !== false || stripos($software, 'litespeed') !== false) {
            $rows[] = [
                'id'       => 'module_check',
                'label'    => 'Apache modules',
                'value'    => 'cannot be inspected from PHP',
                'ok'       => true,
                'severity' => 'warning',
                'kind'     => 'info',
                'hint'     => 'Apache is detected, but this PHP interface cannot list the loaded modules. If pretty permalinks or the security headers do not work, enable mod_rewrite, mod_headers, mod_deflate and mod_authz_core.',
            ];
        }

        foreach (self::iniChecks() as $check) {
            $rows[] = $check;
        }

        return $rows;
    }

    /** php.ini directives that matter for the CMS (documented, not blocking). */
    public static function iniChecks(): array {
        $definitions = [
            'upload_max_filesize' => ['Upload size limit (php.ini)', 'info', null],
            'post_max_size'       => ['POST request limit (php.ini)', 'info', null],
            'memory_limit'        => ['Memory limit (php.ini)', 'info', null],
            'max_execution_time'  => ['Maximum execution time', 'info', null],
            'max_input_vars'      => ['Maximum input variables', 'info', null],
            'date.timezone'       => ['date.timezone', 'warning', 'empty'],
            'log_errors'          => ['log_errors', 'info', null],
            'display_errors'      => ['display_errors (should be off in production)', 'warning', 'on'],
            'allow_url_fopen'     => ['allow_url_fopen', 'warning', 'off'],
            'open_basedir'        => ['open_basedir', 'info', null],
        ];

        $rows = [];
        foreach ($definitions as $directive => [$label, $severity, $failingValue]) {
            $current = ini_get($directive);
            $current = ($current === false) ? '' : trim((string)$current);
            $ok = true;

            if ($failingValue === 'empty') {
                $ok = $current !== '';
            } elseif ($failingValue === 'on') {
                $ok = !in_array(strtolower($current), ['1', 'on', 'yes', 'true'], true);
            } elseif ($failingValue === 'off') {
                $ok = in_array(strtolower($current), ['1', 'on', 'yes', 'true'], true);
            }

            $rows[] = [
                'id'       => 'ini_' . str_replace('.', '_', $directive),
                'label'    => $label,
                'value'    => $current === '' ? 'not set' : $current,
                'ok'       => $ok,
                'severity' => $severity,
                'kind'     => $ok ? 'info' : 'requirement',
                'hint'     => $ok ? '' : match ($failingValue) {
                    'empty' => 'PHP falls back to UTC. Set a timezone in php.ini or through modoconfig.php ("timezone").',
                    'on'    => 'Errors are printed to visitors. Turn display_errors off and use modoconfig.php ("debug_log") to keep them in data/logs/error.log.',
                    'off'   => 'Outgoing HTTP requests used by some plugins will fail.',
                    default => '',
                },
            ];
        }

        return $rows;
    }

    /**
     * Filesystem permission checks for every directory the CMS writes to.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function permissions(): array {
        $root = self::rootPath();

        $directories = [
            ['id' => 'root', 'path' => $root, 'label' => 'Project root', 'purpose' => 'Root .htaccess, robots.txt, sitemap.xml and modoconfig.php.', 'severity' => 'error'],
            ['id' => 'data', 'path' => $root . '/data', 'label' => 'data/', 'purpose' => 'SQLite database with all content and settings.', 'severity' => 'error'],
            ['id' => 'data_backups', 'path' => $root . '/data/backups', 'label' => 'data/backups/', 'purpose' => 'Backups created before every update.', 'severity' => 'warning'],
            ['id' => 'data_cache', 'path' => $root . '/data/cache', 'label' => 'data/cache/', 'purpose' => 'Full page cache (created automatically).', 'severity' => 'warning'],
            ['id' => 'data_logs', 'path' => $root . '/data/logs', 'label' => 'data/logs/', 'purpose' => 'Error log written when "debug_log" is enabled.', 'severity' => 'warning'],
            ['id' => 'uploads', 'path' => $root . '/uploads', 'label' => 'uploads/', 'purpose' => 'Media library - images and files uploaded by editors.', 'severity' => 'error'],
            ['id' => 'uploads_cache', 'path' => $root . '/uploads/cache', 'label' => 'uploads/cache/', 'purpose' => 'Reserved cache directory for derived images.', 'severity' => 'warning'],
            ['id' => 'languages', 'path' => $root . '/languages', 'label' => 'languages/', 'purpose' => 'Translation packs installed from the admin area.', 'severity' => 'warning'],
            ['id' => 'themes', 'path' => $root . '/themes', 'label' => 'themes/', 'purpose' => 'Theme editor and theme installation.', 'severity' => 'warning'],
            ['id' => 'plugins', 'path' => $root . '/plugins', 'label' => 'plugins/', 'purpose' => 'Plugin installation and updates.', 'severity' => 'warning'],
        ];

        $rows = [];
        foreach ($directories as $directory) {
            $exists = is_dir((string)$directory['path']);
            $parent = dirname((string)$directory['path']);
            $writable = $exists ? is_writable((string)$directory['path']) : (is_dir($parent) && is_writable($parent));

            $rows[] = array_merge($directory, [
                'kind'     => 'directory',
                'exists'   => $exists,
                'writable' => $writable,
                'ok'       => $exists && $writable,
                'creatable' => !$exists && $writable,
                'hint'     => ($exists && $writable) ? '' : self::permissionHint((string)$directory['path'], $exists),
            ]);
        }

        foreach (self::permissionFiles() as $file) {
            if (!is_file((string)$file['path'])) {
                continue;
            }

            $writable = is_writable((string)$file['path']);
            $rows[] = array_merge($file, [
                'kind'      => 'file',
                'exists'    => true,
                'writable'  => $writable,
                'ok'        => $writable,
                'creatable' => false,
                'hint'      => $writable ? '' : self::permissionHint((string)$file['path'], true),
            ]);
        }

        return $rows;
    }

    /** Individual files that must stay writable once they exist. */
    public static function permissionFiles(): array {
        $root = self::rootPath();

        return [
            ['id' => 'database', 'path' => $root . '/data/cms.sqlite', 'label' => 'data/cms.sqlite', 'purpose' => 'The site database itself.', 'severity' => 'error'],
            ['id' => 'database_wal', 'path' => $root . '/data/cms.sqlite-wal', 'label' => 'data/cms.sqlite-wal', 'purpose' => 'SQLite write-ahead log.', 'severity' => 'error'],
            ['id' => 'config', 'path' => Config::path(), 'label' => Config::FILENAME, 'purpose' => 'Advanced configuration overrides.', 'severity' => 'warning'],
            ['id' => 'sample_config', 'path' => Config::samplePath(), 'label' => Config::SAMPLE_FILENAME, 'purpose' => 'Documented configuration template.', 'severity' => 'info'],
            ['id' => 'robots', 'path' => $root . '/robots.txt', 'label' => 'robots.txt', 'purpose' => 'Regenerated whenever the settings change.', 'severity' => 'warning'],
            ['id' => 'sitemap', 'path' => $root . '/sitemap.xml', 'label' => 'sitemap.xml', 'purpose' => 'Regenerated whenever content changes.', 'severity' => 'warning'],
        ];
    }

    /** Shell command that fixes a non-writable path. */
    public static function permissionHint(string $path, bool $exists): string {
        $target = $exists ? $path : dirname($path);
        $owner = self::processOwner();

        return 'chmod 775 ' . $target . ' && chown -R ' . $owner . ':' . $owner . ' ' . $target;
    }

    /** Owner of the running PHP process (falls back to www-data). */
    public static function processOwner(): string {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info) && !empty($info['name'])) {
                return (string)$info['name'];
            }
        }

        return 'www-data';
    }

    /** Number of blocking (severity "error") permission problems. */
    public static function permissionErrors(array $rows): int {
        $errors = 0;
        foreach ($rows as $row) {
            if (($row['severity'] ?? '') === 'error' && empty($row['ok'])) {
                $errors++;
            }
        }

        return $errors;
    }

    /** Whether the CMS database already exists. */
    public static function isInstalled(): bool {
        return is_file(self::rootPath() . '/data/cms.sqlite');
    }

    /**
     * Ready to paste nginx configuration: the equivalent of every shipped
     * .htaccess rule for servers that ignore .htaccess files.
     */
    public static function webServerRules(): string {
        return <<<'NGINX'
# ---------------------------------------------------------------------------
# ModoCMS - nginx equivalent of the shipped .htaccess rules
# Paste this inside the "server { ... }" block of your site and adjust the
# fastcgi_pass socket to your PHP-FPM version.
# ---------------------------------------------------------------------------

# 1. Never serve these directories or files directly
location ~ ^/(data|languages|core)/  { deny all; }
location ~ ^/uploads/cache/         { deny all; }
location ~ /\.(?!well-known)        { deny all; }
location ~* \.(sqlite3?|db|sql|log|ini|lock|bak|orig|old|dist)$ { deny all; }
location ~ ^/uploads/.*\.(php|phtml|php[0-9]|phps|pht|phar|cgi|pl|py|sh|shtml)$ { deny all; }
location = /modoconfig.php          { deny all; }
location = /composer.lock           { deny all; }

# 2. Front controller (works in the document root and in a subfolder)
location / {
    try_files $uri $uri/ /index.php?$query_string;
}

# 3. PHP execution
location ~ \.php$ {
    try_files $uri =404;
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.2-fpm.sock;   # adjust to your socket
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_param HTTPS $https if_not_empty;
    client_max_body_size 16m;                     # keep >= upload_max_size
}

# 4. Security headers (mirror of the .htaccess rules)
add_header X-Content-Type-Options nosniff always;
add_header X-Frame-Options SAMEORIGIN always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "geolocation=(), microphone=(), camera=()" always;
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

# 5. Compression
gzip on;
gzip_types text/html text/css text/plain text/xml application/javascript application/json image/svg+xml;

# NOTE: the plugin asset rewrite rules from .htaccess are not needed here -
# "try_files" already resolves the physical files.
NGINX;
    }

    /** Whether the server requirements contain a blocking problem. */
    public static function requirementErrors(array $rows): int {
        $errors = 0;
        foreach ($rows as $row) {
            if (($row['severity'] ?? '') === 'error' && empty($row['ok'])) {
                $errors++;
            }
        }

        return $errors;
    }

    /** SERVER_SOFTWARE reported by the web server (empty in CLI). */
    public static function serverSoftware(): string {
        $software = trim((string)($_SERVER['SERVER_SOFTWARE'] ?? ''));

        return $software === '' ? 'unknown (CLI / PHP built-in server)' : $software;
    }
}
