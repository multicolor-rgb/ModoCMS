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

// ---------------------------------------------------------------------
// Two-Factor Authentication (2FA / TOTP) management
// ---------------------------------------------------------------------
$twoFactorAvailable = \Core\Router::getOption('security_2fa_enabled', '0') === '1';

$totpStmt = $db->prepare("SELECT totp_secret, totp_enabled FROM users WHERE id = :id");
$totpStmt->execute([':id' => Auth::id()]);
$totpRow = $totpStmt->fetch() ?: [];
$totpEnabled = (int)($totpRow['totp_enabled'] ?? 0) === 1;
$pendingSecret = (string)($totpRow['totp_secret'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'enable_2fa') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) die('Invalid CSRF');
    $secret = \Core\Totp::generateSecret();
    $db->prepare("UPDATE users SET totp_secret = :s, totp_enabled = 0 WHERE id = :id")
       ->execute([':s' => $secret, ':id' => Auth::id()]);
    $pendingSecret = $secret;
    $msg = __('Scan the QR code, then confirm with a code to activate 2FA.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_2fa') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) die('Invalid CSRF');
    $code = trim($_POST['totp_code'] ?? '');
    $secretStmt = $db->prepare("SELECT totp_secret FROM users WHERE id = :id");
    $secretStmt->execute([':id' => Auth::id()]);
    $secret = (string)$secretStmt->fetchColumn();
    if ($secret !== '' && \Core\Totp::verify($secret, $code)) {
        $db->prepare("UPDATE users SET totp_enabled = 1 WHERE id = :id")->execute([':id' => Auth::id()]);
        $totpEnabled = true;
        $msg = __('Two-factor authentication has been enabled.');
    } else {
        $err = __('Invalid authentication code. Please try again.');
        $pendingSecret = $secret;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'disable_2fa') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) die('Invalid CSRF');
    $db->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = :id")->execute([':id' => Auth::id()]);
    $totpEnabled = false;
    $pendingSecret = '';
    $msg = __('Two-factor authentication has been disabled.');
}

$totpUri = '';
if (!$totpEnabled && $pendingSecret !== '') {
    $issuer = \Core\Router::getOption('site_title', 'Modo CMS');
    $totpUri = \Core\Totp::provisioningUri($pendingSecret, (string)($user['username'] ?? 'admin'), $issuer);
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

            <button type="submit" class="btn btn-secondary" style="width: 100%;"
                    data-confirm
                    data-confirm-title="<?= empty($currentApiToken) ? 'Generate API Token' : 'Regenerate API Token' ?>"
                    data-confirm-message="<?= _e('Regenerate API token? Current key will stop working immediately.') ?>"
                    data-confirm-ok="<?= _e('Confirm') ?>">
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
<div class="card" style="margin-top: 24px;">
    <h2 style="font-size: 15px; font-weight: 700; margin-bottom: 12px;">
        <span style="display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:8px; background: <?= $totpEnabled ? 'var(--success, #10b981)' : 'var(--text-muted, #94a3b8)' ?>;"></span>
        <?= _e('Two-Factor Authentication (2FA)') ?>
    </h2>

    <?php if (!$twoFactorAvailable): ?>
        <p style="font-size: 13px; color: var(--text-muted); margin: 0;">
            <?= _e('Two-factor authentication is currently disabled globally. Enable it under Settings → Security Hardening.') ?>
        </p>
    <?php elseif ($totpEnabled): ?>
        <p style="font-size: 13px; color: var(--success, #10b981); font-weight: 600; margin: 0 0 12px 0;">
            <?= _e('Two-factor authentication is active on your account.') ?>
        </p>
        <form method="POST" action=""
              data-confirm
              data-confirm-title="<?= _e('Disable Two-Factor Authentication') ?>"
              data-confirm-message="<?= _e('Disable two-factor authentication?') ?>"
              data-confirm-ok="<?= _e('Disable') ?>"
              data-confirm-danger>
            <input type="hidden" name="action" value="disable_2fa">
            <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
            <button type="submit" class="btn btn-danger-ghost"><?= _e('Disable 2FA') ?></button>
        </form>
    <?php else: ?>
        <?php if ($totpUri !== ''): ?>
            <p style="font-size: 13px; color: var(--text-muted); margin: 0 0 14px 0;">
                <?= _e('Scan this QR code with Google Authenticator, Microsoft Authenticator or any TOTP app, then confirm with the generated code.') ?>
            </p>
            <div style="display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start; margin-bottom: 16px;">
                <div id="totp-qr" style="background: #fff; padding: 8px; border-radius: 8px;"></div>
                <div style="font-size: 13px; max-width: 320px;">
                    <p style="margin: 0 0 4px 0; color: var(--text-muted);"><?= _e('Or enter this key manually:') ?></p>
                    <code style="display:block; background:#f1f5f9; color:#0f172a; padding:8px; border-radius:6px; font-size:13px; word-break: break-all;"><?= htmlspecialchars($pendingSecret, ENT_QUOTES, 'UTF-8') ?></code>
                </div>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="confirm_2fa">
                <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
                <div class="form-group" style="max-width: 220px;">
                    <label class="form-label" for="totp_code"><?= _e('Confirmation Code') ?></label>
                    <input class="form-control" type="text" name="totp_code" id="totp_code" inputmode="numeric" pattern="[0-9]*" maxlength="6" required autocomplete="one-time-code">
                </div>
                <button type="submit" class="btn btn-primary"><?= _e('Activate 2FA') ?></button>
            </form>
        <?php else: ?>
            <p style="font-size: 13px; color: var(--text-muted); margin: 0 0 14px 0;">
                <?= _e('Add an extra layer of security by requiring a one-time code from your authenticator app at every sign in.') ?>
            </p>
            <form method="POST" action="">
                <input type="hidden" name="action" value="enable_2fa">
                <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">
                <button type="submit" class="btn btn-primary"><?= _e('Enable 2FA') ?></button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($totpUri !== ''): ?>
<script src="assets/js/qrcode.js"></script>
<script>
(function () {
    var el = document.getElementById('totp-qr');
    if (!el || typeof qrcode !== 'function') return;
    var otpauthUri = <?= json_encode($totpUri, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var qr = qrcode(0, 'M');
    qr.addData(otpauthUri);
    qr.make();
    el.innerHTML = qr.createSvgTag(4, 2);
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/views/footer.php'; ?>
