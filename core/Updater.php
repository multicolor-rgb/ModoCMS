<?php
declare(strict_types=1);

namespace Core;

/**
 * One-click updater for ModoCMS.
 *
 * The updater reads the latest public release from the GitHub API, downloads
 * the release ZIP over HTTPS and replaces the application files after creating
 * a database snapshot and a backup of every file that is going to be changed.
 * User content (data/, uploads/), modoconfig.php and install.php are never
 * touched.
 */
final class Updater
{
    public const REPO = 'multicolor-rgb/ModoCMS';

    private const API_URL = 'https://api.github.com/repos/' . self::REPO . '/releases/latest';
    private const ATOM_URL = 'https://github.com/' . self::REPO . '/releases.atom';
    private const CACHE_TTL = 43200;              // 12 hours
    private const STALE_LOCK_SECONDS = 900;       // 15 minutes
    private const MAX_DOWNLOAD_BYTES = 52428800;  // 50 MB
    private const MAX_UNPACKED_BYTES = 209715200; // 200 MB
    private const MAX_FILES = 10000;
    private const USER_AGENT = 'ModoCMS-Updater/1.0';

    /** Hosts that may serve the GitHub API response and the release archive. */
    private const ALLOWED_HOSTS = [
        'api.github.com',
        'github.com',
        'codeload.github.com',
        'objects.githubusercontent.com',
        'github-releases.githubusercontent.com',
        'release-assets.githubusercontent.com',
    ];

    public static function currentVersion(): string
    {
        return Version::current();
    }

    /** Directory used for downloaded packages and the update cache. */
    public static function dataPath(): string
    {
        return dirname(__DIR__) . '/data/updates';
    }

    /**
     * Ensures the update directory exists and is protected from HTTP access.
     * data/.htaccess already denies the whole tree, but a local file makes the
     * protection explicit when a custom server configuration is used.
     */
    public static function dir(): string
    {
        $dir = self::dataPath();
        self::ensureDir($dir);

        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents(
                $htaccess,
                "# ModoCMS .htaccess v3 - do not remove this line\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
                . "Options -Indexes -ExecCGI\n"
            );
        }

        return $dir;
    }

    /**
     * True while an update is copying files. The public frontend is served with
     * the maintenance page during this window. A stale flag (crashed request) is
     * ignored and removed automatically.
     */
    public static function isUpdating(): bool
    {
        $flag = self::dataPath() . '/.updating';
        if (!is_file($flag)) {
            return false;
        }

        $modified = @filemtime($flag);
        if ($modified !== false && (time() - $modified) > self::STALE_LOCK_SECONDS) {
            @unlink($flag);
            return false;
        }

        return true;
    }

    /**
     * Runtime requirements for checking and installing updates.
     *
     * @return array<string,bool>
     */
    public static function requirements(): array
    {
        $root = dirname(__DIR__);
        $data = $root . '/data';
        $backups = $data . '/backups';

        return [
            'zip'       => class_exists('\ZipArchive'),
            'network'   => function_exists('curl_init') || self::urlFopenEnabled(),
            'root'      => self::pathWritable($root),
            'core'      => self::pathWritable($root . '/core'),
            'admin'     => self::pathWritable($root . '/admin'),
            'languages' => self::pathWritable($root . '/languages'),
            'themes'    => self::pathWritable($root . '/themes'),
            'plugins'   => self::pathWritable($root . '/plugins'),
            'data'      => self::pathWritable($data),
            'backups'   => self::pathWritable($backups),
            'updates'   => self::pathWritable($data . '/updates'),
        ];
    }

    public static function canUpdate(): bool
    {
        foreach (self::requirements() as $available) {
            if (!$available) {
                return false;
            }
        }

        return true;
    }

    /**
     * Optional GitHub token. It is used only for api.github.com, never for the
     * public feed or the release archive. Set MODOCMS_GITHUB_TOKEN in the
     * environment or "update_github_token" in modoconfig.php to raise the API
     * limit from 60 to 5000 requests per hour.
     */
    private static function githubToken(): string
    {
        foreach (['MODOCMS_GITHUB_TOKEN', 'MODO_GITHUB_TOKEN'] as $envName) {
            $value = getenv($envName);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        if (class_exists(Config::class)) {
            try {
                $value = Config::getString('update_github_token', '');
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            } catch (\Throwable $e) {
                // The updater must keep working even if the configuration file is broken.
            }
        }

        return '';
    }

    /**
     * Cached state for the Settings screen - never performs a network request.
     *
     * @return array<string,mixed>
     */
    public static function state(): array
    {
        $current = Version::current();
        $cache = self::readCache();
        if ($cache === null) {
            return self::emptyState($current);
        }

        $stale = (time() - (int)$cache['fetched_at']) > self::CACHE_TTL;
        $state = self::buildState($current, $cache['release'], true, $stale, '');
        $state['checked_at'] = (int)$cache['fetched_at'];

        return $state;
    }

    /**
     * Checks GitHub for the latest release. When $force is false a fresh cache
     * (12 hours) is used, otherwise the API is queried again.
     *
     * @return array<string,mixed>
     */
    public static function check(bool $force = false): array
    {
        $current = Version::current();

        try {
            $result = self::latestRelease($force);
        } catch (\Throwable $e) {
            $state = self::emptyState($current);
            $state['ok'] = false;
            $state['checked'] = true;
            $state['error'] = $e->getMessage();

            return $state;
        }

        $state = self::buildState(
            $current,
            $result['release'],
            $result['cached'],
            $result['stale'],
            $result['warning']
        );
        $state['checked'] = true;
        $state['checked_at'] = (int)$result['fetched_at'];

        return $state;
    }

    /**
     * Downloads and installs the latest release.
     *
     * @return array<string,mixed> Result report suitable for the admin panel.
     */
    public static function update(): array
    {
        $from = Version::current();
        $check = self::check(false);

        if (!$check['ok']) {
            return self::failure($from, '', (string)$check['error']);
        }
        if (!$check['has_update']) {
            return self::failure($from, '', 'No newer release is available.');
        }

        $release = (array)$check['release'];
        $to = (string)($release['version'] ?? '');
        $asset = (array)($release['asset'] ?? []);

        if ($to === '' || empty($asset['url'])) {
            return self::failure($from, $to, 'The release does not contain a ZIP package.');
        }

        $requirements = self::requirements();
        if (!$requirements['zip']) {
            return self::failure($from, $to, 'The PHP ZipArchive extension is required to install updates.');
        }
        if (!self::canUpdate()) {
            return self::failure($from, $to, 'The installation is not writable. Check the file permissions in Settings -> System.');
        }

        $lock = null;
        $flagPath = self::dataPath() . '/.updating';
        $zipPath = '';
        $extractDir = '';
        $backupDir = '';
        $dbBackup = '';
        $manifest = [];
        $skipped = [];
        $replaced = 0;
        $added = 0;

        try {
            $lock = self::acquireLock();
            @set_time_limit(300);
            @ignore_user_abort(true);
            self::dir();
            if (@file_put_contents($flagPath, (string)time(), LOCK_EX) === false) {
                throw new \RuntimeException('Could not enable maintenance mode for the update.');
            }

            try {
                $dbBackup = Backup::createDatabaseSnapshot();
            } catch (\Throwable $e) {
                throw new \RuntimeException('Could not create the database backup: ' . $e->getMessage());
            }

            $backupDir = self::createBackupDir($from, $to);

            $zipPath = self::dataPath() . '/ModoCMS-' . preg_replace('/[^a-zA-Z0-9._-]/', '-', $to)
                . '-' . bin2hex(random_bytes(4)) . '.zip';
            self::downloadToFile((string)$asset['url'], $zipPath);

            $size = @filesize($zipPath) ?: 0;
            if ($size <= 0 || $size > self::MAX_DOWNLOAD_BYTES) {
                throw new \RuntimeException('The downloaded package has an invalid size.');
            }

            $expectedHash = (string)($asset['sha256'] ?? '');
            if ($expectedHash !== '') {
                $actualHash = @hash_file('sha256', $zipPath);
                if (!is_string($actualHash) || !hash_equals(strtolower($expectedHash), strtolower($actualHash))) {
                    throw new \RuntimeException('The downloaded package checksum is invalid.');
                }
            }

            $extractDir = self::dataPath() . '/extract-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
            self::ensureDir($extractDir);
            $packageRoot = self::extractZip($zipPath, $extractDir);
            self::validatePackage($packageRoot, $to);

            $copy = self::copyPackage($packageRoot, dirname(__DIR__), $backupDir, $manifest, $skipped);
            $replaced = (int)$copy['replaced'];
            $added = (int)$copy['added'];

            if (($replaced + $added) === 0) {
                throw new \RuntimeException('The package did not contain any application files.');
            }

            self::purgeCaches();
            self::log('Updated ModoCMS from ' . $from . ' to ' . $to . '. Backup: ' . $backupDir);

            return [
                'ok'          => true,
                'from'        => $from,
                'to'          => $to,
                'backup_dir'  => $backupDir,
                'db_backup'   => $dbBackup,
                'replaced'    => $replaced,
                'added'       => $added,
                'skipped'     => array_values($skipped),
                'release_url' => (string)($release['html_url'] ?? ''),
                'message'     => 'The update completed successfully.',
            ];
        } catch (\Throwable $e) {
            $rolledBack = false;
            if ($backupDir !== '' && $manifest !== []) {
                $rolledBack = self::rollback($manifest, $backupDir);
            }

            self::log('Update from ' . $from . ' to ' . ($to !== '' ? $to : '?') . ' failed: '
                . $e->getMessage() . ($rolledBack ? ' (files restored from backup)' : ''));

            return [
                'ok'          => false,
                'from'        => $from,
                'to'          => $to,
                'error'       => $e->getMessage(),
                'rolled_back' => $rolledBack,
                'backup_dir'  => $backupDir,
                'db_backup'   => $dbBackup,
                'replaced'    => $replaced,
                'added'       => $added,
                'skipped'     => array_values($skipped),
            ];
        } finally {
            if ($extractDir !== '') {
                self::removeDirectory($extractDir);
            }
            if ($zipPath !== '') {
                @unlink($zipPath);
            }
            @unlink($flagPath);
            if (is_array($lock)) {
                self::releaseLock($lock);
            }
        }
    }

    // ---------------------------------------------------------------------
    // Remote release lookup
    // ---------------------------------------------------------------------

    /**
     * @return array{release: array<string,mixed>, fetched_at: int, cached: bool, stale: bool, warning: string}
     */
    private static function latestRelease(bool $force): array
    {
        $cache = self::readCache();
        $fresh = $cache !== null && (time() - (int)$cache['fetched_at']) < self::CACHE_TTL;

        if (!$force && $fresh) {
            return [
                'release'    => $cache['release'],
                'fetched_at' => (int)$cache['fetched_at'],
                'cached'     => true,
                'stale'      => false,
                'warning'    => '',
            ];
        }

        try {
            $release = self::fetchLatestRelease();
            $now = time();
            try {
                self::writeCache($release, $now);
            } catch (\Throwable $e) {
                // A read-only update directory must not hide a successful check.
            }

            return [
                'release'    => $release,
                'fetched_at' => $now,
                'cached'     => false,
                'stale'      => false,
                'warning'    => '',
            ];
        } catch (\Throwable $e) {
            if ($cache !== null) {
                return [
                    'release'    => $cache['release'],
                    'fetched_at' => (int)$cache['fetched_at'],
                    'cached'     => true,
                    'stale'      => true,
                    'warning'    => $e->getMessage(),
                ];
            }

            throw $e;
        }
    }

    /**
     * Prefers the GitHub API (it also provides the SHA-256 digest). When the API
     * is unavailable or rate-limited, the public releases Atom feed is used -
     * it has no API rate limit and still contains the tag and release notes.
     *
     * @return array<string,mixed>
     */
    private static function fetchLatestRelease(): array
    {
        try {
            return self::fetchLatestReleaseFromApi();
        } catch (\Throwable $apiError) {
            try {
                return self::fetchLatestReleaseFromAtom();
            } catch (\Throwable $atomError) {
                throw new \RuntimeException(
                    'Could not check for updates. API: ' . $apiError->getMessage()
                    . ' Fallback: ' . $atomError->getMessage()
                );
            }
        }
    }

    /** @return array<string,mixed> */
    private static function fetchLatestReleaseFromApi(): array
    {
        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
        ];
        $token = self::githubToken();
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $body = self::httpGet(self::API_URL, 2097152, $headers);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new \RuntimeException('The GitHub API returned an invalid response.');
        }
        if (!empty($data['message'])) {
            throw new \RuntimeException('GitHub API: ' . (string)$data['message']);
        }

        $tag = trim((string)($data['tag_name'] ?? ''));
        $version = ltrim($tag, 'vV');
        if ($version === '' || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+].*)?$/', $version)) {
            throw new \RuntimeException('The latest release has an invalid version tag.');
        }

        $asset = null;
        foreach ((array)($data['assets'] ?? []) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $name = (string)($candidate['name'] ?? '');
            $url = (string)($candidate['browser_download_url'] ?? '');
            if ($name === '' || $url === '' || !self::isAllowedUrl($url)) {
                continue;
            }
            if (strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
                continue;
            }
            if ($asset === null || stripos($name, 'ModoCMS-') === 0) {
                $asset = $candidate;
            }
        }

        if ($asset === null) {
            throw new \RuntimeException('The latest release does not contain a ZIP package.');
        }

        $digest = (string)($asset['digest'] ?? '');
        $sha256 = '';
        if (preg_match('/^sha256:([a-f0-9]{64})$/i', $digest, $match)) {
            $sha256 = strtolower($match[1]);
        }

        return [
            'version'      => $version,
            'tag'          => $tag !== '' ? $tag : 'v' . $version,
            'name'         => (string)($data['name'] ?? $tag),
            'published_at' => (string)($data['published_at'] ?? ''),
            'html_url'     => (string)($data['html_url'] ?? ''),
            'body'         => self::truncate((string)($data['body'] ?? ''), 20000),
            'source'       => 'api',
            'asset'        => [
                'name'   => (string)$asset['name'],
                'url'    => (string)$asset['browser_download_url'],
                'size'   => (int)($asset['size'] ?? 0),
                'sha256' => $sha256,
            ],
        ];
    }

    /**
     * Fallback parser for https://github.com/<repo>/releases.atom. GitHub serves
     * this feed without the REST API rate limit. It contains only stable
     * releases in reverse chronological order, so the first matching entry is
     * the latest stable release.
     *
     * @return array<string,mixed>
     */
    private static function fetchLatestReleaseFromAtom(): array
    {
        $xml = self::httpGet(self::ATOM_URL, 2097152, ['Accept: application/atom+xml']);

        if (!preg_match_all('#<entry\b[^>]*>(.*?)</entry>#s', $xml, $entries) || $entries[1] === []) {
            throw new \RuntimeException('The public releases feed does not contain any release.');
        }

        foreach ($entries[1] as $entry) {
            $url = '';
            if (preg_match_all('#<link\b([^>]+)/?>#i', $entry, $links)) {
                foreach ($links[1] as $attributes) {
                    if (preg_match('#\brel="alternate"#i', $attributes)
                        && preg_match('#\bhref="([^"]+)"#i', $attributes, $href)) {
                        $url = html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        break;
                    }
                }
            }

            if ($url === ''
                || !preg_match('#/releases/tag/([^/"]+)$#i', $url, $tagMatch)) {
                continue;
            }

            $tag = rawurldecode(trim($tagMatch[1]));
            $version = ltrim($tag, 'vV');

            // Match the "latest stable release" behaviour of the REST API.
            if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $version)) {
                continue;
            }

            $name = 'Modo CMS ' . $tag;
            if (preg_match('#<title>(.*?)</title>#s', $entry, $title)) {
                $decodedTitle = html_entity_decode(strip_tags($title[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (trim($decodedTitle) !== '') {
                    $name = trim($decodedTitle);
                }
            }

            $published = '';
            if (preg_match('#<updated>(.*?)</updated>#s', $entry, $updated)) {
                $published = trim($updated[1]);
            }

            $body = '';
            if (preg_match('#<content\b[^>]*>(.*?)</content>#s', $entry, $content)) {
                $decoded = html_entity_decode($content[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $decoded = preg_replace('#^<!\[CDATA\[(.*)\]\]>$#s', '$1', trim($decoded));
                $decoded = str_replace(
                    ['</p>', '</li>', '<br>', '<br/>', '<br />'],
                    "\n",
                    (string)$decoded
                );
                $body = trim(strip_tags($decoded));
                $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;
            }

            $assetName = 'ModoCMS-' . $tag . '.zip';
            $assetUrl = 'https://github.com/' . self::REPO . '/releases/download/'
                . rawurlencode($tag) . '/' . rawurlencode($assetName);

            return [
                'version'      => $version,
                'tag'          => $tag,
                'name'         => $name,
                'published_at' => $published,
                'html_url'     => $url,
                'body'         => self::truncate($body, 20000),
                'source'       => 'atom',
                'asset'        => [
                    'name'   => $assetName,
                    'url'    => $assetUrl,
                    'size'   => 0,
                    'sha256' => '',
                ],
            ];
        }

        throw new \RuntimeException('The public releases feed does not contain a stable release.');
    }

    /** @return array<string,mixed>|null */
    private static function readCache(): ?array
    {
        $file = self::dataPath() . '/latest.json';
        if (!is_file($file)) {
            return null;
        }

        $raw = @file_get_contents($file);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['release']) || !is_array($data['release'])) {
            return null;
        }

        $fetchedAt = (int)($data['fetched_at'] ?? 0);
        if ($fetchedAt <= 0 || empty($data['release']['version'])) {
            return null;
        }

        return [
            'fetched_at' => $fetchedAt,
            'release'    => $data['release'],
        ];
    }

    /** @param array<string,mixed> $release */
    private static function writeCache(array $release, int $fetchedAt): void
    {
        self::dir();
        @file_put_contents(
            self::dataPath() . '/latest.json',
            (string)json_encode(
                ['fetched_at' => $fetchedAt, 'release' => $release],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            ),
            LOCK_EX
        );
    }

    /** @param array<string,mixed> $release @return array<string,mixed> */
    private static function buildState(
        string $current,
        array $release,
        bool $cached,
        bool $stale,
        string $warning
    ): array {
        $latest = (string)($release['version'] ?? '');
        $hasUpdate = $latest !== ''
            && version_compare(Version::comparable($latest), Version::comparable($current), '>');

        return [
            'ok'         => true,
            'checked'    => true,
            'current'    => $current,
            'latest'     => $latest,
            'has_update' => $hasUpdate,
            'release'    => $release,
            'checked_at' => 0,
            'cached'     => $cached,
            'stale'      => $stale,
            'error'      => $warning,
        ];
    }

    /** @return array<string,mixed> */
    private static function emptyState(string $current): array
    {
        return [
            'ok'         => true,
            'checked'    => false,
            'current'    => $current,
            'latest'     => '',
            'has_update' => false,
            'release'    => null,
            'checked_at' => 0,
            'cached'     => false,
            'stale'      => false,
            'error'      => '',
        ];
    }

    /** @return array<string,mixed> */
    private static function failure(string $from, string $to, string $error): array
    {
        return [
            'ok'          => false,
            'from'        => $from,
            'to'          => $to,
            'error'       => $error,
            'rolled_back' => false,
            'backup_dir'  => '',
            'db_backup'   => '',
            'replaced'    => 0,
            'added'       => 0,
            'skipped'     => [],
        ];
    }

    // ---------------------------------------------------------------------
    // HTTP helpers
    // ---------------------------------------------------------------------

    /**
     * @param array<int,string> $headers
     */
    private static function httpGet(string $url, int $maxBytes, array $headers = []): string
    {
        if (!self::isAllowedUrl($url)) {
            throw new \RuntimeException('The update server URL is not allowed.');
        }

        $requestHeaders = $headers !== [] ? $headers : ['Accept: application/json'];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('Could not initialise the HTTP client.');
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER   => true,
                CURLOPT_FOLLOWLOCATION   => true,
                CURLOPT_MAXREDIRS        => 5,
                CURLOPT_CONNECTTIMEOUT   => 15,
                CURLOPT_TIMEOUT          => 30,
                CURLOPT_USERAGENT        => self::USER_AGENT,
                CURLOPT_HTTPHEADER       => $requestHeaders,
                CURLOPT_SSL_VERIFYPEER   => true,
                CURLOPT_SSL_VERIFYHOST   => 2,
                CURLOPT_PROTOCOLS        => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS  => CURLPROTO_HTTPS,
                CURLOPT_MAXFILESIZE      => $maxBytes,
            ]);

            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);

            if ($body === false || $errno !== 0) {
                throw new \RuntimeException('Could not contact GitHub: ' . ($error !== '' ? $error : 'unknown error'));
            }
            if ($effectiveUrl !== '' && !self::isAllowedUrl($effectiveUrl)) {
                throw new \RuntimeException('GitHub redirected the request to an unexpected host.');
            }
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException(self::friendlyHttpError($status, (string)$body));
            }
            if (strlen((string)$body) > $maxBytes) {
                throw new \RuntimeException('The GitHub response is too large.');
            }

            return (string)$body;
        }

        if (!self::urlFopenEnabled()) {
            throw new \RuntimeException('Neither cURL nor allow_url_fopen is available.');
        }

        $context = stream_context_create([
            'http' => [
                'method'          => 'GET',
                'header'          => implode("\r\n", $requestHeaders)
                    . "\r\nUser-Agent: " . self::USER_AGENT . "\r\n",
                'timeout'         => 30,
                'follow_location' => 1,
                'max_redirects'   => 5,
                'ignore_errors'   => true,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context, 0, $maxBytes + 1);
        if (!is_string($body) || $body === '') {
            throw new \RuntimeException('Could not contact GitHub. Check the server network connection.');
        }
        if (strlen($body) > $maxBytes) {
            throw new \RuntimeException('The GitHub response is too large.');
        }

        $status = self::responseStatus($http_response_header ?? []);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(self::friendlyHttpError($status, $body));
        }

        return $body;
    }

    private static function friendlyHttpError(int $status, string $body): string
    {
        $message = '';
        $data = json_decode($body, true);
        if (is_array($data) && isset($data['message'])) {
            $message = trim((string)$data['message']);
        }

        if (($status === 403 || $status === 429)
            && ($message === '' || stripos($message, 'rate limit') !== false)) {
            return 'GitHub API rate limit exceeded.';
        }

        return 'GitHub returned HTTP ' . $status . ($message !== '' ? ': ' . $message : '');
    }

    private static function downloadToFile(string $url, string $path): void
    {
        if (!self::isAllowedUrl($url)) {
            throw new \RuntimeException('The update package URL is not allowed.');
        }

        self::ensureDir(dirname($path));

        if (function_exists('curl_init')) {
            $handle = @fopen($path, 'wb');
            if ($handle === false) {
                throw new \RuntimeException('Could not create the update package file.');
            }

            $ch = curl_init($url);
            if ($ch === false) {
                fclose($handle);
                @unlink($path);
                throw new \RuntimeException('Could not initialise the HTTP client.');
            }

            curl_setopt_array($ch, [
                CURLOPT_FILE             => $handle,
                CURLOPT_FOLLOWLOCATION   => true,
                CURLOPT_MAXREDIRS        => 5,
                CURLOPT_CONNECTTIMEOUT   => 15,
                CURLOPT_TIMEOUT          => 180,
                CURLOPT_USERAGENT        => self::USER_AGENT,
                CURLOPT_SSL_VERIFYPEER   => true,
                CURLOPT_SSL_VERIFYHOST   => 2,
                CURLOPT_PROTOCOLS        => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS  => CURLPROTO_HTTPS,
                CURLOPT_MAXFILESIZE      => self::MAX_DOWNLOAD_BYTES,
            ]);

            $ok = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);
            fclose($handle);

            if (!$ok || $errno !== 0) {
                @unlink($path);
                throw new \RuntimeException('Could not download the update package: ' . ($error !== '' ? $error : 'unknown error'));
            }
            if ($effectiveUrl !== '' && !self::isAllowedUrl($effectiveUrl)) {
                @unlink($path);
                throw new \RuntimeException('GitHub redirected the package download to an unexpected host.');
            }
            if ($status < 200 || $status >= 300) {
                @unlink($path);
                throw new \RuntimeException('GitHub returned HTTP ' . $status . ' while downloading the package.');
            }
        } else {
            if (!self::urlFopenEnabled()) {
                throw new \RuntimeException('Neither cURL nor allow_url_fopen is available.');
            }

            $context = stream_context_create([
                'http' => [
                    'method'          => 'GET',
                    'header'          => "Accept: application/octet-stream\r\nUser-Agent: " . self::USER_AGENT . "\r\n",
                    'timeout'         => 180,
                    'follow_location' => 1,
                    'max_redirects'   => 5,
                    'ignore_errors'   => true,
                ],
                'ssl' => [
                    'verify_peer'      => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $body = @file_get_contents($url, false, $context, 0, self::MAX_DOWNLOAD_BYTES + 1);
            if (!is_string($body) || $body === '') {
                throw new \RuntimeException('Could not download the update package.');
            }
            if (strlen($body) > self::MAX_DOWNLOAD_BYTES) {
                throw new \RuntimeException('The update package is too large.');
            }

            $status = self::responseStatus($http_response_header ?? []);
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException('GitHub returned HTTP ' . $status . ' while downloading the package.');
            }
            if (@file_put_contents($path, $body) === false) {
                throw new \RuntimeException('Could not write the update package.');
            }
        }

        $size = @filesize($path) ?: 0;
        if ($size <= 0) {
            @unlink($path);
            throw new \RuntimeException('The downloaded update package is empty.');
        }
        if ($size > self::MAX_DOWNLOAD_BYTES) {
            @unlink($path);
            throw new \RuntimeException('The update package is too large.');
        }
    }

    /** @param array<int,string> $headers */
    private static function responseStatus(array $headers): int
    {
        $status = 0;
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+([0-9]{3})#i', (string)$line, $match)) {
                $status = (int)$match[1];
            }
        }

        return $status;
    }

    private static function isAllowedUrl(string $url): bool
    {
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));

        return $scheme === 'https'
            && $host !== ''
            && in_array($host, self::ALLOWED_HOSTS, true);
    }

    private static function urlFopenEnabled(): bool
    {
        $value = strtolower(trim((string)ini_get('allow_url_fopen')));

        return in_array($value, ['1', 'on', 'yes', 'true'], true);
    }

    // ---------------------------------------------------------------------
    // Archive handling
    // ---------------------------------------------------------------------

    /**
     * Extracts a ZIP into a fresh directory without trusting entry names.
     *
     * @return string Detected package root (the directory containing index.php).
     */
    private static function extractZip(string $zipPath, string $targetDir): string
    {
        if (!class_exists('\ZipArchive')) {
            throw new \RuntimeException('The PHP ZipArchive extension is required.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Could not open the downloaded ZIP package.');
        }

        if ($zip->numFiles > self::MAX_FILES) {
            $zip->close();
            throw new \RuntimeException('The update package contains too many files.');
        }

        $targetDir = rtrim($targetDir, '/');
        $totalBytes = 0;

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (!is_array($stat)) {
                    continue;
                }

                $name = str_replace('\\', '/', (string)($stat['name'] ?? ''));
                if ($name === '' || strpos($name, "\0") !== false) {
                    continue;
                }

                $clean = ltrim($name, '/');
                if ($clean === '' || $clean === '.') {
                    continue;
                }

                $parts = explode('/', $clean);
                foreach ($parts as $part) {
                    if ($part === '..') {
                        throw new \RuntimeException('The update package contains an unsafe path.');
                    }
                }

                if (str_ends_with($name, '/')) {
                    self::ensureDir($targetDir . '/' . $clean);
                    continue;
                }

                $totalBytes += (int)($stat['size'] ?? 0);
                if ($totalBytes > self::MAX_UNPACKED_BYTES) {
                    throw new \RuntimeException('The update package is too large after unpacking.');
                }

                $target = $targetDir . '/' . $clean;
                if (!self::isPathInside($targetDir, $target)) {
                    throw new \RuntimeException('The update package contains an unsafe path.');
                }

                $attributes = 0;
                $operatingSystem = 0;
                if (method_exists($zip, 'getExternalAttributesIndex')
                    && $zip->getExternalAttributesIndex($index, $operatingSystem, $attributes)
                    && (($attributes >> 16) & 0170000) === 0120000) {
                    throw new \RuntimeException('The update package contains a symbolic link.');
                }

                self::ensureDir(dirname($target));

                $input = $zip->getStream($name);
                if ($input === false) {
                    throw new \RuntimeException('Could not read a file from the update package.');
                }

                $output = @fopen($target, 'wb');
                if ($output === false) {
                    fclose($input);
                    throw new \RuntimeException('Could not extract the update package.');
                }

                $copied = stream_copy_to_stream($input, $output);
                fclose($input);
                fclose($output);
                if ($copied === false) {
                    throw new \RuntimeException('Could not extract the update package.');
                }
                @chmod($target, 0644);
            }
        } finally {
            $zip->close();
        }

        $scanned = @scandir($targetDir);
        if (!is_array($scanned)) {
            throw new \RuntimeException('Could not inspect the extracted update package.');
        }
        $entries = array_values(array_filter(
            $scanned,
            static fn(string $entry): bool => $entry !== '.' && $entry !== '..'
        ));

        if (count($entries) === 1
            && is_dir($targetDir . '/' . $entries[0])
            && is_file($targetDir . '/' . $entries[0] . '/index.php')) {
            return $targetDir . '/' . $entries[0];
        }
        if (is_file($targetDir . '/index.php')) {
            return $targetDir;
        }

        throw new \RuntimeException('The downloaded package does not look like a ModoCMS release.');
    }

    private static function validatePackage(string $packageRoot, string $expectedVersion): void
    {
        foreach (['index.php', 'core/bootstrap.php', 'admin/index.php', 'CHANGELOG.md'] as $required) {
            if (!is_file($packageRoot . '/' . $required)) {
                throw new \RuntimeException('The downloaded package is incomplete (missing ' . $required . ').');
            }
        }

        $packageVersion = self::packageVersion($packageRoot);
        if ($packageVersion === '') {
            throw new \RuntimeException('Could not verify the version of the downloaded package.');
        }
        if (version_compare(Version::comparable($packageVersion), Version::comparable($expectedVersion), '!=')) {
            throw new \RuntimeException('The package version does not match the published release.');
        }
    }

    private static function packageVersion(string $packageRoot): string
    {
        $versionFile = $packageRoot . '/core/Version.php';
        if (is_file($versionFile)) {
            $source = @file_get_contents($versionFile);
            if (is_string($source)
                && preg_match('/const\s+CURRENT\s*=\s*[\'"]([^\'"]+)[\'"]/', $source, $match)) {
                return trim($match[1]);
            }
        }

        $changelog = $packageRoot . '/CHANGELOG.md';
        if (is_file($changelog)) {
            $source = @file_get_contents($changelog);
            if (is_string($source)
                && preg_match('/^##\s*\[([0-9]+\.[0-9]+\.[0-9]+(?:[-+][^\]]+)?)\]/m', $source, $match)) {
                return trim($match[1]);
            }
        }

        return '';
    }

    // ---------------------------------------------------------------------
    // File copying and rollback
    // ---------------------------------------------------------------------

    /**
     * @param array<int,array<string,mixed>> $manifest
     * @param array<int,string> $skipped
     * @return array{replaced: int, added: int}
     */
    private static function copyPackage(
        string $packageRoot,
        string $root,
        string $backupDir,
        array &$manifest,
        array &$skipped
    ): array {
        $replaced = 0;
        $added = 0;

        foreach (self::collectFiles($packageRoot) as $relative) {
            $source = $packageRoot . '/' . $relative;
            $target = $root . '/' . $relative;

            if (!is_file($source)) {
                continue;
            }

            // Never replace a customised root .htaccess. It carries the ModoCMS
            // version marker only when the CMS created it.
            if ($relative === '.htaccess' && is_file($target)) {
                $current = (string)@file_get_contents($target);
                if (!preg_match('/#\s*ModoCMS\s+\.htaccess\s+v[0-9]/i', $current)) {
                    $skipped[] = $relative;
                    continue;
                }
            }

            if (is_link($target)) {
                throw new \RuntimeException('Refusing to replace symbolic link: ' . $relative);
            }
            if (is_dir($target)) {
                throw new \RuntimeException('A directory exists where the package expects a file: ' . $relative);
            }

            $exists = is_file($target);
            $mode = $exists ? ((int)@fileperms($target) & 0777) : 0644;

            if ($exists) {
                $backup = $backupDir . '/files/' . $relative;
                self::ensureDir(dirname($backup));
                if (!@copy($target, $backup)) {
                    throw new \RuntimeException('Could not back up ' . $relative . ' before the update.');
                }
            }

            self::ensureDir(dirname($target));
            self::assertInside($root, $target);

            // Record the target before copying, so a partially written file is
            // still restored if the copy fails.
            $manifest[] = [
                'path'    => $relative,
                'existed' => $exists,
                'mode'    => $mode,
            ];
            self::writeManifest($backupDir, $manifest);

            // Write to a temporary file in the same directory and rename it into
            // place. A request arriving during the update then sees either the
            // old or the new file, never a half-written one.
            $temporary = $target . '.modo-update-' . bin2hex(random_bytes(4));
            if (!@copy($source, $temporary)) {
                @unlink($temporary);
                throw new \RuntimeException('Could not replace ' . $relative . '.');
            }
            @chmod($temporary, $mode > 0 ? $mode : 0644);
            if (!@rename($temporary, $target)) {
                @unlink($temporary);
                if (!@copy($source, $target)) {
                    throw new \RuntimeException('Could not replace ' . $relative . '.');
                }
                @chmod($target, $mode > 0 ? $mode : 0644);
            }

            if ($exists) {
                $replaced++;
            } else {
                $added++;
            }
        }

        return ['replaced' => $replaced, 'added' => $added];
    }

    /** @return array<int,string> */
    private static function collectFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $rootLength = strlen(rtrim($root, '/')) + 1;

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }

            $absolute = str_replace('\\', '/', $file->getPathname());
            $relative = substr($absolute, $rootLength);
            if (!is_string($relative) || $relative === '' || self::shouldSkip($relative)) {
                continue;
            }

            $files[] = $relative;
        }

        sort($files, SORT_STRING);

        return $files;
    }

    private static function shouldSkip(string $relative): bool
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '') {
            return true;
        }

        $segments = explode('/', $relative);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return true;
            }
        }

        $first = strtolower($segments[0]);
        $basename = strtolower((string)end($segments));

        if (in_array($first, ['.git', '.github', 'node_modules', 'site'], true)) {
            return true;
        }

        // Content and uploads stay untouched. Their .htaccess files are part of
        // the security rule set, so those are allowed through.
        if ($first === 'data' || $first === 'uploads') {
            return $basename !== '.htaccess';
        }

        if (in_array($relative, [
            'modoconfig.php',
            'install.php',
            'robots.txt',
            'sitemap.xml',
            '.gitignore',
            '.gitattributes',
            '.user.ini',
            '.DS_Store',
        ], true)) {
            return true;
        }

        if (str_starts_with($basename, '.') && $basename !== '.htaccess') {
            return true;
        }

        return preg_match('/\.(zip|tar|tgz|gz|lz|7z|rar|bak|old|orig|log|sqlite|sqlite3|db|sql)$/i', $basename) === 1;
    }

    /** @param array<int,array<string,mixed>> $manifest */
    private static function writeManifest(string $backupDir, array $manifest): void
    {
        @file_put_contents(
            $backupDir . '/manifest.json',
            (string)json_encode(
                ['created_at' => date('c'), 'files' => $manifest],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            ),
            LOCK_EX
        );
    }

    /** @param array<int,array<string,mixed>> $manifest */
    private static function rollback(array $manifest, string $backupDir): bool
    {
        $ok = true;
        $root = dirname(__DIR__);

        foreach (array_reverse($manifest) as $entry) {
            $relative = (string)($entry['path'] ?? '');
            if ($relative === '') {
                continue;
            }

            $target = $root . '/' . $relative;
            $backup = $backupDir . '/files/' . $relative;

            if (!empty($entry['existed'])) {
                if (!is_file($backup)) {
                    $ok = false;
                    continue;
                }
                self::ensureDir(dirname($target));
                if (!@copy($backup, $target)) {
                    $ok = false;
                    continue;
                }
                @chmod($target, (int)($entry['mode'] ?? 0644));
            } else {
                if (is_file($target) && !@unlink($target)) {
                    $ok = false;
                }
            }
        }

        return $ok;
    }

    private static function createBackupDir(string $from, string $to): string
    {
        $label = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $from . '-to-' . $to);
        $dir = dirname(__DIR__) . '/data/backups/update-' . $label . '-' . date('Ymd-His')
            . '-' . bin2hex(random_bytes(2));

        self::ensureDir($dir . '/files');

        return $dir;
    }

    // ---------------------------------------------------------------------
    // Locks, cache and small utilities
    // ---------------------------------------------------------------------

    /** @return array{handle: resource|false, path: string} */
    private static function acquireLock(): array
    {
        self::dir();
        $path = self::dataPath() . '/update.lock';
        $handle = @fopen($path, 'c');

        if ($handle === false) {
            throw new \RuntimeException('Could not create the update lock file.');
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Another update is already running.');
        }

        return ['handle' => $handle, 'path' => $path];
    }

    /** @param array{handle: resource|false, path: string} $lock */
    private static function releaseLock(array $lock): void
    {
        if (is_resource($lock['handle'])) {
            @flock($lock['handle'], LOCK_UN);
            fclose($lock['handle']);
        }
        @unlink($lock['path']);
    }

    private static function purgeCaches(): void
    {
        if (class_exists(PageCache::class)) {
            try {
                PageCache::purge();
            } catch (\Throwable $e) {
                // Cache invalidation is best effort.
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        clearstatcache(true);
    }

    private static function log(string $message): void
    {
        $dir = dirname(__DIR__) . '/data/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(
            $dir . '/updates.log',
            '[' . date('c') . '] ' . $message . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    private static function pathWritable(string $path): bool
    {
        if (is_dir($path)) {
            return is_writable($path);
        }

        return is_writable(dirname($path));
    }

    private static function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create directory: ' . $dir);
        }
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = @scandir($dir);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                self::removeDirectory($path);
            }
        }

        @rmdir($dir);
    }

    private static function isPathInside(string $base, string $path): bool
    {
        $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, $base)) {
            return false;
        }

        $relative = substr($path, strlen($base));

        return $relative !== '' && !preg_match('#(^|/)\.\.(/|$)#', $relative);
    }

    private static function assertInside(string $root, string $path): void
    {
        $rootReal = realpath($root);
        $parentReal = realpath(dirname($path));

        if ($rootReal === false || $parentReal === false) {
            throw new \RuntimeException('Could not resolve the target path.');
        }
        if ($parentReal !== $rootReal
            && !str_starts_with($parentReal, rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('The update target is outside the installation directory.');
        }
    }

    private static function truncate(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }

        return substr($value, 0, $length);
    }
}
