<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Security;

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Auth::login($_POST['username'] ?? '', $_POST['password'] ?? '')) {
        header('Location: index.php');
        exit;
    } else {
        $error = __('Invalid username or password.');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= _e('Sign in to Clean CMS') ?></title>
    <link rel="stylesheet" href="assets/css/admin.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background-color: #0b0f19; }
        .login-card { width: 100%; max-width: 380px; padding: 36px; background: #fff; border-radius: 12px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.3); }
    </style>
</head>
<body>
    <div class="login-card">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 24px;">
            <div class="brand-badge" style="width: 38px; height: 38px; font-size: 18px;">C</div>
            <div>
                <h1 style="font-size: 17px; font-weight: 700; color: var(--text-main);">Clean CMS</h1>
                <p style="font-size: 13px; color: var(--text-muted);"><?= _e('Sign in to Clean CMS') ?></p>
            </div>
        </div>

        <?php if ($error): ?>
            <div style="background: var(--danger-subtle); color: var(--danger); padding: 10px 14px; border-radius: var(--radius-sm); margin-bottom: 16px; font-size: 13px;">
                <?= Security::sanitize($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label class="form-label" for="username"><?= _e('Username') ?></label>
                <input class="form-control" type="text" name="username" id="username" required autofocus autocomplete="username">
            </div>
            <div class="form-group">
                <label class="form-label" for="password"><?= _e('Password') ?></label>
                <input class="form-control" type="password" name="password" id="password" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary" style="width: 100%; padding: 10px; font-size: 14px; margin-top: 8px;">
                <?= _e('Sign In') ?>
            </button>
        </form>
    </div>
</body>
</html>
