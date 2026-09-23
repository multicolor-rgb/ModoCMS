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
    $base_dir = __DIR__ . '/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

require_once __DIR__ . '/GetSimpleCompat.php';
require_once __DIR__ . '/I18n.php';
require_once __DIR__ . '/frontend.php';

// Initialize storage, localization and load active plugins
\Core\Database::getConnection();
\Core\I18n::init();
\Core\PluginLoader::loadActivePlugins();
