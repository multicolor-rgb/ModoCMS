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
            'delete_pages',
            'edit_others_pages',
            'publish_pages'
        ],
        'editor' => [
            'manage_pages',
            'delete_pages',
            'edit_others_pages',
            'publish_pages'
        ],
        'author' => [
            'manage_pages',
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

            // Second factor required: park a short-lived pending state.
            if ((int)($user['totp_enabled'] ?? 0) === 1 && !empty($user['totp_secret'])) {
                $_SESSION['pending_2fa_user_id'] = (int)$user['id'];
                $_SESSION['pending_2fa_started'] = time();
                return 'totp';
            }

            self::establishSession($user);
            return 'ok';
        }

        Security::recordFailedAttempt($ip);
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

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, username, email, role, admin_lang, totp_secret FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();

        if (!$user || empty($user['totp_secret']) || !Totp::verify((string)$user['totp_secret'], $code)) {
            return false;
        }

        unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_started']);
        self::establishSession($user);
        return true;
    }

    public static function check(): bool {
        return isset($_SESSION['user_id']);
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
        $role = self::role();
        if (!isset(self::$roleCapabilities[$role])) return false;
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