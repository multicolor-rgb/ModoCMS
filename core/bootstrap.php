<?php
declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);


/**
 * Bootstrap Initialization
 * Configures session security, PSR-4 styled class loader, database connection, and translation registry.
 */

ini_set('session.cookie_httponly', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_samesite', 'Lax');

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

// Boot the live customizer (detects an active preview token & draft overlay).
\Core\Customizer::init();

// CSS grid / framework support (frontend + editor, bundled locally, no CDN).
\Core\CssFramework::init();

// Frontend admin toolbar for logged-in users.
\Core\AdminBar::init();

// Frontend "Edit template settings" sidebar (live theme Customizer) for admins.
\Core\CustomizerPanel::init();
