<?php
declare(strict_types=1);

/**
 * Bootstrap Initialization
 * Configures error handling, session security, PSR-4 styled class loader, database connection, and translation registry.
 */

// The advanced configuration (modoconfig.php) is loaded first: it decides how
// errors are handled, which timezone PHP uses and how the session cookie is
// flagged - all of that has to happen before the session starts.
require_once __DIR__ . '/Hooks.php';
require_once __DIR__ . '/Config.php';

$debugMode = \Core\Config::getBool('debug', false);

if ($debugMode) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}
error_reporting(E_ALL);

// Keep errors in a log file instead of dropping them (Settings → System).
if (\Core\Config::getBool('debug_log', false)) {
    $logDirectory = __DIR__ . '/../data/logs';
    if (!is_dir($logDirectory)) {
        @mkdir($logDirectory, 0775, true);
    }
    if (is_dir($logDirectory) && is_writable($logDirectory)) {
        ini_set('log_errors', '1');
        ini_set('error_log', $logDirectory . '/error.log');
    }
}

$configuredTimezone = \Core\Config::getString('timezone', '');
if ($configuredTimezone !== '' && in_array($configuredTimezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
    date_default_timezone_set($configuredTimezone);
}

// Without debug mode a fatal error would leave the visitor with a blank page.
if (PHP_SAPI !== 'cli') {
    register_shutdown_function(static function () use ($debugMode): void {
        $error = error_get_last();
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

        if ($error === null || !in_array($error['type'], $fatalTypes, true) || $debugMode || headers_sent()) {
            return;
        }

        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex">'
            . '<title>500 - Internal Server Error</title></head>'
            . '<body style="margin:0;font:16px/1.6 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#0b0f19;color:#f9fafb;display:flex;align-items:center;justify-content:center;min-height:100vh">'
            . '<div style="max-width:520px;padding:32px;background:#111827;border:1px solid #1f2937;border-radius:16px">'
            . '<h1 style="margin:0 0 12px;font-size:20px">500 - Internal Server Error</h1>'
            . '<p style="margin:0 0 12px;color:#9ca3af">Something went wrong while rendering this page. The site administrator has been notified through the error log.</p>'
            . '<p style="margin:0;color:#6b7280;font-size:13px">Enable debug mode or the error log in Settings &rarr; System to see the details.</p>'
            . '</div></body></html>';
    });
}

ini_set('session.cookie_httponly', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_samesite', 'Lax');

$secureCookieMode = \Core\Config::getString('session_cookie_secure', 'auto');
if ($secureCookieMode === 'always' || ($secureCookieMode === 'auto' && \Core\Config::requestIsHttps())) {
    ini_set('session.cookie_secure', '1');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

spl_autoload_register(function ($class) {
    $prefix = 'Core\\';
    $baseDir = __DIR__ . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// PSR-4 autoloader for the bundled PHPMailer library (core/vendor/PHPMailer/src).
spl_autoload_register(function ($class) {
    $prefix = 'PHPMailer\\PHPMailer\\';
    $baseDir = __DIR__ . '/vendor/PHPMailer/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/GetSimpleCompat.php';
require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/frontend.php';
require_once __DIR__ . '/template_tags.php';
require_once __DIR__ . '/Backup.php';

// Initialize storage, localization and load active plugins
\Core\Database::getConnection();

// Emit hardening HTTP security headers (Settings → Security).
\Core\Security::sendHeaders();

// Content-affecting admin writes invalidate the anonymous full-page cache.
if (defined('IN_ADMIN') && IN_ADMIN === true
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && \Core\Auth::check()
    && class_exists(\Core\PageCache::class)) {
    \Core\PageCache::purge();
}

\Core\I18n::init();
\Core\PluginLoader::loadActivePlugins();

// Plugins may extend the advanced options through the "modo_advanced_options"
// filter, so the cached option table is rebuilt once they are loaded.
\Core\Config::flushDefinitions();

// Boot the live customizer (detects an active preview token & draft overlay).
\Core\Customizer::init();

// CSS grid / framework support (frontend + editor, bundled locally, no CDN).
\Core\CssFramework::init();

// Frontend admin toolbar for logged-in users.
\Core\AdminBar::init();

// Frontend "Edit template settings" sidebar (live theme Customizer) for admins.
\Core\CustomizerPanel::init();
