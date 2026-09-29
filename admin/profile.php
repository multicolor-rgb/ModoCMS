<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';
use Core\Auth;
use Core\Database;
use Core\Security;
use Core\I18n;

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$db = Database::getConnection();
$user = Auth::user();
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_api_token') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) die('Invalid CSRF');

    $newToken = 'cms_' . bin2hex(random_bytes(24));
    $stmt = $db->prepare("UPDATE users SET api_token = :token WHERE id = :id");
    $stmt->execute([':token' => $newToken, ':id' => Auth::id()]);
    $msg = 'Generated new API token.';
    $user = Auth::user();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'save_profile')) {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) die('Invalid CSRF');

    $email = trim($_POST['email'] ?? '');
    $adminLang = trim($_POST['admin_lang'] ?? 'en');
    $newPass = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            $stmt = $db->prepare("UPDATE users SET email = :e, admin_lang = :l WHERE id = :id");
            $stmt->execute([':e' => $email, ':l' => $adminLang, ':id' => Auth::id()]);
            $_SESSION['user_email'] = $email;
            I18n::setAdminLocale($adminLang);
            $msg = __('Profile updated successfully.');

            if (!empty($newPass)) {
                if (strlen($newPass) >= 6 && $newPass === $confirmPass) {
                    $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
                    $stmt = $db->prepare("UPDATE users SET password_hash = :h WHERE id = :id");
                    $stmt->execute([':h' => $hash, ':id' => Auth::id()]);
                    $msg .= ' ' . __('Settings saved successfully.');
                } else {
                    $err = __('Password must be at least 6 characters.') . ' / ' . __('Passwords do not match.');
                }
            }
        } catch (\PDOException $e) {
            $err = 'Email address is already in use.';
        }
    } else {
        $err = 'Please provide a valid email address.';
    }
    $user = Auth::user();
}

$tokenStmt = $db->prepare("SELECT api_token FROM users WHERE id = :id");
$tokenStmt->execute([':id' => Auth::id()]);
$currentApiToken = $tokenStmt->fetchColumn();

require_once __DIR__ . '/views/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= _e('My Profile') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px;"><?= _e('Manage your account credentials and personal security') ?></p>
    </div>
</div>

<?php if ($msg): ?>
    <div class="card" style="border-left: 4px solid var(--success); padding: 12px; margin-bottom: 20px;">
        <?= Security::sanitize($msg) ?>
    </div>
<?php endif; ?>
<?php if ($err): ?>
    <div class="card" style="border-left: 4px solid var(--danger); padding: 12px; margin-bottom: 20px; color: var(--danger);">
        <?= Security::sanitize($err) ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; align-items: start;">
    <div class="card">
        <form method="POST" action="">
            <input type="hidden" name="action" value="save_profile">
            <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">

            <div class="form-group">
                <label class="form-label"><?= _e('Username') ?></label>
                <input class="form-control" type="text" value="<?= Security::sanitize($user['username'] ?? '') ?>" disabled style="background:#f1f5f9;">
            </div>

            <div class="form-group">
                <label class="form-label"><?= _e('Role') ?></label>
                <input class="form-control" type="text" value="<?= strtoupper(Security::sanitize($user['role'] ?? '')) ?>" disabled style="background:#f1f5f9;">
            </div>

            <div class="form-group">
                <label class="form-label" for="email"><?= _e('Email Address') ?></label>
                <input class="form-control" type="email" id="email" name="email" value="<?= Security::sanitize($user['email'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label" for="admin_lang"><?= _e('Admin Panel Language') ?></label>
                <select class="form-control" name="admin_lang" id="admin_lang">
                    <?php foreach (I18n::getAvailableLanguages() as $code => $name): ?>
                        <option value="<?= $code ?>" <?= ($user['admin_lang'] ?? 'en') === $code ? 'selected' : '' ?>>
                            <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?> (<?= strtoupper($code) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="border-top: 1px solid var(--border-subtle); margin: 24px 0 20px 0; padding-top: 20px;">
                <h3 style="font-size: 14px; font-weight: 600; margin-bottom: 12px;"><?= _e('Change Password (Optional)') ?></h3>
                
                <div class="form-group">
                    <label class="form-label" for="new_password"><?= _e('New Password') ?></label>
                    <input class="form-control" type="password" id="new_password" name="new_password" placeholder="<?= _e('Leave blank to keep current password') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label" for="confirm_password"><?= _e('Confirm New Password') ?></label>
                    <input class="form-control" type="password" id="confirm_password" name="confirm_password">
                </div>
            </div>

            <button type="submit" class="btn btn-primary"><?= _e('Save Profile Changes') ?></button>
        </form>
    </div>

    <div class="card">
        <h2 style="font-size: 15px; font-weight: 700; margin-bottom: 8px;">REST API Access</h2>
        <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 16px;">
            Token used to authenticate external integrations sending content to Modo CMS.
        </p>

        <form method="POST" action="">
            <input type="hidden" name="action" value="generate_api_token">
            <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">

            <div class="form-group">
                <label class="form-label">Bearer Token</label>
                <input class="form-control" type="text" value="<?= Security::sanitize((string)$currentApiToken) ?>" readonly style="background: #f8fafc; font-family: monospace; font-size: 12px;">
            </div>

            <button type="submit" class="btn btn-secondary" style="width: 100%;" onclick="return confirm('Regenerate API token? Current key will stop working immediately.');">
                <?= empty($currentApiToken) ? 'Generate API Token' : 'Regenerate API Token' ?>
            </button>
        </form>

        <div style="margin-top: 20px; font-size: 12px; color: var(--text-muted); border-top: 1px solid var(--border-subtle); padding-top: 12px;">
            <strong>Endpoint:</strong>
            <code style="display: block; background: #f1f5f9; padding: 6px; border-radius: 4px; margin-top: 4px; word-break: break-all;">
                /api/content.php
            </code>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/views/footer.php'; ?>
