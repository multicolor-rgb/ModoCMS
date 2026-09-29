<?php
declare(strict_types=1);

namespace Core;

use PDO;

class Security {
    private const MAX_ATTEMPTS = 5;
    private const BAN_MINUTES = 15;

    /**
     * Sends hardened HTTP security headers if enabled in settings.
     */
    public static function applySecurityHeaders(): void {
        if (headers_sent()) {
            return;
        }

        $enabled = Router::getOption('security_headers_enabled', '1');
        if ($enabled !== '1') {
            return;
        }

        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
    }

    /**
     * Checks if client IP is currently blocked due to repeated failures.
     */
    public static function isLoginBlocked(string $ip): bool {
        $enabled = Router::getOption('security_brute_force_enabled', '1');
        if ($enabled !== '1') {
            return false;
        }

        $db = Database::getConnection();
        self::purgeOldAttempts();

        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM login_attempts 
            WHERE ip_address = :ip 
              AND attempted_at >= datetime('now', '-' || :minutes || ' minutes')
        ");
        $stmt->execute([
            ':ip' => $ip,
            ':minutes' => self::BAN_MINUTES
        ]);

        return (int)$stmt->fetchColumn() >= self::MAX_ATTEMPTS;
    }

    /**
     * Records a failed login attempt for the client IP.
     */
    public static function registerFailedLogin(string $ip): void {
        $enabled = Router::getOption('security_brute_force_enabled', '1');
        if ($enabled !== '1') {
            return;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("INSERT INTO login_attempts (ip_address) VALUES (:ip)");
        $stmt->execute([':ip' => $ip]);
    }

    /**
     * Clears failed login history upon successful authentication.
     */
    public static function clearLoginAttempts(string $ip): void {
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM login_attempts WHERE ip_address = :ip");
        $stmt->execute([':ip' => $ip]);
    }

    /**
     * Cleans up attempts older than the lockout window to keep SQLite lean.
     */
    private static function purgeOldAttempts(): void {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            DELETE FROM login_attempts 
            WHERE attempted_at < datetime('now', '-' || :minutes || ' minutes')
        ");
        $stmt->execute([':minutes' => self::BAN_MINUTES * 2]);
    }

    public static function getClientIp(): string {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public static function generateCsrfToken(): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrfToken(?string $token): bool {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return !empty($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
    }
}