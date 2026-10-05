<?php
declare(strict_types=1);

namespace Core;

/**
 * Loads registered active plugins and triggers post-load lifecycle actions.
 */
class PluginLoader {
    public static function loadActivePlugins(): void {
        $pluginsDir = dirname(__DIR__) . '/plugins/';

        if (!is_dir($pluginsDir)) {
            @mkdir($pluginsDir, 0775, true);
            return;
        }

        // Fetch active plugins from the database
        $activePlugins = [];
        try {
            $db = Database::getConnection();
            $stmt = $db->query("SELECT folder FROM plugins WHERE is_active = 1");
            if ($stmt) {
                $activePlugins = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            }
        } catch (\Throwable $e) {
            $activePlugins = [];
        }

        // Include each enabled plugin entry file
        foreach ($activePlugins as $folderName) {
            $folderName = basename(trim((string)$folderName));
            if ($folderName === '') {
                continue;
            }

            $dir = $pluginsDir . $folderName;

            // 1. Check for standalone single-file plugin (e.g., plugins/myPlugin.php)
            if (is_file($dir . '.php')) {
                require_once $dir . '.php';
                continue;
            }

            // 2. Check for folder-based plugin entry points
            if (is_dir($dir)) {
                $candidates = [
                    $dir . '/' . $folderName . '.php',
                    $dir . '/plugin.php',
                    $dir . '/index.php'
                ];

                foreach ($candidates as $candidate) {
                    if (is_file($candidate)) {
                        require_once $candidate;
                        break;
                    }
                }
            }
        }

        // Trigger post-load hook
        Hooks::doAction('plugins_loaded');
    }
}