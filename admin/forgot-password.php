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

// 1. Sprawdzanie czy podano token w adresie URL
if (!empty($token)) {
    $stmt = $db->prepare("SELECT id, username, reset_expires FROM users WHERE reset_token = :token LIMIT 1");
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch(\PDO::FETCH_ASSOC);

    if ($user && strtotime($user['reset_expires']) > time()) {
        $step = 'reset';
    } else {
        $error = __('Invalid or expired reset token.');
    }
}

// 2. Obsługa wysłania formularzy
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // A. Żądanie linku resetującego
    if ($action === 'request_reset') {
        $identity = trim($_POST['identity'] ?? '');

        if (!empty($identity)) {
            $stmt = $db->prepare("SELECT id, username, email FROM users WHERE username = :id OR email = :id LIMIT 1");
            $stmt->execute([':id' => $identity]);
            $foundUser = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($foundUser) {
                $rawToken = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', time() + 3600); // 1 godzina ważności

                $update = $db->prepare("UPDATE users SET reset_token = :token, reset_expires = :exp WHERE id = :uid");
                $update->execute([
                    ':token' => $rawToken,
                    ':exp'   => $expires,
                    ':uid'   => $foundUser['id']
                ]);

                // Generowanie pełnego adresu URL resetu
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
                $host = $_SERVER['HTTP_HOST'];
                $resetUrl = $protocol . $host . dirname($_SERVER['PHP_SELF']) . '/forgot-password.php?token=' . $rawToken;

                // Wysłanie wiadomości e-mail (jeśli e-mail istnieje w bazie)
                $userEmail = $foundUser['email'] ?? null;
                if ($userEmail && filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                    $subject = "=?UTF-8?B?" . base64_encode(__('Modo CMS - Password Reset Request')) . "?=";
                    $body = sprintf(
                        __("Hello %s,\n\nA password reset request was made for your account.\nClick the link below to set a new password:\n\n%s\n\nThis link is valid for 1 hour.\nIf you did not request this, you can safely ignore this email."),
                        $foundUser['username'],
                        $resetUrl
                    );
                    // Send through the central mailer so the site-wide transport
                    // (PHP mail() or SMTP/PHPMailer, see Settings → Email) is used.
                    $fromEmail = 'no-reply@' . parse_url($host, PHP_URL_HOST);
                    if (class_exists('\\Core\\Mailer')) {
                        \Core\Mailer::send($userEmail, $subject, $body, ['from_email' => $fromEmail]);
                    } else {
                        $headers = "From: " . $fromEmail . "\r\n" .
                                   "Content-Type: text/plain; charset=UTF-8\r\n";
                        @mail($userEmail, $subject, $body, $headers);
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
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password = :pwd, reset_token = NULL, reset_expires = NULL WHERE id = :uid");
            $stmt->execute([
                ':pwd' => $hash,
                ':uid' => $user['id']
            ]);

            header('Location: login.php?reset=success');
            exit;
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
            <div class="brand-badge" style="width: 38px; height: 38px; font-size: 18px;">C</div>
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