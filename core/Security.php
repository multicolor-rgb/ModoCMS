<?php
declare(strict_types=1);

namespace Core;

/**
 * Class Security
 * Handles anti-CSRF token generation and validation, input sanitization, and output encoding.
 */
final class Security {
    /**
     * Generates or retrieves existing session CSRF token.
     */
    public static function generateCsrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Validates incoming token against stored session token using constant-time comparison.
     */
    public static function verifyCsrfToken(?string $token): bool {
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Sanitizes string data for secure HTML output to prevent XSS attacks.
     */
    public static function sanitize(string $data): string {
        return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
    }
}
