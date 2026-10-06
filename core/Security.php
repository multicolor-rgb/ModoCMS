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

    /**
     * Emits hardening HTTP security headers when the feature is enabled in
     * Settings. Complements (and mirrors) the static rules in .htaccess so the
     * protection also applies on servers without mod_headers.
     */
    public static function sendHeaders(): void {
        if (headers_sent()) {
            return;
        }
        if (Router::getOption('security_headers_enabled', '1') !== '1') {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /**
     * Resolves the connecting client IP address.
     */
    public static function clientIp(): string {
        return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /**
     * Whether the IP has exceeded the allowed number of failed logins within the
     * configured time window (Brute-Force throttling).
     */
    public static function tooManyAttempts(string $ip): bool {
        if (Router::getOption('security_brute_force_enabled', '1') !== '1') {
            return false;
        }

        $max = max(1, (int)Router::getOption('security_brute_force_max', '5'));
        $window = max(1, (int)Router::getOption('security_brute_force_window', '15'));

        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM login_attempts
                WHERE ip_address = :ip AND attempted_at >= datetime('now', :win)
            ");
            $stmt->execute([':ip' => $ip, ':win' => '-' . $window . ' minutes']);
            return (int)$stmt->fetchColumn() >= $max;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Records a failed login attempt for the given IP.
     */
    public static function recordFailedAttempt(string $ip): void {
        try {
            Database::getConnection()
                ->prepare("INSERT INTO login_attempts (ip_address) VALUES (:ip)")
                ->execute([':ip' => $ip]);
        } catch (\Throwable $e) {
            // Never block authentication on audit-log failure.
        }
    }

    /**
     * Clears the failed-attempt history for an IP (called after a valid login).
     */
    public static function clearAttempts(string $ip): void {
        try {
            Database::getConnection()
                ->prepare("DELETE FROM login_attempts WHERE ip_address = :ip")
                ->execute([':ip' => $ip]);
        } catch (\Throwable $e) {
            // ignore
        }
    }
}
