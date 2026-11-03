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

    /**
     * Reads plugin metadata from the file header comment without executing it.
     *
     * Useful for plugins that are installed but disabled: their
     * register_plugin() call never runs, so the runtime registry stays empty.
     *
     * @return array{name?: string, version?: string, author?: string, url?: string, desc?: string}
     */
    public static function readMetadata(string $entryFile): array
    {
        if (!is_file($entryFile) || !is_readable($entryFile)) {
            return [];
        }

        $header = (string) @file_get_contents($entryFile, false, null, 0, 16384);
        if ($header === '') {
            return [];
        }

        // Metadata lives in the opening header comment; ignore the rest of the file.
        $commentEnd = strpos($header, '*/');
        if ($commentEnd !== false) {
            $header = substr($header, 0, $commentEnd);
        }

        $fields = [
            'name'    => 'Plugin Name',
            'version' => 'Version',
            'author'  => 'Author',
            'url'     => 'Author URI',
            'desc'    => 'Description',
        ];

        $meta = [];
        foreach ($fields as $key => $label) {
            $pattern = '/^\s*[*#\/\s]*' . preg_quote($label, '/') . '\s*:\s*(.+)$/mi';
            if (preg_match($pattern, $header, $match)) {
                $value = trim($match[1]);
                if ($value !== '') {
                    $meta[$key] = $value;
                }
            }
        }

        // GetSimple plugin headers may use "Plugin URI" instead of "Author URI".
        if (empty($meta['url'])) {
            if (preg_match('/^\s*[*#\/\s]*Plugin URI\s*:\s*(.+)$/mi', $header, $match)) {
                $value = trim($match[1]);
                if ($value !== '') {
                    $meta['url'] = $value;
                }
            }
        }

        return $meta;
    }
}
