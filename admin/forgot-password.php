<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Database;
use Core\Security;

$db = Database::getConnection();
$message = '';
$error = '';
$step = 'request'; // 'request' lub 'reset'
$token = trim($_GET['token'] ?? '');
$csrfToken = Security::generateCsrfToken();

// 1. Sprawdzanie czy podano token w adresie URL. Only a hash is stored in the
// database, so a leaked database snapshot cannot be used to reset passwords.
if (!empty($token)) {
    $stmt = $db->prepare("SELECT id, username, reset_expires FROM users WHERE reset_token = :token LIMIT 1");
    $stmt->execute([':token' => hash('sha256', $token)]);
    $user = $stmt->fetch(\PDO::FETCH_ASSOC);

    if ($user && strtotime($user['reset_expires']) > time()) {
        $step = 'reset';
    } else {
        $error = __('Invalid or expired reset token.');
    }
}

// 2. Obsługa wysłania formularzy
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    $error = __('Your session expired. Please reload the page and try again.');
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // A. Żądanie linku resetującego
    if ($action === 'request_reset') {
        $identity = trim($_POST['identity'] ?? '');

        if (!empty($identity) && !Security::rateLimit('pwreset:' . Security::clientIp(), 5, 900)) {
            $error = __('Too many password reset requests. Please try again later.');
        } elseif (!empty($identity)) {
            $stmt = $db->prepare("SELECT id, username, email FROM users WHERE username = :id OR email = :id LIMIT 1");
            $stmt->execute([':id' => $identity]);
            $foundUser = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($foundUser) {
                $rawToken = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', time() + 3600); // 1 godzina ważności

                $update = $db->prepare("UPDATE users SET reset_token = :token, reset_expires = :exp WHERE id = :uid");
                $update->execute([
                    ':token' => hash('sha256', $rawToken),
                    ':exp'   => $expires,
                    ':uid'   => $foundUser['id']
                ]);

                // Generowanie pełnego adresu URL resetu. Router::getSiteUrl()
                // respektuje ustawienie site_url oraz podkatalog instalacji,
                // dzięki czemu link działa też za proxy i po HTTPS.
                $baseUrl = rtrim(\Core\Router::getSiteUrl(), '/');
                $resetUrl = $baseUrl . '/admin/forgot-password.php?token=' . urlencode($rawToken);

                // Wysłanie wiadomości e-mail (jeśli e-mail istnieje w bazie)
                $userEmail = $foundUser['email'] ?? null;
                if ($userEmail && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                    $subject = "=?UTF-8?B?" . base64_encode(__('Modo CMS - Password Reset Request')) . "?=";
                    $body = sprintf(
                        __("Hello %s,\n\nA password reset request was made for your account.\nClick the link below to set a new password:\n\n%s\n\nThis link is valid for 1 hour.\nIf you did not request this, you can safely ignore this email."),
                        $foundUser['username'],
                        $resetUrl
                    );

                    $hostName = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
                    $fromEmail = $hostName !== '' ? 'no-reply@' . $hostName : '';

                    // Send through the central mailer so the site-wide transport
                    // (PHP mail() or SMTP/PHPMailer, see Settings → Email) is used.
                    try {
                        if (class_exists('\\Core\\Mailer')) {
                            $mailOptions = $fromEmail !== '' ? ['from_email' => $fromEmail] : [];
                            if (!\Core\Mailer::send($userEmail, $subject, $body, $mailOptions)) {
                                error_log('Password reset e-mail failed for ' . $userEmail . ': ' . \Core\Mailer::lastError());
                            }
                        } else {
                            $headers = ($fromEmail !== '' ? "From: " . $fromEmail . "\r\n" : '') .
                                       "Content-Type: text/plain; charset=UTF-8\r\n";
                            if (!@mail($userEmail, $subject, $body, $headers)) {
                                error_log('Password reset e-mail failed for ' . $userEmail . ': PHP mail() returned false.');
                            }
                        }
                    } catch (\Throwable $e) {
                        error_log('Password reset e-mail error for ' . $userEmail . ': ' . $e->getMessage());
                    }
                }

                $message = __('If the account exists, password reset instructions have been sent.');
            } else {
                // Bezpieczna odpowiedź chroniąca przed enumeracją użytkowników
                $message = __('If the account exists, password reset instructions have been sent.');
            }
        }
    }

    // B. Zapis nowego hasła
    if ($action === 'set_new_password' && $step === 'reset') {
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if (strlen($newPass) < 8) {
            $error = __('Password must be at least 8 characters long.');
        } elseif ($newPass !== $confirmPass) {
            $error = __('Passwords do not match.');
        } else {
            try {
                $hash = password_hash($newPass, PASSWORD_DEFAULT);
                $stmt = $db->prepare("
                    UPDATE users
                    SET password_hash = :pwd,
                        reset_token = NULL,
                        reset_expires = NULL,
                        password_changed_at = :changed,
                        api_token = NULL,
                        api_token_expires = NULL
                    WHERE id = :uid
                ");
                $stmt->execute([
                    ':pwd'     => $hash,
                    ':changed' => date('Y-m-d H:i:s'),
                    ':uid'     => $user['id']
                ]);

                if (class_exists(\Core\Audit::class)) {
                    \Core\Audit::log('password.reset', 'user:' . (int)$user['id']);
                }
                if (\Core\Auth::check()) {
                    \Core\Auth::logout();
                }

                header('Location: login.php?reset=success');
                exit;
            } catch (\Throwable $e) {
                error_log('Password reset failed for user #' . ($user['id'] ?? '?') . ': ' . $e->getMessage());
                $error = __('Could not update the password. Please try again later.');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= _e('Reset Password - Modo CMS') ?></title>
    <link rel="stylesheet" href="assets/css/admin.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background-color: #0b0f19; margin: 0; font-family: system-ui, -apple-system, sans-serif; }
        .login-card { width: 100%; max-width: 380px; padding: 36px; background: #fff; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3); }
        .form-group { margin-bottom: 16px; }
        .back-link { display: inline-block; font-size: 13px; color: var(--text-muted); text-decoration: none; margin-top: 16px; text-align: center; width: 100%; }
        .back-link:hover { color: var(--text-main); text-decoration: underline; }
    </style>
</head>
<body>
    <div class="login-card">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 24px;">
            <div class="brand-badge" style="width: 38px; height: 38px; font-size: 18px;">M</div>
            <div>
                <h1 style="font-size: 17px; font-weight: 700; color: var(--text-main); margin: 0;">Modo CMS</h1>
                <p style="font-size: 13px; color: var(--text-muted); margin: 0;">
                    <?= $step === 'reset' ? _e('Set a new password') : _e('Password recovery') ?>
                </p>
            </div>
        </div>

        <?php if ($message): ?>
            <div style="background: rgba(16, 185, 129, 0.1); color: #059669; padding: 10px 14px; border-radius: var(--radius-sm); margin-bottom: 16px; font-size: 13px;">
                <?= Security::sanitize($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div style="background: var(--danger-subtle); color: var(--danger); padding: 10px 14px; border-radius: var(--radius-sm); margin-bottom: 16px; font-size: 13px;">
                <?= Security::sanitize($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($step === 'request'): ?>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= Security::sanitize($csrfToken) ?>">
                <input type="hidden" name="action" value="request_reset">
                <div class="form-group">
                    <label class="form-label" for="identity"><?= _e('Username or Email') ?></label>
                    <input class="form-control" type="text" name="identity" id="identity" required autofocus placeholder="admin or email@example.com">
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 10px; font-size: 14px; margin-top: 8px;">
                    <?= _e('Send Reset Link') ?>
                </button>
            </form>
        <?php elseif ($step === 'reset'): ?>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= Security::sanitize($csrfToken) ?>">
                <input type="hidden" name="action" value="set_new_password">
                <div class="form-group">
                    <label class="form-label" for="new_password"><?= _e('New Password') ?></label>
                    <input class="form-control" type="password" name="new_password" id="new_password" required minlength="8" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label class="form-label" for="confirm_password"><?= _e('Confirm New Password') ?></label>
                    <input class="form-control" type="password" name="confirm_password" id="confirm_password" required minlength="8" autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 10px; font-size: 14px; margin-top: 8px;">
                    <?= _e('Update Password') ?>
                </button>
            </form>
        <?php endif; ?>

        <a href="login.php" class="back-link">&larr; <?= _e('Back to sign in') ?></a>
    </div>
</body>
</html>