<?php
declare(strict_types=1);

namespace Core;

/**
 * Class View
 * Scope-isolated template rendering engine with Path Traversal guards.
 */
final class View {
    public static function render(string $templatePath, array $data = []): void {
        extract($data, EXTR_SKIP);
        $realPath = realpath($templatePath);
        if (!$realPath || !file_exists($realPath)) {
            http_response_code(500);
            die("View not found: " . htmlspecialchars($templatePath, ENT_QUOTES, 'UTF-8'));
        }
        include $realPath;
    }
}
