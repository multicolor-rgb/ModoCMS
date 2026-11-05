<?php
declare(strict_types=1);

namespace Core;

/**
 * Actionable health checks for the dashboard.
 *
 * Each row contains a status (ok/warning/error/info), a short explanation and
 * an optional link to the screen where the administrator can fix it.
 */
final class SiteHealth
{
    /** @return array<int,array{id:string,label:string,status:string,detail:string,action:string}> */
    public static function checks(): array
    {
        $rows = [];

        $installed = Setup::isApplicationInstalled();
        $rows[] = self::row(
            'installation',
            __('Installation completed'),
            $installed ? 'ok' : 'error',
            $installed ? __('The installation wizard has completed.') : __('The installation wizard has not finished.'),
            $installed ? '' : 'install.php'
        );

        $installerPresent = is_file(dirname(__DIR__) . '/install.php');
        $rows[] = self::row(
            'installer',
            __('Installer removed'),
            $installerPresent ? 'error' : 'ok',
            $installerPresent
                ? __('install.php is still present. Delete or lock it after installation.')
                : __('install.php is not reachable.'),
            ''
        );

        $writable = is_writable(dirname(__DIR__) . '/data') && is_writable(dirname(__DIR__) . '/uploads');
        $rows[] = self::row(
            'permissions',
            __('Writable data and uploads'),
            $writable ? 'ok' : 'error',
            $writable ? __('The CMS can write to data/ and uploads/.') : __('data/ or uploads/ is not writable by the web server user.'),
            ''
        );

        $https = Config::requestIsHttps();
        $rows[] = self::row(
            'https',
            __('HTTPS'),
            $https ? 'ok' : 'warning',
            $https ? __('The site is served over HTTPS.') : __('The site is currently served over HTTP.'),
            ''
        );

        $debug = Config::getBool('debug', false);
        $rows[] = self::row(
            'debug',
            __('Debug mode off'),
            $debug ? 'warning' : 'ok',
            $debug ? __('Debug mode is enabled. Disable it on a production site.') : __('Debug output is disabled.'),
            ''
        );

        $headers = Router::getOption('security_headers_enabled', '1') === '1';
        $rows[] = self::row(
            'headers',
            __('Security headers'),
            $headers ? 'ok' : 'warning',
            $headers ? __('Security headers and CSP are enabled.') : __('Security headers are disabled.'),
            'settings.php?tab=security'
        );

        $cache = PageCache::isEnabled();
        $rows[] = self::row(
            'cache',
            __('Page cache'),
            $cache ? 'ok' : 'info',
            $cache ? __('Anonymous pages can be served from the cache.') : __('The page cache is disabled.'),
            'settings.php?tab=performance'
        );

        $backups = Backup::listBackups();
        $rows[] = self::row(
            'backups',
            __('Recent backup'),
            $backups === [] ? 'warning' : 'ok',
            $backups === [] ? __('No backup has been created yet.') : sprintf(__('%d backup(s) available.'), count($backups)),
            'backup.php'
        );

        $lastCron = (string)Router::getOption('scheduler_last_run', '');
        $cronFresh = $lastCron !== '' && strtotime($lastCron) !== false && (time() - strtotime($lastCron)) < 172800;
        $rows[] = self::row(
            'cron',
            __('Scheduled tasks'),
            $cronFresh ? 'ok' : 'warning',
            $cronFresh
                ? sprintf(__('Last run: %s'), $lastCron)
                : __('Cron has not run recently. Configure the system cron job.'),
            'settings.php?tab=tools'
        );

        $phpOk = version_compare(PHP_VERSION, '8.2.0', '>=');
        $rows[] = self::row(
            'php',
            __('PHP version'),
            $phpOk ? 'ok' : 'info',
            sprintf(__('Running PHP %s. PHP 8.2+ is recommended.'), PHP_VERSION),
            ''
        );

        try {
            $hash = Database::getConnection()->query("SELECT password_hash FROM users WHERE id = 1 LIMIT 1")->fetchColumn();
            $defaultPassword = is_string($hash) && $hash !== '' && password_verify('admin123', $hash);
        } catch (\Throwable $e) {
            $defaultPassword = false;
        }
        $rows[] = self::row(
            'password',
            __('Administrator password'),
            $defaultPassword ? 'error' : 'ok',
            $defaultPassword ? __('The legacy default administrator password is still in use.') : __('The administrator password is not the legacy default.'),
            'profile.php'
        );

        return $rows;
    }

    /** @param array<int,array{status:string}> $rows */
    public static function score(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $points = 0;
        foreach ($rows as $row) {
            $points += match ($row['status']) {
                'ok' => 1,
                'info' => 0.5,
                'warning' => 0.25,
                default => 0,
            };
        }

        return (int)round(($points / count($rows)) * 100);
    }

    /** @param array<int,array{status:string}> $rows */
    public static function issues(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => in_array($row['status'], ['error', 'warning'], true)
        ));
    }

    /** @return array{id:string,label:string,status:string,detail:string,action:string} */
    private static function row(string $id, string $label, string $status, string $detail, string $action): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'status' => $status,
            'detail' => $detail,
            'action' => $action,
        ];
    }
}
