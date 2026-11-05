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
        header('X-Permitted-Cross-Domain-Policies: none');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        if (Router::getOption('security_csp_enabled', '1') === '1') {
            // Inline handlers are still allowed because the bundled editor and
            // several plugins rely on them. The policy still blocks plugin
            // objects, <base> injection, cross-origin framing and form posts.
            $csp = "default-src 'self' https: data: blob:; "
                . "script-src 'self' 'unsafe-inline' 'unsafe-eval' blob: https:; "
                . "style-src 'self' 'unsafe-inline' https:; "
                . "img-src 'self' data: blob: https:; "
                . "font-src 'self' data: https:; "
                . "connect-src 'self' https:; worker-src 'self' blob:; "
                . "media-src 'self' data: blob: https:; "
                . "frame-src 'self' https:; "
                . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";
            header('Content-Security-Policy: ' . $csp);
        }

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

    /**
     * Fixed-window rate limiter backed by SQLite.
     *
     * @return bool True when the request is still within the limit.
     */
    public static function rateLimit(string $bucket, int $max, int $windowSeconds): bool {
        if ($max < 1 || $windowSeconds < 1) {
            return true;
        }

        try {
            $db = Database::getConnection();
            $key = hash('sha256', $bucket);
            $now = time();
            $reset = $now + $windowSeconds;

            $stmt = $db->prepare("
                INSERT INTO rate_limits (bucket, hits, reset_at) VALUES (:bucket, 1, :reset)
                ON CONFLICT(bucket) DO UPDATE SET
                    hits = CASE WHEN rate_limits.reset_at <= :now THEN 1 ELSE rate_limits.hits + 1 END,
                    reset_at = CASE WHEN rate_limits.reset_at <= :now THEN :reset2 ELSE rate_limits.reset_at END
            ");
            $stmt->execute([
                ':bucket' => $key,
                ':reset'  => $reset,
                ':now'    => $now,
                ':reset2' => $reset,
            ]);

            $check = $db->prepare("SELECT hits FROM rate_limits WHERE bucket = :bucket LIMIT 1");
            $check->execute([':bucket' => $key]);

            return (int)$check->fetchColumn() <= $max;
        } catch (\Throwable $e) {
            // Never make the site unavailable because of the limiter.
            return true;
        }
    }

    /** Removes expired rate-limit rows and old authentication audit entries. */
    public static function prune(): void {
        try {
            $db = Database::getConnection();

            $loginDays = max(1, (int)Router::getOption('login_attempts_retention_days', '30'));
            $db->prepare("DELETE FROM login_attempts WHERE attempted_at < datetime('now', :window)")
                ->execute([':window' => '-' . $loginDays . ' days']);

            $db->prepare("DELETE FROM rate_limits WHERE reset_at < :cutoff")
                ->execute([':cutoff' => time() - 86400]);
        } catch (\Throwable $e) {
            // Housekeeping is best-effort.
        }

        if (class_exists(Audit::class)) {
            Audit::prune((int)Router::getOption('audit_retention_days', '365'));
        }
    }
}
