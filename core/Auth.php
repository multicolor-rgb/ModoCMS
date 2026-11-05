<?php
declare(strict_types=1);

namespace Core;

/**
 * Class Auth
 * Manages user authentication, session security, and Role-Based Access Control (RBAC).
 */
final class Auth {
    /**
     * Capability matrix mapping roles to authorized operations.
     */
    private static array $roleCapabilities = [
        'admin' => [
            'manage_settings',
            'manage_plugins',
            'manage_users',
            'manage_pages',
            'manage_media',
            'manage_taxonomies',
            'view_revisions',
            'create_pages',
            'edit_own_pages',
            'delete_pages',
            'edit_others_pages',
            'publish_pages'
        ],
        'editor' => [
            'manage_pages',
            'manage_media',
            'manage_taxonomies',
            'view_revisions',
            'create_pages',
            'edit_own_pages',
            'delete_pages',
            'edit_others_pages',
            'publish_pages'
        ],
        'author' => [
            'manage_pages',
            'manage_media',
            'view_revisions',
            'create_pages',
            'edit_own_pages'
        ]
    ];

    /**
     * Verifies login credentials (username or email) and regenerates session ID to prevent Session Fixation.
     *
     * @return string 'ok' on full success, 'totp' when a second factor is required,
     *                'locked' when the IP is temporarily throttled, 'fail' otherwise.
     */
    public static function login(string $identifier, string $password): string {
        $ip = Security::clientIp();

        if (Security::tooManyAttempts($ip)) {
            return 'locked';
        }

        $db = Database::getConnection();

        $stmt = $db->prepare("
            SELECT id, username, password_hash, email, role, admin_lang, totp_secret, totp_enabled
            FROM users
            WHERE username = :id OR email = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $identifier]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            Security::clearAttempts($ip);

            // Opportunistically upgrade legacy hashes (cost, algorithm).
            if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
                try {
                    $db->prepare("UPDATE users SET password_hash = :hash WHERE id = :id")
                        ->execute([
                            ':hash' => password_hash($password, PASSWORD_DEFAULT),
                            ':id'   => (int)$user['id'],
                        ]);
                } catch (\Throwable $e) {
                    // A failed rehash must not block a valid login.
                }
            }

            // Second factor required: park a short-lived pending state.
            if ((int)($user['totp_enabled'] ?? 0) === 1 && !empty($user['totp_secret'])) {
                $_SESSION['pending_2fa_user_id'] = (int)$user['id'];
                $_SESSION['pending_2fa_started'] = time();
                $_SESSION['totp_attempts'] = 0;
                return 'totp';
            }

            self::establishSession($user);
            if (class_exists(Audit::class)) {
                Audit::log('auth.login', 'user:' . (int)$user['id']);
            }
            return 'ok';
        }

        Security::recordFailedAttempt($ip);
        if (class_exists(Audit::class)) {
            Audit::log('auth.login.failed', substr($identifier, 0, 120));
        }
        return 'fail';
    }

    /**
     * Establishes the authenticated session (shared by direct and 2FA logins).
     */
    private static function establishSession(array $user): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user_name'] = $user['username'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_admin_lang'] = $user['admin_lang'] ?? 'en';
        $_SESSION['auth_time'] = time();
        $_SESSION['last_activity'] = time();

        // A token issued before authentication must not survive it.
        unset($_SESSION['csrf_token'], $_SESSION['totp_attempts'], $_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_started']);
    }

    /**
     * Returns the id of the user currently awaiting a 2FA code, or 0 when none
     * (the pending state expires after 5 minutes to limit brute-forcing codes).
     */
    public static function pendingTotpUserId(): int {
        $id = (int)($_SESSION['pending_2fa_user_id'] ?? 0);
        if ($id <= 0) {
            return 0;
        }
        if (time() - (int)($_SESSION['pending_2fa_started'] ?? 0) > 300) {
            unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_started']);
            return 0;
        }
        return $id;
    }

    /**
     * Validates a TOTP code against the pending user and, on success, completes
     * the login (regenerating the session id).
     */
    public static function verifyTotp(string $code): bool {
        $id = self::pendingTotpUserId();
        if ($id <= 0) {
            return false;
        }

        // Five wrong codes within a pending login lock the second factor for
        // five minutes; the IP bucket additionally stops scripted guessing.
        $attempts = (int)($_SESSION['totp_attempts'] ?? 0);
        if ($attempts >= 5) {
            return false;
        }
        if (!Security::rateLimit('totp:' . Security::clientIp(), 10, 300)) {
            return false;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, username, email, role, admin_lang, totp_secret FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();

        if (!$user || empty($user['totp_secret']) || !Totp::verify((string)$user['totp_secret'], $code)) {
            $_SESSION['totp_attempts'] = $attempts + 1;
            return false;
        }

        unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_started']);
        self::establishSession($user);
        return true;
    }

    /** Last positive session validation, avoids repeated queries per request. */
    private static ?bool $sessionValid = null;

    public static function check(): bool {
        if (!isset($_SESSION['user_id'])) {
            return false;
        }

        if (self::$sessionValid !== null) {
            return self::$sessionValid;
        }

        $now = time();
        $idleMinutes = (int)Router::getOption('session_idle_timeout', '180');
        $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
        if ($idleMinutes > 0 && $lastActivity > 0 && ($now - $lastActivity) > ($idleMinutes * 60)) {
            self::logout();
            self::$sessionValid = false;
            return false;
        }
        $_SESSION['last_activity'] = $now;

        // Password changes invalidate every session started before the change.
        if (defined('IN_ADMIN') && IN_ADMIN === true) {
            try {
                $stmt = Database::getConnection()->prepare("SELECT password_changed_at FROM users WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => self::id()]);
                $changedAt = $stmt->fetchColumn();
                $authTime = (int)($_SESSION['auth_time'] ?? 0);
                if (is_string($changedAt) && $changedAt !== '' && strtotime($changedAt) > $authTime) {
                    self::logout();
                    self::$sessionValid = false;
                    return false;
                }
            } catch (\Throwable $e) {
                // Never lock an administrator out because of a transient query error.
                Logger::exception($e, 'Session password-stamp check failed');
            }
        }

        self::$sessionValid = true;
        return true;
    }

    public static function id(): int {
        return (int)($_SESSION['user_id'] ?? 0);
    }

    public static function role(): string {
        return (string)($_SESSION['user_role'] ?? 'guest');
    }

    public static function adminLang(): string {
        return (string)($_SESSION['user_admin_lang'] ?? 'en');
    }

    public static function user(): array {
        if (!self::check()) return [];
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, username, email, role, admin_lang, created_at FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => self::id()]);
        return $stmt->fetch() ?: [];
    }

    /**
     * Validates whether the currently authenticated user possesses a given capability.
     */
    public static function can(string $capability): bool {
        return self::roleCan(self::role(), $capability);
    }

    /** Capability check for an explicit role (used by bearer-token API calls). */
    public static function roleCan(string $role, string $capability): bool {
        if (!isset(self::$roleCapabilities[$role])) {
            return false;
        }

        return in_array($capability, self::$roleCapabilities[$role], true);
    }

    /**
     * Access gatekeeper. Halts execution with HTTP 403 if permission is missing.
     */
    public static function requireCapability(string $capability): void {
        if (!self::check()) {
            header('Location: login.php');
            exit;
        }
        if (!self::can($capability)) {
            http_response_code(403);
            die('<div style="font-family:system-ui; padding:40px; text-align:center;"><h2>403 - Forbidden</h2><p>You do not have permission to access this resource.</p><a href="index.php">Back to Dashboard</a></div>');
        }
    }

    public static function logout(): void {
        self::$sessionValid = false;
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }
}